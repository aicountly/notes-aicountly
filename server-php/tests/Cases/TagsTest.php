<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Tags over the real router.
 *
 * A tag list is a navigation surface: if it splits "GST" from "gst", filtering
 * by one silently hides the notes filed under the other, and the user's own
 * filing looks broken. Most of these tests are about that one property holding
 * through every way a tag can be created or renamed.
 */
final class TagsTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Tags';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    private function note(ApiClient $api, string $text, array $tags): array
    {
        return $api->post('/notes', ['document' => Support::doc($text), 'tags' => $tags])['body']['data'];
    }

    /** @return array<int, string> The slugs currently on a note. */
    private function noteTags(ApiClient $api, string $noteId): array
    {
        $tags = $api->get('/notes/' . $noteId)['body']['data']['tags'];

        return array_map(static fn (array $tag): string => $tag['slug'], $tags);
    }

    /** The tag with this slug, from the caller's own list. */
    private function tag(ApiClient $api, string $slug): ?array
    {
        foreach ($api->get('/tags')['body']['data'] as $tag) {
            if ($tag['slug'] === $slug) {
                return $tag;
            }
        }

        return null;
    }

    // -- Listing ------------------------------------------------------------

    public function testListsTagsWithNoteCounts(): void
    {
        $this->note($this->alice, 'invoice queries', ['GST', 'audit']);
        $this->note($this->alice, 'second return', ['gst']);

        $tags = $this->alice->get('/tags');
        $this->assertSame(200, $tags['status']);
        // "GST" and "gst" are one tag, so two notes and one label.
        $this->assertCount(2, $tags['body']['data']);
        $this->assertSame(2, $this->tag($this->alice, 'gst')['note_count']);
        $this->assertSame(1, $this->tag($this->alice, 'audit')['note_count']);
        $this->assertSame('GST', $this->tag($this->alice, 'gst')['name'], 'the display name is what was typed first');
    }

    public function testCountsExcludeTrashedAndArchivedNotes(): void
    {
        $kept = $this->note($this->alice, 'kept', ['gst']);
        $binned = $this->note($this->alice, 'binned', ['gst']);
        $filed = $this->note($this->alice, 'filed', ['gst']);
        unset($kept);

        $this->alice->delete('/notes/' . $binned['id']);
        $this->alice->post('/notes/' . $filed['id'] . '/archive');

        $this->assertSame(1, $this->tag($this->alice, 'gst')['note_count']);
    }

    // -- Create, rename, recolour -------------------------------------------

    public function testCreatesATagFromWhatWasTyped(): void
    {
        $created = $this->alice->post('/tags', ['name' => '#GST Returns', 'color' => 'sage']);

        $this->assertSame(201, $created['status']);
        $this->assertSame('GST Returns', $created['body']['data']['name'], 'the leading # is not part of the name');
        $this->assertSame('gst-returns', $created['body']['data']['slug']);
        $this->assertSame('sage', $created['body']['data']['color']);
    }

    public function testCreatingATagThatAlreadyExistsReturnsTheSameOne(): void
    {
        $first = $this->alice->post('/tags', ['name' => 'gst'])['body']['data'];
        $second = $this->alice->post('/tags', ['name' => 'GST'])['body']['data'];

        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $this->alice->get('/tags')['body']['data']);
    }

    public function testANamelessTagIsRefused(): void
    {
        $result = $this->alice->post('/tags', ['name' => '###']);

        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);
    }

    public function testRenamesAndRecoloursIndependently(): void
    {
        $tag = $this->alice->post('/tags', ['name' => 'gst', 'color' => 'sage'])['body']['data'];

        $renamed = $this->alice->patch('/tags/' . $tag['id'], ['name' => 'GST returns'])['body']['data'];
        $this->assertSame('GST returns', $renamed['name']);
        $this->assertSame('gst-returns', $renamed['slug']);
        $this->assertSame('sage', $renamed['color'], 'a rename must not drop the colour');

        $recoloured = $this->alice->patch('/tags/' . $tag['id'], ['color' => 'sky'])['body']['data'];
        $this->assertSame('sky', $recoloured['color']);
        $this->assertSame('GST returns', $recoloured['name'], 'a recolour must not touch the name');

        $cleared = $this->alice->patch('/tags/' . $tag['id'], ['color' => null])['body']['data'];
        $this->assertNull($cleared['color'], 'an explicit null is how a colour is cleared');
    }

    public function testRenamingATagOntoAnExistingOneMergesThem(): void
    {
        $first = $this->note($this->alice, 'quarterly filing', ['gst']);
        $second = $this->note($this->alice, 'input credit', ['taxes']);

        $taxes = $this->tag($this->alice, 'taxes');
        // Renaming onto a slug that already exists cannot be a rename — the
        // unique index forbids two — so it folds the two labels into one.
        $merged = $this->alice->patch('/tags/' . $taxes['id'], ['name' => 'GST']);

        $this->assertSame(200, $merged['status']);
        $this->assertSame('gst', $merged['body']['data']['slug']);
        $this->assertSame($this->tag($this->alice, 'gst')['id'], $merged['body']['data']['id']);

        $this->assertCount(1, $this->alice->get('/tags')['body']['data']);
        $this->assertSame(2, $this->tag($this->alice, 'gst')['note_count'], 'both notes came across');

        // The notes kept their text and simply share one label now.
        $this->assertSame(['gst'], $this->noteTags($this->alice, $second['id']));
        $this->assertSame(['gst'], $this->noteTags($this->alice, $first['id']));
    }

    // -- Merge --------------------------------------------------------------

    public function testMergeFoldsSeveralTagsIntoOne(): void
    {
        $a = $this->note($this->alice, 'one', ['gst']);
        $b = $this->note($this->alice, 'two', ['g-s-t']);
        $c = $this->note($this->alice, 'three', ['taxes']);

        $target = $this->tag($this->alice, 'taxes');
        $merged = $this->alice->post('/tags/merge', [
            'source_ids' => [$this->tag($this->alice, 'gst')['id'], $this->tag($this->alice, 'g-s-t')['id']],
            'target_id' => $target['id'],
        ]);

        $this->assertSame(200, $merged['status']);
        $this->assertSame($target['id'], $merged['body']['data']['id']);
        $this->assertCount(1, $this->alice->get('/tags')['body']['data']);
        $this->assertSame(3, $this->tag($this->alice, 'taxes')['note_count']);

        foreach ([$a, $b, $c] as $note) {
            $this->assertSame(['taxes'], $this->noteTags($this->alice, $note['id']));
        }
    }

    /**
     * The same source twice is one merge, not a failure.
     *
     * The merge consumes each source in turn, so a repeated id used to find the
     * tag already gone on the second pass, answer 404 about a tag sitting in
     * the caller's own list, and roll the whole thing back — leaving the user
     * with the two labels they asked to fold together and a message saying one
     * of them does not exist.
     */
    public function testARepeatedSourceIsMergedOnceRatherThanFailing(): void
    {
        $note = $this->note($this->alice, 'quarterly filing', ['gst', 'taxes']);
        $gst = $this->tag($this->alice, 'gst');
        $taxes = $this->tag($this->alice, 'taxes');

        $merged = $this->alice->post('/tags/merge', [
            'source_ids' => [$gst['id'], $gst['id']],
            'target_id' => $taxes['id'],
        ]);

        $this->assertSame(200, $merged['status']);
        $this->assertSame($taxes['id'], $merged['body']['data']['id']);
        $this->assertCount(1, $this->alice->get('/tags')['body']['data'], 'the two labels really did fold into one');
        $this->assertSame(['taxes'], $this->noteTags($this->alice, $note['id']));
    }

    /** An unbounded source list is a client fault, not a merge. */
    public function testAnAbsurdlyLongMergeIsRefused(): void
    {
        $target = $this->alice->post('/tags', ['name' => 'taxes'])['body']['data'];

        $tooMany = $this->alice->post('/tags/merge', [
            'source_ids' => array_map(static fn () => Uuid::v4(), range(1, 101)),
            'target_id' => $target['id'],
        ]);

        $this->assertSame(422, $tooMany['status']);
        $this->assertSame('VALIDATION_FAILED', $tooMany['body']['error']['code']);
        $this->assertCount(1, $this->alice->get('/tags')['body']['data']);
    }

    public function testMergeNeedsSomethingToMerge(): void
    {
        $target = $this->alice->post('/tags', ['name' => 'taxes'])['body']['data'];

        $empty = $this->alice->post('/tags/merge', ['source_ids' => [], 'target_id' => $target['id']]);
        $this->assertSame(422, $empty['status']);

        $unknownTarget = $this->alice->post('/tags/merge', [
            'source_ids' => [$target['id']],
            'target_id' => Uuid::v4(),
        ]);
        $this->assertSame(404, $unknownTarget['status']);

        // A malformed id is a 404 as well, not a database error.
        $this->assertSame(404, $this->alice->post('/tags/merge', [
            'source_ids' => [$target['id']],
            'target_id' => 'not-a-uuid',
        ])['status']);
    }

    // -- Delete -------------------------------------------------------------

    public function testDeletingATagRemovesTheLabelAndKeepsTheNote(): void
    {
        $note = $this->note($this->alice, 'still here', ['gst']);
        $tag = $this->tag($this->alice, 'gst');

        $this->assertSame(204, $this->alice->delete('/tags/' . $tag['id'])['status']);
        $this->assertCount(0, $this->alice->get('/tags')['body']['data']);

        $survivor = $this->alice->get('/notes/' . $note['id']);
        $this->assertSame(200, $survivor['status']);
        $this->assertCount(0, $survivor['body']['data']['tags']);
        $this->assertContainsString('still here', $survivor['body']['data']['excerpt']);
    }

    // -- Someone else's tags ------------------------------------------------

    public function testAnotherUsersTagsAreOutOfReach(): void
    {
        $note = $this->note($this->alice, 'alice files this', ['GST']);
        $aliceTag = $this->tag($this->alice, 'gst');
        $bobTag = $this->bob->post('/tags', ['name' => 'taxes'])['body']['data'];

        // Bob's list is his own, and shows nothing of Alice's.
        $this->assertCount(1, $this->bob->get('/tags')['body']['data']);

        // 404 rather than 403: a tag id must not confirm that a tag exists.
        $this->assertSame(404, $this->bob->patch('/tags/' . $aliceTag['id'], ['name' => 'hijacked'])['status']);
        $this->assertSame(404, $this->bob->delete('/tags/' . $aliceTag['id'])['status']);
        $this->assertSame(404, $this->bob->post('/tags/merge', [
            'source_ids' => [$aliceTag['id']],
            'target_id' => $bobTag['id'],
        ])['status']);
        $this->assertSame(404, $this->bob->post('/tags/merge', [
            'source_ids' => [$bobTag['id']],
            'target_id' => $aliceTag['id'],
        ])['status']);

        // Nothing Bob tried touched Alice's tag or her note.
        $this->assertSame('GST', $this->tag($this->alice, 'gst')['name']);
        $this->assertSame(1, $this->tag($this->alice, 'gst')['note_count']);
        $this->assertSame(['gst'], $this->noteTags($this->alice, $note['id']));
        $this->assertCount(1, $this->bob->get('/tags')['body']['data'], 'the refused merge left Bob his own tag');
    }

    public function testAnotherUsersNotesDoNotInflateACount(): void
    {
        $this->note($this->alice, 'alice note', ['gst']);
        $this->note($this->bob, 'bob note', ['gst']);

        // Same slug, two owners, two rows: a count never spans users.
        $this->assertSame(1, $this->tag($this->alice, 'gst')['note_count']);
        $this->assertSame(1, $this->tag($this->bob, 'gst')['note_count']);
        $this->assertNotSame($this->tag($this->alice, 'gst')['id'], $this->tag($this->bob, 'gst')['id']);
    }

    public function testAMalformedTagIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->patch('/tags/not-a-uuid', ['name' => 'x'])['status']);
        $this->assertSame(404, $this->alice->delete('/tags/' . Uuid::v4())['status']);
    }
}
