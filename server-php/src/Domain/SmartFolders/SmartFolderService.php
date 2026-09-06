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

        // One count query for the whole page, not one per folder: see
        // {@see self::countsFor()}.
        $counts = $this->countsFor($identity, $rows);

        return array_map(
            fn (array $row): array => $this->present($identity, $row, $counts[(string) $row['id']] ?? null),
            $rows,
        );
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

        // Every field is validated before anything is written. Validating
        // `position` after the INSERT — where it used to live — answered 422
        // and left the folder behind, so the client showed an error beside a
        // folder that had in fact been created, and the row still counted
        // against the ceiling.
        $name = $this->name($input['name'] ?? null);
        $rules = $this->validRules($input['rules'] ?? self::EMPTY_RULES);
        $icon = $this->icon($input['icon'] ?? null);
        $color = $this->color($input['color'] ?? null);
        $index = array_key_exists('position', $input) ? $this->position($input['position']) : null;

        Connection::transaction(function () use ($identity, $folderId, $name, $rules, $icon, $color, $index): void {
            Connection::execute(
                'INSERT INTO smart_folders (id, tenant_id, owner_user_id, name, icon, color, rules, position)
                 VALUES (:id, :tenant_id, :owner, :name, :icon, :color, :rules::jsonb, :position)',
                [
                    'id' => $folderId,
                    'tenant_id' => $identity->tenantId,
                    'owner' => $identity->userId,
                    'name' => $name,
                    'icon' => $icon,
                    'color' => $color,
                    'rules' => (string) json_encode($rules, JSON_UNESCAPED_SLASHES),
                    'position' => $this->nextPosition($identity),
                ],
            );

            if ($index !== null) {
                $this->reposition($identity, $folderId, $index);
            }
        });

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

        // Every field is validated before any of them is written, and the write
        // is one transaction. An edit is refused whole or applied whole: a
        // `position` that failed validation used to answer 422 *after* the
        // rename had already been committed, which is the same half-applied
        // edit the rules path is careful to avoid.
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

        $index = array_key_exists('position', $input) ? $this->position($input['position']) : null;

        if ($updates !== [] || $index !== null) {
            Connection::transaction(function () use ($identity, $folderId, $updates, $bindings, $index): void {
                if ($updates !== []) {
                    $updates[] = 'updated_at = now()';
                    Connection::execute(
                        'UPDATE smart_folders SET ' . implode(', ', $updates) . '
                         WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL',
                        $bindings,
                    );
                }

                if ($index !== null) {
                    $this->reposition($identity, $folderId, $index);
                }
            });
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

        // A rule tree is an object with `match` and `conditions`. Anything else
        // shaped like an array — a JSON list, a half-serialised form — is not a
        // tree that happens to be empty, and reading it as one stores a folder
        // that quietly matches the entire library under a name the user chose
        // to mean something much narrower. `[]` is exempt: PHP cannot tell it
        // from `{}`, and an empty object is a legitimate "no rules".
        if ($value !== [] && !array_key_exists('match', $value) && !array_key_exists('conditions', $value)) {
            throw ApiException::validation(['rules' => 'Rules need a `match` and a list of `conditions`.']);
        }

        $match = $value['match'] ?? 'all';
        if (!is_scalar($match)) {
            // Refused rather than folded to the default, for the same reason
            // `match: "sometimes"` is refused: the folder would go on to match
            // on a rule the user did not choose.
            throw ApiException::validation(['rules' => '`match` must be "all" or "any".']);
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
            'match' => strtolower((string) $match),
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
     * A rule about `is_archived` is the folder's own answer to the question
     * `scope` asks, so it wins outright: the query is widened to the whole
     * library and the rule does the filtering. Without that, an "Archived
     * invoices" folder is permanently empty — the default list hides archived
     * notes, and a client browsing a folder has no reason to ask for them.
     *
     * Otherwise `?scope=` narrows as it does on `GET /notes`. Trash is not on
     * the list: a folder is a view over the library, and Trash is where things
     * go to stop being in it.
     *
     * @param array{match: string, conditions: array<int, array<string, mixed>>} $rules
     */
    private function scope(array $rules, string $requested): string
    {
        foreach ($rules['conditions'] as $condition) {
            if (is_array($condition) && ($condition['field'] ?? '') === 'is_archived') {
                return 'all';
            }
        }

        return in_array($requested, self::SCOPES, true) ? $requested : 'active';
    }

    // -----------------------------------------------------------------------
    // Counting
    // -----------------------------------------------------------------------

    /**
     * How many notes each folder matches, counted only as far as it is worth.
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
     * The whole page is counted in **one** statement. The per-folder half of
     * the work is the filter; the expensive half is {@see NoteAccess::cte()},
     * which walks every notebook grant the caller holds — and running that once
     * per folder made the sidebar cost grow with the number of folders *on top
     * of* the size of the library. Here the access CTE is evaluated once and
     * each folder is counted against it.
     *
     * Access is still decided by that CTE and nothing else, exactly as it is
     * for a single folder: batching changes how many times the gate is
     * evaluated, never who passes it.
     *
     * @param array<int, array<string, mixed>> $rows Folder rows, as stored.
     * @return array<string, array{count: int|null, capped: bool, valid: bool}> Keyed by folder id.
     */
    private function countsFor(Identity $identity, array $rows): array
    {
        $cap = $this->countCap();
        $counts = [];
        $arms = [];
        $bindings = [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'count_cap' => $cap,
        ];

        foreach (array_values($rows) as $index => $row) {
            $id = (string) $row['id'];
            $rules = $this->rulesOf($row);

            try {
                $query = $this->compile($rules);
            } catch (ApiException) {
                // One folder saved against an older rule vocabulary must not
                // take the whole sidebar down with it: it drops out of the
                // batch and reports that it cannot be run.
                $counts[$id] = ['count' => null, 'capped' => false, 'valid' => false];
                continue;
            }

            $where = $this->scope($rules, '') === 'all'
                ? 'n.deleted_at IS NULL'
                : 'n.deleted_at IS NULL AND NOT n.is_archived';

            // Each folder's filter numbers its placeholders from `:f0`, so the
            // arms would collide. Only the *names* are rewritten here — every
            // value still travels as a bound parameter, and the filter SQL is
            // still the builder's, never a string assembled from user input.
            $prefix = 'q' . $index . '_';
            $filter = preg_replace('/:f(\d+)/', ':' . $prefix . 'f$1', $query->sql());
            if ($filter === null) {
                // Unreachable in practice — the subject is this builder's own
                // output. If it ever were, the folder reports that it could not
                // be counted rather than counting *without* its filter, which
                // would put a confidently wrong number in the sidebar.
                $counts[$id] = ['count' => null, 'capped' => false, 'valid' => false];
                continue;
            }
            foreach ($query->bindings() as $name => $value) {
                $bindings[$prefix . $name] = $value;
            }
            $bindings[$prefix . 'folder'] = $id;

            $arms[] = 'SELECT :' . $prefix . 'folder::text AS folder_id,
                 (SELECT count(*) FROM (
                      SELECT 1
                      FROM notes n
                      JOIN note_access a ON a.note_id = n.id
                      WHERE ' . $where . $filter . '
                      LIMIT :count_cap
                  ) matches) AS matches';
        }

        if ($arms === []) {
            return $counts;
        }

        $result = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . ' ' . implode(' UNION ALL ', $arms),
            $bindings,
        );

        foreach ($result as $counted) {
            $matched = (int) ($counted['matches'] ?? 0);
            $counts[(string) $counted['folder_id']] = [
                'count' => $matched,
                'capped' => $matched >= $cap,
                'valid' => true,
            ];
        }

        return $counts;
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

        // Written by this service, so it is well formed — but it is still a
        // jsonb column someone can edit by hand, and a read path is the wrong
        // place to discover that. Anything unexpected reads as "no rules".
        $conditions = $rules['conditions'] ?? [];
        $match = $rules['match'] ?? 'all';

        return [
            'match' => strtolower(is_scalar($match) ? (string) $match : 'all'),
            'conditions' => is_array($conditions) ? array_values($conditions) : [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{count: int|null, capped: bool, valid: bool}|null $count
     *        Already counted as part of a page, or null to count this one row.
     * @return array<string, mixed>
     */
    private function present(Identity $identity, array $row, ?array $count = null): array
    {
        $rules = $this->rulesOf($row);
        $count ??= $this->countsFor($identity, [$row])[(string) $row['id']]
            ?? ['count' => null, 'capped' => false, 'valid' => false];

        $folder = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'icon' => $row['icon'] === null ? null : (string) $row['icon'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'position' => (int) $row['position'],
            'rules' => $rules,
            'rules_valid' => $count['valid'],
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
        ];

        // When the rules no longer compile the badge is *absent*, not zero: a
        // zero would be a claim about the library, and what is actually known
        // is only that the folder cannot be run. `rules_valid` is what the UI
        // branches on to say so.
        if ($count['valid']) {
            $folder['note_count'] = $count['count'];
            $folder['note_count_is_capped'] = $count['capped'];
        }

        return $folder;
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
     *
     * The list re-sequenced here is **the list the caller is looking at** —
     * same owner, same tenant gate as {@see self::listForUser()}. Re-sequencing
     * every folder the owner has instead got both halves wrong in a multi-
     * company account: an index counted against the visible list was applied to
     * a longer hidden one, so a drag could land the folder back where it
     * started, and folders belonging to a company the caller was not acting in
     * had their positions rewritten by a drag they never saw.
     */
    private function reposition(Identity $identity, string $folderId, int $index): void
    {
        $rows = Connection::select(
            'SELECT id FROM smart_folders
             WHERE owner_user_id = :owner AND deleted_at IS NULL
               AND (tenant_id IS NULL OR tenant_id = :tenant)
               AND id <> :id::uuid
             ORDER BY position, lower(name), id',
            ['owner' => $identity->userId, 'tenant' => $identity->tenantId, 'id' => $folderId],
        );

        $ordered = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        array_splice($ordered, max(0, min($index, count($ordered))), 0, [$folderId]);

        Connection::transaction(static function () use ($identity, $ordered): void {
            foreach ($ordered as $position => $id) {
                Connection::execute(
                    'UPDATE smart_folders SET position = :position, updated_at = now()
                     WHERE id = :id::uuid AND owner_user_id = :owner AND position <> :position',
                    ['id' => $id, 'owner' => $identity->userId, 'position' => $position],
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
