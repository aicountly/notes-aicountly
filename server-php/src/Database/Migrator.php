<?php

declare(strict_types=1);

namespace Aicountly\Api\Database;

/**
 * Migration runner for the plain-SQL files in `server-php/migrations`.
 *
 * Each file holds an `-- @UP` section and a `-- @DOWN` section, so every
 * migration is reversible by construction rather than by convention. A file
 * marked `-- @OPTIONAL` may fail without failing the run: that is how pgvector
 * is attempted on hosts that have it and skipped on hosts that do not, instead
 * of making semantic search a precondition for the product booting at all.
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(private readonly string $migrationsDir)
    {
    }

    public function ensureTable(): void
    {
        Connection::execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version     varchar(120) PRIMARY KEY,
                applied_at  timestamptz NOT NULL DEFAULT now(),
                skipped     boolean NOT NULL DEFAULT FALSE,
                note        text NULL
            )',
        );
    }

    /** @return array<int, string> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = Connection::select('SELECT version FROM ' . self::TABLE . ' ORDER BY version');

        return array_map(static fn (array $row) => (string) $row['version'], $rows);
    }

    /** @return array<int, array{version: string, path: string}> */
    public function available(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map(
            static fn (string $path) => ['version' => basename($path, '.sql'), 'path' => $path],
            $files,
        );
    }

    /**
     * Apply everything not yet applied.
     *
     * @return array<int, array{version: string, status: string, error?: string}>
     */
    public function up(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $results = [];

        foreach ($this->available() as $migration) {
            if (in_array($migration['version'], $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($migration['path']);
            $optional = str_contains($sql, '@OPTIONAL');
            $up = self::section($sql, 'UP');

            if (trim($up) === '') {
                $results[] = ['version' => $migration['version'], 'status' => 'empty'];
                continue;
            }

            try {
                // Postgres runs DDL transactionally, so a migration that fails
                // half way leaves no partial schema behind.
                Connection::transaction(static function () use ($up): void {
                    Connection::pdo()->exec($up);
                });
                $this->record($migration['version'], false, null);
                $results[] = ['version' => $migration['version'], 'status' => 'applied'];
            } catch (\Throwable $e) {
                if (!$optional) {
                    throw $e;
                }
                // Recorded as skipped, not left pending: a later run must not
                // keep retrying an extension the host will never have.
                $this->record($migration['version'], true, $e->getMessage());
                $results[] = [
                    'version' => $migration['version'],
                    'status' => 'skipped',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** Roll back the most recently applied migration. */
    public function down(int $steps = 1): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $results = [];

        foreach (array_slice(array_reverse($applied), 0, max(1, $steps)) as $version) {
            $path = rtrim($this->migrationsDir, '/') . '/' . $version . '.sql';
            if (!is_readable($path)) {
                $results[] = ['version' => $version, 'status' => 'missing-file'];
                continue;
            }

            $down = self::section((string) file_get_contents($path), 'DOWN');
            if (trim($down) !== '') {
                Connection::transaction(static function () use ($down): void {
                    Connection::pdo()->exec($down);
                });
            }
            Connection::execute('DELETE FROM ' . self::TABLE . ' WHERE version = :v', ['v' => $version]);
            $results[] = ['version' => $version, 'status' => 'reverted'];
        }

        return $results;
    }

    private function record(string $version, bool $skipped, ?string $note): void
    {
        Connection::execute(
            'INSERT INTO ' . self::TABLE . ' (version, skipped, note) VALUES (:v, :s, :n)
             ON CONFLICT (version) DO UPDATE SET skipped = EXCLUDED.skipped, note = EXCLUDED.note',
            ['v' => $version, 's' => $skipped ? 1 : 0, 'n' => $note],
        );
    }

    /** Extract `-- @UP` … up to `-- @DOWN` (or EOF). */
    private static function section(string $sql, string $name): string
    {
        $marker = '-- @' . $name;
        $start = strpos($sql, $marker);
        if ($start === false) {
            return '';
        }
        $start += strlen($marker);

        $body = substr($sql, $start);
        foreach (['-- @UP', '-- @DOWN'] as $other) {
            $end = strpos($body, $other);
            if ($end !== false) {
                $body = substr($body, 0, $end);
            }
        }

        return $body;
    }
}
