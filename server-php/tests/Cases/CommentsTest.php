<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Comments, over the real router.
 *
 * The properties worth holding: a commenter can say something without being
 * able to change the note, a comment is never dropped because the paragraph it
 * pointed at was rewritten, and a comment on a note you cannot open is
 * unreachable in every direction — by listing it, by posting one, or by acting
 * on its id.
 */
final class CommentsTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;
    private ApiClient $carol;

    public function name(): string
    {
        return 'Comments';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
        $this->carol = new ApiClient(Support::user('c'));
    }

    /** @return array<string, mixed> */
    private function note(): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Draft agreement',
            'document' => Support::doc('clause four needs work'),
        ])['body']['data'];
    }

    /** A note whose first paragraph has an id a comment can anchor to. */
    private function anchoredNote(string $blockId): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Draft agreement',
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'attrs' => ['blockId' => $blockId],
                    'content' => [['type' => 'text', 'text' => 'the disputed clause']],
                ]],
            ],
        ])['body']['data'];
    }

    private function share(string $noteId, string $userId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'user' => $userId, 'role' => $role],
        );
    }

    // -- Who may comment ----------------------------------------------------

    public function testACommenterMayCommentButNotEditTheNote(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');

        $comment = $this->bob->post('/notes/' . $note['id'] . '/comments', ['body' => 'Clause four is unclear.']);
        $this->assertSame(201, $comment['status']);
        $this->assertSame('user-b', $comment['body']['data']['author_user_id']);
        $this->assertFalse($comment['body']['data']['is_resolved']);

        $edit = $this->bob->patch('/notes/' . $note['id'], ['document' => Support::doc('rewritten by bob')]);
        $this->assertSame(403, $edit['status']);
        $this->assertContainsString('comment on this note, but not edit', $edit['body']['error']['message']);
    }

    public function testAViewerMayReadCommentsButNotWriteOne(): void
    {
        $note = $this->note();
        $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'Sending to legal.']);
        $this->share($note['id'], 'user-b', 'viewer');

        $read = $this->bob->get('/notes/' . $note['id'] . '/comments');
        $this->assertSame(200, $read['status']);
        $this->assertCount(1, $read['body']['data']);
        $this->assertFalse($read['body']['data'][0]['capabilities']['reply']);

        $write = $this->bob->post('/notes/' . $note['id'] . '/comments', ['body' => 'me too']);
        $this->assertSame(403, $write['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $write['body']['error']['code']);
    }

    public function testAStrangerCannotReachCommentsInAnyDirection(): void
    {
        $note = $this->note();
        $comment = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'The bank password is hunter2.',
        ])['body']['data'];

        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/comments')['status']);
        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/comments', ['body' => 'hello'])['status']);
        $this->assertSame(404, $this->bob->patch('/comments/' . $comment['id'], ['body' => 'hijacked'])['status']);
        $this->assertSame(404, $this->bob->post('/comments/' . $comment['id'] . '/resolve')['status']);
        $this->assertSame(404, $this->bob->post('/comments/' . $comment['id'] . '/reopen')['status']);
        $this->assertSame(404, $this->bob->delete('/comments/' . $comment['id'])['status']);

        $survives = $this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data'];
        $this->assertCount(1, $survives);
        $this->assertSame('The bank password is hunter2.', $survives[0]['body']);
    }

    // -- Threads ------------------------------------------------------------

    public function testRepliesAreReturnedUnderTheCommentTheyAnswer(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');

        $root = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'Is this the final wording?'])['body']['data'];
        $reply = $this->bob->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Not yet — legal is still reading it.',
            'parent_id' => $root['id'],
        ])['body']['data'];

        $this->assertSame($root['id'], $reply['parent_id']);

        $threads = $this->alice->get('/notes/' . $note['id'] . '/comments');
        $this->assertCount(1, $threads['body']['data'], 'a reply is not a thread of its own');
        $this->assertCount(1, $threads['body']['data'][0]['replies']);
        $this->assertSame($reply['id'], $threads['body']['data'][0]['replies'][0]['id']);
        $this->assertSame(2, $threads['body']['meta']['total']);
        $this->assertSame(1, $threads['body']['meta']['unresolved']);
        $this->assertFalse($threads['body']['meta']['has_more']);
    }

    public function testAReplyToAReplyJoinsTheSameThread(): void
    {
        $note = $this->note();

        $root = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'First.'])['body']['data'];
        $reply = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Second.',
            'parent_id' => $root['id'],
        ])['body']['data'];

        // Flattened rather than refused: replying under a reply means "reply in
        // this conversation", which has exactly one sensible home.
        $third = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Third.',
            'parent_id' => $reply['id'],
        ]);
        $this->assertSame(201, $third['status']);
        $this->assertSame($root['id'], $third['body']['data']['parent_id']);

        $threads = $this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data'];
        $this->assertCount(1, $threads);
        $this->assertCount(2, $threads[0]['replies']);
    }

    public function testAReplyCannotBeHungOnACommentFromAnotherNote(): void
    {
        $first = $this->note();
        $second = $this->note();
        $elsewhere = $this->alice->post('/notes/' . $first['id'] . '/comments', ['body' => 'On note one.'])['body']['data'];

        $result = $this->alice->post('/notes/' . $second['id'] . '/comments', [
            'body' => 'Stitched across notes.',
            'parent_id' => $elsewhere['id'],
        ]);
        $this->assertSame(404, $result['status']);
        $this->assertCount(0, $this->alice->get('/notes/' . $second['id'] . '/comments')['body']['data']);
    }

    // -- Anchoring ----------------------------------------------------------

    public function testAnAnchoredCommentSurvivesTheBlockBeingEditedAway(): void
    {
        $blockId = Uuid::v4();
        $note = $this->anchoredNote($blockId);

        $comment = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'This clause contradicts clause two.',
            'block_id' => $blockId,
            'anchor_text' => 'the disputed clause',
        ]);
        $this->assertSame(201, $comment['status']);
        $this->assertSame($blockId, $comment['body']['data']['block_id']);
        $this->assertFalse($comment['body']['data']['orphaned']);

        // The paragraph the comment pointed at is rewritten away.
        $this->alice->patch('/notes/' . $note['id'], ['document' => Support::doc('an entirely new draft')]);

        $threads = $this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data'];
        $this->assertCount(1, $threads, 'the comment is never dropped with its anchor');
        $this->assertTrue($threads[0]['orphaned']);
        $this->assertSame('the disputed clause', $threads[0]['anchor_text'], 'the quotation is what keeps it readable');
    }

    public function testCommentsCanBeFilteredToOneBlock(): void
    {
        $blockId = Uuid::v4();
        $note = $this->anchoredNote($blockId);

        $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'On the block.', 'block_id' => $blockId]);
        $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'On the whole note.']);

        $forBlock = $this->alice->get('/notes/' . $note['id'] . '/comments', ['block_id' => $blockId]);
        $this->assertCount(1, $forBlock['body']['data']);
        $this->assertSame('On the block.', $forBlock['body']['data'][0]['body']);
        $this->assertSame(2, $forBlock['body']['meta']['total'], 'the meta counts the note, not the filter');
    }

    // -- Resolve and reopen -------------------------------------------------

    public function testResolvingFromAReplyClosesTheWholeThread(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');

        $root = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'Please check this.'])['body']['data'];
        $reply = $this->bob->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Checked.',
            'parent_id' => $root['id'],
        ])['body']['data'];

        // Resolution is a property of the conversation, not of one message.
        $resolved = $this->bob->post('/comments/' . $reply['id'] . '/resolve');
        $this->assertSame(200, $resolved['status']);
        $this->assertSame($root['id'], $resolved['body']['data']['id']);
        $this->assertTrue($resolved['body']['data']['is_resolved']);
        $this->assertSame('user-b', $resolved['body']['data']['resolved_by']);

        $open = $this->alice->get('/notes/' . $note['id'] . '/comments', ['resolved' => 'open']);
        $this->assertCount(0, $open['body']['data']);
        $this->assertSame(0, $open['body']['meta']['unresolved']);

        $reopened = $this->alice->post('/comments/' . $root['id'] . '/reopen');
        $this->assertFalse($reopened['body']['data']['is_resolved']);
        $this->assertNull($reopened['body']['data']['resolved_by']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/comments', ['resolved' => 'open'])['body']['data']);
    }

    public function testAViewerCannotResolveADiscussion(): void
    {
        $note = $this->note();
        $comment = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'Still open.'])['body']['data'];
        $this->share($note['id'], 'user-b', 'viewer');

        $this->assertSame(403, $this->bob->post('/comments/' . $comment['id'] . '/resolve')['status']);
        $this->assertFalse($this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data'][0]['is_resolved']);
    }

    // -- Editing and deleting -----------------------------------------------

    public function testOnlyTheAuthorMayEditTheirOwnWords(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');
        $comment = $this->bob->post('/notes/' . $note['id'] . '/comments', ['body' => 'I disagree.'])['body']['data'];
        $this->assertFalse($comment['edited']);

        $edited = $this->bob->patch('/comments/' . $comment['id'], ['body' => 'I disagree, respectfully.']);
        $this->assertSame(200, $edited['status']);
        $this->assertSame('I disagree, respectfully.', $edited['body']['data']['body']);
        $this->assertTrue($edited['body']['data']['edited']);

        // Not even the note's owner: removing somebody's words is moderation,
        // replacing them under their name is not.
        $byOwner = $this->alice->patch('/comments/' . $comment['id'], ['body' => 'I agree entirely.']);
        $this->assertSame(403, $byOwner['status']);
        $this->assertSame('COMMENT_NOT_AUTHOR', $byOwner['body']['error']['code']);
    }

    public function testTheAuthorOrTheNoteOwnerMayDeleteAndNobodyElseMay(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');
        $this->share($note['id'], 'user-c', 'commenter');

        $byBob = $this->bob->post('/notes/' . $note['id'] . '/comments', ['body' => 'Bob was here.'])['body']['data'];

        $this->assertSame(403, $this->carol->delete('/comments/' . $byBob['id'])['status']);
        $this->assertSame(204, $this->bob->delete('/comments/' . $byBob['id'])['status']);

        $byCarol = $this->carol->post('/notes/' . $note['id'] . '/comments', ['body' => 'Carol was here.'])['body']['data'];
        $this->assertSame(204, $this->alice->delete('/comments/' . $byCarol['id'])['status'], 'the owner may moderate');

        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data']);
    }

    public function testDeletingTheOpeningCommentTakesItsRepliesWithIt(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'user-b', 'commenter');

        $root = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => 'Question.'])['body']['data'];
        $reply = $this->bob->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Answer.',
            'parent_id' => $root['id'],
        ])['body']['data'];

        $this->assertSame(204, $this->alice->delete('/comments/' . $root['id'])['status']);

        $threads = $this->alice->get('/notes/' . $note['id'] . '/comments');
        $this->assertCount(0, $threads['body']['data'], 'an answer without its question is unreadable');
        $this->assertSame(0, $threads['body']['meta']['total']);
        $this->assertSame(404, $this->bob->patch('/comments/' . $reply['id'], ['body' => 'still here?'])['status']);

        // Soft, not gone: the row survives for the note's own history.
        $rows = Connection::select('SELECT deleted_at FROM note_comments WHERE note_id = :n', ['n' => $note['id']]);
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]['deleted_at']);
    }

    // -- Mentions and the trail ---------------------------------------------

    public function testMentionsAreStoredAsEntityIds(): void
    {
        $note = $this->note();

        $comment = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'Asking @Bob and @ABC Pvt Ltd to look.',
            'mentions' => ['user-b', ['entity_id' => 'contact-8871'], 'user-b', ''],
        ]);
        $this->assertSame(201, $comment['status']);
        $this->assertSame(['user-b', 'contact-8871'], $comment['body']['data']['mentions'], 'deduped, ids only');

        $stored = Connection::selectOne('SELECT mentions FROM note_comments WHERE id = :id', ['id' => $comment['body']['data']['id']]);
        $this->assertSame('["user-b", "contact-8871"]', (string) $stored['mentions']);

        // Absent leaves them alone; an empty array clears them.
        $kept = $this->alice->patch('/comments/' . $comment['body']['data']['id'], ['body' => 'Asking Bob to look.']);
        $this->assertCount(2, $kept['body']['data']['mentions']);
        $cleared = $this->alice->patch('/comments/' . $comment['body']['data']['id'], ['body' => 'Never mind.', 'mentions' => []]);
        $this->assertCount(0, $cleared['body']['data']['mentions']);
    }

    public function testTheTrailRecordsCommentsWithoutRepeatingWhatTheySay(): void
    {
        $blockId = Uuid::v4();
        $note = $this->anchoredNote($blockId);

        $comment = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'The bank password is hunter2.',
            'block_id' => $blockId,
            'anchor_text' => 'the disputed clause',
            'mentions' => ['user-b'],
        ])['body']['data'];
        $this->alice->post('/comments/' . $comment['id'] . '/resolve');

        $activity = $this->alice->get('/notes/' . $note['id'] . '/activity');
        $actions = array_map(static fn (array $row): string => $row['action'], $activity['body']['data']);
        $this->assertTrue(in_array('comment.added', $actions, true));
        $this->assertTrue(in_array('comment.resolved', $actions, true));

        $encoded = (string) json_encode($activity['body']);
        $this->assertFalse(str_contains($encoded, 'hunter2'), 'a comment body must never reach the trail');
        $this->assertFalse(str_contains($encoded, 'disputed clause'), 'nor the note text it quoted');
    }

    // -- Shape --------------------------------------------------------------

    public function testAnEmptyCommentIsRefused(): void
    {
        $note = $this->note();

        $empty = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => '   ']);
        $this->assertSame(422, $empty['status']);
        $this->assertSame('VALIDATION_FAILED', $empty['body']['error']['code']);

        $tooLong = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => str_repeat('a', 10001)]);
        $this->assertSame(422, $tooLong['status'], 'refused rather than silently truncated');

        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/comments')['body']['data']);
    }

    /**
     * Control bytes are stripped, not stored — and never crash the request.
     *
     * A `\u0000` is legal JSON, so it reaches the service as a real NUL. PDO
     * hands parameters to libpq as C strings, which truncated a body at that
     * byte — the silent loss the length cap above refuses to perform. In a
     * `jsonb` value the same byte is refused outright, so a single mention id
     * carrying one answered 500 instead of saving the comment.
     */
    public function testAControlCharacterNeitherTruncatesACommentNorCrashesTheRequest(): void
    {
        $note = $this->note();

        $posted = $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => "visible\u{0000}HIDDEN",
            'anchor_text' => "quoted\u{0000}text",
            'mentions' => ["contact\u{0000}1", 'contact-2'],
        ]);
        $this->assertSame(201, $posted['status'], 'a mention id must not reach jsonb as a control byte');
        $this->assertSame('visibleHIDDEN', $posted['body']['data']['body'], 'nothing is cut off at the NUL');
        $this->assertSame('quotedtext', $posted['body']['data']['anchor_text']);
        $this->assertSame(['contact1', 'contact-2'], $posted['body']['data']['mentions']);

        $stored = Connection::selectOne(
            'SELECT body FROM note_comments WHERE id = :id',
            ['id' => $posted['body']['data']['id']],
        );
        $this->assertSame('visibleHIDDEN', (string) $stored['body'], 'what was saved is what comes back');

        // The edit path carries the same values into the same columns.
        $edited = $this->alice->patch('/comments/' . $posted['body']['data']['id'], [
            'body' => "kept\u{0000}whole",
            'mentions' => ["contact\u{0000}3"],
        ]);
        $this->assertSame(200, $edited['status']);
        $this->assertSame('keptwhole', $edited['body']['data']['body']);
        $this->assertSame(['contact3'], $edited['body']['data']['mentions']);

        // A comment that is nothing but control bytes is the empty one it is.
        $this->assertSame(422, $this->alice->post('/notes/' . $note['id'] . '/comments', [
            'body' => "\u{0000}\u{0001}\u{0007}",
        ])['status']);

        // Newlines are how people write a paragraph, so they survive.
        $multiline = $this->alice->post('/notes/' . $note['id'] . '/comments', ['body' => "one\ntwo"]);
        $this->assertSame("one\ntwo", $multiline['body']['data']['body']);
    }

    /**
     * A comment id is not a way around the company gate.
     *
     * The comment routes are addressed by comment id alone, so this is the
     * property that makes the shorter path safe: the note is resolved from the
     * comment and checked, and a member acting in another company is refused on
     * every one of them.
     */
    public function testACommentIdReachesNothingAcrossACompanyBoundary(): void
    {
        $inCompanyOne = new ApiClient(new Identity('user-a', 'company-1'));
        $note = $inCompanyOne->post('/notes', [
            'title' => 'Board pack',
            'document' => Support::doc('for company one only'),
        ])['body']['data'];
        $this->share($note['id'], 'user-b', 'editor');
        $comment = $inCompanyOne->post('/notes/' . $note['id'] . '/comments', [
            'body' => 'The bank password is hunter2.',
        ])['body']['data'];

        $elsewhere = new ApiClient(new Identity('user-b', 'company-2'));
        $this->assertSame(404, $elsewhere->get('/notes/' . $note['id'] . '/comments')['status']);
        $this->assertSame(404, $elsewhere->post('/notes/' . $note['id'] . '/comments', ['body' => 'hi'])['status']);
        $this->assertSame(404, $elsewhere->patch('/comments/' . $comment['id'], ['body' => 'hijacked'])['status']);
        $this->assertSame(404, $elsewhere->post('/comments/' . $comment['id'] . '/resolve')['status']);
        $this->assertSame(404, $elsewhere->delete('/comments/' . $comment['id'])['status']);

        $inCompanyOneToo = new ApiClient(new Identity('user-b', 'company-1'));
        $this->assertSame(200, $inCompanyOneToo->get('/notes/' . $note['id'] . '/comments')['status']);
    }

    public function testAMalformedCommentIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/notes/not-a-uuid/comments')['status']);
        $this->assertSame(404, $this->alice->patch('/comments/not-a-uuid', ['body' => 'hello'])['status']);
        $this->assertSame(404, $this->alice->delete('/comments/' . Uuid::v4())['status']);
        $this->assertSame(404, $this->alice->post('/comments/' . Uuid::v4() . '/resolve')['status']);
    }
}
