<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Meetings\MeetingService;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Domain\Notes\NotesService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;

/**
 * The one place this API talks to AICOUNTLY Calendar.
 *
 * Calendar owns the event. Notes borrows two things from it — when the meeting
 * is and who was invited — and stores a **reference**, `calendar_event_id`, not
 * a copy. The event moving an hour later does not silently rewrite anyone's
 * minutes, and deleting the note does not delete the meeting.
 *
 * The event is fetched on the caller's own ses_key, which is the whole
 * authorisation model: Calendar decides whether this person may see that event.
 * An event id is never treated as a capability, so pasting somebody else's id
 * into `link` gets its 404 from Calendar rather than a note quietly filled with
 * their meeting.
 *
 * With {@see Features::CALENDAR} off — the default — every method here answers
 * FEATURE_DISABLED. The link is still *clearable* without Calendar, through
 * `PATCH /notes/{id}/meeting` with `calendar_event_id: null`, so switching the
 * integration off can never strand a note with a link it cannot remove.
 */
final class CalendarIntegrationService
{
    /**
     * Calendar's own path.
     *
     * **Must be confirmed against the Calendar API before the flag is switched
     * on.** One event by id is the only thing this product asks for.
     */
    private const EVENT_PATH = '/events/';

    public function __construct(
        private readonly AicountlyClient $client = new AicountlyClient(
            'calendar',
            Features::CALENDAR,
            'calendar',
        ),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly NotesService $notes = new NotesService(),
    ) {
    }

    /**
     * Attach an existing note to a calendar event.
     *
     * The event's time, place and attendees are written through
     * {@see MeetingService::upsert}, so exactly the same validation applies as
     * when a person types them in — an integration is not a way around the
     * rules of the domain it writes to. Permission on the note is checked
     * there too, on the caller's identity, not on the fact that Calendar
     * answered.
     *
     * @return array<string, mixed> The meeting resource.
     */
    public function linkEvent(Identity $identity, string $sesKey, string $noteId, string $eventId): array
    {
        Features::require(Features::CALENDAR);

        $event = $this->event($sesKey, $eventId);

        // Two sets of minutes for one meeting is nearly always a mistake, and
        // the useful answer is where the first set is — when the caller can see
        // it. The rule itself lives in MeetingService, which is also where the
        // hand-typed `PATCH /notes/{id}/meeting` goes through it.
        $this->meetings->assertExternalIdAvailable($identity, $noteId, 'calendar_event_id', $eventId);

        return $this->meetings->upsert($identity, $noteId, $this->meetingFields($event) + [
            'calendar_event_id' => $eventId,
        ]);
    }

    /**
     * Detach a note from its calendar event.
     *
     * Only the reference goes. The times, participants and agenda already
     * written stay, because they are what the meeting *was* — re-deriving the
     * note from an event that has since been cancelled would erase the record
     * of a meeting that happened.
     *
     * @return array<string, mixed> The meeting resource.
     */
    public function unlinkEvent(Identity $identity, string $noteId): array
    {
        Features::require(Features::CALENDAR);

        return $this->meetings->upsert($identity, $noteId, ['calendar_event_id' => null]);
    }

    /**
     * Start a meeting note from an event.
     *
     * Idempotent on the event: if a note is already the record of this meeting,
     * that note comes back rather than a second one. A calendar sync that runs
     * twice, or a user who taps twice, must not split one meeting's minutes
     * across two notes.
     *
     * @return array{note: array<string, mixed>, meeting: array<string, mixed>}
     */
    public function createMeetingNoteFromEvent(Identity $identity, string $sesKey, string $eventId): array
    {
        Features::require(Features::CALENDAR);

        $event = $this->event($sesKey, $eventId);

        $existingNoteId = $this->meetings->noteIdForExternalId('calendar_event_id', $eventId);
        if ($existingNoteId !== null) {
            return [
                'note' => $this->notes->get($identity, $existingNoteId),
                'meeting' => $this->meetings->get($identity, $existingNoteId),
            ];
        }

        $note = $this->notes->create($identity, [
            'title' => $event['title'] ?? 'Meeting',
            'note_type' => 'meeting',
            // The description is a starting point for the minutes, not the
            // minutes: it goes into the document as text the user then writes
            // over, while the structured half goes to note_meetings.
            'document' => NoteDocument::fromPlainText((string) ($event['description'] ?? '')),
            'source' => 'calendar',
        ]);

        $meeting = $this->meetings->upsert($identity, (string) $note['id'], $this->meetingFields($event) + [
            'calendar_event_id' => $eventId,
        ]);

        return ['note' => $note, 'meeting' => $meeting];
    }

    // -----------------------------------------------------------------------

    /**
     * One event, in this product's terms.
     *
     * The field names are read through the aliases these APIs use in practice
     * — **the exact contract must be confirmed against Calendar before the flag
     * is switched on.** An event this server cannot make sense of is an
     * upstream failure, not an empty meeting: writing blanks over someone's
     * notes because a payload changed shape would be worse than saying so.
     *
     * @return array<string, mixed>
     */
    private function event(string $sesKey, string $eventId): array
    {
        $data = $this->client->send(
            'GET',
            self::EVENT_PATH . AicountlyClient::segment($eventId),
            $sesKey,
        );

        $event = is_array($data['event'] ?? null) ? $data['event'] : $data;

        $starts = self::instant($event['starts_at'] ?? $event['start'] ?? $event['start_time'] ?? null);
        if ($starts === null) {
            throw ApiException::upstream('calendar', 'Calendar returned an event without a start time.');
        }

        return [
            'title' => self::text($event['title'] ?? $event['summary'] ?? $event['name'] ?? null, 500),
            'description' => self::text($event['description'] ?? $event['notes'] ?? null, 20000) ?? '',
            'starts_at' => $starts,
            'ends_at' => self::instant($event['ends_at'] ?? $event['end'] ?? $event['end_time'] ?? null),
            'timezone' => self::text($event['timezone'] ?? $event['time_zone'] ?? null, 64),
            'location' => self::text($event['location'] ?? $event['venue'] ?? null, 300),
            'participants' => self::attendees(
                $event['attendees'] ?? $event['participants'] ?? $event['invitees'] ?? [],
            ),
        ];
    }

    /**
     * The event fields that belong in `note_meetings`.
     *
     * Only what the event actually carried: a null returned for a field
     * Calendar did not send would clear whatever the user had typed there.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function meetingFields(array $event): array
    {
        $fields = ['starts_at' => $event['starts_at']];

        foreach (['ends_at', 'timezone', 'location'] as $field) {
            if (($event[$field] ?? null) !== null) {
                $fields[$field] = $event[$field];
            }
        }
        if ($event['participants'] !== []) {
            $fields['participants'] = $event['participants'];
        }

        return $fields;
    }

    /**
     * Invitees, as participants.
     *
     * `contact_id` is carried across only when Calendar supplies one. A
     * participant with an email and no contact id is still a participant — it
     * is a name-and-address, not a link into the directory, and the difference
     * is exactly what {@see MeetingService} refuses to blur.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function attendees(mixed $attendees): array
    {
        if (!is_array($attendees)) {
            return [];
        }

        $participants = [];
        foreach (array_values($attendees) as $attendee) {
            if (is_string($attendee)) {
                // A bare string in an attendee list is an address.
                $participants[] = ['email' => $attendee];
                continue;
            }
            if (!is_array($attendee)) {
                continue;
            }
            $participants[] = [
                'contact_id' => self::text($attendee['contact_id'] ?? $attendee['contactId'] ?? null, 128),
                'name' => self::text($attendee['name'] ?? $attendee['display_name'] ?? null, 200),
                'email' => self::text($attendee['email'] ?? null, 320),
                'role' => self::text($attendee['role'] ?? $attendee['response_status'] ?? null, 60),
            ];
        }

        return $participants;
    }

    private static function instant(mixed $value): ?string
    {
        // Some calendars wrap the moment in an object with the zone beside it.
        if (is_array($value)) {
            $value = $value['date_time'] ?? $value['dateTime'] ?? $value['date'] ?? null;
        }
        if ($value === null || !is_scalar($value) || trim((string) $value) === '') {
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

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max);
    }
}
