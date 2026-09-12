<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Reminders are personal, even on a shared note.
 *
 * The failure this guards against is subtle, because everything about it looks
 * like it works: two people share a note, each sets their own reminder, and a
 * naive "reminders for this note" query hands each of them the other's — or
 * worse, lets one reschedule or delete the other's. A reminder is a private
 * arrangement between a person and their own calendar; sharing the note does
 * not share it.
 */
final class ReminderIsolationTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;
    private string $noteId = '';

    public function name(): string
    {
        return 'Reminder isolation';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    /** A note Alice owns and Bob can edit. */
    private function sharedNote(): string
    {
        if ($this->noteId !== '') {
            return $this->noteId;
        }

        $note = $this->alice->post('/notes', [
            'title' => 'Quarterly filing',
            'document' => Support::doc('due soon'),
        ])['body']['data'];

        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, \'user-b\', \'editor\', \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $note['id']],
        );

        return $this->noteId = (string) $note['id'];
    }

    private function setReminder(ApiClient $api, string $noteId, string $when): array
    {
        return $api->post('/notes/' . $noteId . '/reminders', [
            'due_at' => (new \DateTimeImmutable($when))->format(\DateTimeInterface::RFC3339),
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    public function testEachCollaboratorSeesOnlyTheirOwnReminder(): void
    {
        $noteId = $this->sharedNote();

        $this->assertSame(201, $this->setReminder($this->alice, $noteId, '+2 days')['status']);
        $this->assertSame(201, $this->setReminder($this->bob, $noteId, '+5 days')['status']);

        $aliceSees = $this->alice->get('/reminders')['body']['data'];
        $bobSees = $this->bob->get('/reminders')['body']['data'];

        $this->assertCount(1, $aliceSees, 'Alice sees her own reminder and only hers');
        $this->assertCount(1, $bobSees, 'Bob sees his own reminder and only his');
        $this->assertNotSame($aliceSees[0]['id'], $bobSees[0]['id']);
    }

    public function testOneCollaboratorCannotRescheduleAnothersReminder(): void
    {
        $noteId = $this->sharedNote();
        $alices = $this->setReminder($this->alice, $noteId, '+2 days')['body']['data'];

        $hijack = $this->bob->patch('/reminders/' . $alices['id'], [
            'due_at' => (new \DateTimeImmutable('+1 year'))->format(\DateTimeInterface::RFC3339),
        ]);

        // Editing the note is Bob's business; Alice's reminder is not.
        $this->assertSame(404, $hijack['status']);

        $unchanged = $this->alice->get('/reminders')['body']['data'][0];
        $this->assertSame($alices['due_at'], $unchanged['due_at']);
    }

    public function testOneCollaboratorCannotDeleteOrCompleteAnothersReminder(): void
    {
        $noteId = $this->sharedNote();
        $alices = $this->setReminder($this->alice, $noteId, '+2 days')['body']['data'];

        $this->assertSame(404, $this->bob->delete('/reminders/' . $alices['id'])['status']);
        $this->assertSame(404, $this->bob->post('/reminders/' . $alices['id'] . '/complete')['status']);
        $this->assertSame(404, $this->bob->post('/reminders/' . $alices['id'] . '/snooze', ['minutes' => 60])['status']);

        $this->assertCount(1, $this->alice->get('/reminders')['body']['data'], 'still scheduled');
    }

    public function testAStrangerCannotSetAReminderOnANoteTheyCannotSee(): void
    {
        $private = $this->alice->post('/notes', ['title' => 'Private'])['body']['data'];
        $carol = new ApiClient(Support::user('c'));

        $this->assertSame(404, $this->setReminder($carol, $private['id'], '+1 day')['status']);
        $this->assertCount(0, $carol->get('/reminders')['body']['data']);
    }

    public function testLosingAccessToTheNoteHidesTheReminder(): void
    {
        $noteId = $this->sharedNote();
        $this->setReminder($this->bob, $noteId, '+3 days');
        $this->assertCount(1, $this->bob->get('/reminders')['body']['data']);

        Connection::execute('DELETE FROM note_members WHERE note_id = :n', ['n' => $noteId]);

        // The reminder points at a note Bob can no longer open, so listing it
        // would leak the note's title into his reminders screen.
        $this->assertCount(0, $this->bob->get('/reminders')['body']['data']);
    }

    public function testATrashedNotesReminderDoesNotKeepNagging(): void
    {
        $noteId = $this->sharedNote();
        $this->setReminder($this->alice, $noteId, '+2 days');

        $this->alice->delete('/notes/' . $noteId);

        $this->assertCount(
            0,
            $this->alice->get('/reminders')['body']['data'],
            'a reminder for a note in the trash must not stay in the list',
        );
    }
}
