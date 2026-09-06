<?php

declare(strict_types=1);

namespace Aicountly\Api\Database;

use Aicountly\Api\Env;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * PDO access to PostgreSQL.
 *
 * PostgreSQL specifically, not "a database": notes are stored as `jsonb`,
 * search runs on a generated `tsvector` with a GIN index, and semantic search
 * expects pgvector. Those are the reasons the note document is a queryable
 * structure rather than a blob, so they are not portability details to trade
 * away. See docs/DATABASE.md.
 *
 * Every query in this codebase goes through `select`/`execute` with bound
 * parameters. There is no method here that concatenates a caller's value into
 * SQL, which is the property that makes injection a code-review question with
 * one answer rather than a per-query risk.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '5432');
        $name = Env::get('DB_NAME');
        $user = Env::get('DB_USER');
        $password = Env::get('DB_PASSWORD');

        if ($name === '' || $user === '') {
            throw new ApiException(
                503,
                'DATABASE_NOT_CONFIGURED',
                'The Notes database is not configured on this server.',
            );
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
        if (Env::get('DB_SSLMODE') !== '') {
            $dsn .= ';sslmode=' . Env::get('DB_SSLMODE');
        }

        try {
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // Real prepared statements, so the value never reaches the
                // parser as text.
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (\PDOException $e) {
            // The DSN carries the credentials; the message must not.
            Logger::error('database.connect_failed', ['driver' => 'pgsql', 'host' => $host]);
            throw new ApiException(503, 'DATABASE_UNAVAILABLE', 'The Notes database is unavailable.');
        }

        $schema = Env::get('DB_SCHEMA');
        if ($schema !== '' && preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) === 1) {
            $pdo->exec('SET search_path TO "' . $schema . '", public');
        }

        // Timestamps cross the wire as UTC regardless of the server's locale.
        $pdo->exec("SET TIME ZONE 'UTC'");

        return self::$pdo = $pdo;
    }

    /**
     * Make PHP values safe to bind against PostgreSQL.
     *
     * PDO's pgsql driver sends a bound `false` as an **empty string**, which
     * Postgres then refuses with `invalid input syntax for type boolean`. A
     * bound `true` happens to arrive as `1` and works, so the bug only shows up
     * on the false branch — in production, in whichever endpoint nobody tested
     * with the flag off. Normalising both here means no caller has to remember.
     *
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>
     */
    private static function normalise(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute(self::normalise($bindings));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = self::select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $bindings */
    public static function execute(string $sql, array $bindings = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute(self::normalise($bindings));

        return $statement->rowCount();
    }

    /**
     * Run a unit of work in one transaction.
     *
     * Nested calls join the outer transaction rather than opening a second one,
     * so a service that writes a note and a service that writes its revision
     * can each be transactional without fighting over the connection.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Test seam: point the pool at an already-open handle. */
    public static function swap(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
