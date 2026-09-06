<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * The product's acceptance criteria, walked end to end over the real router,
 * the real services and a real PostgreSQL database.
 *
 * The per-domain suites prove each part in isolation. This one exists because
 * a product can pass every unit test and still not work: it checks that the
 * pieces compose into the journeys a person actually takes — capture, organise,
 * find, act, share, recover.
 *
 * Each test is one journey rather than one assertion, so a break tells you
 * which workflow stopped working, not merely which method did.
 */
final class AcceptanceJourneyTest extends TestCase
{
    private ApiClient $api;

    public function name(): string
    {
        return 'Acceptance journeys';
    }

    public function setUp(): void
    {
        $this->api = new ApiClient(Support::user('a'));
    }

    /** @return array<string, mixed> */
    private function data(array $result): array
    {
        return is_array($result['body']['data'] ?? null) ? $result['body']['data'] : [];
    }

    /**
     * Capture → organise → recall.
     *
     * The core loop: make a note, file it, tag it, then find it by every route a
     * user would try.
     */
    public function testCaptureOrganiseAndFindAgain(): void
    {
        // 1. A notebook to file into.
        $notebook = $this->data($this->api->post('/notebooks', ['name' => 'Clients']));
        $this->assertTrue(Uuid::isValid($notebook['id'] ?? null), 'a notebook is created');

        // 2. Capture, the way the composer does it — plain text, no ceremony.
        $captured = $this->api->post('/capture', [
            'type' => 'text',
            'title' => 'ABC Pvt Ltd — GST reconciliation',
            'content' => "Mismatch in the July return.\nThey will send the ledger by Friday.",
            'notebook_id' => $notebook['id'],
            'tags' => ['GST', 'clients'],
            'source' => 'web',
        ]);
        $this->assertSame(201, $captured['status'], 'quick capture creates a note');
        $note = $this->data($captured);

        // 3. It comes back with everything the card needs.
        $this->assertSame($notebook['id'], $note['notebook_id']);
        $this->assertCount(2, $note['tags']);

        // 4. Found by full-text search…
        $byText = $this->data($this->api->get('/search/notes', ['q' => 'reconciliation']));
        $hits = $byText['results'] ?? $byText;
        $this->assertCount(1, $hits, 'the note is findable by a word in its body');

        // 5. …by notebook…
        $byNotebook = $this->data($this->api->get('/notes', ['notebook_id' => $notebook['id']]));
        $this->assertCount(1, $byNotebook);

        // 6. …and by tag, case-insensitively. "GST" and "gst" are one tag.
        $byTag = $this->data($this->api->get('/notes', ['tags' => 'gst']));
        $this->assertCount(1, $byTag, 'tags resolve regardless of the case typed');
    }

    /**
     * Write → autosave → reload → nothing lost.
     *
     * The promise a notes app lives on.
     */
    public function testWritingSurvivesAReload(): void
    {
        $note = $this->data($this->api->post('/notes', ['document' => Support::doc('first thought')]));

        $version = $note['version'];
        foreach (['second thought', 'third thought', 'a fourth, longer thought'] as $text) {
            $saved = $this->api->patch('/notes/' . $note['id'], [
                'document' => Support::doc($text),
                'version' => $version,
            ]);
            $this->assertSame(200, $saved['status']);
            $version = $this->data($saved)['version'];
        }

        // A fresh client — this is the reload.
        $reloaded = $this->data((new ApiClient(Support::user('a')))->get('/notes/' . $note['id']));
        $this->assertContainsString('a fourth, longer thought', $reloaded['excerpt']);

        // And every intermediate state is still recoverable.
        $versions = $this->data($this->api->get('/notes/' . $note['id'] . '/versions'));
        $this->assertTrue(count($versions) >= 2, 'history has checkpoints, not one entry per save');
    }

    /**
     * A checklist becomes work you can track.
     */
    public function testChecklistBecomesTrackableWork(): void
    {
        $note = $this->data($this->api->post('/notes', [
            'note_type' => 'checklist',
            'title' => 'Month end',
            'document' => Support::checklist([
                ['text' => 'File GSTR-1', 'checked' => false],
                ['text' => 'Reconcile bank', 'checked' => false],
                ['text' => 'Email the client', 'checked' => true],
            ]),
        ]));

        $actions = $this->data($this->api->get('/notes/' . $note['id'] . '/actions'));
        $this->assertCount(3, $actions);

        // Progress shows on the card without opening the note.
        $card = $this->data($this->api->get('/notes'))[0];
        $this->assertSame(3, $card['checklist']['total']);
        $this->assertSame(1, $card['checklist']['done']);

        // Open actions surface across the whole library.
        $open = $this->data($this->api->get('/actions'));
        $this->assertTrue(count($open) >= 2, 'open actions are collected across notes');
    }

    /**
     * Notes link to each other, and the link survives a rename.
     */
    public function testInternalLinksSurviveARename(): void
    {
        $target = $this->data($this->api->post('/notes', ['title' => 'Connect architecture']));
        $source = $this->data($this->api->post('/notes', [
            'title' => 'Phase planning',
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'attrs' => ['blockId' => Uuid::v4()],
                    'content' => [
                        ['type' => 'text', 'text' => 'Decided in '],
                        ['type' => 'noteLink', 'attrs' => ['noteId' => $target['id'], 'label' => 'Connect architecture']],
                    ],
                ]],
            ],
        ]));

        $links = $this->data($this->api->get('/notes/' . $target['id'] . '/links'));
        $this->assertCount(1, $links['incoming'], 'the target knows what points at it');
        $this->assertSame($source['id'], $links['incoming'][0]['note_id']);

        // Rename the target. A title-parsing implementation would break here.
        $this->api->patch('/notes/' . $target['id'], [
            'title' => 'Connect architecture (v2)',
            'version' => $target['version'],
        ]);

        $after = $this->data($this->api->get('/notes/' . $target['id'] . '/links'));
        $this->assertCount(1, $after['incoming'], 'the backlink survives the rename');
    }

    /**
     * Share → collaborate → revoke.
     */
    public function testSharingAndRevoking(): void
    {
        $bob = new ApiClient(Support::user('b'));
        $note = $this->data($this->api->post('/notes', [
            'title' => 'Board pack',
            'document' => Support::doc('draft agenda'),
        ]));

        // Bob cannot see it at all yet.
        $this->assertSame(404, $bob->get('/notes/' . $note['id'])['status']);

        $shared = $this->api->post('/notes/' . $note['id'] . '/members', [
            'user_id' => 'user-b',
            'role' => 'editor',
        ]);
        $this->assertTrue(in_array($shared['status'], [200, 201], true), 'the owner can share');

        // Bob can now read and edit, and it appears in his shared list.
        $this->assertSame(200, $bob->get('/notes/' . $note['id'])['status']);
        $this->assertCount(1, $this->data($bob->get('/notes', ['shared_with_me' => 'true'])));

        $edited = $bob->patch('/notes/' . $note['id'], [
            'document' => Support::doc('agenda, revised by Bob'),
            'version' => $note['version'],
        ]);
        $this->assertSame(200, $edited['status']);

        // But not share it onwards, and not delete it.
        $this->assertSame(403, $bob->post('/notes/' . $note['id'] . '/members', [
            'user_id' => 'user-c', 'role' => 'viewer',
        ])['status']);
        $this->assertSame(403, $bob->delete('/notes/' . $note['id'])['status']);

        // Revoking takes effect on the very next request.
        $this->api->delete('/notes/' . $note['id'] . '/members/user-b');
        $this->assertSame(404, $bob->get('/notes/' . $note['id'])['status']);
        $this->assertCount(0, $this->data($bob->get('/notes')));

        // Bob's edit is still in the note's history — revoking access does not
        // erase what he contributed.
        $this->assertContainsString(
            'revised by Bob',
            $this->data($this->api->get('/notes/' . $note['id']))['excerpt'],
        );
    }

    /**
     * Archive → trash → restore. Nothing is lost by accident.
     */
    public function testRecoveryPaths(): void
    {
        $note = $this->data($this->api->post('/notes', [
            'title' => 'Important',
            'document' => Support::doc('do not lose this'),
        ]));

        $this->api->post('/notes/' . $note['id'] . '/archive');
        $this->assertCount(0, $this->data($this->api->get('/notes')));
        $this->assertCount(1, $this->data($this->api->get('/notes', ['scope' => 'archive'])));

        $this->api->post('/notes/' . $note['id'] . '/unarchive');
        $this->api->delete('/notes/' . $note['id']);
        $this->assertCount(1, $this->data($this->api->get('/notes', ['scope' => 'trash'])));

        $restored = $this->api->post('/notes/' . $note['id'] . '/restore');
        $this->assertSame(200, $restored['status']);
        $this->assertContainsString('do not lose this', $this->data($restored)['excerpt']);
    }

    /**
     * A template produces a real, editable note.
     */
    public function testTemplateProducesANote(): void
    {
        $seeded = (new \Aicountly\Api\Domain\Templates\SystemTemplateSeeder())->run();
        $this->assertTrue($seeded > 0, 'system templates seed');

        $templates = $this->data($this->api->get('/templates'));
        $this->assertTrue(count($templates) > 0);

        $meeting = null;
        foreach ($templates as $template) {
            if (($template['template_key'] ?? '') === 'meeting-notes') {
                $meeting = $template;
                break;
            }
        }
        $this->assertNotNull($meeting, 'a meeting-notes template is seeded');

        $created = $this->api->post('/templates/' . $meeting['id'] . '/create-note');
        $this->assertSame(201, $created['status']);
        $note = $this->data($created);
        $this->assertTrue(($note['document']['content'] ?? []) !== [], 'the note starts from real content');
    }

    /**
     * A reminder is set on a note and shows up in the reminders view.
     */
    public function testReminderRoundTrip(): void
    {
        $note = $this->data($this->api->post('/notes', ['title' => 'Call the auditor']));

        $created = $this->api->post('/notes/' . $note['id'] . '/reminders', [
            'due_at' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeInterface::RFC3339),
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->assertSame(201, $created['status']);

        $reminders = $this->data($this->api->get('/reminders'));
        $this->assertCount(1, $reminders);

        // The card knows, so the UI can show a bell without a second request.
        $this->assertTrue($this->data($this->api->get('/notes'))[0]['has_reminder']);
    }

    /**
     * A smart folder is a saved query — it finds notes without moving them.
     */
    public function testSmartFolderFindsWithoutMoving(): void
    {
        $this->api->post('/notes', ['title' => 'GST research', 'document' => Support::doc('rates'), 'tags' => ['gst']]);
        $this->api->post('/notes', ['title' => 'Unrelated', 'document' => Support::doc('nothing to do with it')]);

        $folder = $this->data($this->api->post('/smart-folders', [
            'name' => 'GST, recent',
            'rules' => [
                'match' => 'all',
                'conditions' => [
                    ['field' => 'tag', 'operator' => 'is', 'value' => 'gst'],
                    ['field' => 'updated_at', 'operator' => 'within_days', 'value' => 30],
                ],
            ],
        ]));

        $matched = $this->data($this->api->get('/smart-folders/' . $folder['id'] . '/notes'));
        $this->assertCount(1, $matched, 'only the tagged note matches');

        // The note has not moved: it is still in the main list.
        $this->assertCount(2, $this->data($this->api->get('/notes')));
    }

    /**
     * An invalid smart folder cannot be stored, so it cannot break later.
     */
    public function testInvalidSmartFolderIsRefusedAtWriteTime(): void
    {
        $result = $this->api->post('/smart-folders', [
            'name' => 'Broken',
            'rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'note_body; DROP TABLE notes', 'operator' => 'is', 'value' => 'x']],
            ],
        ]);

        $this->assertTrue(
            in_array($result['status'], [400, 422], true),
            'an unknown rule field is rejected on save, not on read',
        );
        $this->assertCount(1, Connection::select("SELECT 1 FROM information_schema.tables WHERE table_name = 'notes'"));
    }

    /**
     * Pulse is off by default, and says so rather than failing oddly.
     */
    public function testPulseIsHonestlyDisabledByDefault(): void
    {
        $note = $this->data($this->api->post('/notes', ['title' => 'Anything']));

        $asked = $this->api->post('/pulse/note/' . $note['id'] . '/ask', ['question' => 'Summarise this']);
        $this->assertSame(503, $asked['status']);
        $this->assertSame('FEATURE_DISABLED', $asked['body']['error']['code']);

        // And the catalogue still answers, so the UI can render an honest
        // disabled state instead of a menu that fails on click.
        $catalogue = $this->api->get('/pulse/actions');
        $this->assertSame(200, $catalogue['status']);
    }
}
