<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Meetings;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Str;

/**
 * The structured half of a meeting note.
 *
 * When it started, who was in the room, what was on the agenda and what was
 * decided live in `note_meetings` rather than inside the document, because
 * those are the parts something other than a human reader needs: "meetings with
 * this client", "notes from last Tuesday", "decisions this quarter". Text in a
 * paragraph answers none of those without re-parsing every note.
 *
 * Three rules this class exists to keep:
 *
 *   - **A read creates nothing.** Opening a note that has never had a meeting
 *     row must not write one. `GET` answers the empty shape with
 *     `exists: false`, so the panel renders and the table stays free of rows
 *     for every note anyone ever opened.
 *   - **A participant is a contact id, not a name.** Where the participant was
 *     linked to Contacts, that id is the identity and the name beside it is a
 *     cached label. Renaming "R. Sharma" to "Ravi Sharma" must not silently
 *     unlink them, and two different Ravis must not merge because their labels
 *     match.
 *   - **Provenance is not inherited.** `summary_model` says which Pulse model
 *     wrote the summary. A person editing that text clears it, because leaving
 *     a model name attached to words a human wrote is a lie about where they
 *     came from — and the opposite one is worse.
 */
final class MeetingService
{
    /**
     * `privacy_mode` travels with the id because {@see NotePermissionService}
     * reads it to refuse a private note to anyone but its owner. Selecting the
     * id alone would quietly skip that guard.
     */
    private const NOTE_COLUMNS = 'n.id, n.privacy_mode';

    /**
     * Columns a caller may set, and how each is bound.
     *
     * The allowlist *is* the SQL: `upsert()` assembles its statement from these
     * keys, never from the request, so a field this map does not name cannot
     * reach the table however it is spelled in the body.
     */
    private const WRITABLE = [
        'starts_at' => ':starts_at::timestamptz',
        'ends_at' => ':ends_at::timestamptz',
        'timezone' => ':timezone',
        'location' => ':location',
        'calendar_event_id' => ':calendar_event_id',
        'connect_meeting_id' => ':connect_meeting_id',
        'participants' => ':participants::jsonb',
        'agenda' => ':agenda::jsonb',
        'decisions' => ':decisions::jsonb',
        'summary' => ':summary',
        'summary_model' => ':summary_model',
        'summary_at' => ':summary_at::timestamptz',
    ];

    /**
     * The external ids one note may claim, and how a second claim is refused.
     *
     * These two columns are not decoration: an inbound recording is routed by
     * `connect_meeting_id` and nothing else, so whichever note holds the id
     * receives the transcript of that call. Letting two notes hold the same id
     * would make the routing a race — and the note that wins is the one edited
     * most recently, which anyone can arrange for themselves. So the id is
     * claimed exclusively, on every path that writes it.
     *
     * column => [error code, message]
     */
    private const EXTERNAL_IDS = [
        'calendar_event_id' => ['CALENDAR_EVENT_ALREADY_LINKED', 'Another note is already the record of this meeting.'],
        'connect_meeting_id' => ['CONNECT_MEETING_ALREADY_LINKED', 'Another note is already the record of this call.'],
    ];

    /** Matches `note_meetings.calendar_event_id` / `connect_meeting_id`. */
    private const MAX_EXTERNAL_ID_CHARS = 128;

    /** A meeting, not a conference: enough for a room and its apologies. */
    private const MAX_PARTICIPANTS = 200;
    private const MAX_LIST_ITEMS = 100;
    private const MAX_ITEM_CHARS = 500;
    private const MAX_SUMMARY_CHARS = 20000;

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function get(Identity $identity, string $noteId): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: self::NOTE_COLUMNS,
        );

        return self::present($noteId, $this->row($noteId));
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Create or update the meeting attached to a note.
     *
     * PATCH semantics: a field that is absent from the body is left as it is,
     * and a field sent as `null` is cleared. That distinction is why every
     * check below is on `array_key_exists` rather than on emptiness — a client
     * clearing the location and a client not mentioning it are different acts.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsert(Identity $identity, string $noteId, array $input): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: self::NOTE_COLUMNS,
        );

        return Connection::transaction(function () use ($identity, $noteId, $input): array {
            $existing = $this->row($noteId);
            $values = $this->changes($input, $existing);

            // Inside the transaction, so the check and the claim are one act.
            foreach (array_keys(self::EXTERNAL_IDS) as $column) {
                if (($values[$column] ?? null) !== null) {
                    $this->assertExternalIdAvailable($identity, $noteId, $column, (string) $values[$column]);
                }
            }

            if ($values === []) {
                // Nothing recognised in the body. A no-op PATCH must not bring
                // a meeting row into existence any more than a GET does.
                return self::present($noteId, $existing);
            }

            $columns = array_keys($values);

            Connection::execute(
                'INSERT INTO note_meetings (note_id, ' . implode(', ', $columns) . ')
                 VALUES (:note_id, ' . implode(', ', array_map(
                    static fn (string $column): string => self::WRITABLE[$column],
                    $columns,
                 )) . ')
                 ON CONFLICT (note_id) DO UPDATE SET '
                    . implode(', ', array_map(
                        static fn (string $column): string => $column . ' = EXCLUDED.' . $column,
                        $columns,
                    ))
                    . ', updated_at = now()',
                $values + ['note_id' => $noteId],
            );

            return self::present($noteId, $this->row($noteId));
        });
    }

    /**
     * The note a calendar event or a Connect meeting is already attached to.
     *
     * Used by the integrations to keep one external event from being linked to
     * two notes, and to route an inbound recording to the note that asked for
     * it. Not permission-filtered on purpose — it answers "is this id taken?",
     * and every caller checks the note it gets back before writing to it.
     */
    public function noteIdForExternalId(
        string $column,
        string $externalId,
        bool $includeTrashed = false,
    ): ?string {
        if (!in_array($column, ['calendar_event_id', 'connect_meeting_id'], true)) {
            throw new \InvalidArgumentException('Unknown external id column.');
        }

        // Two questions, one query, and they want different answers about a
        // note in the Trash.
        //
        // *Routing* a recording skips it: a trashed note is not where anyone
        // expects today's call to land.
        //
        // *Claiming* the id must not, and this is the subtle half. Trash is a
        // soft delete — `NotesService::trash()` sets `deleted_at` and nothing
        // else, and Restore is one click. If the guard cannot see the trashed
        // holder, a second note claims the id, the first is restored, and two
        // notes hold it with the recording going to whichever was edited last.
        // Which is the race the exclusive claim exists to prevent, reached the
        // long way round.
        $liveOnly = $includeTrashed ? '' : ' AND n.deleted_at IS NULL';

        $row = Connection::selectOne(
            'SELECT m.note_id FROM note_meetings m
             JOIN notes n ON n.id = m.note_id' . $liveOnly . '
             WHERE m.' . $column . ' = :external_id
             ORDER BY m.updated_at DESC
             LIMIT 1',
            ['external_id' => $externalId],
        );

        return $row === null ? null : (string) $row['note_id'];
    }

    /**
     * Refuse an external id that is already some other note's.
     *
     * The one guard behind every path that claims one — the hand-typed
     * `PATCH /notes/{id}/meeting` as much as
     * {@see \Aicountly\Api\Integrations\ConnectIntegrationService::linkMeeting()}
     * — because a rule enforced only in the integration is not enforced at all:
     * the endpoint is what a browser can reach.
     *
     * The other note's id is returned **only when the caller can already see
     * that note**. "Another note has this" is a fact they need in order to act;
     * which note it is, in someone else's account, is not.
     */
    public function assertExternalIdAvailable(
        Identity $identity,
        string $noteId,
        string $column,
        string $externalId,
    ): void {
        [$code, $message] = self::EXTERNAL_IDS[$column]
            ?? throw new \InvalidArgumentException('Unknown external id column.');

        $holder = $this->noteIdForExternalId($column, $externalId, includeTrashed: true);
        if ($holder === null || $holder === $noteId) {
            return;
        }

        throw new ApiException(
            409,
            $code,
            $message,
            $this->permissions->roleFor($identity, $holder) === null ? [] : ['note_id' => $holder],
        );
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Turn a request body into the columns it is allowed to write.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed> column => bound value
     */
    private function changes(array $input, ?array $existing): array
    {
        $values = [];

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = self::timestamp($input[$field], $field);
            }
        }
        $this->assertOrdered($values, $existing);

        if (array_key_exists('timezone', $input)) {
            $values['timezone'] = self::timezone($input['timezone']);
        }
        if (array_key_exists('location', $input)) {
            $values['location'] = self::text($input['location'], 300);
        }
        foreach (array_keys(self::EXTERNAL_IDS) as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = self::externalId($input[$field], $field);
            }
        }

        if (array_key_exists('participants', $input)) {
            $values['participants'] = self::encode(self::participants(
                $input['participants'],
                self::decodeList($existing['participants'] ?? null),
            ));
        }
        if (array_key_exists('agenda', $input)) {
            $values['agenda'] = self::encode(self::agenda($input['agenda']));
        }
        if (array_key_exists('decisions', $input)) {
            $values['decisions'] = self::encode(self::decisions($input['decisions']));
        }

        if (array_key_exists('summary', $input)) {
            $values['summary'] = self::text($input['summary'], self::MAX_SUMMARY_CHARS);
            // A person wrote this, so no model may claim it. Pulse writes the
            // pair together through its own path and is unaffected.
            $values['summary_model'] = null;
            $values['summary_at'] = $values['summary'] === null ? null : Clock::iso();
        }

        return $values;
    }

    /**
     * A meeting that ends before it starts is a typo, not a meeting.
     *
     * Checked against whichever end the request did not send, so correcting one
     * of the two against a stored value still fails when it should.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $existing
     */
    private function assertOrdered(array $values, ?array $existing): void
    {
        $startsAt = array_key_exists('starts_at', $values)
            ? $values['starts_at']
            : ($existing['starts_at'] ?? null);
        $endsAt = array_key_exists('ends_at', $values)
            ? $values['ends_at']
            : ($existing['ends_at'] ?? null);

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if (strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw ApiException::validation(['ends_at' => 'A meeting cannot end before it starts.']);
        }
    }

    /**
     * The people in the room.
     *
     * Identity is `contact_id` where there is one, and the email address where
     * there is not. A name is never an identity: it is the label a client
     * renders, cached here so a participant list does not need a round trip to
     * Contacts to be readable.
     *
     * Two consequences, both deliberate:
     *
     *   - An incoming participant that carries no contact id but matches a
     *     stored one by email **inherits the stored id**. Editing a spelling in
     *     the UI must not quietly unlink someone from Contacts.
     *   - Two participants with the same display name and no shared id or email
     *     stay two participants.
     *
     * @param array<int, array<string, mixed>> $existing
     * @return array<int, array<string, mixed>>
     */
    private static function participants(mixed $value, array $existing): array
    {
        if (!is_array($value)) {
            throw ApiException::validation(['participants' => 'Send participants as a list.']);
        }

        $knownByEmail = [];
        foreach ($existing as $participant) {
            $email = strtolower(trim((string) ($participant['email'] ?? '')));
            $contactId = trim((string) ($participant['contact_id'] ?? ''));
            if ($email !== '' && $contactId !== '') {
                $knownByEmail[$email] = $contactId;
            }
        }

        $people = [];
        foreach (array_slice(array_values($value), 0, self::MAX_PARTICIPANTS) as $index => $entry) {
            if (!is_array($entry)) {
                // A bare string is a name, which is exactly what must not be
                // treated as an identity — but it is still a person in a room.
                $entry = is_scalar($entry) ? ['name' => (string) $entry] : [];
            }

            $name = self::text($entry['name'] ?? null, 200);
            $email = self::email($entry['email'] ?? null);
            $contactId = self::text($entry['contact_id'] ?? null, 128);
            $role = self::text($entry['role'] ?? null, 60);

            if ($contactId === null && $email !== null) {
                $contactId = $knownByEmail[$email] ?? null;
            }
            if ($name === null && $email === null && $contactId === null) {
                continue;
            }

            $key = $contactId !== null
                ? 'contact:' . $contactId
                : ($email !== null ? 'email:' . $email : 'row:' . $index);

            // First occurrence wins, so a duplicated row cannot overwrite the
            // one that carried the contact id.
            $people[$key] ??= [
                'contact_id' => $contactId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ];
        }

        return array_values($people);
    }

    /** @return array<int, string> */
    private static function agenda(mixed $value): array
    {
        if (!is_array($value)) {
            throw ApiException::validation(['agenda' => 'Send the agenda as a list.']);
        }

        $items = [];
        foreach (array_slice(array_values($value), 0, self::MAX_LIST_ITEMS) as $entry) {
            $text = self::itemText($entry);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * What was decided, and by whom where anyone said.
     *
     * An object rather than a string because Pulse writes this column too, and
     * a model asked for minutes returns an owner alongside the decision often
     * enough that flattening it here would throw the useful half away.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function decisions(mixed $value): array
    {
        if (!is_array($value)) {
            throw ApiException::validation(['decisions' => 'Send decisions as a list.']);
        }

        $decisions = [];
        foreach (array_slice(array_values($value), 0, self::MAX_LIST_ITEMS) as $entry) {
            $text = self::itemText($entry);
            if ($text === null) {
                continue;
            }
            $decisions[] = [
                'text' => $text,
                'decided_by' => is_array($entry)
                    ? self::text($entry['decided_by'] ?? $entry['owner'] ?? null, 200)
                    : null,
            ];
        }

        return $decisions;
    }

    /** The text of a list entry, whether it arrived as a string or an object. */
    private static function itemText(mixed $entry): ?string
    {
        if (is_scalar($entry)) {
            return self::text($entry, self::MAX_ITEM_CHARS);
        }
        if (!is_array($entry)) {
            return null;
        }

        return self::text(
            $entry['text'] ?? $entry['title'] ?? $entry['decision'] ?? null,
            self::MAX_ITEM_CHARS,
        );
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw ApiException::validation(['meeting' => 'That field must be text.']);
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max);
    }

    /**
     * An id belonging to Calendar or Connect.
     *
     * Refused when it is too long rather than trimmed to fit: a truncated id
     * still points at *something*, and the something is another meeting. The
     * same reasoning as {@see \Aicountly\Api\Domain\Links\EntityLinkService},
     * and here it is load-bearing — a shortened id could be made to collide
     * with an id another note already holds.
     */
    private static function externalId(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw ApiException::validation([$field => 'That id must be text.']);
        }
        $id = trim((string) $value);
        if ($id === '') {
            return null;
        }
        if (mb_strlen($id, 'UTF-8') > self::MAX_EXTERNAL_ID_CHARS) {
            throw ApiException::validation([$field => 'That id is too long to be one of ours.']);
        }

        return $id;
    }

    private static function email(mixed $value): ?string
    {
        $email = self::text($value, 320);
        if ($email === null) {
            return null;
        }
        $email = strtolower($email);

        // Stored, not verified: a participant typed in by hand is still a
        // participant. Rejecting only what is definitely not an address keeps
        // the field useful without pretending it was validated against a
        // mailbox.
        if (!str_contains($email, '@')) {
            throw ApiException::validation(['participants' => 'A participant email needs an @.']);
        }

        return $email;
    }

    private static function timestamp(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw ApiException::validation([$field => 'Use an ISO-8601 date and time.']);
        }

        try {
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            throw ApiException::validation([$field => 'Use an ISO-8601 date and time.']);
        }
    }

    /**
     * The IANA zone the meeting was held in.
     *
     * Checked against the tz database rather than handed to `DateTimeZone`,
     * which also accepts abbreviations like `EST` and fixed offsets. Neither
     * knows when the clocks change, so "09:00 in Kolkata" stored against one
     * would render an hour out twice a year.
     */
    private static function timezone(mixed $value): ?string
    {
        $name = self::text($value, 64);
        if ($name === null) {
            return null;
        }

        static $identifiers = null;
        $identifiers ??= array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL));

        if (!isset($identifiers[$name])) {
            throw ApiException::validation([
                'timezone' => sprintf('`%s` is not an IANA time zone name such as Asia/Kolkata.', $name),
            ]);
        }

        return $name;
    }

    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function row(string $noteId): ?array
    {
        return Connection::selectOne(
            'SELECT * FROM note_meetings WHERE note_id = :note_id',
            ['note_id' => $noteId],
        );
    }

    /**
     * Row → API resource, with the same shape whether the row exists or not.
     *
     * `exists` is what tells a client "this note has never had a meeting"
     * without making the absence a 404: the panel renders empty and the first
     * PATCH creates the row.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private static function present(string $noteId, ?array $row): array
    {
        $row ??= [];

        return [
            'note_id' => $noteId,
            'exists' => $row !== [],
            'starts_at' => self::iso($row['starts_at'] ?? null),
            'ends_at' => self::iso($row['ends_at'] ?? null),
            'timezone' => isset($row['timezone']) && $row['timezone'] !== null ? (string) $row['timezone'] : null,
            'location' => isset($row['location']) && $row['location'] !== null ? (string) $row['location'] : null,
            'calendar_event_id' => isset($row['calendar_event_id']) && $row['calendar_event_id'] !== null
                ? (string) $row['calendar_event_id']
                : null,
            'connect_meeting_id' => isset($row['connect_meeting_id']) && $row['connect_meeting_id'] !== null
                ? (string) $row['connect_meeting_id']
                : null,
            'participants' => self::participantsOut(self::decodeList($row['participants'] ?? null)),
            'agenda' => self::agendaOut(self::decodeList($row['agenda'] ?? null)),
            // Normalised on the way out as well as in, because Pulse writes
            // this column too and a client should not have to handle two
            // shapes depending on who produced the minutes.
            'decisions' => self::decisionsOut(self::decodeList($row['decisions'] ?? null)),
            'summary' => isset($row['summary']) && $row['summary'] !== null ? (string) $row['summary'] : null,
            'summary_model' => isset($row['summary_model']) && $row['summary_model'] !== null
                ? (string) $row['summary_model']
                : null,
            'summary_at' => self::iso($row['summary_at'] ?? null),
            'created_at' => self::iso($row['created_at'] ?? null),
            'updated_at' => self::iso($row['updated_at'] ?? null),
        ];
    }

    /**
     * Rendering a stored row, which is not the same job as validating a request.
     *
     * Everything below reads with {@see softText}, which drops what it cannot
     * render, rather than with {@see text}, which raises a 422. That difference
     * is the whole point of these three methods existing separately from the
     * validators above.
     *
     * `note_meetings.decisions` is written by Pulse as well as by this service
     * — {@see \Aicountly\Api\Domain\Ai\NotesAIService} stores the model's own
     * JSON — so a decision can arrive with an object where a string belongs.
     * Validating that on the way *out* made the whole meeting unreadable: `GET`
     * answered 422, and so did every `PATCH`, which left the note with no way
     * to correct the row that caused it. A read is not the place to discover
     * that something else wrote badly; it renders what it can and says nothing
     * about the rest.
     *
     * @param array<int, mixed> $participants
     * @return array<int, array<string, mixed>>
     */
    private static function participantsOut(array $participants): array
    {
        $out = [];
        foreach (array_slice(array_values($participants), 0, self::MAX_PARTICIPANTS) as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $contactId = self::softText($participant['contact_id'] ?? null, 128);
            $out[] = [
                'contact_id' => $contactId,
                'name' => self::softText($participant['name'] ?? null, 200),
                'email' => self::softText($participant['email'] ?? null, 320),
                'role' => self::softText($participant['role'] ?? null, 60),
                // The one thing a client must not infer from the name.
                'is_linked' => $contactId !== null,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, string>
     */
    private static function agendaOut(array $items): array
    {
        $out = [];
        foreach (array_slice(array_values($items), 0, self::MAX_LIST_ITEMS) as $entry) {
            $text = self::softItemText($entry);
            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private static function decisionsOut(array $items): array
    {
        $out = [];
        foreach (array_slice(array_values($items), 0, self::MAX_LIST_ITEMS) as $entry) {
            $text = self::softItemText($entry);
            if ($text === null) {
                continue;
            }
            $out[] = [
                'text' => $text,
                'decided_by' => is_array($entry)
                    ? self::softText($entry['decided_by'] ?? $entry['owner'] ?? null, 200)
                    : null,
            ];
        }

        return $out;
    }

    /** The renderable text of a stored list entry, or null. */
    private static function softItemText(mixed $entry): ?string
    {
        if (is_array($entry)) {
            return self::softText(
                $entry['text'] ?? $entry['title'] ?? $entry['decision'] ?? null,
                self::MAX_ITEM_CHARS,
            );
        }

        return self::softText($entry, self::MAX_ITEM_CHARS);
    }

    /** {@see text}, but a value that cannot be rendered is dropped, not refused. */
    private static function softText(mixed $value, int $max): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max);
    }

    /** @return array<int, mixed> */
    private static function decodeList(mixed $value): array
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
