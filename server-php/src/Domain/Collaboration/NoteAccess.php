<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Collaboration;

/**
 * The SQL that decides which notes a user may see.
 *
 * This exists as one reusable fragment rather than as a condition repeated in
 * every query, because the alternative is the failure mode that matters most in
 * a notes product: one list endpoint that forgot the tenant check, and a user
 * reading another company's notes.
 *
 * Two independent gates, both required:
 *
 *   1. A **grant** — the user owns the note, is a member of the note, or is a
 *      member of a notebook that contains it (cascading to descendants).
 *   2. A **tenant match** — the note is personal (`tenant_id IS NULL`) or
 *      belongs to the company the caller is currently acting in. This holds
 *      even when a grant exists, so a stale membership row cannot reach across
 *      a tenant boundary.
 *
 * Every read path composes this: list, search, backlinks, and the retrieval
 * that feeds Pulse. Filtering happens *here*, before ranking — never in the UI.
 */
final class NoteAccess
{
    /**
     * CTEs defining `note_access(note_id, role, role_rank)`.
     *
     * Bind `:auth_user` and `:auth_tenant`. Prefix with `WITH RECURSIVE`.
     */
    public static function cte(): string
    {
        return <<<'SQL'
        shared_notebooks AS (
            SELECT nb.id AS notebook_id, nbm.role::text AS role
            FROM notebook_members nbm
            JOIN notebooks nb ON nb.id = nbm.notebook_id AND nb.deleted_at IS NULL
            WHERE nbm.user_id = :auth_user
          UNION
            -- A notebook share cascades to nested notebooks, which is what
            -- makes "share Projects with the team" mean what people expect.
            SELECT child.id, parent.role
            FROM notebooks child
            JOIN shared_notebooks parent ON child.parent_id = parent.notebook_id
            WHERE child.deleted_at IS NULL
        ),
        note_grants AS (
            SELECT n.id AS note_id, 'owner'::text AS role
            FROM notes n
            WHERE n.owner_user_id = :auth_user
          UNION ALL
            SELECT nm.note_id, nm.role::text
            FROM note_members nm
            WHERE nm.user_id = :auth_user
          UNION ALL
            SELECT n.id, sn.role
            FROM notes n
            JOIN shared_notebooks sn ON sn.notebook_id = n.notebook_id
        ),
        note_access AS (
            SELECT
                g.note_id,
                MAX(CASE g.role
                        WHEN 'owner'     THEN 4
                        WHEN 'editor'    THEN 3
                        WHEN 'commenter' THEN 2
                        WHEN 'viewer'    THEN 1
                        ELSE 0
                    END) AS role_rank
            FROM note_grants g
            JOIN notes n ON n.id = g.note_id
            -- The tenant gate. `IS NULL` keeps personal notes reachable
            -- whichever company the user is currently acting in; the equality
            -- keeps company notes inside their own company.
            WHERE (n.tenant_id IS NULL OR n.tenant_id = :auth_tenant)
            GROUP BY g.note_id
        )
        SQL;
    }

    /** Turn the numeric rank the CTE produces back into a role name. */
    public static function roleFromRank(int $rank): string
    {
        return match ($rank) {
            4 => NoteRole::OWNER,
            3 => NoteRole::EDITOR,
            2 => NoteRole::COMMENTER,
            default => NoteRole::VIEWER,
        };
    }

    public static function rankFor(string $role): int
    {
        return match ($role) {
            NoteRole::OWNER => 4,
            NoteRole::EDITOR => 3,
            NoteRole::COMMENTER => 2,
            default => 1,
        };
    }

    /**
     * Notebooks the caller may see, on the same two-gate principle.
     *
     * Bind `:auth_user` and `:auth_tenant`.
     */
    public static function notebookCte(): string
    {
        return <<<'SQL'
        shared_notebooks AS (
            SELECT nb.id AS notebook_id, nbm.role::text AS role
            FROM notebook_members nbm
            JOIN notebooks nb ON nb.id = nbm.notebook_id AND nb.deleted_at IS NULL
            WHERE nbm.user_id = :auth_user
          UNION
            SELECT child.id, parent.role
            FROM notebooks child
            JOIN shared_notebooks parent ON child.parent_id = parent.notebook_id
            WHERE child.deleted_at IS NULL
        ),
        notebook_access AS (
            SELECT nb.id AS notebook_id,
                   MAX(CASE grant_role
                           WHEN 'owner'     THEN 4
                           WHEN 'editor'    THEN 3
                           WHEN 'commenter' THEN 2
                           WHEN 'viewer'    THEN 1
                           ELSE 0
                       END) AS role_rank
            FROM (
                SELECT id, 'owner'::text AS grant_role FROM notebooks WHERE owner_user_id = :auth_user
                UNION ALL
                SELECT notebook_id, role FROM shared_notebooks
            ) grants
            JOIN notebooks nb ON nb.id = grants.id AND nb.deleted_at IS NULL
            WHERE (nb.tenant_id IS NULL OR nb.tenant_id = :auth_tenant)
            GROUP BY nb.id
        )
        SQL;
    }
}
