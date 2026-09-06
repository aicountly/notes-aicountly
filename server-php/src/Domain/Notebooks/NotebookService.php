<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notebooks;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Collaboration\NoteRole;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * The notebook tree.
 *
 * A notebook is a place, not a label — that is what separates it from a tag —
 * so the rules here are mostly about keeping the *shape* of the tree something
 * a sidebar can render and a person can reason about:
 *
 *   - **The hierarchy stays a tree.** A notebook can never end up inside its
 *     own subtree. A cycle is not a cosmetic bug: every read path in this API
 *     walks `parent_id` recursively (access cascades, the notebook filter in
 *     {@see \Aicountly\Api\Domain\Notes\NoteQuery}, this class), so one cycle
 *     turns list endpoints into queries that never return. It is refused at
 *     the write, not repaired at the read.
 *   - **Nesting is capped** at {@see self::MAX_DEPTH} levels, because a tree
 *     deeper than that cannot be shown in a sidebar without horizontal
 *     scrolling, and nobody navigates it anyway.
 *   - **Deleting never destroys notes.** See {@see self::delete()}.
 *
 * Moving and deleting are owner-only for a reason worth stating: a notebook
 * share cascades to descendants ({@see NoteAccess::notebookCte()}), so moving
 * a notebook under a shared one hands its whole branch to that notebook's
 * members. That is a sharing decision, and sharing belongs to the owner.
 */
final class NotebookService
{
    /**
     * How deep the tree may go, counted in levels: a root notebook is level 1,
     * so the deepest stored `depth` is MAX_DEPTH - 1.
     */
    public const MAX_DEPTH = 8;

    private const MAX_NAME = 200;
    private const MAX_DESCRIPTION = 2000;
    private const MAX_ICON = 60;
    private const MAX_POSITION = 100000;

    // The recorder's `action` column is free-form and its own constants cover
    // notes; these are the notebook-scoped verbs the same trail carries.
    private const ACTIVITY_CREATED = 'notebook.created';
    private const ACTIVITY_UPDATED = 'notebook.updated';
    private const ACTIVITY_ARCHIVED = 'notebook.archived';
    private const ACTIVITY_UNARCHIVED = 'notebook.unarchived';
    private const ACTIVITY_MOVED = 'notebook.moved';
    private const ACTIVITY_DELETED = 'notebook.deleted';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * The caller's whole visible tree, in one query.
     *
     * One query rather than one per level, and the note counts come with it:
     * a sidebar renders on every page load, and counting per node would be an
     * N+1 that grows with the number of notebooks a user has — exactly the
     * thing that makes a tree feel slow.
     *
     * @return array<int, array<string, mixed>> Root nodes, each with `children`.
     */
    public function tree(Identity $identity, bool $includeArchived = false): array
    {
        $rows = Connection::select(self::treeSql(), [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
        ]);

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(string) $row['id']] = self::present($row);
        }

        return self::nest($nodes, $includeArchived);
    }

    /**
     * One notebook with its subtree.
     *
     * @return array<string, mixed>
     */
    public function get(Identity $identity, string $notebookId): array
    {
        // Ask the permission service first so the failure is the right one:
        // 404 for a notebook the caller cannot see, 403 for one they can see
        // but may not open. Searching the tree could only ever say "absent".
        $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::VIEW);

        $node = self::find($this->tree($identity, includeArchived: true), $notebookId);
        if ($node === null) {
            throw ApiException::notFound('That notebook');
        }

        return $node;
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, array $input): array
    {
        // The client may supply the id it already used locally, so a notebook
        // created offline keeps its identity when the queue drains.
        $notebookId = isset($input['id']) && Uuid::isValid($input['id'])
            ? strtolower((string) $input['id'])
            : Uuid::v4();

        if (Connection::selectOne('SELECT id FROM notebooks WHERE id = :id', ['id' => $notebookId]) !== null) {
            // A replayed create must not produce a second notebook.
            return $this->get($identity, $notebookId);
        }

        $name = $this->name($input['name'] ?? null);
        $parent = $this->resolveParent($identity, $input['parent_id'] ?? null);
        $depth = $parent === null ? 0 : (int) $parent['depth'] + 1;

        if ($depth > self::MAX_DEPTH - 1) {
            throw $this->tooDeep();
        }

        $parentId = $parent === null ? null : (string) $parent['id'];
        $this->requireNameFree($identity->userId, $parentId, $name, null);

        $this->guardNameCollision(fn (): int => Connection::execute(
            'INSERT INTO notebooks
                (id, tenant_id, owner_user_id, parent_id, name, description, icon, color,
                 position, depth, created_by, updated_by)
             VALUES
                (:id, :tenant_id, :owner, :parent_id, :name, :description, :icon, :color,
                 :position, :depth, :actor, :actor)',
            [
                'id' => $notebookId,
                'tenant_id' => $identity->tenantId,
                'owner' => $identity->userId,
                'parent_id' => $parentId,
                'name' => $name,
                'description' => $this->description($input['description'] ?? null),
                'icon' => $this->icon($input['icon'] ?? null),
                'color' => $this->color($input['color'] ?? null),
                'position' => $this->nextPosition($identity->userId, $parentId),
                'depth' => $depth,
                'actor' => $identity->userId,
            ],
        ));

        if (array_key_exists('position', $input)) {
            $this->reposition($identity, $notebookId, $identity->userId, $parentId, $this->position($input['position']));
        }

        $this->activity->record($identity, self::ACTIVITY_CREATED, null, $notebookId, [
            'parent_id' => $parentId,
            'depth' => $depth,
        ]);

        return $this->get($identity, $notebookId);
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    /**
     * Rename, restyle, reorder, archive.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $notebookId, array $input): array
    {
        $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::EDIT);

        if (array_key_exists('parent_id', $input)) {
            // Re-parenting through PATCH is the same act as POST /move, so it
            // takes the same path — owner-only check, cycle check, depth cap —
            // rather than becoming a field anyone with edit rights can set.
            // It also runs first: a move that is refused must not leave a
            // half-applied rename behind it.
            $this->move($identity, $notebookId, [
                'parent_id' => $input['parent_id'],
                'position' => $input['position'] ?? null,
            ]);
            unset($input['position']);
            $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::EDIT);
        }

        $updates = [];
        $bindings = ['id' => $notebookId, 'actor' => $identity->userId];

        if (array_key_exists('name', $input)) {
            $name = $this->name($input['name']);
            if (mb_strtolower($name, 'UTF-8') !== mb_strtolower((string) $notebook['name'], 'UTF-8')) {
                $this->requireNameFree(
                    (string) $notebook['owner_user_id'],
                    self::nullableId($notebook['parent_id'] ?? null),
                    $name,
                    $notebookId,
                );
            }
            $updates[] = 'name = :name';
            $bindings['name'] = $name;
        }

        if (array_key_exists('description', $input)) {
            $updates[] = 'description = :description';
            $bindings['description'] = $this->description($input['description']);
        }
        if (array_key_exists('icon', $input)) {
            $updates[] = 'icon = :icon';
            $bindings['icon'] = $this->icon($input['icon']);
        }
        if (array_key_exists('color', $input)) {
            $updates[] = 'color = :color';
            $bindings['color'] = $this->color($input['color']);
        }

        $archiveChange = null;
        if (array_key_exists('is_archived', $input)) {
            // Archiving hides the whole branch from everyone it is shared with,
            // so it is the owner's call, not an editor's.
            $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::MANAGE);
            $archived = (bool) $input['is_archived'];
            $archiveChange = $archived === (bool) $notebook['is_archived'] ? null : $archived;
            $updates[] = 'is_archived = :is_archived';
            $bindings['is_archived'] = $archived;
        }

        if ($updates !== []) {
            $updates[] = 'updated_by = :actor';
            $updates[] = 'updated_at = now()';
            $this->guardNameCollision(fn (): int => Connection::execute(
                'UPDATE notebooks SET ' . implode(', ', $updates) . ' WHERE id = :id AND deleted_at IS NULL',
                $bindings,
            ));
        }

        if (array_key_exists('position', $input)) {
            $this->reposition(
                $identity,
                $notebookId,
                (string) $notebook['owner_user_id'],
                self::nullableId($notebook['parent_id'] ?? null),
                $this->position($input['position']),
            );
        }

        if ($archiveChange !== null) {
            $this->activity->record(
                $identity,
                $archiveChange ? self::ACTIVITY_ARCHIVED : self::ACTIVITY_UNARCHIVED,
                null,
                $notebookId,
            );
        } elseif ($updates !== []) {
            // Ids and field names only — a notebook name is a label the whole
            // share can read, but the trail has no reason to carry content.
            $this->activity->record($identity, self::ACTIVITY_UPDATED, null, $notebookId, [
                'fields' => array_values(array_intersect(
                    ['name', 'description', 'icon', 'color'],
                    array_keys($input),
                )),
            ]);
        }

        return $this->get($identity, $notebookId);
    }

    // -----------------------------------------------------------------------
    // Move
    // -----------------------------------------------------------------------

    /**
     * Re-parent a notebook, with its whole subtree.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function move(Identity $identity, string $notebookId, array $input): array
    {
        $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::MANAGE);
        $owner = (string) $notebook['owner_user_id'];

        if (!array_key_exists('parent_id', $input)) {
            // Defaulting a missing key to null would quietly move the notebook
            // to the top level, which is the one outcome nobody asks for by
            // omission. Reordering within a parent is PATCH `position`.
            throw ApiException::validation([
                'parent_id' => 'Send the new parent notebook id, or null to move it to the top level.',
            ]);
        }

        $parent = $this->resolveParent($identity, $input['parent_id'], $notebookId);
        $parentId = $parent === null ? null : (string) $parent['id'];
        $newDepth = $parent === null ? 0 : (int) $parent['depth'] + 1;

        // One query answers both questions the subtree decides: does it contain
        // the proposed parent (a cycle), and how tall is it (the depth cap)?
        $subtree = Connection::selectOne(
            'WITH RECURSIVE subtree AS (
                 SELECT id, 0 AS level FROM notebooks WHERE id = :id::uuid AND deleted_at IS NULL
               UNION ALL
                 SELECT c.id, s.level + 1
                 FROM notebooks c JOIN subtree s ON c.parent_id = s.id
                 WHERE c.deleted_at IS NULL
             )
             SELECT coalesce(max(level), 0) AS height,
                    count(*) FILTER (WHERE id = :target::uuid) AS target_hits
             FROM subtree',
            ['id' => $notebookId, 'target' => $parentId],
        );

        if ((int) ($subtree['target_hits'] ?? 0) > 0) {
            throw $this->cycle();
        }
        if ($newDepth + (int) ($subtree['height'] ?? 0) > self::MAX_DEPTH - 1) {
            throw $this->tooDeep();
        }

        $currentParentId = self::nullableId($notebook['parent_id'] ?? null);
        if ($parentId !== $currentParentId) {
            $this->requireNameFree($owner, $parentId, (string) $notebook['name'], $notebookId);
        }

        $this->guardNameCollision(function () use ($identity, $notebookId, $owner, $parentId, $newDepth): void {
            $position = $this->nextPosition($owner, $parentId);

            Connection::transaction(static function () use ($identity, $notebookId, $parentId, $newDepth, $position): void {
                Connection::execute(
                    'UPDATE notebooks
                     SET parent_id = :parent::uuid, depth = :depth, position = :position,
                         updated_by = :actor, updated_at = now()
                     WHERE id = :id::uuid AND deleted_at IS NULL',
                    [
                        'id' => $notebookId,
                        'parent' => $parentId,
                        'depth' => $newDepth,
                        'position' => $position,
                        'actor' => $identity->userId,
                    ],
                );

                // The subtree came along, so its stored depths are now wrong.
                // `depth` is what the cap is enforced against, so leaving it
                // stale would let the next move sneak past the limit.
                Connection::execute(
                    'WITH RECURSIVE subtree AS (
                         SELECT id, :depth::int AS depth FROM notebooks WHERE id = :id::uuid
                       UNION ALL
                         SELECT c.id, s.depth + 1
                         FROM notebooks c JOIN subtree s ON c.parent_id = s.id
                         WHERE c.deleted_at IS NULL
                     )
                     UPDATE notebooks nb SET depth = s.depth, updated_at = now()
                     FROM subtree s
                     WHERE nb.id = s.id AND nb.depth <> s.depth',
                    ['id' => $notebookId, 'depth' => $newDepth],
                );
            });
        });

        if (($input['position'] ?? null) !== null) {
            $this->reposition($identity, $notebookId, $owner, $parentId, $this->position($input['position']));
        }

        $this->activity->record($identity, self::ACTIVITY_MOVED, null, $notebookId, [
            'from_parent_id' => $currentParentId,
            'to_parent_id' => $parentId,
            'depth' => $newDepth,
        ]);

        return $this->get($identity, $notebookId);
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    /**
     * Soft-delete a notebook and rehome what was inside it.
     *
     * The decision, stated outright: **a notebook is a container, so deleting
     * it deletes the container and nothing else.** Its notes move up to the
     * parent notebook — or out to no notebook at all when it was a root — and
     * its sub-notebooks move by the same rule. Cascading the delete to the
     * notes would mean one click on a folder silently trashing hundreds of
     * notes nobody selected, and on a shared notebook, other people's notes.
     *
     * The response says what moved and where, so the UI can tell the user
     * instead of leaving them to hunt for their notes.
     *
     * One consequence is deliberate rather than hidden: access granted
     * *through* this notebook ends with it. Its members lose the notes it was
     * lending them unless the destination notebook shares them too.
     *
     * @return array<string, mixed>
     */
    public function delete(Identity $identity, string $notebookId): array
    {
        $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::MANAGE);
        $parentId = self::nullableId($notebook['parent_id'] ?? null);

        $children = Connection::select(
            'SELECT id, name, owner_user_id FROM notebooks
             WHERE parent_id = :id::uuid AND deleted_at IS NULL
             ORDER BY position, lower(name), id',
            ['id' => $notebookId],
        );

        $notesMoved = Connection::transaction(function () use ($identity, $notebookId, $parentId, $children): int {
            // Depth first, while the children are still attached: everything
            // under this notebook comes up exactly one level.
            Connection::execute(
                'WITH RECURSIVE subtree AS (
                     SELECT id FROM notebooks WHERE parent_id = :id::uuid AND deleted_at IS NULL
                   UNION ALL
                     SELECT c.id FROM notebooks c JOIN subtree s ON c.parent_id = s.id
                     WHERE c.deleted_at IS NULL
                 )
                 UPDATE notebooks nb SET depth = greatest(nb.depth - 1, 0), updated_at = now()
                 FROM subtree s WHERE nb.id = s.id',
                ['id' => $notebookId],
            );

            foreach ($children as $child) {
                // A notebook at the destination may already own this name. The
                // delete must not fail on that, and merging two notebooks by
                // accident would be worse, so the moved one is suffixed.
                $name = $this->freeNameUnder(
                    (string) $child['owner_user_id'],
                    $parentId,
                    (string) $child['name'],
                    (string) $child['id'],
                );

                Connection::execute(
                    'UPDATE notebooks
                     SET parent_id = :parent::uuid, name = :name, updated_by = :actor, updated_at = now()
                     WHERE id = :id::uuid',
                    [
                        'id' => (string) $child['id'],
                        'parent' => $parentId,
                        'name' => $name,
                        'actor' => $identity->userId,
                    ],
                );
            }

            // Trashed notes move too: restoring one later must not put it back
            // into a notebook that no longer exists.
            //
            // `updated_at` is deliberately left alone. Nothing in these notes
            // changed, and touching it would push every one of them to the top
            // of "recently updated" — deleting a folder of 200 notes would
            // rewrite the user's most useful list.
            $moved = Connection::execute(
                'UPDATE notes SET notebook_id = :parent::uuid WHERE notebook_id = :id::uuid',
                ['id' => $notebookId, 'parent' => $parentId],
            );

            Connection::execute(
                'UPDATE notebooks SET deleted_at = now(), updated_by = :actor, updated_at = now()
                 WHERE id = :id::uuid AND deleted_at IS NULL',
                ['id' => $notebookId, 'actor' => $identity->userId],
            );

            return $moved;
        });

        $this->activity->record($identity, self::ACTIVITY_DELETED, null, null, [
            'notebook_id' => $notebookId,
            'moved_to_notebook_id' => $parentId,
            'notes_moved' => $notesMoved,
            'notebooks_moved' => count($children),
        ]);

        return [
            'id' => $notebookId,
            'deleted' => true,
            'moved_to_notebook_id' => $parentId,
            'notes_moved' => $notesMoved,
            'notebooks_moved' => count($children),
            'message' => sprintf(
                $parentId === null
                    ? '%d note(s) and %d sub-notebook(s) were moved out of this notebook and are now unfiled.'
                    : '%d note(s) and %d sub-notebook(s) were moved to the parent notebook.',
                $notesMoved,
                count($children),
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Members
    // -----------------------------------------------------------------------

    /**
     * Who this notebook is shared with, owner included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(Identity $identity, string $notebookId): array
    {
        $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::VIEW);

        $rows = Connection::select(
            'SELECT user_id, role, invited_by, created_at, updated_at
             FROM notebook_members WHERE notebook_id = :id::uuid
             ORDER BY created_at, user_id',
            ['id' => $notebookId],
        );

        // The owner is not a row in notebook_members — ownership comes from the
        // notebook itself — but a sharing dialog that omitted them would read
        // as "shared with nobody" on a notebook you own.
        $members = [[
            'user_id' => (string) $notebook['owner_user_id'],
            'role' => NoteRole::OWNER,
            'invited_by' => null,
            'created_at' => self::timestamp($notebook['created_at'] ?? null),
            'updated_at' => self::timestamp($notebook['updated_at'] ?? null),
        ]];

        foreach ($rows as $row) {
            $members[] = [
                'user_id' => (string) $row['user_id'],
                'role' => (string) $row['role'],
                'invited_by' => (string) $row['invited_by'],
                'created_at' => self::timestamp($row['created_at'] ?? null),
                'updated_at' => self::timestamp($row['updated_at'] ?? null),
            ];
        }

        return $members;
    }

    /**
     * Grant or change someone's access.
     *
     * @param array<string, mixed> $input
     * @return array{created: bool, member: array<string, mixed>}
     */
    public function addMember(Identity $identity, string $notebookId, array $input): array
    {
        $notebook = $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::MANAGE);

        $userId = $this->memberUserId($input['user_id'] ?? null);
        $role = $input['role'] ?? NoteRole::VIEWER;

        // `owner` is absent from GRANTABLE on purpose: sharing widens who can
        // read, it never hands the notebook over.
        if (!NoteRole::isGrantable($role)) {
            throw ApiException::validation([
                'role' => 'Choose one of: ' . implode(', ', NoteRole::GRANTABLE) . '.',
            ]);
        }
        if (strcasecmp($userId, (string) $notebook['owner_user_id']) === 0) {
            throw ApiException::badRequest('The owner already has full access to this notebook.');
        }

        // Matched exactly, the way the unique constraint the upsert below
        // targets matches: anything looser would report a role change and then
        // insert a second grant.
        $existing = Connection::selectOne(
            'SELECT role FROM notebook_members WHERE notebook_id = :id::uuid AND user_id = :user',
            ['id' => $notebookId, 'user' => $userId],
        );

        Connection::execute(
            'INSERT INTO notebook_members (id, notebook_id, user_id, role, invited_by)
             VALUES (:row_id, :id::uuid, :user, :role, :actor)
             ON CONFLICT (notebook_id, user_id)
             DO UPDATE SET role = excluded.role, updated_at = now()',
            [
                'row_id' => Uuid::v4(),
                'id' => $notebookId,
                'user' => $userId,
                'role' => (string) $role,
                'actor' => $identity->userId,
            ],
        );

        $this->activity->record(
            $identity,
            $existing === null ? ActivityRecorder::MEMBER_ADDED : ActivityRecorder::MEMBER_ROLE_CHANGED,
            null,
            $notebookId,
            [
                'member_user_id' => $userId,
                'role' => (string) $role,
                'previous_role' => $existing === null ? null : (string) $existing['role'],
            ],
        );

        return [
            'created' => $existing === null,
            'member' => [
                'user_id' => $userId,
                'role' => (string) $role,
                'invited_by' => $identity->userId,
            ],
        ];
    }

    public function removeMember(Identity $identity, string $notebookId, string $userId): void
    {
        $this->permissions->requireNotebook($identity, $notebookId, NotePermissionService::MANAGE);
        $userId = $this->memberUserId($userId);

        // Matched case-insensitively because the front controller lower-cases
        // the whole request path, so a mixed-case portal id never survives the
        // trip through the URL.
        $removed = Connection::execute(
            'DELETE FROM notebook_members WHERE notebook_id = :id::uuid AND lower(user_id) = lower(:user)',
            ['id' => $notebookId, 'user' => $userId],
        );

        if ($removed === 0) {
            throw ApiException::notFound('That member');
        }

        $this->activity->record($identity, ActivityRecorder::MEMBER_REMOVED, null, $notebookId, [
            'member_user_id' => $userId,
        ]);
    }

    // -----------------------------------------------------------------------
    // The tree query
    // -----------------------------------------------------------------------

    /**
     * Every notebook the caller may see, with the number of notes *they* may
     * see in it.
     *
     * The count composes {@see NoteAccess::cte()} rather than counting
     * `notes.notebook_id` directly: on a shared notebook the two differ, and
     * the difference is a number telling the caller how much someone else
     * filed in there. Both access CTEs define `shared_notebooks`, so the note
     * gate is nested in a sub-select where its own definition shadows the
     * notebook one instead of colliding with it.
     */
    private static function treeSql(): string
    {
        return 'WITH RECURSIVE ' . NoteAccess::notebookCte() . ',
            visible_notes AS (
                SELECT note_id FROM (
                    WITH RECURSIVE ' . NoteAccess::cte() . '
                    SELECT note_id FROM note_access
                ) granted
            ),
            note_counts AS (
                SELECT n.notebook_id, count(*) AS note_count
                FROM notes n
                JOIN visible_notes v ON v.note_id = n.id
                WHERE n.notebook_id IS NOT NULL
                  AND n.deleted_at IS NULL
                  AND NOT n.is_archived
                GROUP BY n.notebook_id
            )
            SELECT nb.id, nb.parent_id, nb.name, nb.description, nb.icon, nb.color,
                   nb.position, nb.depth, nb.is_archived, nb.owner_user_id,
                   nb.created_at, nb.updated_at,
                   a.role_rank,
                   coalesce(c.note_count, 0) AS note_count
            FROM notebooks nb
            JOIN notebook_access a ON a.notebook_id = nb.id
            LEFT JOIN note_counts c ON c.notebook_id = nb.id
            WHERE nb.deleted_at IS NULL
            ORDER BY nb.position, lower(nb.name), nb.id';
    }

    // -----------------------------------------------------------------------
    // Tree assembly
    // -----------------------------------------------------------------------

    /**
     * Flat rows → nested tree. Two cases decide the shape, and both come from
     * sharing:
     *
     *   - A node whose parent is not in the visible set becomes a root. Sharing
     *     a sub-notebook grants its descendants, never its ancestors, so the
     *     recipient's tree has to start somewhere.
     *   - Archiving hides a branch, not a node. Dropping only the archived
     *     notebook would re-root its children, and they would come back in the
     *     sidebar looking like brand new top-level notebooks.
     *
     * @param array<string, array<string, mixed>> $nodes Presented, keyed by id.
     * @return array<int, array<string, mixed>>
     */
    private static function nest(array $nodes, bool $includeArchived): array
    {
        $hidden = $includeArchived ? [] : self::archivedBranches($nodes);

        $childIds = [];
        $rootIds = [];
        foreach ($nodes as $id => $node) {
            if ($hidden[$id] ?? false) {
                continue;
            }
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $childIds[$parentId][] = $id;
            } else {
                $rootIds[] = $id;
            }
        }

        $build = static function (string $id) use (&$build, $nodes, $childIds): array {
            $node = $nodes[$id];
            $node['children'] = array_map($build, $childIds[$id] ?? []);
            $node['total_note_count'] = array_reduce(
                $node['children'],
                static fn (int $carry, array $child): int => $carry + $child['total_note_count'],
                $node['note_count'],
            );

            return $node;
        };

        return array_map($build, $rootIds);
    }

    /**
     * Ids sitting on or under an archived notebook.
     *
     * Memoised, and bounded by the number of nodes, so that a cycle which
     * somehow reached the table cannot hang a request — the writes above stop
     * one being created, this keeps the read survivable if one ever is.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, bool>
     */
    private static function archivedBranches(array $nodes): array
    {
        $hidden = [];
        $limit = count($nodes) + 1;

        foreach (array_keys($nodes) as $id) {
            $chain = [];
            $cursor = $id;
            $isHidden = false;

            for ($step = 0; $step < $limit && $cursor !== null && isset($nodes[$cursor]); $step++) {
                if (isset($hidden[$cursor])) {
                    $isHidden = $hidden[$cursor];
                    break;
                }
                $chain[] = $cursor;
                if ($nodes[$cursor]['is_archived']) {
                    $isHidden = true;
                    break;
                }
                $cursor = $nodes[$cursor]['parent_id'];
            }

            foreach ($chain as $node) {
                $hidden[$node] = $isHidden;
            }
        }

        return $hidden;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private static function find(array $nodes, string $notebookId): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $notebookId) {
                return $node;
            }
            $found = self::find($node['children'], $notebookId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $role = NoteAccess::roleFromRank((int) $row['role_rank']);

        return [
            'id' => (string) $row['id'],
            'parent_id' => self::nullableId($row['parent_id'] ?? null),
            'name' => (string) $row['name'],
            'description' => self::nullableString($row['description'] ?? null),
            'icon' => self::nullableString($row['icon'] ?? null),
            'color' => self::nullableString($row['color'] ?? null),
            'position' => (int) $row['position'],
            'depth' => (int) $row['depth'],
            'is_archived' => (bool) $row['is_archived'],
            'owner_user_id' => (string) $row['owner_user_id'],
            'role' => $role,
            // The same idea as NotePermissionService::capabilities(): the UI
            // disables what the caller cannot do instead of offering it and
            // failing on click.
            'capabilities' => [
                'view' => true,
                'edit' => NoteRole::atLeast($role, NoteRole::EDITOR),
                'add_notes' => NoteRole::atLeast($role, NoteRole::EDITOR),
                'move' => $role === NoteRole::OWNER,
                'delete' => $role === NoteRole::OWNER,
                'manage_members' => $role === NoteRole::OWNER,
            ],
            'note_count' => (int) $row['note_count'],
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
            'children' => [],
        ];
    }

    // -----------------------------------------------------------------------
    // Placement
    // -----------------------------------------------------------------------

    /**
     * The notebook something is going under, or null for a root.
     *
     * Filing into a notebook is a write to it, so it needs edit rights there —
     * the same rule a note follows when it is filed — and the parent must
     * additionally be the caller's own.
     *
     * That second rule is not tidiness. Notebook access resolves through
     * ownership, or a membership on the notebook itself or an ancestor
     * ({@see NoteAccess::notebookCte()}), and an owner is not a member row of
     * their own notebook. A child owned by somebody else would therefore be
     * invisible to the very person who owns the notebook it sits in — a folder
     * inside your folder that you cannot see. Notes are the thing that files
     * into a shared notebook; they cascade, so they stay visible.
     *
     * @return array<string, mixed>|null
     */
    private function resolveParent(Identity $identity, mixed $parentId, ?string $movingId = null): ?array
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        if (!Uuid::isValid($parentId)) {
            throw ApiException::validation(['parent_id' => 'That is not a notebook id.']);
        }

        $parentId = strtolower((string) $parentId);
        if ($movingId !== null && $parentId === $movingId) {
            // Caught here as well as by the subtree probe, because a notebook
            // that is its own parent is the one cycle a UI can produce with a
            // single mis-drop.
            throw $this->cycle();
        }

        $parent = $this->permissions->requireNotebook($identity, $parentId, NotePermissionService::EDIT);

        if ((string) $parent['owner_user_id'] !== $identity->userId) {
            throw ApiException::forbidden(
                'NOTEBOOK_ACCESS_DENIED',
                'Only the owner of a notebook can put sub-notebooks inside it.',
            );
        }

        return $parent;
    }

    private function nextPosition(string $owner, ?string $parentId): int
    {
        $row = Connection::selectOne(
            'SELECT coalesce(max(position), -1) + 1 AS next FROM notebooks
             WHERE owner_user_id = :owner AND deleted_at IS NULL
               AND parent_id IS NOT DISTINCT FROM :parent::uuid',
            ['owner' => $owner, 'parent' => $parentId],
        );

        return min(self::MAX_POSITION, (int) ($row['next'] ?? 0));
    }

    /**
     * Drop a notebook at an index among its siblings and renumber them.
     *
     * A drag-and-drop means "third from the top", not "position 7", so the
     * siblings are re-sequenced 0..n-1 around it. Storing the raw number
     * instead would leave two siblings sharing a position and the sidebar
     * flipping between two orders from one reload to the next.
     */
    private function reposition(
        Identity $identity,
        string $notebookId,
        string $owner,
        ?string $parentId,
        int $index,
    ): void {
        $siblings = Connection::select(
            'SELECT id FROM notebooks
             WHERE owner_user_id = :owner AND deleted_at IS NULL
               AND parent_id IS NOT DISTINCT FROM :parent::uuid
               AND id <> :id::uuid
             ORDER BY position, lower(name), id',
            ['owner' => $owner, 'parent' => $parentId, 'id' => $notebookId],
        );

        $ordered = array_map(static fn (array $row): string => (string) $row['id'], $siblings);
        array_splice($ordered, max(0, min($index, count($ordered))), 0, [$notebookId]);

        Connection::transaction(static function () use ($ordered, $identity): void {
            foreach ($ordered as $position => $id) {
                Connection::execute(
                    'UPDATE notebooks SET position = :position, updated_by = :actor, updated_at = now()
                     WHERE id = :id::uuid AND position <> :position',
                    ['id' => $id, 'position' => $position, 'actor' => $identity->userId],
                );
            }
        });
    }

    // -----------------------------------------------------------------------
    // Naming
    // -----------------------------------------------------------------------

    /**
     * Refuse a name a sibling already uses.
     *
     * The database has the last word — two partial unique indexes on
     * (owner, parent, lower(name)) — but reaching them raises a PDOException,
     * and a 500 tells the user nothing they can act on. This turns the common
     * case into a 409 with a code the UI can render as "that name is taken".
     */
    private function requireNameFree(string $owner, ?string $parentId, string $name, ?string $excludeId): void
    {
        if (!$this->nameIsFree($owner, $parentId, $name, $excludeId)) {
            throw $this->nameTaken();
        }
    }

    private function nameIsFree(string $owner, ?string $parentId, string $name, ?string $excludeId): bool
    {
        $clash = Connection::selectOne(
            'SELECT id FROM notebooks
             WHERE owner_user_id = :owner AND deleted_at IS NULL
               AND lower(name) = lower(:name)
               AND parent_id IS NOT DISTINCT FROM :parent::uuid
               AND (:exclude::uuid IS NULL OR id <> :exclude::uuid)
             LIMIT 1',
            ['owner' => $owner, 'parent' => $parentId, 'name' => $name, 'exclude' => $excludeId],
        );

        return $clash === null;
    }

    /** The first free variant of a name under a parent: "Notes", "Notes (2)", … */
    private function freeNameUnder(string $owner, ?string $parentId, string $name, string $excludeId): string
    {
        $candidate = $name;
        for ($suffix = 2; $suffix <= 50; $suffix++) {
            if ($this->nameIsFree($owner, $parentId, $candidate, $excludeId)) {
                return $candidate;
            }
            $candidate = Str::limit($name, self::MAX_NAME - 8) . ' (' . $suffix . ')';
        }

        // Fifty collisions is absurd, but a delete that cannot finish is worse
        // than an ugly name, so fall back to something guaranteed unique.
        return Str::limit($name, self::MAX_NAME - 12) . ' (' . substr($excludeId, 0, 8) . ')';
    }

    /**
     * Run a write the unique index might refuse.
     *
     * The pre-check loses to a concurrent create; this is the backstop that
     * keeps that race a 409 instead of a 500.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function guardNameCollision(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw $this->nameTaken();
            }
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    private function name(mixed $value): string
    {
        $name = is_scalar($value) ? trim((string) $value) : '';
        if ($name === '') {
            throw ApiException::validation(['name' => 'A notebook needs a name.']);
        }

        return Str::limit($name, self::MAX_NAME);
    }

    private function description(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $description = trim((string) $value);

        return $description === '' ? null : Str::limit($description, self::MAX_DESCRIPTION);
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
            throw ApiException::validation(['color' => 'Unknown notebook colour.']);
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

    /**
     * A portal user id. Its format belongs to the portal, so only the length
     * the column allows is enforced here.
     */
    private function memberUserId(mixed $value): string
    {
        $userId = is_scalar($value) ? trim((string) $value) : '';
        if ($userId === '' || mb_strlen($userId, 'UTF-8') > 64) {
            throw ApiException::validation(['user_id' => 'Choose someone to share this notebook with.']);
        }

        return $userId;
    }

    // -----------------------------------------------------------------------
    // Failures
    // -----------------------------------------------------------------------

    private function cycle(): ApiException
    {
        return new ApiException(
            409,
            'NOTEBOOK_CYCLE',
            'A notebook cannot be moved into itself or into one of its own sub-notebooks.',
        );
    }

    private function tooDeep(): ApiException
    {
        return new ApiException(
            409,
            'NOTEBOOK_TOO_DEEP',
            sprintf('Notebooks can be nested %d levels deep at most.', self::MAX_DEPTH),
        );
    }

    private function nameTaken(): ApiException
    {
        return new ApiException(
            409,
            'NOTEBOOK_NAME_TAKEN',
            'You already have a notebook with that name in this place.',
        );
    }

    // -----------------------------------------------------------------------

    private static function nullableId(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : strtolower((string) $value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
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
