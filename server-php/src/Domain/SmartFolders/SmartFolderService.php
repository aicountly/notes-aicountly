<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\SmartFolders;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Notes\NoteQuery;
use Aicountly\Api\Domain\Notes\NoteRepository;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Saved queries that look like folders.
 *
 * A smart folder **never moves a note**. It stores a rule tree and nothing
 * else, so one note can appear in five of them at once and in none of them
 * tomorrow because someone removed a tag. That is the whole difference between
 * this and a notebook, and it is why deleting a folder cannot lose anything.
 *
 * Three rules hold the design together:
 *
 *   - **One rule interpreter.** The rules are executed by
 *     {@see NoteQuery::fromRules()} — the same builder behind `GET /notes` and
 *     search. A second interpreter here would be a second place for an unknown
 *     field to reach SQL as text, and the two would drift.
 *   - **Validation happens at the write.** A folder is saved only if its rules
 *     compile, so a broken folder cannot be stored and then 500 the sidebar of
 *     whoever opens it next.
 *   - **Access is decided at the read, once.** Listing goes through
 *     {@see NoteRepository::list()}, which composes {@see NoteAccess::cte()}.
 *
 * That last point has a consequence worth stating outright: a rule may name a
 * notebook id or a tag the author cannot see, and saving it is *not* refused.
 * The tenant-and-grant gate runs on every read, so a pasted-in id matches
 * nothing — whereas checking it at write time would turn this endpoint into an
 * oracle for "does this id exist somewhere in the system".
 *
 * A smart folder belongs to one person. There is no share surface and no role
 * ladder, so every failure here is 404: there is no state in which a caller can
 * see a folder but may not change it.
 */
final class SmartFolderService
{
    /**
     * How many folders one person may keep.
     *
     * The list endpoint costs one bounded count per folder, so this number is
     * what bounds that page — not a taste judgement about how many folders is
     * tidy.
     */
    private const MAX_FOLDERS = 50;

    /**
     * The default ceiling on a folder's live match count.
     *
     * Overridable with `NOTES_SMART_FOLDER_COUNT_CAP`, because how much a scan
     * costs depends on the size of the library it runs over.
     *
     * @see self::countFor()
     */
    private const DEFAULT_COUNT_CAP = 500;

    private const MAX_NAME = 200;
    private const MAX_ICON = 60;
    private const MAX_POSITION = 100000;

    /** The scopes a smart folder can be read in. Trash is deliberately absent. */
    private const SCOPES = ['active', 'archive', 'all'];

    private const EMPTY_RULES = ['match' => 'all', 'conditions' => []];

    public function __construct(
        private readonly NoteRepository $repository = new NoteRepository(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * The caller's folders, each with a live match count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(Identity $identity): array
    {
        $rows = Connection::select(
            'SELECT id, name, icon, color, rules, position, created_at, updated_at
             FROM smart_folders
             WHERE owner_user_id = :owner AND deleted_at IS NULL
               AND (tenant_id IS NULL OR tenant_id = :tenant)
             ORDER BY position, lower(name), id
             LIMIT :limit',
            [
                'owner' => $identity->userId,
                'tenant' => $identity->tenantId,
                'limit' => self::MAX_FOLDERS,
            ],
        );

        return array_map(fn (array $row): array => $this->present($identity, $row), $rows);
    }

    /**
     * The notes a folder matches, one page at a time.
     *
     * Everything about *which* notes are reachable is decided by the repository
     * and the access CTE it composes, exactly as `GET /notes` decides it. This
     * method only turns a stored rule tree into the filter that goes with it.
     *
     * @param array<string, mixed> $options `limit`, `cursor`, `sort`, `scope`.
     * @return array{notes: array<int, array<string, mixed>>, next_cursor: string|null, has_more: bool}
     */
    public function notes(Identity $identity, string $folderId, array $options = []): array
    {
        $rules = $this->rulesOf($this->requireFolder($identity, $folderId));

        return $this->repository->list($identity, $this->compile($rules), [
            'limit' => $options['limit'] ?? 30,
            'cursor' => $options['cursor'] ?? '',
            'sort' => $options['sort'] ?? 'updated_desc',
            'scope' => $this->scope($rules, (string) ($options['scope'] ?? '')),
        ]);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, array $input): array
    {
        // The client may supply the id it already used locally, so a folder
        // created offline keeps its identity when the queue drains.
        $folderId = isset($input['id']) && Uuid::isValid($input['id'])
            ? strtolower((string) $input['id'])
            : Uuid::v4();

        $existing = Connection::selectOne('SELECT id FROM smart_folders WHERE id = :id', ['id' => $folderId]);
        if ($existing !== null) {
            // A replayed create must not produce a second folder — and must not
            // hand the caller someone else's, so it goes back through the
            // ownership check like any other read.
            return $this->present($identity, $this->requireFolder($identity, $folderId));
        }

        $this->requireRoom($identity);

        $name = $this->name($input['name'] ?? null);
        $rules = $this->validRules($input['rules'] ?? self::EMPTY_RULES);

        Connection::execute(
            'INSERT INTO smart_folders (id, tenant_id, owner_user_id, name, icon, color, rules, position)
             VALUES (:id, :tenant_id, :owner, :name, :icon, :color, :rules::jsonb, :position)',
            [
                'id' => $folderId,
                'tenant_id' => $identity->tenantId,
                'owner' => $identity->userId,
                'name' => $name,
                'icon' => $this->icon($input['icon'] ?? null),
                'color' => $this->color($input['color'] ?? null),
                'rules' => (string) json_encode($rules, JSON_UNESCAPED_SLASHES),
                'position' => $this->nextPosition($identity),
            ],
        );

        if (array_key_exists('position', $input)) {
            $this->reposition($identity, $folderId, $this->position($input['position']));
        }

        return $this->present($identity, $this->requireFolder($identity, $folderId));
    }

    /**
     * Rename, restyle, re-rule, reorder.
     *
     * Every field is optional and independent: the colour picker sends only a
     * colour, and a drag sends only a position. Sending `icon: null` is how an
     * icon is cleared — omitting it cannot be, or every rename would strip it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $folderId, array $input): array
    {
        $this->requireFolder($identity, $folderId);

        $updates = [];
        $bindings = ['id' => $folderId, 'owner' => $identity->userId];

        if (array_key_exists('name', $input)) {
            $updates[] = 'name = :name';
            $bindings['name'] = $this->name($input['name']);
        }
        if (array_key_exists('icon', $input)) {
            $updates[] = 'icon = :icon';
            $bindings['icon'] = $this->icon($input['icon']);
        }
        if (array_key_exists('color', $input)) {
            $updates[] = 'color = :color';
            $bindings['color'] = $this->color($input['color']);
        }
        if (array_key_exists('rules', $input)) {
            $updates[] = 'rules = :rules::jsonb';
            $bindings['rules'] = (string) json_encode($this->validRules($input['rules']), JSON_UNESCAPED_SLASHES);
        }

        if ($updates !== []) {
            $updates[] = 'updated_at = now()';
            Connection::execute(
                'UPDATE smart_folders SET ' . implode(', ', $updates) . '
                 WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL',
                $bindings,
            );
        }

        if (array_key_exists('position', $input)) {
            $this->reposition($identity, $folderId, $this->position($input['position']));
        }

        return $this->present($identity, $this->requireFolder($identity, $folderId));
    }

    /**
     * Remove a folder.
     *
     * Soft, because a folder is a query someone assembled by hand and holds no
     * note of its own: nothing is protected by destroying the row, and keeping
     * it makes an accidental delete a one-line UPDATE instead of a rule tree
     * the user has to rebuild from memory.
     */
    public function delete(Identity $identity, string $folderId): void
    {
        $this->requireFolder($identity, $folderId);

        Connection::execute(
            'UPDATE smart_folders SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL',
            ['id' => $folderId, 'owner' => $identity->userId],
        );
    }

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    /**
     * Normalise a rule tree and prove it compiles.
     *
     * The proof is the point: {@see NoteQuery::fromRules()} is asked to build
     * the query and its refusal is passed on as a 422 against the `rules`
     * field. An unknown field or operator is therefore rejected while the user
     * is still looking at the rule editor, and a folder that cannot be executed
     * can never reach the table.
     *
     * @return array{match: string, conditions: array<int, array<string, mixed>>}
     */
    private function validRules(mixed $value): array
    {
        if (!is_array($value)) {
            throw ApiException::validation(['rules' => 'Rules must be an object.']);
        }

        $conditions = $value['conditions'] ?? [];
        if (!is_array($conditions)) {
            throw ApiException::validation(['rules' => '`conditions` must be a list of rules.']);
        }

        $normalised = [];
        foreach (array_values($conditions) as $index => $condition) {
            if (!is_array($condition)) {
                // The builder skips a malformed condition silently, which is
                // right for a query string and wrong for something stored: a
                // folder that quietly drops a rule the user typed looks broken
                // later, when it matches more than they asked for.
                throw ApiException::validation(['rules' => sprintf('Rule %d is not a rule.', $index + 1)]);
            }

            $normalised[] = [
                'field' => (string) (is_scalar($condition['field'] ?? null) ? $condition['field'] : ''),
                'operator' => (string) (is_scalar($condition['operator'] ?? null) ? $condition['operator'] : 'is'),
                'value' => $this->ruleValue($condition['value'] ?? null, $index),
            ];
        }

        $rules = [
            'match' => strtolower((string) (is_scalar($value['match'] ?? null) ? $value['match'] : 'all')),
            'conditions' => $normalised,
        ];

        try {
            NoteQuery::fromRules($rules);
        } catch (ApiException $e) {
            // The builder answers 400 because it is normally reached from a
            // query string. Stored on a folder, the same mistake is a bad value
            // in a submitted field, and the client needs it under `rules` to
            // show the message beside the rule that is wrong.
            throw ApiException::validation(['rules' => $e->getMessage()]);
        }

        return $rules;
    }

    /** A rule's value: a scalar, or the flat object `entity` needs. */
    private function ruleValue(mixed $value, int $index): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_scalar($item) && $item !== null) {
                    throw ApiException::validation([
                        'rules' => sprintf('Rule %d has a value that is not a value.', $index + 1),
                    ]);
                }
            }

            return $value;
        }

        throw ApiException::validation(['rules' => sprintf('Rule %d has a value that is not a value.', $index + 1)]);
    }

    /**
     * @param array{match: string, conditions: array<int, array<string, mixed>>} $rules
     */
    private function compile(array $rules): NoteQuery
    {
        try {
            return NoteQuery::fromRules($rules);
        } catch (ApiException $e) {
            // Rules are validated on the way in, so reaching this means a field
            // this release no longer supports is sitting in a folder saved by
            // an older one. Saying so is better than a 500, and far better than
            // silently dropping the condition and showing more notes than the
            // folder was built to show.
            throw new ApiException(
                422,
                'SMART_FOLDER_RULES_INVALID',
                'This smart folder\'s rules are no longer valid: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Which slice of the library the rules are asked about.
     *
     * An explicit `?scope=` wins. Otherwise a folder whose rules talk about
     * `is_archived` gets the whole library, because the default view hides
     * archived notes and an "Archived invoices" folder that always came back
     * empty would read as a bug in the folder rather than in the default.
     *
     * @param array{match: string, conditions: array<int, array<string, mixed>>} $rules
     */
    private function scope(array $rules, string $requested): string
    {
        if (in_array($requested, self::SCOPES, true)) {
            return $requested;
        }

        foreach ($rules['conditions'] as $condition) {
            if (($condition['field'] ?? '') === 'is_archived') {
                return 'all';
            }
        }

        return 'active';
    }

    // -----------------------------------------------------------------------
    // Counting
    // -----------------------------------------------------------------------

    /**
     * How many notes a folder matches, counted only as far as it is worth.
     *
     * The sidebar renders this badge next to every folder on every page load.
     * An exact number means visiting every matching row, and a rule as ordinary
     * as "updated in the last year" matches most of a library — so the cost of
     * the badge grows with the number of notes a user has, which is precisely
     * the user whose sidebar must not get slower. The scan therefore stops at
     * {@see self::countCap()} and reports that it did, so the client renders
     * "500+" rather than paying for a digit nobody reads. The folder itself
     * still lists every match, page by page.
     *
     * @param array{match: string, conditions: array<int, array<string, mixed>>} $rules
     * @return array{count: int|null, capped: bool, valid: bool}
     */
    private function countFor(Identity $identity, array $rules): array
    {
        try {
            $query = $this->compile($rules);
        } catch (ApiException) {
            // One folder saved against an older rule vocabulary must not take
            // the whole sidebar down with it.
            return ['count' => null, 'capped' => false, 'valid' => false];
        }

        $scope = $this->scope($rules, '');
        $where = $scope === 'all'
            ? 'n.deleted_at IS NULL'
            : 'n.deleted_at IS NULL AND NOT n.is_archived';

        $cap = $this->countCap();

        $row = Connection::selectOne(
            'WITH RECURSIVE ' . NoteAccess::cte() . ',
             matches AS (
                 SELECT 1
                 FROM notes n
                 JOIN note_access a ON a.note_id = n.id
                 WHERE ' . $where . $query->sql() . '
                 LIMIT :count_cap
             )
             SELECT count(*) AS matches FROM matches',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'count_cap' => $cap,
            ] + $query->bindings(),
        );

        $count = (int) ($row['matches'] ?? 0);

        return ['count' => $count, 'capped' => $count >= $cap, 'valid' => true];
    }

    private function countCap(): int
    {
        return Features::int('NOTES_SMART_FOLDER_COUNT_CAP', self::DEFAULT_COUNT_CAP, 1, 10000);
    }

    // -----------------------------------------------------------------------
    // Loading and shaping
    // -----------------------------------------------------------------------

    /**
     * One folder the caller owns, or 404.
     *
     * The tenant clause is the same two-gate rule {@see NoteAccess} applies to
     * notes: a folder created while acting for a company stays inside it, and a
     * personal folder (`tenant_id IS NULL`) follows its owner into whichever
     * company they are acting in.
     *
     * @return array<string, mixed>
     */
    private function requireFolder(Identity $identity, string $folderId): array
    {
        $row = Connection::selectOne(
            'SELECT id, name, icon, color, rules, position, created_at, updated_at
             FROM smart_folders
             WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL
               AND (tenant_id IS NULL OR tenant_id = :tenant)',
            ['id' => $folderId, 'owner' => $identity->userId, 'tenant' => $identity->tenantId],
        );

        if ($row === null) {
            // 404 rather than 403, for the same reason a note uses 404: an id
            // must not confirm that a folder exists.
            throw ApiException::notFound('That smart folder');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{match: string, conditions: array<int, array<string, mixed>>}
     */
    private function rulesOf(array $row): array
    {
        $rules = $row['rules'] ?? null;
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            $rules = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rules)) {
            return self::EMPTY_RULES;
        }

        $conditions = $rules['conditions'] ?? [];

        return [
            'match' => strtolower((string) ($rules['match'] ?? 'all')),
            'conditions' => is_array($conditions) ? array_values($conditions) : [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(Identity $identity, array $row): array
    {
        $rules = $this->rulesOf($row);
        $count = $this->countFor($identity, $rules);

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'icon' => $row['icon'] === null ? null : (string) $row['icon'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'position' => (int) $row['position'],
            'rules' => $rules,
            // Null when the stored rules no longer compile; `rules_valid` is
            // what lets the UI say so instead of rendering a blank badge.
            'note_count' => $count['count'],
            'note_count_is_capped' => $count['capped'],
            'rules_valid' => $count['valid'],
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
        ];
    }

    // -----------------------------------------------------------------------
    // Ordering
    // -----------------------------------------------------------------------

    private function nextPosition(Identity $identity): int
    {
        $row = Connection::selectOne(
            'SELECT coalesce(max(position), -1) + 1 AS next FROM smart_folders
             WHERE owner_user_id = :owner AND deleted_at IS NULL',
            ['owner' => $identity->userId],
        );

        return min(self::MAX_POSITION, (int) ($row['next'] ?? 0));
    }

    /**
     * Drop a folder at an index in the list and renumber the rest.
     *
     * A drag means "third from the top", not "position 7", so the whole list is
     * re-sequenced 0..n-1 around it. Storing the raw number instead leaves two
     * folders sharing a position and the sidebar flipping between two orders
     * from one reload to the next.
     */
    private function reposition(Identity $identity, string $folderId, int $index): void
    {
        $rows = Connection::select(
            'SELECT id FROM smart_folders
             WHERE owner_user_id = :owner AND deleted_at IS NULL AND id <> :id::uuid
             ORDER BY position, lower(name), id',
            ['owner' => $identity->userId, 'id' => $folderId],
        );

        $ordered = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        array_splice($ordered, max(0, min($index, count($ordered))), 0, [$folderId]);

        Connection::transaction(static function () use ($ordered): void {
            foreach ($ordered as $position => $id) {
                Connection::execute(
                    'UPDATE smart_folders SET position = :position, updated_at = now()
                     WHERE id = :id::uuid AND position <> :position',
                    ['id' => $id, 'position' => $position],
                );
            }
        });
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    private function requireRoom(Identity $identity): void
    {
        $row = Connection::selectOne(
            'SELECT count(*) AS total FROM smart_folders
             WHERE owner_user_id = :owner AND deleted_at IS NULL',
            ['owner' => $identity->userId],
        );

        if ((int) ($row['total'] ?? 0) >= self::MAX_FOLDERS) {
            throw new ApiException(
                409,
                'SMART_FOLDER_LIMIT',
                sprintf('You can keep up to %d smart folders. Delete one to make room.', self::MAX_FOLDERS),
            );
        }
    }

    private function name(mixed $value): string
    {
        $name = is_scalar($value) ? trim((string) $value) : '';
        if ($name === '') {
            throw ApiException::validation(['name' => 'A smart folder needs a name.']);
        }

        return Str::limit($name, self::MAX_NAME);
    }

    private function icon(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $icon = trim((string) $value);

        return $icon === '' ? null : Str::limit($icon, self::MAX_ICON);
    }

    /**
     * A palette token, not a colour.
     *
     * The client resolves the token against its own theme, so light and dark
     * mode stay coherent — and a stored `#fff; background:url(…)` can never
     * reach a style attribute.
     */
    private function color(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'default') {
            return null;
        }
        $color = strtolower(trim((string) (is_scalar($value) ? $value : '')));
        if (preg_match('/^[a-z][a-z0-9-]{0,29}$/', $color) !== 1) {
            throw ApiException::validation(['color' => 'Unknown smart folder colour.']);
        }

        return $color;
    }

    private function position(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw ApiException::validation(['position' => 'Position must be a number.']);
        }

        return max(0, min(self::MAX_POSITION, (int) $value));
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            return null;
        }
    }
}
