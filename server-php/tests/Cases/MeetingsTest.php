<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Features;
use Aicountly\Api\Integrations\CalendarIntegrationService;
use Aicountly\Api\Integrations\ConnectIntegrationService;
use Aicountly\Api\Integrations\ContactsIntegrationService;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Meetings and transcripts over the real router.
 *
 * Four properties carry the weight here:
 *
 *   - **Reading creates nothing.** Opening the meeting panel on a note that has
 *     never had one must not write a row, or the table grows by one for every
 *     note anyone ever looked at.
 *   - **A correction never touches the source.** `text` is what the provider
 *     heard and stays byte-for-byte; the edit lives in `edited_text` and can be
 *     taken back. This is the test that would fail if someone "simplified" the
 *     two columns into one.
 *   - **A name is not an identity.** A participant linked to Contacts keeps
 *     that id through a rename, and two people with the same display name stay
 *     two people.
 *   - **An unconfigured integration says so.** With Calendar, Contacts and
 *     Connect switched off — the default, and the state of this suite — every
 *     one of them answers 503 and writes nothing at all.
 */
final class MeetingsTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Meetings';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function note(string $title = 'Acme kickoff'): array
    {
        return $this->alice->post('/notes', [
            'title' => $title,
            'note_type' => 'meeting',
            'document' => Support::doc('Attendees to confirm.'),
        ])['body']['data'];
    }

    private function share(string $noteId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, \'user-b\', :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'role' => $role],
        );
    }

    /** A transcript as the transcription handler would have written it. */
    private function transcript(string $noteId, string $text, ?string $attachmentId = null): string
    {
        $id = Uuid::v4();
        Connection::execute(
            "INSERT INTO note_transcripts
                (id, note_id, attachment_id, provider, model, language, status, text, segments)
             VALUES (:id, :note, :attachment, 'remote', 'whisper-1', 'en', 'completed', :text, :segments::jsonb)",
            [
                'id' => $id,
                'note' => $noteId,
                'attachment' => $attachmentId,
                'text' => $text,
                'segments' => (string) json_encode([
                    ['start' => 0.0, 'end' => 4.5, 'speaker' => 'S1', 'text' => $text],
                ]),
            ],
        );

        return $id;
    }

    private function audioAttachment(string $noteId, string $extracted): string
    {
        $id = Uuid::v4();
        Connection::execute(
            "INSERT INTO note_attachments
                (id, note_id, storage_provider, storage_key, filename, mime_type,
                 byte_size, kind, extracted_text, created_by)
             VALUES (:id, :note, 'local', :key, 'call.m4a', 'audio/mp4', 2048, 'audio', :text, 'user-a')",
            ['id' => $id, 'note' => $noteId, 'key' => 'notes/' . $noteId . '/' . $id, 'text' => $extracted],
        );

        return $id;
    }

    private function derivedText(string $noteId): string
    {
        $row = Connection::selectOne('SELECT derived_text FROM notes WHERE id = :id', ['id' => $noteId]);

        return (string) ($row['derived_text'] ?? '');
    }

    // -- Reading ------------------------------------------------------------

    public function testReadingAMeetingCreatesNothing(): void
    {
        $note = $this->note();

        $meeting = $this->alice->get('/notes/' . $note['id'] . '/meeting');

        $this->assertSame(200, $meeting['status']);
        $this->assertFalse($meeting['body']['data']['exists']);
        $this->assertNull($meeting['body']['data']['starts_at']);
        $this->assertSame([], $meeting['body']['data']['participants']);

        $rows = Connection::select('SELECT note_id FROM note_meetings');
        $this->assertCount(0, $rows, 'a GET must not write a meeting row');
    }

    public function testAnEmptyPatchStillWritesNothing(): void
    {
        $note = $this->note();

        $this->assertSame(200, $this->alice->patch('/notes/' . $note['id'] . '/meeting', [])['status']);
        $this->assertCount(0, Connection::select('SELECT note_id FROM note_meetings'));
    }

    // -- Writing ------------------------------------------------------------

    public function testPatchUpsertsTheStructuredHalf(): void
    {
        $note = $this->note();

        $saved = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'starts_at' => '2030-03-04T09:30:00Z',
            'ends_at' => '2030-03-04T10:30:00Z',
            'timezone' => 'Asia/Kolkata',
            'location' => 'Room 3',
            'calendar_event_id' => 'evt-8891',
            'participants' => [
                ['contact_id' => 'contact-11', 'name' => 'Ravi Sharma', 'email' => 'Ravi@Acme.test', 'role' => 'chair'],
                ['name' => 'Guest'],
            ],
            'agenda' => ['Scope', 'Timeline', ''],
            'decisions' => [['text' => 'Ship in April', 'decided_by' => 'Ravi']],
        ]);

        $this->assertSame(200, $saved['status']);
        $meeting = $saved['body']['data'];

        $this->assertTrue($meeting['exists']);
        $this->assertSame('2030-03-04T09:30:00+00:00', $meeting['starts_at']);
        $this->assertSame('2030-03-04T10:30:00+00:00', $meeting['ends_at']);
        $this->assertSame('Asia/Kolkata', $meeting['timezone']);
        $this->assertSame('Room 3', $meeting['location']);
        $this->assertSame('evt-8891', $meeting['calendar_event_id']);
        $this->assertCount(2, $meeting['participants']);
        $this->assertSame('contact-11', $meeting['participants'][0]['contact_id']);
        // Addresses are folded to lower case so the same person typed twice is
        // matched on the next edit.
        $this->assertSame('ravi@acme.test', $meeting['participants'][0]['email']);
        $this->assertTrue($meeting['participants'][0]['is_linked']);
        $this->assertFalse($meeting['participants'][1]['is_linked']);
        // The blank agenda line is dropped rather than stored as an empty item.
        $this->assertSame(['Scope', 'Timeline'], $meeting['agenda']);
        $this->assertSame('Ship in April', $meeting['decisions'][0]['text']);
        $this->assertSame('Ravi', $meeting['decisions'][0]['decided_by']);

        // And it reads back the same way a fresh client would see it.
        $reread = $this->alice->get('/notes/' . $note['id'] . '/meeting')['body']['data'];
        $this->assertSame($meeting['participants'], $reread['participants']);
    }

    public function testFieldsNotSentAreLeftAlone(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'location' => 'Room 3',
            'participants' => [['contact_id' => 'contact-11', 'name' => 'Ravi']],
        ]);

        $updated = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'location' => 'Room 5',
        ])['body']['data'];

        $this->assertSame('Room 5', $updated['location']);
        $this->assertCount(1, $updated['participants'], 'an absent field must not be cleared');

        // …and a field sent as null is a deliberate clear.
        $cleared = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'location' => null,
        ])['body']['data'];
        $this->assertNull($cleared['location']);
        $this->assertCount(1, $cleared['participants']);
    }

    public function testAParticipantKeepsItsContactIdThroughARename(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'participants' => [
                ['contact_id' => 'contact-11', 'name' => 'R. Sharma', 'email' => 'ravi@acme.test'],
            ],
        ]);

        // The UI sends back a corrected display name and no contact id, which
        // is exactly the case where a name-keyed store would silently unlink
        // the person from Contacts.
        $updated = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'participants' => [
                ['name' => 'Ravi Sharma', 'email' => 'ravi@acme.test'],
            ],
        ])['body']['data'];

        $this->assertCount(1, $updated['participants']);
        $this->assertSame('contact-11', $updated['participants'][0]['contact_id']);
        $this->assertSame('Ravi Sharma', $updated['participants'][0]['name']);
        $this->assertTrue($updated['participants'][0]['is_linked']);
    }

    public function testTwoPeopleWithOneNameStayTwoPeople(): void
    {
        $note = $this->note();

        $meeting = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'participants' => [
                ['contact_id' => 'contact-11', 'name' => 'Ravi'],
                ['contact_id' => 'contact-42', 'name' => 'Ravi'],
                // The same contact twice is one person, whatever it is called.
                ['contact_id' => 'contact-11', 'name' => 'Ravi S'],
            ],
        ])['body']['data'];

        $this->assertCount(2, $meeting['participants']);
        $this->assertSame('contact-11', $meeting['participants'][0]['contact_id']);
        $this->assertSame('contact-42', $meeting['participants'][1]['contact_id']);
        $this->assertSame('Ravi', $meeting['participants'][0]['name'], 'the first row wins, ids and all');
    }

    public function testAMeetingCannotEndBeforeItStarts(): void
    {
        $note = $this->note();

        $refused = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'starts_at' => '2030-03-04T10:00:00Z',
            'ends_at' => '2030-03-04T09:00:00Z',
        ]);

        $this->assertSame(422, $refused['status']);
        $this->assertSame('VALIDATION_FAILED', $refused['body']['error']['code']);

        // Even when only one end is being corrected against a stored value.
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['starts_at' => '2030-03-04T10:00:00Z']);
        $this->assertSame(422, $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'ends_at' => '2030-03-04T08:00:00Z',
        ])['status']);
    }

    public function testTimeZonesMustBeRealOnes(): void
    {
        $note = $this->note();

        // An abbreviation does not know when the clocks change, so it is not a
        // time zone this column will accept.
        $refused = $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['timezone' => 'IST']);
        $this->assertSame(422, $refused['status']);

        $accepted = $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['timezone' => 'Europe/London']);
        $this->assertSame('Europe/London', $accepted['body']['data']['timezone']);
    }

    public function testAHumanEditedSummaryCarriesNoModel(): void
    {
        $note = $this->note();

        // As Pulse would have left it.
        Connection::execute(
            "INSERT INTO note_meetings (note_id, summary, summary_model, summary_at)
             VALUES (:note, 'Model minutes', 'gpt-4o-mini', now())",
            ['note' => $note['id']],
        );
        $this->assertSame('gpt-4o-mini', $this->alice->get('/notes/' . $note['id'] . '/meeting')['body']['data']['summary_model']);

        $edited = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'summary' => 'We agreed to start in April.',
        ])['body']['data'];

        $this->assertSame('We agreed to start in April.', $edited['summary']);
        $this->assertNull($edited['summary_model'], 'a person rewrote it, so no model may be credited');
        $this->assertNotNull($edited['summary_at']);
    }

    // -- Who may reach a meeting --------------------------------------------

    public function testAnotherUserCannotReachTheMeeting(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['location' => 'Room 3']);

        // 404, not 403: whether the note exists is itself not theirs to learn.
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/meeting')['status']);
        $this->assertSame(404, $this->bob->patch('/notes/' . $note['id'] . '/meeting', ['location' => 'Room 9'])['status']);
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/transcripts')['status']);

        $row = Connection::selectOne('SELECT location FROM note_meetings WHERE note_id = :id', ['id' => $note['id']]);
        $this->assertSame('Room 3', (string) $row['location']);
    }

    public function testAViewerReadsTheMeetingButCannotEditIt(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['location' => 'Room 3']);
        $this->share($note['id'], 'viewer');

        $read = $this->bob->get('/notes/' . $note['id'] . '/meeting');
        $this->assertSame(200, $read['status']);
        $this->assertSame('Room 3', $read['body']['data']['location']);

        $refused = $this->bob->patch('/notes/' . $note['id'] . '/meeting', ['location' => 'Room 9']);
        $this->assertSame(403, $refused['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $refused['body']['error']['code']);
    }

    // -- Transcripts --------------------------------------------------------

    public function testTranscriptsAreListedForTheirNote(): void
    {
        $note = $this->note();
        $this->transcript($note['id'], 'Ravi opened the call.');

        $listed = $this->alice->get('/notes/' . $note['id'] . '/transcripts');

        $this->assertSame(200, $listed['status']);
        $this->assertCount(1, $listed['body']['data']);
        $transcript = $listed['body']['data'][0];
        $this->assertSame('Ravi opened the call.', $transcript['text']);
        $this->assertSame('Ravi opened the call.', $transcript['display_text']);
        $this->assertNull($transcript['edited_text']);
        $this->assertFalse($transcript['is_edited']);
        $this->assertCount(1, $transcript['segments']);
    }

    public function testACorrectionLeavesTheProviderTextIntact(): void
    {
        $note = $this->note();
        $attachmentId = $this->audioAttachment($note['id'], 'Call with Mr Zeffrin about the retainer.');
        $transcriptId = $this->transcript($note['id'], 'Call with Mr Zeffrin about the retainer.', $attachmentId);

        $corrected = $this->alice->patch('/transcripts/' . $transcriptId, [
            'edited_text' => 'Call with Mr Zephyrine about the retainer.',
        ]);

        $this->assertSame(200, $corrected['status']);
        $data = $corrected['body']['data'];
        $this->assertSame('Call with Mr Zephyrine about the retainer.', $data['edited_text']);
        $this->assertSame('Call with Mr Zephyrine about the retainer.', $data['display_text']);
        $this->assertTrue($data['is_edited']);
        $this->assertSame('user-a', $data['edited_by']);
        $this->assertNotNull($data['edited_at']);

        // The provider's own words, and the timings that go with them, are
        // exactly where they were.
        $this->assertSame('Call with Mr Zeffrin about the retainer.', $data['text']);
        $row = Connection::selectOne(
            'SELECT text, segments FROM note_transcripts WHERE id = :id',
            ['id' => $transcriptId],
        );
        $this->assertSame('Call with Mr Zeffrin about the retainer.', (string) $row['text']);
        $this->assertContainsString('Zeffrin', (string) $row['segments']);
    }

    public function testACorrectionSurvivesTheAttachmentRollup(): void
    {
        $note = $this->note();
        $attachmentId = $this->audioAttachment($note['id'], 'Zeffrin');
        $transcriptId = $this->transcript($note['id'], 'Zeffrin', $attachmentId);

        $this->alice->patch('/transcripts/' . $transcriptId, ['edited_text' => 'Zephyrine']);

        // The correction is pushed into the column the background rollup reads,
        // so the next time a job rebuilds derived_text it agrees rather than
        // reverting the note to what the machine heard.
        $attachment = Connection::selectOne(
            'SELECT extracted_text FROM note_attachments WHERE id = :id',
            ['id' => $attachmentId],
        );
        $this->assertSame('Zephyrine', (string) $attachment['extracted_text']);
        $this->assertContainsString('Zephyrine', $this->derivedText($note['id']));
    }

    public function testACorrectionCanBeFoundBySearch(): void
    {
        $note = $this->note('Retainer call');
        $transcriptId = $this->transcript($note['id'], 'Discussed the Zeffrin retainer.');

        $before = $this->alice->get('/search/notes', ['q' => 'Zephyrine']);
        $this->assertCount(0, $before['body']['data']);

        $this->alice->patch('/transcripts/' . $transcriptId, [
            'edited_text' => 'Discussed the Zephyrine retainer.',
        ]);

        // A correction nobody can search for is a text box that does nothing.
        $after = $this->alice->get('/search/notes', ['q' => 'Zephyrine']);
        $this->assertCount(1, $after['body']['data']);
        $this->assertSame($note['id'], $after['body']['data'][0]['id']);
    }

    public function testACorrectionCanBeTakenBack(): void
    {
        $note = $this->note();
        $transcriptId = $this->transcript($note['id'], 'Zeffrin');
        $this->alice->patch('/transcripts/' . $transcriptId, ['edited_text' => 'Zephyrine']);

        $reverted = $this->alice->patch('/transcripts/' . $transcriptId, ['edited_text' => null])['body']['data'];

        $this->assertNull($reverted['edited_text']);
        $this->assertNull($reverted['edited_by']);
        $this->assertNull($reverted['edited_at']);
        $this->assertFalse($reverted['is_edited']);
        $this->assertSame('Zeffrin', $reverted['display_text']);
        $this->assertContainsString('Zeffrin', $this->derivedText($note['id']));
    }

    public function testTheProviderTextIsNotWritableThroughTheApi(): void
    {
        $note = $this->note();
        $transcriptId = $this->transcript($note['id'], 'Zeffrin');

        $refused = $this->alice->patch('/transcripts/' . $transcriptId, ['text' => 'Zephyrine']);

        $this->assertSame(422, $refused['status']);
        $row = Connection::selectOne('SELECT text FROM note_transcripts WHERE id = :id', ['id' => $transcriptId]);
        $this->assertSame('Zeffrin', (string) $row['text']);
    }

    public function testOnlyAnEditorMayCorrectATranscript(): void
    {
        $note = $this->note();
        $transcriptId = $this->transcript($note['id'], 'Zeffrin');

        // A stranger cannot even learn that the transcript exists.
        $this->assertSame(404, $this->bob->patch('/transcripts/' . $transcriptId, ['edited_text' => 'Mine now'])['status']);

        $this->share($note['id'], 'viewer');
        $refused = $this->bob->patch('/transcripts/' . $transcriptId, ['edited_text' => 'Mine now']);
        $this->assertSame(403, $refused['status']);

        $row = Connection::selectOne('SELECT edited_text FROM note_transcripts WHERE id = :id', ['id' => $transcriptId]);
        $this->assertNull($row['edited_text']);
    }

    // -- The external ids a recording is routed by ---------------------------

    /**
     * Regression: a Connect meeting id belongs to one note.
     *
     * `PATCH /notes/{id}/meeting` writes `connect_meeting_id` directly, and an
     * inbound recording is routed by that column alone — most recently updated
     * note wins. So before this was enforced on the write path, anyone could
     * put someone else's meeting id on a note of their own, touch it last, and
     * be handed the transcript of a call they were never in. The 409 the
     * integration raised was no defence: the endpoint went around it.
     */
    public function testAnotherNoteCannotClaimTheMeetingIdARecordingIsRoutedBy(): void
    {
        $alice = $this->note('Board call');
        $this->alice->patch('/notes/' . $alice['id'] . '/meeting', ['connect_meeting_id' => 'mtg-7']);

        $decoy = $this->bob->post('/notes', [
            'title' => 'Decoy',
            'document' => Support::doc('Nothing here.'),
        ])['body']['data'];

        $claim = $this->bob->patch('/notes/' . $decoy['id'] . '/meeting', ['connect_meeting_id' => 'mtg-7']);
        $this->assertSame(409, $claim['status']);
        $this->assertSame('CONNECT_MEETING_ALREADY_LINKED', $claim['body']['error']['code']);
        // Which note holds it is not Bob's to learn — he cannot see it.
        $this->assertFalse(
            isset($claim['body']['error']['details']['note_id']),
            'a note id Bob cannot open must not be handed to him',
        );

        // The claim wrote nothing, so the recording still goes where it was asked to.
        $this->assertNull(
            Connection::selectOne(
                'SELECT connect_meeting_id FROM note_meetings WHERE note_id = :id',
                ['id' => $decoy['id']],
            )['connect_meeting_id'] ?? null,
        );

        putenv('NOTES_CONNECT_ENABLED=true');
        try {
            $transcript = (new ConnectIntegrationService())->ingestRecording([
                'connect_meeting_id' => 'mtg-7',
                'transcript' => ['text' => 'The acquisition price is 4.2 crore.'],
            ]);
            $this->assertSame($alice['id'], $transcript['note_id'], 'the recording belongs to the note that asked');
        } finally {
            putenv('NOTES_CONNECT_ENABLED=false');
        }

        $this->assertCount(0, $this->bob->get('/notes/' . $decoy['id'] . '/transcripts')['body']['data']);
        $this->assertContainsString(
            '4.2 crore',
            $this->alice->get('/notes/' . $alice['id'] . '/transcripts')['body']['data'][0]['text'],
        );
    }

    /**
     * Trash is not a release of the id.
     *
     * Trash is a soft delete and Restore is one click, so a trashed note still
     * holds its meeting id. A guard that could not see the trashed holder let
     * a second note claim it — and then the first is restored, two notes hold
     * one id, and the recording goes to whichever was edited last. Which is
     * the race the exclusive claim exists to prevent, reached the long way
     * round.
     */
    public function testATrashedNoteStillHoldsItsMeetingId(): void
    {
        $note = $this->note('Board call');
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['connect_meeting_id' => 'mtg-42']);
        $this->assertSame(204, $this->alice->delete('/notes/' . $note['id'])['status']);

        $second = $this->note('Another call');
        $claim = $this->alice->patch('/notes/' . $second['id'] . '/meeting', [
            'connect_meeting_id' => 'mtg-42',
        ]);

        $this->assertSame(409, $claim['status']);
        $this->assertSame('CONNECT_MEETING_ALREADY_LINKED', $claim['body']['error']['code']);

        // Restored, and still the one and only holder.
        $this->alice->post('/notes/' . $note['id'] . '/restore');
        $this->assertSame(
            $note['id'],
            (string) Connection::selectOne(
                "SELECT note_id FROM note_meetings WHERE connect_meeting_id = 'mtg-42'",
            )['note_id'],
        );
    }

    /**
     * And the database says so too.
     *
     * The service refuses a second claim on every path that writes the column,
     * which is where the useful message lives. This is the floor underneath
     * it: an application guard is one forgotten INSERT away from not being a
     * guarantee, and what it guards is somebody else's private conversation.
     */
    public function testTheDatabaseItselfRefusesASecondHolder(): void
    {
        $note = $this->note('Board call');
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['connect_meeting_id' => 'mtg-99']);

        $other = $this->note('Another call');
        $threw = false;
        try {
            Connection::execute(
                "INSERT INTO note_meetings (note_id, connect_meeting_id) VALUES (:id, 'mtg-99')",
                ['id' => $other['id']],
            );
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a unique index, not just a service that remembers to check');
    }

    public function testANoteMayKeepItsOwnExternalIdAndTheOwnerIsToldWhereTheOtherIs(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['calendar_event_id' => 'evt-9']);

        // Re-sending the same id to the same note is not a conflict with itself.
        $again = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'calendar_event_id' => 'evt-9',
            'location' => 'Room 3',
        ]);
        $this->assertSame(200, $again['status']);
        $this->assertSame('evt-9', $again['body']['data']['calendar_event_id']);

        // And when the caller *can* see the other note, saying which one it is
        // is the useful half of the answer.
        $other = $this->note('Second set of minutes');
        $refused = $this->alice->patch('/notes/' . $other['id'] . '/meeting', ['calendar_event_id' => 'evt-9']);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('CALENDAR_EVENT_ALREADY_LINKED', $refused['body']['error']['code']);
        $this->assertSame($note['id'], $refused['body']['error']['details']['note_id'] ?? null);
    }

    public function testAnExternalIdIsRefusedRatherThanTrimmedToFit(): void
    {
        $note = $this->note();

        // A truncated id still points at something — at a different meeting.
        $refused = $this->alice->patch('/notes/' . $note['id'] . '/meeting', [
            'calendar_event_id' => str_repeat('e', 129),
        ]);

        $this->assertSame(422, $refused['status']);
        $this->assertCount(0, Connection::select('SELECT note_id FROM note_meetings'));
    }

    // -- Reading a row somebody else wrote ------------------------------------

    /**
     * Regression: minutes written by Pulse must not make the meeting unreadable.
     *
     * `note_meetings.decisions` is written by {@see \Aicountly\Api\Domain\Ai\NotesAIService}
     * straight from a model's JSON, so a decision can carry an object where a
     * string belongs. Running the request validator over that on the way out
     * answered 422 to `GET` — and to every `PATCH`, which left the note with no
     * way to repair the row that caused it.
     */
    public function testMinutesWrittenByPulseStillRender(): void
    {
        $note = $this->note();

        Connection::execute(
            "INSERT INTO note_meetings (note_id, summary, summary_model, summary_at, decisions, participants)
             VALUES (:note, 'Model minutes', 'gpt-4o-mini', now(), :decisions::jsonb, :participants::jsonb)",
            [
                'note' => $note['id'],
                'decisions' => (string) json_encode([
                    ['text' => 'Ship in April', 'decided_by' => ['Ravi', 'Anu']],
                    ['text' => ['nested' => 'thing']],
                    'Renew the retainer',
                ]),
                'participants' => (string) json_encode([['name' => ['R', 'S'], 'email' => 'ravi@acme.test']]),
            ],
        );

        $read = $this->alice->get('/notes/' . $note['id'] . '/meeting');
        $this->assertSame(200, $read['status']);
        $decisions = $read['body']['data']['decisions'];
        $this->assertCount(2, $decisions, 'what can be rendered is rendered; the rest is dropped');
        $this->assertSame('Ship in April', $decisions[0]['text']);
        $this->assertNull($decisions[0]['decided_by'], 'a list of names is not a name');
        $this->assertSame('Renew the retainer', $decisions[1]['text']);
        $this->assertSame('ravi@acme.test', $read['body']['data']['participants'][0]['email']);
        $this->assertNull($read['body']['data']['participants'][0]['name']);

        // And the note is still editable, which is what makes the row fixable.
        $patched = $this->alice->patch('/notes/' . $note['id'] . '/meeting', ['location' => 'Room 3']);
        $this->assertSame(200, $patched['status']);
        $this->assertSame('Room 3', $patched['body']['data']['location']);
    }

    // -- Integrations, unconfigured -----------------------------------------

    public function testTheIntegrationsAnswerFeatureDisabledUntilTheyAreConfigured(): void
    {
        $identity = Support::user('a');
        $note = $this->note();

        // The premise of everything below: this deployment has none of them.
        $this->assertFalse(Features::enabled(Features::CALENDAR));
        $this->assertFalse(Features::enabled(Features::CONTACTS));
        $this->assertFalse(Features::enabled(Features::CONNECT));

        $calendar = new CalendarIntegrationService();
        $this->assertApiError('FEATURE_DISABLED', static fn () => $calendar->linkEvent($identity, 'ses-key', $note['id'], 'evt-1'));
        $this->assertApiError('FEATURE_DISABLED', static fn () => $calendar->unlinkEvent($identity, $note['id']));
        $this->assertApiError('FEATURE_DISABLED', static fn () => $calendar->createMeetingNoteFromEvent($identity, 'ses-key', 'evt-1'));

        $contacts = new ContactsIntegrationService();
        $this->assertApiError('FEATURE_DISABLED', static fn () => $contacts->search('ses-key', 'ravi'));
        $this->assertApiError('FEATURE_DISABLED', static fn () => $contacts->resolve('ses-key', ['contact-11']));

        $connect = new ConnectIntegrationService();
        $this->assertApiError('FEATURE_DISABLED', static fn () => $connect->linkMeeting($identity, 'ses-key', $note['id'], 'mtg-1'));
        $this->assertApiError('FEATURE_DISABLED', static fn () => $connect->ingestRecording([
            'connect_meeting_id' => 'mtg-1',
            'transcript' => ['text' => 'Anything at all'],
        ]));

        // 503 and nothing else: no meeting invented, no note created, no
        // transcript accepted from a service this deployment has never heard of.
        $this->assertCount(0, Connection::select('SELECT note_id FROM note_meetings'));
        $this->assertCount(0, Connection::select('SELECT id FROM note_transcripts'));
        $this->assertCount(1, Connection::select('SELECT id FROM notes'));
    }
}
