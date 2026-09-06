<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Links;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Integrations\ContactsIntegrationService;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Links from a note to a record in another AICOUNTLY product.
 *
 * "This is the meeting about the Acme retainer" is a fact worth storing as a
 * row: it makes the note findable from the invoice, and the invoice findable
 * from the note, without either product reaching into the other's database.
 * Which is the constraint that shapes this whole class —
 * `note_entity_links.entity_id` is a **varchar, not a foreign key**, because
 * Contacts, Calendar, Drive and Connect are separate services. Nothing here
 * joins to them, and nothing here treats one of their ids as proof of anything.
 *
 * Two rules follow from that:
 *
 *   - **`entity_type` comes from an allowlist.** The type decides how a client
 *     renders and routes a link, so an unknown one is not a harmless extra row:
 *     it is a link nothing can open. It is also the one field of this table
 *     that a query filters on ({@see \Aicountly\Api\Domain\Notes\NoteQuery}).
 *   - **A link written by the document belongs to the document.** Mentions
 *     typed into a note are reconciled on every save by
 *     {@see NoteLinkService::syncEntityMentions}, which owns those rows and
 *     deletes the ones no longer mentioned. Letting the API delete one would
 *     produce a link that reappears on the next keystroke, so this class
 *     refuses and says where to remove it instead.
 *
 * Labels are a cache, never authority. The link resolves by id; the label is
 * what a list can render without calling four services.
 */
final class EntityLinkService
{
    /**
     * Products this note may point at.
     *
     * Adding one is a deliberate act: the client needs a route and an icon for
     * it, and {@see \Aicountly\Api\Domain\Notes\NoteQuery} needs to know the
     * filter means something.
     */
    public const ENTITY_TYPES = [
        'contact', 'company', 'employee', 'calendar_event', 'drive_file',
        'connect_meeting', 'project', 'invoice', 'voucher',
    ];

    /** Selected explicitly so `privacy_mode` is present for the permission check. */
    private const NOTE_COLUMNS = 'n.id, n.privacy_mode';

    private const COLUMNS = 'id, note_id, entity_type, entity_id, label, metadata, created_by, created_at';

    /** A note about a client, not an import of the client list. */
    private const MAX_PER_NOTE = 200;

    /** Metadata key the document sync owns. A request may not set it. */
    private const VIA_DOCUMENT = 'via_document';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly ContactsIntegrationService $contacts = new ContactsIntegrationService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function listForNote(Identity $identity, string $noteId): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: self::NOTE_COLUMNS,
        );

        $rows = Connection::select(
            'SELECT ' . self::COLUMNS . ' FROM note_entity_links
             WHERE note_id = :note_id ORDER BY created_at, id',
            ['note_id' => $noteId],
        );

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Link this note to a record somewhere else in AICOUNTLY.
     *
     * Needs EDIT: a link changes what the note says about itself and what it
     * can be found by.
     *
     * `$sesKey` is the caller's own session, forwarded to Contacts when a label
     * is missing and can be looked up. It is optional because every other type
     * of link needs no network at all, and because a deployment with Contacts
     * switched off must still be able to record the link — just without a
     * cached name beside it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, string $noteId, array $input, string $sesKey = ''): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: self::NOTE_COLUMNS,
        );

        $entityType = self::entityType($input['entity_type'] ?? null);
        $entityId = self::entityId($input['entity_id'] ?? null);
        $metadata = self::metadata($input['metadata'] ?? null);
        $label = self::label($input['label'] ?? null) ?? $this->lookUpLabel($entityType, $entityId, $sesKey);

        return Connection::transaction(function () use (
            $identity, $noteId, $entityType, $entityId, $label, $metadata
        ): array {
            $existing = Connection::selectOne(
                'SELECT id FROM note_entity_links
                  WHERE note_id = :note_id AND entity_type = :entity_type AND entity_id = :entity_id',
                ['note_id' => $noteId, 'entity_type' => $entityType, 'entity_id' => $entityId],
            );

            if ($existing === null) {
                $count = Connection::selectOne(
                    'SELECT count(*) AS total FROM note_entity_links WHERE note_id = :note_id',
                    ['note_id' => $noteId],
                );
                if ((int) ($count['total'] ?? 0) >= self::MAX_PER_NOTE) {
                    throw ApiException::badRequest(sprintf(
                        'A note can link to at most %d records.',
                        self::MAX_PER_NOTE,
                    ));
                }
            }

            Connection::execute(
                "INSERT INTO note_entity_links
                    (id, note_id, entity_type, entity_id, label, metadata, created_by)
                 VALUES (:id, :note_id, :entity_type, :entity_id, :label, :metadata::jsonb, :created_by)
                 ON CONFLICT (note_id, entity_type, entity_id) DO UPDATE SET
                    -- Re-linking refreshes a stale cached name, and never
                    -- replaces a good one with nothing.
                    label = coalesce(EXCLUDED.label, note_entity_links.label),
                    -- A link the document wrote keeps its provenance: adding
                    -- the same one by hand must not make it deletable here
                    -- while the mention is still in the note.
                    metadata = CASE WHEN note_entity_links.metadata->>'via_document' = 'true'
                                    THEN note_entity_links.metadata
                                    ELSE EXCLUDED.metadata END",
                [
                    'id' => Uuid::v4(),
                    'note_id' => $noteId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'label' => $label,
                    'metadata' => (string) json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    'created_by' => $identity->userId,
                ],
            );

            return $this->findByEntity($noteId, $entityType, $entityId);
        });
    }

    /**
     * Remove a link.
     *
     * Scoped by note as well as by id, so a link id from another note is a 404
     * rather than a delete the caller was never authorised for.
     */
    public function delete(Identity $identity, string $noteId, string $linkId): void
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: self::NOTE_COLUMNS,
        );

        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_entity_links WHERE id = :id AND note_id = :note_id',
            ['id' => $linkId, 'note_id' => $noteId],
        );
        if ($row === null) {
            throw ApiException::notFound('That link');
        }

        if (self::viaDocument($row)) {
            // 409 rather than 403: this is not about who the caller is — the
            // owner gets the same answer — it is about the state of the note.
            // The mention in the text is the source, so that is where it goes.
            throw new ApiException(
                409,
                'ENTITY_LINK_FROM_DOCUMENT',
                'This link comes from a mention in the note. Remove the mention from the text and it will go with it.',
            );
        }

        Connection::execute(
            'DELETE FROM note_entity_links WHERE id = :id AND note_id = :note_id',
            ['id' => $linkId, 'note_id' => $noteId],
        );
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    private static function entityType(mixed $value): string
    {
        $type = strtolower(trim((string) (is_scalar($value) ? $value : '')));
        if (!in_array($type, self::ENTITY_TYPES, true)) {
            throw ApiException::validation([
                'entity_type' => sprintf('Link to one of: %s.', implode(', ', self::ENTITY_TYPES)),
            ]);
        }

        return $type;
    }

    private static function entityId(mixed $value): string
    {
        $id = trim((string) (is_scalar($value) ? $value : ''));
        if ($id === '') {
            throw ApiException::validation(['entity_id' => 'A link needs the id of the record it points at.']);
        }
        if (mb_strlen($id, 'UTF-8') > 128) {
            // Truncating would point the link at a different record, which is
            // worse than refusing it.
            throw ApiException::validation(['entity_id' => 'That id is too long to be one of ours.']);
        }

        return $id;
    }

    private static function label(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw ApiException::validation(['label' => 'A label must be text.']);
        }
        $label = trim((string) $value);

        return $label === '' ? null : Str::limit($label, 400);
    }

    /** @return array<string, mixed> */
    private static function metadata(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw ApiException::validation(['metadata' => 'Send metadata as an object.']);
        }
        if (array_key_exists(self::VIA_DOCUMENT, $value)) {
            // Refused rather than dropped: a client that set this was trying to
            // claim ownership it cannot have, and silently ignoring it would
            // leave it believing the flag stuck.
            throw ApiException::validation([
                'metadata' => '`via_document` is set by the note itself and cannot be sent.',
            ]);
        }

        return $value;
    }

    /**
     * A display name for a contact, when Contacts is configured and reachable.
     *
     * Best effort by design. The link is the point; the label is a convenience,
     * so an unconfigured or unreachable directory leaves it null rather than
     * failing the request — and nothing invents a name in its place.
     */
    private function lookUpLabel(string $entityType, string $entityId, string $sesKey): ?string
    {
        if ($entityType !== 'contact' || $sesKey === '' || !Features::enabled(Features::CONTACTS)) {
            return null;
        }

        try {
            $labels = $this->contacts->resolve($sesKey, [$entityId]);
        } catch (\Throwable $e) {
            Logger::warn('contacts.label_lookup_failed', ['error' => get_debug_type($e)]);

            return null;
        }

        return $labels[$entityId] ?? null;
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function findByEntity(string $noteId, string $entityType, string $entityId): array
    {
        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_entity_links
              WHERE note_id = :note_id AND entity_type = :entity_type AND entity_id = :entity_id',
            ['note_id' => $noteId, 'entity_type' => $entityType, 'entity_id' => $entityId],
        );
        if ($row === null) {
            throw ApiException::notFound('That link');
        }

        return self::present($row);
    }

    /** @param array<string, mixed> $row */
    private static function viaDocument(array $row): bool
    {
        $metadata = self::decodeObject($row['metadata'] ?? null);

        // Compared as a string because that is how the document sync writes it
        // and how the SQL in NoteLinkService matches it.
        return (string) ($metadata[self::VIA_DOCUMENT] ?? '') === 'true';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'note_id' => (string) $row['note_id'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (string) $row['entity_id'],
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'metadata' => self::decodeObject($row['metadata'] ?? null),
            // Surfaced so a client can render the link without an X on it,
            // rather than offering a delete that answers 409.
            'via_document' => self::viaDocument($row),
            'created_by' => (string) $row['created_by'],
            'created_at' => self::iso($row['created_at'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function iso(mixed $value): ?string
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
