<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Templates;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Domain\Notes\NotesService;
use Aicountly\Api\Domain\Tags\TagService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * The starting points a note can be created from.
 *
 * Three scopes, and what separates them is who they belong to rather than what
 * they contain:
 *
 *   - **system** — seeded by {@see SystemTemplateSeeder}. Visible to everyone,
 *     editable by nobody. They are part of the deployment, not user data, so an
 *     edit is refused with `TEMPLATE_READ_ONLY` rather than silently forking a
 *     private copy the user did not ask for.
 *   - **tenant** — shared inside one company. Visible to every member of it,
 *     editable only by whoever created it: a shared template any colleague
 *     could rewrite underneath you is one nobody can rely on.
 *   - **user** — private to its owner and invisible to everyone else.
 *
 * Visibility follows the same two-gate shape as
 * {@see \Aicountly\Api\Domain\Collaboration\NoteAccess}: a grant (system, your
 * company's, or yours) *and* a tenant match. A template you made while acting
 * in one company does not follow you into another, for the same reason a note
 * does not. That gate is one SQL fragment ({@see self::VISIBLE}) composed by
 * every read here, because the failure that matters is the one query that
 * forgot it.
 *
 * A template the caller cannot see is answered **404, never 403** — an id from
 * someone else's list must not confirm that a template exists.
 */
final class NoteTemplateService
{
    public const SCOPE_SYSTEM = 'system';
    public const SCOPE_TENANT = 'tenant';
    public const SCOPE_USER = 'user';

    private const MAX_NAME = 200;
    private const MAX_DESCRIPTION = 2000;
    private const MAX_ICON = 60;
    private const MAX_TITLE_TEMPLATE = 500;
    private const MAX_DEFAULT_TAGS = 20;
    private const MAX_POSITION = 100000;

    /**
     * The scopes a caller may see, as one fragment.
     *
     * Binds `:auth_user` and `:auth_tenant`. `NULL = NULL` is NULL in SQL, so a
     * caller acting without a company matches no company template — which is
     * exactly the rule, not an accident of the comparison.
     */
    private const VISIBLE = "(
            t.scope = 'system'
            OR (t.scope = 'tenant' AND t.tenant_id = :auth_tenant)
            OR (t.scope = 'user'
                AND t.owner_user_id = :auth_user
                AND (t.tenant_id IS NULL OR t.tenant_id = :auth_tenant))
        )";

    /** Deliberately excludes document_json: a picker lists names, not trees. */
    private const COLUMNS = 't.id, t.scope, t.tenant_id, t.owner_user_id, t.template_key,
        t.name, t.description, t.icon, t.note_type, t.title_template, t.default_tags,
        t.position, t.is_archived, t.created_at, t.updated_at';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly NotesService $notes = new NotesService(),
        private readonly TagService $tags = new TagService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Everything the caller may start a note from, in picker order.
     *
     * System first, then the company's, then their own: the built-ins everyone
     * knows, then what the company agreed on, then what this person made.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(Identity $identity, bool $includeArchived = false): array
    {
        // Archiving is how a template is taken out of the picker without
        // destroying the notes that reference it, so the default list hides
        // archived rows or the action would mean nothing.
        $archived = $includeArchived ? '' : ' AND NOT t.is_archived';

        $rows = Connection::select(
            'SELECT ' . self::COLUMNS . '
             FROM note_templates t
             WHERE t.deleted_at IS NULL' . $archived . '
               AND ' . self::VISIBLE . "
             ORDER BY CASE t.scope WHEN 'system' THEN 0 WHEN 'tenant' THEN 1 ELSE 2 END,
                      t.position, lower(t.name), t.id",
            ['auth_user' => $identity->userId, 'auth_tenant' => $identity->tenantId],
        );

        return array_map(fn (array $row): array => self::present($row, $identity), $rows);
    }

    /** @return array<string, mixed> The template, document included. */
    public function get(Identity $identity, string $templateId): array
    {
        return self::present($this->requireVisible($identity, $templateId, withDocument: true), $identity, true);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    /**
     * Create a template, either from scratch or from a note.
     *
     * `{"from_note_id": "…"}` is "save this note as a template": it needs VIEW
     * on that note and copies its document, type and tags. Everything else the
     * request supplies wins over what the note carried, so a save-as can still
     * be named and described in the same call.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, array $input): array
    {
        // The client may supply the id, so a retried POST cannot leave two
        // copies of the same template behind.
        $templateId = isset($input['id']) && Uuid::isValid($input['id'])
            ? strtolower((string) $input['id'])
            : Uuid::v4();

        if (Connection::selectOne('SELECT id FROM note_templates WHERE id = :id', ['id' => $templateId]) !== null) {
            // Replay. `get` re-checks visibility, so an id that happens to
            // belong to someone else answers 404 rather than handing it over.
            return $this->get($identity, $templateId);
        }

        $scope = $this->scope($identity, $input['scope'] ?? self::SCOPE_USER);
        $source = $this->fromNote($identity, $input['from_note_id'] ?? null);

        $name = $this->name($input['name'] ?? ($source['name'] ?? null));
        $noteType = $this->noteType($input['note_type'] ?? ($source['note_type'] ?? 'document'));

        $document = array_key_exists('document', $input)
            ? NoteDocument::sanitize($input['document'])
            : ($source['document'] ?? NoteDocument::empty());

        $defaultTags = array_key_exists('default_tags', $input)
            ? $this->tagSlugs($input['default_tags'])
            : ($source['tags'] ?? []);

        Connection::execute(
            'INSERT INTO note_templates
                (id, scope, tenant_id, owner_user_id, name, description, icon, note_type,
                 title_template, document_json, default_tags, position, created_by)
             VALUES
                (:id, :scope, :tenant_id, :owner, :name, :description, :icon, :note_type,
                 :title_template, :document::jsonb, :default_tags::jsonb, :position, :actor)',
            [
                'id' => $templateId,
                'scope' => $scope,
                // A personal template keeps the company it was made in, so it
                // stays inside that context exactly as a note does.
                'tenant_id' => $identity->tenantId,
                'owner' => $identity->userId,
                'name' => $name,
                'description' => $this->text($input['description'] ?? null, self::MAX_DESCRIPTION),
                'icon' => $this->text($input['icon'] ?? null, self::MAX_ICON),
                'note_type' => $noteType,
                'title_template' => $this->text($input['title_template'] ?? null, self::MAX_TITLE_TEMPLATE),
                'document' => json_encode($document, JSON_UNESCAPED_SLASHES),
                'default_tags' => json_encode($defaultTags, JSON_UNESCAPED_SLASHES),
                'position' => $this->position($input['position'] ?? null),
                'actor' => $identity->userId,
            ],
        );

        return $this->get($identity, $templateId);
    }

    // -----------------------------------------------------------------------
    // Update and delete
    // -----------------------------------------------------------------------

    /**
     * Change a template the caller owns.
     *
     * Every field is optional and independent — the picker's rename sends only
     * a name, and it must not wipe the document.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $templateId, array $input): array
    {
        $this->requireOwned($identity, $templateId);

        $updates = [];
        $bindings = ['id' => $templateId];

        if (array_key_exists('name', $input)) {
            $updates[] = 'name = :name';
            $bindings['name'] = $this->name($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $updates[] = 'description = :description';
            $bindings['description'] = $this->text($input['description'], self::MAX_DESCRIPTION);
        }
        if (array_key_exists('icon', $input)) {
            $updates[] = 'icon = :icon';
            $bindings['icon'] = $this->text($input['icon'], self::MAX_ICON);
        }
        if (array_key_exists('note_type', $input)) {
            $updates[] = 'note_type = :note_type';
            $bindings['note_type'] = $this->noteType($input['note_type']);
        }
        if (array_key_exists('title_template', $input)) {
            $updates[] = 'title_template = :title_template';
            $bindings['title_template'] = $this->text($input['title_template'], self::MAX_TITLE_TEMPLATE);
        }
        if (array_key_exists('default_tags', $input)) {
            $updates[] = 'default_tags = :default_tags::jsonb';
            $bindings['default_tags'] = json_encode($this->tagSlugs($input['default_tags']), JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('document', $input)) {
            $updates[] = 'document_json = :document::jsonb';
            $bindings['document'] = json_encode(NoteDocument::sanitize($input['document']), JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('position', $input)) {
            $updates[] = 'position = :position';
            $bindings['position'] = $this->position($input['position']);
        }
        if (array_key_exists('is_archived', $input)) {
            $updates[] = 'is_archived = :is_archived';
            $bindings['is_archived'] = (bool) $input['is_archived'];
        }

        if ($updates !== []) {
            $updates[] = 'updated_at = now()';
            Connection::execute(
                'UPDATE note_templates SET ' . implode(', ', $updates) . ' WHERE id = :id AND deleted_at IS NULL',
                $bindings,
            );
        }

        return $this->get($identity, $templateId);
    }

    /**
     * Soft delete.
     *
     * The row stays so a note's `template_key` still resolves to something, and
     * so an accidental delete is a database fix rather than lost work. The
     * partial unique index on system keys ignores deleted rows, which is what
     * lets the seeder re-create one if it ever came to that.
     */
    public function delete(Identity $identity, string $templateId): void
    {
        $this->requireOwned($identity, $templateId);

        Connection::execute(
            'UPDATE note_templates SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $templateId],
        );
    }

    // -----------------------------------------------------------------------
    // Use
    // -----------------------------------------------------------------------

    /**
     * Start a note from a template.
     *
     * The note is created through {@see NotesService::create()} rather than by
     * inserting a row here, so a templated note is not a second kind of note:
     * it gets the same sanitisation, the same first revision, the same derived
     * links and checklist actions, and the same activity entry.
     *
     * The request may override `title`, `notebook_id` and `tags`. An overriding
     * title goes through the same token substitution as the template's own, so
     * a "name this note" dialog can offer `{{date}}` and mean it. An explicit
     * `tags` list replaces the template's defaults rather than adding to them,
     * because "create it with exactly these tags" is the only version of that
     * instruction a UI can express unambiguously.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createNote(Identity $identity, string $templateId, array $input): array
    {
        $template = $this->requireVisible($identity, $templateId, withDocument: true);

        $tags = array_key_exists('tags', $input)
            ? $this->tagSlugs($input['tags'])
            : self::decodeTags($template['default_tags'] ?? null);

        $title = $this->renderTitle(
            array_key_exists('title', $input) ? $input['title'] : ($template['title_template'] ?? null),
            $input['timezone'] ?? null,
        );

        return $this->notes->create($identity, [
            'id' => $input['id'] ?? null,
            'title' => $title,
            'document' => self::decodeDocument($template['document_json'] ?? null),
            'note_type' => (string) $template['note_type'],
            'notebook_id' => $input['notebook_id'] ?? null,
            'tags' => $tags,
            'source' => 'template',
            'template_key' => self::reference($template),
        ]);
    }

    /**
     * Substitute the tokens a `title_template` — or a title the request sends
     * in its place — may contain.
     *
     * There are exactly two, and this is the whole list:
     *
     *   - `{{date}}` → `2026-09-06` (ISO, so a folder of daily notes sorts
     *     chronologically by title)
     *   - `{{time}}` → `14:05` (24-hour)
     *
     * Both resolve in the timezone the request passes as `timezone` (an IANA
     * name such as `Asia/Kolkata`), falling back to UTC. The server otherwise
     * has no idea where the user is, and a "Daily note — {{date}}" that says
     * yesterday evening is worse than one that admits it is UTC.
     *
     * Anything else in braces is left exactly as typed. `{{client}}` is a
     * placeholder a person fills in; deleting it would quietly remove the
     * instruction the template was giving them.
     */
    private function renderTitle(mixed $titleTemplate, mixed $timezone): ?string
    {
        if (!is_string($titleTemplate) || trim($titleTemplate) === '') {
            return null;
        }

        $now = Clock::now()->setTimezone(self::timezone($timezone));

        return Str::limit(trim(str_replace(
            ['{{date}}', '{{time}}'],
            [$now->format('Y-m-d'), $now->format('H:i')],
            $titleTemplate,
        )), self::MAX_TITLE_TEMPLATE);
    }

    // -----------------------------------------------------------------------
    // Loading and authorisation
    // -----------------------------------------------------------------------

    /**
     * A template the caller may see, or 404.
     *
     * @return array<string, mixed>
     */
    private function requireVisible(Identity $identity, string $templateId, bool $withDocument = false): array
    {
        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ($withDocument ? ', t.document_json' : '') . '
             FROM note_templates t
             WHERE t.id = :id AND t.deleted_at IS NULL AND ' . self::VISIBLE,
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'id' => $templateId,
            ],
        );

        if ($row === null) {
            throw ApiException::notFound('That template');
        }

        return $row;
    }

    /**
     * A template the caller may change.
     *
     * 403 rather than 404 here on purpose: these are templates the caller can
     * already see in their own list, so the id tells them nothing new — and
     * "you may look but not edit" is what the UI needs to know to disable the
     * button instead of failing on click.
     *
     * @return array<string, mixed>
     */
    private function requireOwned(Identity $identity, string $templateId): array
    {
        $row = $this->requireVisible($identity, $templateId);

        if ((string) $row['scope'] === self::SCOPE_SYSTEM) {
            throw ApiException::forbidden(
                'TEMPLATE_READ_ONLY',
                'Built-in templates cannot be changed. Start a note from it and save that as your own template instead.',
            );
        }

        if ((string) ($row['owner_user_id'] ?? '') !== $identity->userId) {
            throw ApiException::forbidden(
                'TEMPLATE_NOT_OWNED',
                'Only the person who created this template can change it.',
            );
        }

        return $row;
    }

    /**
     * The note behind `from_note_id`, reduced to what a template keeps.
     *
     * @return array{document: array<string, mixed>, name: string|null, note_type: string, tags: array<int, string>}|null
     */
    private function fromNote(Identity $identity, mixed $noteId): ?array
    {
        if ($noteId === null || $noteId === '') {
            return null;
        }
        if (!Uuid::isValid($noteId)) {
            // Answered the way an unreachable note is answered: the id must not
            // be able to tell the caller whether it exists.
            throw ApiException::notFound('That note');
        }

        $note = $this->permissions->requireNote(
            $identity,
            strtolower((string) $noteId),
            NotePermissionService::VIEW,
        );

        if ((string) ($note['privacy_mode'] ?? 'standard') === 'private') {
            // A private note holds ciphertext the server cannot read. Copying
            // it into a template would produce an unreadable template and, for
            // a tenant template, hand those bytes to a company.
            throw ApiException::forbidden(
                'NOTE_PRIVATE',
                'A private note cannot be saved as a template: its content is readable only by its owner, on their own device.',
            );
        }

        $title = $note['title'] === null ? '' : trim((string) $note['title']);

        return [
            // Re-sanitised rather than trusted: a row written before a rule in
            // NoteDocument tightened must not be laundered back in by a copy.
            'document' => NoteDocument::sanitize(self::decodeDocument($note['document_json'] ?? null)),
            'name' => $title !== '' ? $title : Str::firstLine((string) ($note['extracted_text'] ?? '')),
            'note_type' => (string) ($note['note_type'] ?? 'document'),
            'tags' => $this->tagSlugs($this->tags->namesForNote((string) $note['id'])),
        ];
    }

    // -----------------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------------

    private function scope(Identity $identity, mixed $value): string
    {
        $scope = strtolower((string) (is_scalar($value) ? $value : self::SCOPE_USER));

        if ($scope === self::SCOPE_SYSTEM) {
            // System templates are configuration this deployment ships. The API
            // is not a way to add one, or a tenant could plant a template that
            // looks built-in to every other tenant.
            throw ApiException::forbidden(
                'TEMPLATE_READ_ONLY',
                'Built-in templates are part of the deployment and cannot be created here.',
            );
        }

        if ($scope === self::SCOPE_TENANT) {
            if ($identity->tenantId === null) {
                throw ApiException::validation([
                    'scope' => 'You are not signed in to a company, so this template can only be your own.',
                ]);
            }

            return self::SCOPE_TENANT;
        }

        if ($scope !== self::SCOPE_USER) {
            throw ApiException::validation(['scope' => 'Unknown template scope.']);
        }

        return self::SCOPE_USER;
    }

    private function name(mixed $value): string
    {
        $name = trim((string) (is_scalar($value) ? $value : ''));
        if ($name === '') {
            throw ApiException::validation(['name' => 'A template needs a name.']);
        }

        return Str::limit($name, self::MAX_NAME);
    }

    private function noteType(mixed $value): string
    {
        $type = strtolower((string) (is_scalar($value) ? $value : 'document'));
        if (!in_array($type, NotesService::NOTE_TYPES, true)) {
            throw ApiException::validation(['note_type' => 'Unknown note type.']);
        }

        // Deliberately not checked against the feature flags here: a template
        // is configuration, and a deployment that turns canvas on next month
        // should find its canvas template waiting. NotesService answers
        // FEATURE_DISABLED at the moment a note is actually created from it.
        return $type;
    }

    /** Optional free text; `null` or `""` clears the column. */
    private function text(mixed $value, int $max): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : Str::limit($text, $max);
    }

    private function position(mixed $value): int
    {
        return max(0, min(self::MAX_POSITION, (int) (is_numeric($value) ? $value : 0)));
    }

    /**
     * Tag input → the slugs the column stores.
     *
     * Slugs, not display names, because `#GST`, `GST` and `gst` are one tag
     * ({@see TagService}) and a template that stored all three spellings would
     * hand a note three labels that fold into one.
     *
     * @return array<int, string>
     */
    private function tagSlugs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $slugs = [];
        foreach (array_slice($value, 0, self::MAX_DEFAULT_TAGS) as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $slug = Str::tagSlug(ltrim(trim((string) $tag), '#'));
            if ($slug === '' || in_array($slug, $slugs, true)) {
                continue;
            }
            $slugs[] = $slug;
        }

        return $slugs;
    }

    private static function timezone(mixed $value): \DateTimeZone
    {
        if (!is_string($value) || trim($value) === '') {
            return new \DateTimeZone('UTC');
        }

        try {
            return new \DateTimeZone(trim($value));
        } catch (\Throwable) {
            // Falling back to UTC would put a wrong date in the title with no
            // sign anything went wrong, which is the one outcome nobody can
            // debug from the note itself.
            throw ApiException::validation(['timezone' => 'That is not a timezone this server recognises.']);
        }
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row, Identity $identity, bool $withDocument = false): array
    {
        $resource = [
            'id' => (string) $row['id'],
            'scope' => (string) $row['scope'],
            'template_key' => self::nullableString($row['template_key'] ?? null),
            'name' => (string) $row['name'],
            'description' => self::nullableString($row['description'] ?? null),
            'icon' => self::nullableString($row['icon'] ?? null),
            'note_type' => (string) $row['note_type'],
            'title_template' => self::nullableString($row['title_template'] ?? null),
            'default_tags' => self::decodeTags($row['default_tags'] ?? null),
            'position' => (int) ($row['position'] ?? 0),
            'is_archived' => (bool) ($row['is_archived'] ?? false),
            // So the UI can render an Edit control only where one would work,
            // rather than offering it and failing on click.
            'editable' => (string) $row['scope'] !== self::SCOPE_SYSTEM
                && (string) ($row['owner_user_id'] ?? '') === $identity->userId,
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
        ];

        if ($withDocument) {
            $resource['document'] = self::decodeDocument($row['document_json'] ?? null);
        }

        return $resource;
    }

    /**
     * What a note records as the template it came from.
     *
     * A seeded template's key is stable across deployments and readable in an
     * export; a user's template has none, so its id stands in. Both fit the 60
     * characters `notes.template_key` allows.
     *
     * @param array<string, mixed> $row
     */
    private static function reference(array $row): string
    {
        $key = self::nullableString($row['template_key'] ?? null);

        return $key ?? (string) $row['id'];
    }

    /** @return array<string, mixed> */
    private static function decodeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : NoteDocument::empty();
    }

    /** @return array<int, string> */
    private static function decodeTags(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
            array_filter($decoded, static fn (mixed $tag): bool => is_scalar($tag) && (string) $tag !== ''),
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    /**
     * Postgres' text form is not something a browser parses reliably, so the
     * wire carries RFC 3339 — the same shape a note's timestamps arrive in.
     */
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
