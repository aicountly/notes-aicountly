<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Reminders\ReminderService;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Reminders over the real router.
 *
 * Three properties carry most of the weight here:
 *
 *   - **A reminder is one person's.** Two collaborators on the same note keep
 *     separate reminders, and neither can read or change the other's — a
 *     reminder id must not be a way to learn what a colleague is being nudged
 *     about, let alone to snooze it for them.
 *   - **09:00 means 09:00.** A weekly reminder that drifts to 08:00 when the
 *     clocks change is the bug this domain exists to avoid, so both directions
 *     of a daylight-saving boundary are walked with real zones.
 *   - **Completing a repeat does not end it.** The series moves on; only the
 *     rule running out finishes it.
 */
final class RemindersTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Reminders';
    }

    public function setUp(): void
    {
        // Some tests pin the clock so "the next occurrence" is not a function
        // of the day the suite runs. Released here rather than at the end of
        // each test, so a failing assertion cannot leak a frozen clock into
        // the next case.
        Clock::freeze(null);

        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    private function note(?string $title = 'Quarterly filing', string $text = 'GST working papers'): array
    {
        return $this->alice->post('/notes', [
            'title' => $title,
            'document' => Support::doc($text),
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

    private function unshare(string $noteId): void
    {
        Connection::execute(
            'DELETE FROM note_members WHERE note_id = :note AND user_id = \'user-b\'',
            ['note' => $noteId],
        );
    }

    /** @param array<string, mixed> $body */
    private function reminder(ApiClient $api, string $noteId, array $body = []): array
    {
        return $api->post('/notes/' . $noteId . '/reminders', $body + [
            'due_at' => '2030-01-15T09:00:00Z',
        ])['body']['data'];
    }

    // -- Creating -----------------------------------------------------------

    public function testCreatesAOneOffReminderOnANote(): void
    {
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->assertSame(201, $created['status']);
        $reminder = $created['body']['data'];
        $this->assertSame($note['id'], $reminder['note_id']);
        $this->assertSame('user-a', $reminder['user_id']);
        $this->assertSame('datetime', $reminder['reminder_type']);
        $this->assertSame('2030-01-15T09:00:00+00:00', $reminder['due_at']);
        $this->assertSame('2030-01-15T09:00:00+00:00', $reminder['due_effective_at']);
        $this->assertSame('Asia/Kolkata', $reminder['timezone']);
        $this->assertSame('scheduled', $reminder['status']);
        $this->assertNull($reminder['recurrence_rule']);
        $this->assertNull($reminder['notified_at']);

        // The note now advertises it, which is what drives the bell on a card.
        $this->assertTrue($this->alice->get('/notes/' . $note['id'])['body']['data']['has_reminder']);
    }

    public function testAReminderNeedsADateAndTime(): void
    {
        $note = $this->note();

        $missing = $this->alice->post('/notes/' . $note['id'] . '/reminders', []);
        $this->assertSame(422, $missing['status']);
        $this->assertSame('VALIDATION_FAILED', $missing['body']['error']['code']);

        $nonsense = $this->alice->post('/notes/' . $note['id'] . '/reminders', ['due_at' => 'next tuesdayish']);
        $this->assertSame(422, $nonsense['status']);

        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);
    }

    public function testANonIanaTimezoneIsRefused(): void
    {
        $note = $this->note();

        // Both of these are things DateTimeZone would happily accept and
        // neither knows when the clocks change, so both are refused.
        foreach (['EST', '+05:30', 'Mars/Olympus'] as $zone) {
            $result = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
                'due_at' => '2030-01-15T09:00:00Z',
                'timezone' => $zone,
            ]);
            $this->assertSame(422, $result['status'], $zone . ' should not be accepted as a zone');
        }

        // The default is UTC rather than the server's locale, which is a
        // property of the deployment and not of the user.
        $this->assertSame('UTC', $this->reminder($this->alice, $note['id'])['timezone']);
    }

    // -- The recurrence subset ----------------------------------------------

    public function testAcceptsTheRecurrenceSubsetAndCanonicalisesIt(): void
    {
        $note = $this->note();

        $rules = [
            'FREQ=DAILY' => 'FREQ=DAILY',
            'RRULE:FREQ=WEEKLY;BYDAY=MO,WE' => 'FREQ=WEEKLY;BYDAY=MO,WE',
            'freq=weekly;interval=2;byday=fr' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=FR',
            'FREQ=MONTHLY;BYDAY=-1FR' => 'FREQ=MONTHLY;BYDAY=-1FR',
            'FREQ=YEARLY;INTERVAL=1' => 'FREQ=YEARLY',
        ];

        foreach ($rules as $sent => $stored) {
            $reminder = $this->reminder($this->alice, $note['id'], ['recurrence_rule' => $sent]);
            $this->assertSame($stored, $reminder['recurrence_rule'], $sent);
            $this->assertSame('recurring', $reminder['reminder_type']);
        }

        // The parsed form travels with it so a client need not re-implement
        // the parser to render "every 2 weeks on Friday".
        $parsed = $this->reminder($this->alice, $note['id'], ['recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=FR']);
        $this->assertSame('WEEKLY', $parsed['recurrence']['frequency']);
        $this->assertSame(2, $parsed['recurrence']['interval']);
        $this->assertSame(['FR'], $parsed['recurrence']['by_day']);
    }

    public function testRulePartsOutsideTheSubsetAreRefusedRatherThanIgnored(): void
    {
        $note = $this->note();

        $refused = [
            'FREQ=HOURLY',
            'FREQ=DAILY;BYSETPOS=1',
            'FREQ=DAILY;BYMONTHDAY=15',
            'FREQ=WEEKLY;BYDAY=XX',
            'FREQ=DAILY;BYDAY=MO',
            'FREQ=WEEKLY;BYDAY=2MO',
            'FREQ=DAILY;INTERVAL=0',
            'FREQ=DAILY;COUNT=3;UNTIL=20300101T000000Z',
            'FREQ=DAILY;UNTIL=next-year',
            'INTERVAL=2',
            'nonsense',
        ];

        foreach ($refused as $rule) {
            $result = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
                'due_at' => '2030-01-15T09:00:00Z',
                'recurrence_rule' => $rule,
            ]);
            $this->assertSame(422, $result['status'], $rule . ' should be refused');
            // The message names the part, so the caller can fix it rather than
            // guess which half of their rule the server disliked.
            $this->assertNotNull($result['body']['error']['details']['fields']['recurrence_rule'] ?? null, $rule);
        }

        // Nothing was stored, so no reminder is quietly firing on a schedule
        // nobody asked for.
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);
    }

    // -- Daylight saving ----------------------------------------------------

    public function testAWeeklyReminderKeepsItsLocalTimeWhenLondonLeavesSummerTime(): void
    {
        Clock::freeze((int) strtotime('2026-10-01T00:00:00Z'));
        $note = $this->note();

        // Friday 09:00 in London, while British Summer Time is still running.
        $reminder = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2026-10-23T08:00:00Z',
            'timezone' => 'Europe/London',
            'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=FR',
        ])['body']['data'];

        $advanced = $this->alice->post('/reminders/' . $reminder['id'] . '/complete');
        $this->assertSame(200, $advanced['status']);
        $due = $advanced['body']['data']['due_at'];

        // The clocks went back on 25 October. The instant moves by an hour
        // precisely so that the wall clock does not.
        $this->assertSame('2026-10-30T09:00:00+00:00', $due, 'the UTC instant absorbs the offset change');
        $this->assertSame('09:00', $this->localTime($due, 'Europe/London'), 'the user still sees 09:00');
    }

    public function testADailyReminderKeepsItsLocalTimeWhenNewYorkLeavesSummerTime(): void
    {
        Clock::freeze((int) strtotime('2026-10-01T00:00:00Z'));
        $note = $this->note();

        // 09:00 in New York on the last day of Eastern Daylight Time.
        $reminder = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2026-10-31T13:00:00Z',
            'timezone' => 'America/New_York',
            'recurrence_rule' => 'FREQ=DAILY',
        ])['body']['data'];

        $due = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data']['due_at'];

        $this->assertSame('2026-11-01T14:00:00+00:00', $due);
        $this->assertSame('09:00', $this->localTime($due, 'America/New_York'));

        // Adding 86400 seconds to the instant would have produced 08:00 local.
        $this->assertNotSame('2026-11-01T13:00:00+00:00', $due, 'a reminder is not 24 hours of arithmetic');
    }

    private function localTime(string $iso, string $zone): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone($zone))->format('H:i');
    }

    // -- Completing ---------------------------------------------------------

    public function testCompletingAOneOffFinishesIt(): void
    {
        $reminder = $this->reminder($this->alice, $this->note()['id']);

        $done = $this->alice->post('/reminders/' . $reminder['id'] . '/complete');
        $this->assertSame(200, $done['status']);
        $this->assertSame('completed', $done['body']['data']['status']);
        $this->assertNotNull($done['body']['data']['completed_at']);

        // Gone from the default view, findable when asked for.
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);
        $this->assertCount(1, $this->alice->get('/reminders', ['status' => 'completed'])['body']['data']);

        // And it cannot be completed twice.
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['status']);
    }

    public function testCompletingARecurringReminderAdvancesItInsteadOfEndingTheSeries(): void
    {
        Clock::freeze((int) strtotime('2030-01-10T00:00:00Z'));
        $note = $this->note();

        $reminder = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'recurrence_rule' => 'FREQ=WEEKLY',
        ])['body']['data'];

        $first = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data'];
        $this->assertSame('scheduled', $first['status'], 'the series is still running');
        $this->assertSame('2030-01-22T09:00:00+00:00', $first['due_at']);
        $this->assertNull($first['completed_at']);

        $second = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data'];
        $this->assertSame('2030-01-29T09:00:00+00:00', $second['due_at']);

        // Still exactly one row, still open: advancing is not creating.
        $open = $this->alice->get('/reminders')['body']['data'];
        $this->assertCount(1, $open);
        $this->assertSame($reminder['id'], $open[0]['id']);
    }

    public function testACountedSeriesEndsAfterItsLastOccurrence(): void
    {
        Clock::freeze((int) strtotime('2030-01-10T00:00:00Z'));
        $note = $this->note();

        $reminder = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'recurrence_rule' => 'FREQ=DAILY;COUNT=2',
        ])['body']['data'];

        // COUNT is anchored to the start of the series, and the row has no
        // column for that anchor, so it is resolved once into the equivalent
        // end date. Two occurrences: the 15th and the 16th.
        $this->assertSame('FREQ=DAILY;UNTIL=20300116T090000Z', $reminder['recurrence_rule']);

        $second = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data'];
        $this->assertSame('2030-01-16T09:00:00+00:00', $second['due_at']);
        $this->assertSame('scheduled', $second['status']);

        $finished = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data'];
        $this->assertSame('completed', $finished['status'], 'the rule ran out, so the series ends');
        $this->assertNotNull($finished['completed_at']);
    }

    public function testCompletingLateSkipsTheBacklogRatherThanRingingSixTimes(): void
    {
        // The reminder was due a week ago and the user is only dealing with it
        // now: the next occurrence should be tomorrow, not last Tuesday.
        Clock::freeze((int) strtotime('2030-01-22T11:00:00Z'));
        $note = $this->note();

        $reminder = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'recurrence_rule' => 'FREQ=DAILY',
        ])['body']['data'];

        $advanced = $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data'];
        $this->assertSame('2030-01-23T09:00:00+00:00', $advanced['due_at']);
    }

    // -- Snoozing -----------------------------------------------------------

    public function testSnoozeTakesMinutesOrAnAbsoluteTime(): void
    {
        $note = $this->note();
        $byMinutes = $this->reminder($this->alice, $note['id']);
        $byTime = $this->reminder($this->alice, $note['id']);

        $snoozed = $this->alice->post('/reminders/' . $byMinutes['id'] . '/snooze', ['minutes' => 30]);
        $this->assertSame(200, $snoozed['status']);
        $this->assertSame('snoozed', $snoozed['body']['data']['status']);
        $this->assertNotNull($snoozed['body']['data']['snoozed_until']);
        // The effective time is what the user will actually be interrupted at.
        $this->assertSame(
            $snoozed['body']['data']['snoozed_until'],
            $snoozed['body']['data']['due_effective_at'],
        );
        $this->assertSame('2030-01-15T09:00:00+00:00', $snoozed['body']['data']['due_at'], 'the original intent survives');

        $absolute = $this->alice->post('/reminders/' . $byTime['id'] . '/snooze', ['until' => '2030-01-16T07:30:00Z']);
        $this->assertSame('2030-01-16T07:30:00+00:00', $absolute['body']['data']['snoozed_until']);
    }

    public function testSnoozeRefusesWhatItCannotActOn(): void
    {
        $note = $this->note();
        $reminder = $this->reminder($this->alice, $note['id']);

        // One of the two, not neither and not both.
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', [])['status']);
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', [
            'minutes' => 10,
            'until' => '2030-01-16T07:30:00Z',
        ])['status']);

        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', ['minutes' => 0])['status']);
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', ['minutes' => 99999])['status']);
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', [
            'until' => '2000-01-01T00:00:00Z',
        ])['status'], 'snoozing into the past would fire immediately');

        // A finished reminder is not a thing to postpone.
        $this->alice->post('/reminders/' . $reminder['id'] . '/complete');
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', ['minutes' => 10])['status']);

        $this->assertSame('scheduled', $this->reminder($this->alice, $note['id'])['status']);
    }

    // -- Updating and deleting ----------------------------------------------

    public function testRescheduleClearRecurrenceAndCancel(): void
    {
        $note = $this->note();
        $reminder = $this->reminder($this->alice, $note['id'], ['recurrence_rule' => 'FREQ=WEEKLY']);

        $moved = $this->alice->patch('/reminders/' . $reminder['id'], ['due_at' => '2030-02-01T18:00:00Z']);
        $this->assertSame(200, $moved['status']);
        $this->assertSame('2030-02-01T18:00:00+00:00', $moved['body']['data']['due_at']);

        $oneOff = $this->alice->patch('/reminders/' . $reminder['id'], ['recurrence_rule' => null])['body']['data'];
        $this->assertNull($oneOff['recurrence_rule']);
        $this->assertSame('datetime', $oneOff['reminder_type']);

        $cancelled = $this->alice->patch('/reminders/' . $reminder['id'], ['status' => 'cancelled'])['body']['data'];
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);

        // A bad rule on a PATCH is refused just as it is on a create.
        $this->assertSame(422, $this->alice->patch('/reminders/' . $reminder['id'], [
            'recurrence_rule' => 'FREQ=FORTNIGHTLY',
        ])['status']);
        $this->assertSame(422, $this->alice->patch('/reminders/' . $reminder['id'], ['status' => 'completed'])['status']);
    }

    public function testDeletingAReminderRemovesItAndKeepsTheNote(): void
    {
        $note = $this->note();
        $reminder = $this->reminder($this->alice, $note['id']);

        $this->assertSame(204, $this->alice->delete('/reminders/' . $reminder['id'])['status']);
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);

        // Soft-deleted rows are invisible to the id lookup, so a second attempt
        // is a 404 rather than a resurrection.
        $this->assertSame(404, $this->alice->delete('/reminders/' . $reminder['id'])['status']);
        $this->assertSame(404, $this->alice->patch('/reminders/' . $reminder['id'], ['due_at' => '2030-03-01T09:00:00Z'])['status']);

        $survivor = $this->alice->get('/notes/' . $note['id']);
        $this->assertSame(200, $survivor['status']);
        $this->assertFalse($survivor['body']['data']['has_reminder']);
    }

    public function testAMalformedOrUnknownReminderIdIsNotFound(): void
    {
        $this->assertSame(404, $this->alice->patch('/reminders/not-a-uuid', ['due_at' => '2030-01-01T09:00:00Z'])['status']);
        $this->assertSame(404, $this->alice->delete('/reminders/' . Uuid::v4())['status']);
        $this->assertSame(404, $this->alice->post('/reminders/' . Uuid::v4() . '/snooze', ['minutes' => 5])['status']);
        $this->assertSame(404, $this->alice->post('/reminders/' . Uuid::v4() . '/complete')['status']);
        $this->assertSame(404, $this->alice->post('/notes/' . Uuid::v4() . '/reminders', [
            'due_at' => '2030-01-01T09:00:00Z',
        ])['status']);
    }

    // -- The list -----------------------------------------------------------

    public function testTheListCarriesTheNoteAndIsOrderedByEffectiveDueTime(): void
    {
        $first = $this->note('Board pack');
        $second = $this->note(null, "Call the auditor\nabout the stock count");

        $late = $this->reminder($this->alice, $first['id'], ['due_at' => '2030-03-01T09:00:00Z']);
        $early = $this->reminder($this->alice, $second['id'], ['due_at' => '2030-02-01T09:00:00Z']);

        $list = $this->alice->get('/reminders');
        $this->assertSame(200, $list['status']);
        $this->assertCount(2, $list['body']['data']);
        $this->assertSame($early['id'], $list['body']['data'][0]['id']);
        $this->assertSame($first['id'], $list['body']['data'][1]['note_id']);
        $this->assertSame('Board pack', $list['body']['data'][1]['note_display_title']);
        // An untitled note falls back to its first line, exactly as its card does.
        $this->assertSame('Call the auditor', $list['body']['data'][0]['note_display_title']);
        $this->assertNull($list['body']['data'][0]['note_title']);

        // Snoozing the early one past the late one re-orders the list, because
        // the order is the order the user will be interrupted in.
        $this->alice->post('/reminders/' . $early['id'] . '/snooze', ['until' => '2030-04-01T09:00:00Z']);
        $reordered = $this->alice->get('/reminders')['body']['data'];
        $this->assertSame($late['id'], $reordered[0]['id']);
        $this->assertSame($early['id'], $reordered[1]['id']);
    }

    public function testTheListSeparatesOverdueFromUpcoming(): void
    {
        $note = $this->note();
        $overdue = $this->reminder($this->alice, $note['id'], ['due_at' => '2020-01-01T09:00:00Z']);
        $upcoming = $this->reminder($this->alice, $note['id'], ['due_at' => '2030-01-01T09:00:00Z']);

        // The default view is both, overdue first, because that is what a
        // reminders screen is for.
        $open = $this->alice->get('/reminders')['body']['data'];
        $this->assertCount(2, $open);
        $this->assertSame($overdue['id'], $open[0]['id']);

        $this->assertCount(1, $this->alice->get('/reminders', ['status' => 'overdue'])['body']['data']);
        $this->assertSame($upcoming['id'], $this->alice->get('/reminders', ['status' => 'upcoming'])['body']['data'][0]['id']);
        $this->assertSame(400, $this->alice->get('/reminders', ['status' => 'everything'])['status']);
    }

    // -- One person's reminders are their own --------------------------------

    public function testTwoCollaboratorsKeepSeparateRemindersOnOneNote(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'editor');

        $hers = $this->reminder($this->alice, $note['id'], ['due_at' => '2030-01-15T09:00:00Z']);
        $his = $this->reminder($this->bob, $note['id'], ['due_at' => '2030-01-14T18:00:00Z']);

        $this->assertNotSame($hers['id'], $his['id']);
        $this->assertSame('user-a', $hers['user_id']);
        $this->assertSame('user-b', $his['user_id']);

        // Each sees exactly one — their own — on the same note.
        $aliceList = $this->alice->get('/reminders')['body']['data'];
        $bobList = $this->bob->get('/reminders')['body']['data'];
        $this->assertCount(1, $aliceList);
        $this->assertCount(1, $bobList);
        $this->assertSame($hers['id'], $aliceList[0]['id']);
        $this->assertSame($his['id'], $bobList[0]['id']);
    }

    public function testACollaboratorCannotReachAnotherUsersReminder(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'editor');
        $hers = $this->reminder($this->alice, $note['id']);

        // Bob can open the note and can even edit it — and still cannot touch
        // Alice's reminder. 404 everywhere: a reminder id must not confirm that
        // someone else has one, let alone let him snooze it for her.
        $this->assertSame(404, $this->bob->patch('/reminders/' . $hers['id'], ['due_at' => '2031-01-01T09:00:00Z'])['status']);
        $this->assertSame(404, $this->bob->delete('/reminders/' . $hers['id'])['status']);
        $this->assertSame(404, $this->bob->post('/reminders/' . $hers['id'] . '/snooze', ['minutes' => 60])['status']);
        $this->assertSame(404, $this->bob->post('/reminders/' . $hers['id'] . '/complete')['status']);

        // Nothing Bob sent was applied.
        $survivor = $this->alice->get('/reminders')['body']['data'][0];
        $this->assertSame('scheduled', $survivor['status']);
        $this->assertSame('2030-01-15T09:00:00+00:00', $survivor['due_at']);
        $this->assertNull($survivor['snoozed_until']);
    }

    public function testAStrangerCannotSeeOrSetAReminderOnANoteTheyCannotOpen(): void
    {
        $note = $this->note();
        $hers = $this->reminder($this->alice, $note['id']);

        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
        ])['status']);
        $this->assertSame(404, $this->bob->patch('/reminders/' . $hers['id'], ['status' => 'cancelled'])['status']);
        $this->assertCount(0, $this->bob->get('/reminders')['body']['data']);

        $this->assertCount(1, $this->alice->get('/reminders')['body']['data']);
    }

    public function testAViewerMaySetTheirOwnReminder(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'viewer');

        // Being reminded to read something is reading it, not editing it.
        $created = $this->bob->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
        ]);
        $this->assertSame(201, $created['status']);
        $this->assertSame(200, $this->bob->post('/reminders/' . $created['body']['data']['id'] . '/complete')['status']);
    }

    public function testWithdrawingAShareTakesTheReminderOutOfReach(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'editor');
        $his = $this->reminder($this->bob, $note['id']);
        $this->assertCount(1, $this->bob->get('/reminders')['body']['data']);

        $this->unshare($note['id']);

        $this->assertCount(0, $this->bob->get('/reminders')['body']['data'], 'the list is gated on the note, not on the row');
        $this->assertSame(404, $this->bob->patch('/reminders/' . $his['id'], ['due_at' => '2031-01-01T09:00:00Z'])['status']);
        $this->assertSame(404, $this->bob->post('/reminders/' . $his['id'] . '/snooze', ['minutes' => 5])['status']);

        // Deleting is the one thing he may still do: otherwise his own row sits
        // in the dispatch queue with no way for him to remove it.
        $this->assertSame(204, $this->bob->delete('/reminders/' . $his['id'])['status']);
    }

    public function testAReminderCannotBeReachedAcrossATenantBoundary(): void
    {
        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $note = $acme->post('/notes', ['document' => Support::doc('acme numbers')])['body']['data'];
        $reminder = $acme->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
        ])['body']['data'];

        // Same user id, wrong company: the tenant gate is an AND on the grant,
        // and it holds on deletion too, which skips the note check.
        $globex = new ApiClient(Support::user('a', 'tenant-globex'));
        $this->assertCount(0, $globex->get('/reminders')['body']['data']);
        $this->assertSame(404, $globex->patch('/reminders/' . $reminder['id'], ['status' => 'cancelled'])['status']);
        $this->assertSame(404, $globex->delete('/reminders/' . $reminder['id'])['status']);
        $this->assertSame(404, $globex->post('/reminders/' . $reminder['id'] . '/complete')['status']);

        $this->assertCount(1, $acme->get('/reminders')['body']['data']);
    }

    // -- What the worker sees -----------------------------------------------

    public function testTheDispatchQueueClaimsDueRemindersExactlyOnce(): void
    {
        $note = $this->note();
        $due = $this->reminder($this->alice, $note['id'], ['due_at' => '2020-01-01T09:00:00Z']);
        $this->reminder($this->alice, $note['id'], ['due_at' => '2030-01-01T09:00:00Z']);

        $service = new ReminderService();
        $claimed = $service->claimDue();

        $this->assertCount(1, $claimed, 'only what is actually due');
        $this->assertSame($due['id'], $claimed[0]['id']);
        $this->assertSame('user-a', $claimed[0]['user_id']);
        $this->assertNotNull($claimed[0]['notified_at']);
        // The note travels with it: a delivery channel needs something to say.
        $this->assertSame('Quarterly filing', $claimed[0]['note_display_title']);

        // Marked, so a second pass in the same minute does not send it twice.
        $this->assertCount(0, $service->claimDue());

        // Rescheduling it makes it deliverable again.
        $this->alice->patch('/reminders/' . $due['id'], ['due_at' => '2020-06-01T09:00:00Z']);
        $this->assertCount(1, $service->claimDue());
    }

    public function testTheDispatchQueueIgnoresTrashedNotesAndFinishedReminders(): void
    {
        $note = $this->note();
        $snoozedPast = $this->reminder($this->alice, $note['id'], ['due_at' => '2020-01-01T09:00:00Z']);
        $cancelled = $this->reminder($this->alice, $note['id'], ['due_at' => '2020-01-01T09:00:00Z']);
        $this->alice->patch('/reminders/' . $cancelled['id'], ['status' => 'cancelled']);

        $trashed = $this->note('Binned');
        $this->reminder($this->alice, $trashed['id'], ['due_at' => '2020-01-01T09:00:00Z']);
        $this->alice->delete('/notes/' . $trashed['id']);

        $claimed = (new ReminderService())->claimDue();
        $this->assertCount(1, $claimed);
        $this->assertSame($snoozedPast['id'], $claimed[0]['id']);
    }

    // -- Regressions --------------------------------------------------------

    /**
     * A date PHP will parse but `timestamptz` will not store.
     *
     * `+300000-01-15T09:00:00Z` is a perfectly good DateTimeImmutable and a
     * perfectly bad timestamp: unbounded, the refusal arrived as an uncaught
     * PDOException from inside the INSERT, so a malformed date answered 500.
     * Every door that takes one is walked here, because each built its own
     * statement.
     */
    public function testADateBeyondWhatPostgresStoresIsRefusedRatherThanCrashing(): void
    {
        $note = $this->note();
        $beyond = '+300000-01-15T09:00:00Z';

        $created = $this->alice->post('/notes/' . $note['id'] . '/reminders', ['due_at' => $beyond]);
        $this->assertSame(422, $created['status']);
        $this->assertNotNull($created['body']['error']['details']['fields']['due_at'] ?? null);
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data'], 'nothing was written');

        $reminder = $this->reminder($this->alice, $note['id']);
        $this->assertSame(422, $this->alice->patch('/reminders/' . $reminder['id'], ['due_at' => $beyond])['status']);
        $this->assertSame(422, $this->alice->post('/reminders/' . $reminder['id'] . '/snooze', [
            'until' => $beyond,
        ])['status']);

        // Neither attempt moved the reminder that does exist.
        $survivor = $this->alice->get('/reminders')['body']['data'][0];
        $this->assertSame('2030-01-15T09:00:00+00:00', $survivor['due_at']);
        $this->assertNull($survivor['snoozed_until']);
    }

    /**
     * A repeat that ends before it starts is a repeat that never repeats.
     *
     * Stored, it produces a row labelled `recurring` that completes once and
     * dies — the quiet wrongness this domain refuses everywhere else.
     */
    public function testASeriesThatHasAlreadyEndedIsRefusedRatherThanStored(): void
    {
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'recurrence_rule' => 'FREQ=DAILY;UNTIL=20200101T000000Z',
        ]);
        $this->assertSame(422, $created['status']);
        $this->assertNotNull($created['body']['error']['details']['fields']['recurrence_rule'] ?? null);
        $this->assertCount(0, $this->alice->get('/reminders')['body']['data']);

        // `UNTIL` on the first occurrence itself is a series of one, which is
        // what `COUNT=1` means and is allowed.
        $once = $this->alice->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
            'recurrence_rule' => 'FREQ=DAILY;COUNT=1',
        ]);
        $this->assertSame(201, $once['status']);
    }

    /**
     * Rescheduling a counted series past its own end.
     *
     * `COUNT` is resolved into an absolute `UNTIL` against the first
     * occurrence, so moving the reminder beyond that date leaves a row that
     * still says `recurring`, still draws the repeat icon, and has nothing
     * left to fire. The move is refused with the end date named.
     */
    public function testMovingACountedSeriesPastItsEndIsRefusedInsteadOfKillingItQuietly(): void
    {
        Clock::freeze((int) strtotime('2030-01-10T00:00:00Z'));
        $note = $this->note();
        $reminder = $this->reminder($this->alice, $note['id'], ['recurrence_rule' => 'FREQ=DAILY;COUNT=5']);
        $this->assertSame('FREQ=DAILY;UNTIL=20300119T090000Z', $reminder['recurrence_rule']);

        $moved = $this->alice->patch('/reminders/' . $reminder['id'], ['due_at' => '2030-03-01T09:00:00Z']);
        $this->assertSame(422, $moved['status']);
        // The complaint names the half the caller actually sent.
        $this->assertNotNull($moved['body']['error']['details']['fields']['due_at'] ?? null);

        // Inside the series it moves, and it still repeats afterwards.
        $inside = $this->alice->patch('/reminders/' . $reminder['id'], ['due_at' => '2030-01-17T09:00:00Z'])['body']['data'];
        $this->assertSame('recurring', $inside['reminder_type']);
        $this->assertSame(
            '2030-01-18T09:00:00+00:00',
            $this->alice->post('/reminders/' . $reminder['id'] . '/complete')['body']['data']['due_at'],
        );

        // Clearing the repeat frees the date, because there is no series left
        // to contradict.
        $this->alice->patch('/reminders/' . $reminder['id'], ['recurrence_rule' => null]);
        $this->assertSame(200, $this->alice->patch('/reminders/' . $reminder['id'], [
            'due_at' => '2030-03-01T09:00:00Z',
        ])['status']);
    }

    /**
     * The row's company is the note's, not whoever was logged in.
     *
     * A personal note is readable from every company context. Stamping the
     * acting company onto its reminder left a row the list happily returned
     * and every write answered 404 to — visible, undeletable, still queued.
     */
    public function testAReminderOnAPersonalNoteFollowsTheNoteIntoEveryCompanyContext(): void
    {
        $personal = new ApiClient(Support::user('a'));
        $note = $personal->post('/notes', ['document' => Support::doc('personal papers')])['body']['data'];

        $acme = new ApiClient(Support::user('a', 'tenant-acme'));
        $reminder = $acme->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
        ])['body']['data'];

        $this->assertCount(1, $personal->get('/reminders')['body']['data']);
        $this->assertSame(200, $personal->patch('/reminders/' . $reminder['id'], [
            'due_at' => '2030-02-01T09:00:00Z',
        ])['status'], 'the list and the writes must agree about the same row');
        $this->assertSame(200, $personal->post('/reminders/' . $reminder['id'] . '/complete')['status']);

        // A company note still keeps its own boundary, for the same reason:
        // the reminder inherits the note's tenant, so both halves agree.
        $acmeNote = $acme->post('/notes', ['document' => Support::doc('acme numbers')])['body']['data'];
        $acmeReminder = $acme->post('/notes/' . $acmeNote['id'] . '/reminders', [
            'due_at' => '2030-01-15T09:00:00Z',
        ])['body']['data'];

        $globex = new ApiClient(Support::user('a', 'tenant-globex'));
        $this->assertCount(0, $globex->get('/reminders')['body']['data']);
        $this->assertSame(404, $globex->delete('/reminders/' . $acmeReminder['id'])['status']);
    }

    /**
     * Deleting your own row is not a licence to write to someone else's note.
     *
     * Deletion deliberately skips the note check so a lost share cannot strand
     * a row in the caller's queue — but the activity trail belongs to the note
     * and is read by everyone still on it, so the trail entry is the one part
     * that stays gated.
     */
    public function testClearingAReminderOnALostNoteDoesNotWriteToItsTrail(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'editor');
        $his = $this->reminder($this->bob, $note['id']);
        $this->unshare($note['id']);

        $this->assertSame(204, $this->bob->delete('/reminders/' . $his['id'])['status']);

        $trail = Connection::select(
            'SELECT action FROM note_activity WHERE note_id = :note AND actor_user_id = \'user-b\' ORDER BY created_at',
            ['note' => $note['id']],
        );
        $this->assertSame(['reminder.set'], array_column($trail, 'action'), 'no trail entry after the share was withdrawn');

        // Someone who can still open the note does leave a trace, so this is a
        // gate rather than the feature quietly going missing.
        $hers = $this->reminder($this->alice, $note['id']);
        $this->alice->delete('/reminders/' . $hers['id']);
        $this->assertTrue(in_array('reminder.cleared', array_column(Connection::select(
            'SELECT action FROM note_activity WHERE note_id = :note AND actor_user_id = \'user-a\'',
            ['note' => $note['id']],
        ), 'action'), true));
    }
}
