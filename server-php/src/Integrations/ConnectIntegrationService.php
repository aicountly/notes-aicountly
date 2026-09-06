<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Meetings\MeetingService;
use Aicountly\Api\Domain\Meetings\TranscriptService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;

/**
 * The one place this API talks to AICOUNTLY Connect.
 *
 * Connect runs the call; Notes keeps the record of it. Two directions, and they
 * are not symmetrical:
 *
 *   - **Outbound**, {@see linkMeeting()}: a person says "these are the minutes
 *     of that call". The meeting is fetched on their own ses_key, so Connect
 *     decides whether they were in it, and the note keeps the meeting **id**.
 *   - **Inbound**, {@see ingestRecording()}: Connect finishes processing a
 *     recording and hands over the transcript. This is the path a webhook will
 *     call. It carries no {@see Identity} because there is no user behind it,
 *     so it grants nothing on its own: the only note it can write to is one
 *     whose `connect_meeting_id` a person with edit rights already set. An
 *     unrecognised meeting id is a 404, not a new note.
 *
 * There is no route to `ingestRecording()` in {@see \Aicountly\Api\Routes}
 * because a webhook needs a signature check that belongs to whatever endpoint
 * exposes it, and inventing that contract before Connect defines it would be
 * guessing at a security boundary. What is here is the part that can be written
 * honestly today: the mapping, the note lookup, and the transcript write.
 *
 * With {@see Features::CONNECT} off — the default — both directions answer
 * FEATURE_DISABLED. A deployment that never configured Connect does not quietly
 * accept transcripts claiming to come from it.
 */
final class ConnectIntegrationService
{
    /**
     * Connect's own path.
     *
     * **Must be confirmed against the Connect API before the flag is switched
     * on.** One meeting by id is all this product asks for.
     */
    private const MEETING_PATH = '/meetings/';

    private const SERVICE = 'connect';

    public function __construct(
        private readonly AicountlyClient $client = new AicountlyClient(
            self::SERVICE,
            Features::CONNECT,
            // Connect's product_code is `chat`; SiblingApi maps either spelling
            // onto connect.aicountly.com.
            'connect',
        ),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly TranscriptService $transcripts = new TranscriptService(),
    ) {
    }

    /**
     * Make this note the record of a Connect meeting.
     *
     * Written through {@see MeetingService::upsert}, so the caller's rights on
     * the note are checked the same way a hand-typed edit is, and the meeting
     * id is what a later recording is routed by.
     *
     * @return array<string, mixed> The meeting resource.
     */
    public function linkMeeting(Identity $identity, string $sesKey, string $noteId, string $meetingId): array
    {
        Features::require(Features::CONNECT);

        $meeting = $this->meeting($sesKey, $meetingId);

        $alreadyOn = $this->meetings->noteIdForExternalId('connect_meeting_id', $meetingId);
        if ($alreadyOn !== null && $alreadyOn !== $noteId) {
            // The recording will be delivered once, to one note. Silently
            // linking a second would leave a note that never receives one.
            throw new ApiException(
                409,
                'CONNECT_MEETING_ALREADY_LINKED',
                'Another note is already the record of this call.',
                ['note_id' => $alreadyOn],
            );
        }

        $fields = ['connect_meeting_id' => $meetingId];
        foreach (['starts_at', 'ends_at'] as $field) {
            if ($meeting[$field] !== null) {
                $fields[$field] = $meeting[$field];
            }
        }
        if ($meeting['participants'] !== []) {
            $fields['participants'] = $meeting['participants'];
        }

        return $this->meetings->upsert($identity, $noteId, $fields);
    }

    /**
     * Take delivery of a finished recording.
     *
     * The note is found by the meeting id someone already linked; there is no
     * other way in, and no note is created. The transcript lands in
     * `note_transcripts` beside any correction already made to a previous
     * delivery — {@see TranscriptService::ingest()} replaces the machine text
     * and leaves `edited_text` alone.
     *
     * The recording itself is not downloaded. Connect stores the media and
     * enforces who may play it; copying the bytes here would fork that decision
     * and put a second copy of a private call in this product's storage.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> The transcript resource.
     */
    public function ingestRecording(array $payload): array
    {
        Features::require(Features::CONNECT);

        $meetingId = trim((string) (
            $payload['connect_meeting_id'] ?? $payload['meeting_id'] ?? $payload['id'] ?? ''
        ));
        if ($meetingId === '') {
            throw ApiException::validation([
                'connect_meeting_id' => 'A recording must say which meeting it belongs to.',
            ]);
        }

        $noteId = $this->meetings->noteIdForExternalId('connect_meeting_id', $meetingId);
        if ($noteId === null) {
            // Nobody asked for this. Creating a note here would let an inbound
            // call write into this database on its own say-so.
            Logger::warn('connect.recording_for_unlinked_meeting', ['meeting_id' => $meetingId]);
            throw ApiException::notFound('A note for that meeting');
        }

        $transcript = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : $payload;

        return $this->transcripts->ingest($noteId, [
            'provider' => self::SERVICE,
            'model' => $transcript['model'] ?? null,
            'language' => $transcript['language'] ?? null,
            'status' => $transcript['status'] ?? 'completed',
            'text' => $transcript['text'] ?? '',
            'segments' => $transcript['segments'] ?? [],
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * One meeting, in this product's terms.
     *
     * Read through the aliases these APIs use in practice; **the exact contract
     * must be confirmed against Connect before the flag is switched on.**
     *
     * @return array<string, mixed>
     */
    private function meeting(string $sesKey, string $meetingId): array
    {
        $data = $this->client->send(
            'GET',
            self::MEETING_PATH . AicountlyClient::segment($meetingId),
            $sesKey,
        );

        $meeting = is_array($data['meeting'] ?? null) ? $data['meeting'] : $data;

        return [
            'starts_at' => self::instant(
                $meeting['starts_at'] ?? $meeting['started_at'] ?? $meeting['start_time'] ?? null,
            ),
            'ends_at' => self::instant(
                $meeting['ends_at'] ?? $meeting['ended_at'] ?? $meeting['end_time'] ?? null,
            ),
            'participants' => self::participants(
                $meeting['participants'] ?? $meeting['attendees'] ?? [],
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function participants(mixed $participants): array
    {
        if (!is_array($participants)) {
            return [];
        }

        $people = [];
        foreach (array_values($participants) as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $people[] = [
                // Only a real Contacts id is carried across. A Connect user id
                // is that product's own identifier and is not a contact.
                'contact_id' => self::text($participant['contact_id'] ?? null, 128),
                'name' => self::text($participant['name'] ?? $participant['display_name'] ?? null, 200),
                'email' => self::text($participant['email'] ?? null, 320),
                'role' => self::text($participant['role'] ?? null, 60),
            ];
        }

        return $people;
    }

    private static function instant(mixed $value): ?string
    {
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
