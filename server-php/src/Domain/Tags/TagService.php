<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Tags;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Tags, normalised.
 *
 * `GST`, `gst` and `#GST` are one tag with one slug, and the display name is
 * whatever the user typed first. Without that rule a tag list becomes three
 * near-identical entries and filtering by one of them quietly hides the notes
 * filed under the others.
 */
final class TagService
{
    private const MAX_TAGS_PER_NOTE = 50;

    /**
     * Resolve a list of names to tag ids, creating what does not exist.
     *
     * @param array<int, string> $names
     * @return array<int, string> Tag ids.
     */
    public function resolve(Identity $identity, array $names): array
    {
        $ids = [];
        $seen = [];

        foreach (array_slice($names, 0, self::MAX_TAGS_PER_NOTE) as $name) {
            if (!is_scalar($name)) {
                continue;
            }
            // A tag typed inline arrives as "#gst".
            $display = Str::limit(ltrim(trim((string) $name), '#'), 80);
            $slug = Str::tagSlug($display);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $ids[] = $this->findOrCreate($identity, $display, $slug);
        }

        return $ids;
    }

    private function findOrCreate(Identity $identity, string $display, string $slug): string
    {
        $existing = Connection::selectOne(
            'SELECT id FROM tags WHERE owner_user_id = :owner AND slug = :slug',
            ['owner' => $identity->userId, 'slug' => $slug],
        );
        if ($existing !== null) {
            return (string) $existing['id'];
        }

        $id = Uuid::v4();
        Connection::execute(
            'INSERT INTO tags (id, tenant_id, owner_user_id, name, slug)
             VALUES (:id, :tenant_id, :owner, :name, :slug)
             ON CONFLICT (owner_user_id, slug) DO NOTHING',
            [
                'id' => $id,
                'tenant_id' => $identity->tenantId,
                'owner' => $identity->userId,
                'name' => $display,
                'slug' => $slug,
            ],
        );

        // A concurrent request may have won the race; re-read rather than
        // assume the insert took.
        $row = Connection::selectOne(
            'SELECT id FROM tags WHERE owner_user_id = :owner AND slug = :slug',
            ['owner' => $identity->userId, 'slug' => $slug],
        );

        return (string) ($row['id'] ?? $id);
    }

    /** Replace a note's tags with exactly this set. */
    public function setForNote(Identity $identity, string $noteId, array $names): void
    {
        $tagIds = $this->resolve($identity, $names);

        Connection::transaction(static function () use ($noteId, $tagIds): void {
            Connection::execute('DELETE FROM note_tags WHERE note_id = :note_id', ['note_id' => $noteId]);
            foreach ($tagIds as $tagId) {
                Connection::execute(
                    'INSERT INTO note_tags (note_id, tag_id) VALUES (:note_id, :tag_id)
                     ON CONFLICT DO NOTHING',
                    ['note_id' => $noteId, 'tag_id' => $tagId],
                );
            }
        });
    }

    /**
     * The caller's tags with a usage count.
     *
     * Counting only notes the caller can actually open keeps a shared tag from
     * advertising how many notes someone else filed under it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(Identity $identity): array
    {
        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT t.id, t.name, t.slug, t.color,
                    count(n.id) FILTER (WHERE n.deleted_at IS NULL AND NOT n.is_archived) AS note_count
             FROM tags t
             LEFT JOIN note_tags nt ON nt.tag_id = t.id
             LEFT JOIN notes n ON n.id = nt.note_id
             LEFT JOIN note_access a ON a.note_id = n.id
             WHERE t.owner_user_id = :auth_user
             GROUP BY t.id, t.name, t.slug, t.color
             ORDER BY note_count DESC, lower(t.name)',
            ['auth_user' => $identity->userId, 'auth_tenant' => $identity->tenantId],
        );

        return array_map(static fn (array $row) => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'note_count' => (int) $row['note_count'],
        ], $rows);
    }

    public function create(Identity $identity, string $name, ?string $color): array
    {
        $display = Str::limit(ltrim(trim($name), '#'), 80);
        $slug = Str::tagSlug($display);
        if ($slug === '') {
            throw ApiException::validation(['name' => 'A tag needs a name.']);
        }

        $id = $this->findOrCreate($identity, $display, $slug);
        if ($color !== null) {
            $this->update($identity, $id, null, $color);
        }

        return $this->requireTag($identity, $id);
    }

    public function update(Identity $identity, string $tagId, ?string $name, ?string $color): array
    {
        $tag = $this->requireTag($identity, $tagId);

        $updates = [];
        $bindings = ['id' => $tagId, 'owner' => $identity->userId];

        if ($name !== null) {
            $display = Str::limit(ltrim(trim($name), '#'), 80);
            $slug = Str::tagSlug($display);
            if ($slug === '') {
                throw ApiException::validation(['name' => 'A tag needs a name.']);
            }
            // Renaming onto an existing slug is a merge, not a rename —
            // otherwise it would violate the unique index and 500.
            if ($slug !== $tag['slug']) {
                $clash = Connection::selectOne(
                    'SELECT id FROM tags WHERE owner_user_id = :owner AND slug = :slug AND id <> :id',
                    ['owner' => $identity->userId, 'slug' => $slug, 'id' => $tagId],
                );
                if ($clash !== null) {
                    return $this->merge($identity, [$tagId], (string) $clash['id']);
                }
            }
            $updates[] = 'name = :name';
            $updates[] = 'slug = :slug';
            $bindings['name'] = $display;
            $bindings['slug'] = $slug;
        }

        if ($color !== null) {
            $updates[] = 'color = :color';
            $bindings['color'] = $color === '' ? null : Str::limit($color, 30);
        }

        if ($updates !== []) {
            $updates[] = 'updated_at = now()';
            Connection::execute(
                'UPDATE tags SET ' . implode(', ', $updates) . ' WHERE id = :id AND owner_user_id = :owner',
                $bindings,
            );
        }

        return $this->requireTag($identity, $tagId);
    }

    public function delete(Identity $identity, string $tagId): void
    {
        $this->requireTag($identity, $tagId);
        // note_tags cascades, so the notes keep their content and simply lose
        // the label.
        Connection::execute(
            'DELETE FROM tags WHERE id = :id AND owner_user_id = :owner',
            ['id' => $tagId, 'owner' => $identity->userId],
        );
    }

    /**
     * Fold several tags into one.
     *
     * @param array<int, string> $sourceIds
     */
    public function merge(Identity $identity, array $sourceIds, string $targetId): array
    {
        $target = $this->requireTag($identity, $targetId);

        Connection::transaction(function () use ($identity, $sourceIds, $targetId): void {
            foreach ($sourceIds as $sourceId) {
                if (!Uuid::isValid($sourceId) || $sourceId === $targetId) {
                    continue;
                }
                $this->requireTag($identity, $sourceId);

                Connection::execute(
                    'INSERT INTO note_tags (note_id, tag_id)
                     SELECT note_id, :target FROM note_tags WHERE tag_id = :source
                     ON CONFLICT DO NOTHING',
                    ['target' => $targetId, 'source' => $sourceId],
                );
                Connection::execute(
                    'DELETE FROM tags WHERE id = :id AND owner_user_id = :owner',
                    ['id' => $sourceId, 'owner' => $identity->userId],
                );
            }
        });

        return $this->requireTag($identity, $target['id']);
    }

    /** @return array<string, mixed> */
    private function requireTag(Identity $identity, string $tagId): array
    {
        $row = Connection::selectOne(
            'SELECT id, name, slug, color FROM tags WHERE id = :id AND owner_user_id = :owner',
            ['id' => $tagId, 'owner' => $identity->userId],
        );
        if ($row === null) {
            throw ApiException::notFound('That tag');
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
        ];
    }

    /** @return array<int, string> Tag names currently on a note. */
    public function namesForNote(string $noteId): array
    {
        $rows = Connection::select(
            'SELECT t.name FROM note_tags nt JOIN tags t ON t.id = nt.tag_id
             WHERE nt.note_id = :note_id ORDER BY t.name',
            ['note_id' => $noteId],
        );

        return array_map(static fn (array $row) => (string) $row['name'], $rows);
    }
}
