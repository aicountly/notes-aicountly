<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Domain\Templates\SystemTemplateSeeder;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Templates over the real router.
 *
 * Two properties carry most of these tests. The first is that a template list
 * is a *shared* surface — system templates everyone sees, company templates a
 * colleague sees, private ones nobody else does — so the tests are largely
 * about which of the three a given caller gets, and what happens when they
 * reach for one of the others. The second is that the seeder is run on every
 * deploy: if it were not idempotent, the picker would grow a duplicate set of
 * built-ins each time somebody shipped.
 */
final class TemplatesTest extends TestCase
{
    /** Everything `SystemTemplateSeeder` ships, in picker order. */
    private const SEEDED_KEYS = [
        'blank', 'meeting-notes', 'daily-note', 'weekly-review', 'brainstorm',
        'todo', 'project-notes', 'research-note', 'decision-note',
        'client-meeting', 'minutes-of-meeting', 'phone-call', 'sop',
    ];

    private ApiClient $alice;
    private ApiClient $bob;
    private ApiClient $carol;
    private ApiClient $solo;

    public function name(): string
    {
        return 'Templates';
    }

    public function setUp(): void
    {
        // Alice and Bob are colleagues; Carol is at another company; Solo has
        // no company at all. Those four cover every branch of the visibility
        // gate between them.
        $this->alice = new ApiClient(Support::user('a', 'acme'));
        $this->bob = new ApiClient(Support::user('b', 'acme'));
        $this->carol = new ApiClient(Support::user('c', 'globex'));
        $this->solo = new ApiClient(Support::user('s'));

        // Clock::freeze is process-wide, so a test that pins time must not
        // leak it into the next one.
        Clock::freeze(null);
    }

    private function seed(): int
    {
        return (new SystemTemplateSeeder())->run();
    }

    /** @return array<int, array<string, mixed>> */
    private function list(ApiClient $api, array $query = []): array
    {
        return $api->get('/templates', $query)['body']['data'];
    }

    /** The listed template with this key, or null. */
    private function byKey(ApiClient $api, string $key): ?array
    {
        foreach ($this->list($api) as $template) {
            if (($template['template_key'] ?? null) === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return array<int, string> Names in the order the API returned them. */
    private function names(ApiClient $api): array
    {
        return array_map(static fn (array $t): string => $t['name'], $this->list($api));
    }

    private function template(ApiClient $api, array $body = []): array
    {
        return $api->post('/templates', $body + ['name' => 'Untitled'])['body']['data'];
    }

    /** Every node in a document, depth-first — the cheapest structural fingerprint. */
    private static function nodeTypes(array $node): array
    {
        $types = [(string) ($node['type'] ?? '?')];
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $types = array_merge($types, self::nodeTypes($child));
            }
        }

        return $types;
    }

    // -- The seeder ---------------------------------------------------------

    public function testSeedsTheCatalogueAndSaysHowMany(): void
    {
        $this->assertSame(count(self::SEEDED_KEYS), $this->seed());

        $keys = array_map(static fn (array $t): ?string => $t['template_key'], $this->list($this->alice));
        $this->assertSame(self::SEEDED_KEYS, $keys, 'the picker order is the catalogue order');
    }

    public function testReSeedingUpdatesInPlaceInsteadOfDuplicating(): void
    {
        $this->seed();
        $first = $this->byKey($this->alice, 'daily-note');

        // A deploy runs the seeder again. Nothing may duplicate, and the ids
        // must survive: notes record where they came from.
        $this->assertSame(count(self::SEEDED_KEYS), $this->seed());
        $this->assertSame(count(self::SEEDED_KEYS), $this->seed());

        $this->assertCount(count(self::SEEDED_KEYS), $this->list($this->alice));
        $this->assertSame($first['id'], $this->byKey($this->alice, 'daily-note')['id']);
    }

    public function testSeededDocumentsAreRealAndSurviveSanitisation(): void
    {
        $this->seed();

        foreach ($this->list($this->alice) as $listed) {
            $template = $this->alice->get('/templates/' . $listed['id'])['body']['data'];
            $document = $template['document'];

            // Nothing in a seeded document may be dropped by the sanitiser: a
            // node type typed wrongly here would ship a template with a
            // silently missing block.
            $this->assertSame(
                self::nodeTypes($document),
                self::nodeTypes(NoteDocument::sanitize($document)),
                $listed['template_key'] . ' survives sanitisation intact',
            );
        }

        $meeting = $this->alice->get('/templates/' . $this->byKey($this->alice, 'meeting-notes')['id'])['body']['data'];
        $types = self::nodeTypes($meeting['document']);

        // Real structure, not a paragraph of placeholder text.
        $this->assertTrue(in_array('heading', $types, true), 'meeting notes has headings');
        $this->assertTrue(in_array('taskList', $types, true), 'meeting notes has an action checklist');
        $this->assertTrue(in_array('callout', $types, true), 'meeting notes has a callout');
        $this->assertSame('meeting', $meeting['note_type']);
        $this->assertSame(['meeting'], $meeting['default_tags']);
    }

    public function testSeededTemplatesAreVisibleToEveryone(): void
    {
        $this->seed();

        foreach ([$this->alice, $this->carol, $this->solo] as $api) {
            $this->assertCount(count(self::SEEDED_KEYS), $this->list($api));
        }
    }

    // -- Listing and scope --------------------------------------------------

    public function testListIsSystemThenCompanyThenOwn(): void
    {
        $this->seed();
        $this->template($this->alice, ['name' => 'Alice private', 'scope' => 'user']);
        $this->template($this->alice, ['name' => 'Acme letterhead', 'scope' => 'tenant']);

        $names = $this->names($this->alice);
        $this->assertCount(count(self::SEEDED_KEYS) + 2, $names);
        $this->assertSame('Blank note', $names[0], 'system first');
        $this->assertSame('Acme letterhead', $names[count($names) - 2], 'then the company’s');
        $this->assertSame('Alice private', $names[count($names) - 1], 'then the caller’s own');
    }

    public function testACompanyTemplateIsSharedButOnlyItsAuthorMayChangeIt(): void
    {
        $shared = $this->template($this->alice, ['name' => 'Acme letterhead', 'scope' => 'tenant']);

        // Bob works at Acme: he sees it and may start notes from it.
        $this->assertSame('Acme letterhead', $this->list($this->bob)[0]['name']);
        $this->assertSame(200, $this->bob->get('/templates/' . $shared['id'])['status']);
        $this->assertFalse($this->bob->get('/templates/' . $shared['id'])['body']['data']['editable']);
        $this->assertSame(201, $this->bob->post('/templates/' . $shared['id'] . '/create-note')['status']);

        // But a shared template a colleague could rewrite is one nobody can
        // rely on: 403, because Bob can see it — this is not a hidden id.
        $refused = $this->bob->patch('/templates/' . $shared['id'], ['name' => 'Bob’s letterhead']);
        $this->assertSame(403, $refused['status']);
        $this->assertSame('TEMPLATE_NOT_OWNED', $refused['body']['error']['code']);
        $this->assertSame(403, $this->bob->delete('/templates/' . $shared['id'])['status']);

        $this->assertSame('Acme letterhead', $this->list($this->alice)[0]['name'], 'nothing Bob tried landed');
        $this->assertTrue($this->list($this->alice)[0]['editable']);
    }

    public function testAnotherCompanyNeverSeesIt(): void
    {
        $shared = $this->template($this->alice, ['name' => 'Acme letterhead', 'scope' => 'tenant']);
        $private = $this->template($this->alice, ['name' => 'Alice private']);

        foreach ([$this->carol, $this->solo] as $outsider) {
            $this->assertCount(0, $this->list($outsider));
            foreach ([$shared, $private] as $template) {
                // 404, never 403: an id must not confirm a template exists.
                $this->assertSame(404, $outsider->get('/templates/' . $template['id'])['status']);
                $this->assertSame(404, $outsider->post('/templates/' . $template['id'] . '/create-note')['status']);
            }
        }
    }

    public function testAPersonalTemplateNeedsNoCompany(): void
    {
        $mine = $this->template($this->solo, ['name' => 'Reading list']);

        $this->assertSame('Reading list', $this->list($this->solo)[0]['name']);
        $this->assertTrue($this->list($this->solo)[0]['editable']);
        $this->assertSame(201, $this->solo->post('/templates/' . $mine['id'] . '/create-note')['status']);
    }

    public function testATemplateMadeAtOneCompanyDoesNotFollowYouToAnother(): void
    {
        $atAcme = $this->template($this->alice, ['name' => 'Acme working papers']);

        // The same person, acting in a different company. Their own template
        // stays where it was made, exactly as a note does — the second gate in
        // NoteAccess, applied to templates.
        $elsewhere = new ApiClient(Support::user('a', 'globex'));

        $this->assertCount(0, $this->list($elsewhere));
        $this->assertSame(404, $elsewhere->get('/templates/' . $atAcme['id'])['status']);
        $this->assertSame(404, $elsewhere->patch('/templates/' . $atAcme['id'], ['name' => 'moved'])['status']);
        $this->assertSame(200, $this->alice->get('/templates/' . $atAcme['id'])['status']);
    }

    public function testAnotherUsersPrivateTemplateIsOutOfReach(): void
    {
        $private = $this->template($this->alice, ['name' => 'Alice private', 'description' => 'mine']);

        // Bob is a colleague, so the company gate lets him past — the scope
        // is what stops him.
        $this->assertCount(0, $this->list($this->bob));
        $this->assertSame(404, $this->bob->get('/templates/' . $private['id'])['status']);
        $this->assertSame(404, $this->bob->patch('/templates/' . $private['id'], ['name' => 'hijacked'])['status']);
        $this->assertSame(404, $this->bob->delete('/templates/' . $private['id'])['status']);
        $this->assertSame(404, $this->bob->post('/templates/' . $private['id'] . '/create-note')['status']);

        $survivor = $this->alice->get('/templates/' . $private['id']);
        $this->assertSame('Alice private', $survivor['body']['data']['name']);
        $this->assertSame('mine', $survivor['body']['data']['description']);
    }

    public function testArchivedTemplatesLeaveThePickerButAreStillThere(): void
    {
        $template = $this->template($this->alice, ['name' => 'Old form']);
        $this->alice->patch('/templates/' . $template['id'], ['is_archived' => true]);

        $this->assertCount(0, $this->list($this->alice));
        $this->assertCount(1, $this->list($this->alice, ['include_archived' => 'true']));
        $this->assertSame(200, $this->alice->get('/templates/' . $template['id'])['status']);
    }

    // -- System templates are read-only -------------------------------------

    public function testASystemTemplateCannotBeEditedOrDeleted(): void
    {
        $this->seed();
        $blank = $this->byKey($this->alice, 'blank');
        $this->assertFalse($blank['editable']);

        $edit = $this->alice->patch('/templates/' . $blank['id'], ['name' => 'Mine now']);
        $this->assertSame(403, $edit['status']);
        $this->assertSame('TEMPLATE_READ_ONLY', $edit['body']['error']['code']);

        $delete = $this->alice->delete('/templates/' . $blank['id']);
        $this->assertSame(403, $delete['status']);
        $this->assertSame('TEMPLATE_READ_ONLY', $delete['body']['error']['code']);

        $this->assertSame('Blank note', $this->byKey($this->alice, 'blank')['name']);
    }

    public function testNobodyCanCreateASystemTemplateThroughTheApi(): void
    {
        $refused = $this->alice->post('/templates', ['name' => 'Looks official', 'scope' => 'system']);

        $this->assertSame(403, $refused['status']);
        $this->assertSame('TEMPLATE_READ_ONLY', $refused['body']['error']['code']);
        $this->assertCount(0, $this->list($this->alice));
    }

    // -- Creating templates -------------------------------------------------

    public function testCreatesATemplateWithItsOwnDocument(): void
    {
        $created = $this->alice->post('/templates', [
            'name' => 'Site visit',
            'description' => 'For the quarterly walk-round.',
            'icon' => 'clipboard',
            'note_type' => 'checklist',
            'title_template' => 'Site visit — {{date}}',
            'default_tags' => ['#Site Visit', 'site visit', 'audit'],
            'document' => Support::checklist([['text' => 'Check the fire exits']]),
        ]);

        $this->assertSame(201, $created['status']);
        $template = $created['body']['data'];
        $this->assertSame('user', $template['scope']);
        $this->assertTrue($template['editable']);
        $this->assertNull($template['template_key']);
        $this->assertSame('checklist', $template['note_type']);
        // "#Site Visit" and "site visit" are one tag, so the template stores
        // one slug rather than handing a note two labels that fold into one.
        $this->assertSame(['site-visit', 'audit'], $template['default_tags']);
        $this->assertTrue(in_array('taskItem', self::nodeTypes($template['document']), true));
    }

    public function testARepeatedCreateDoesNotLeaveTwoTemplates(): void
    {
        $id = Uuid::v4();
        $first = $this->alice->post('/templates', ['id' => $id, 'name' => 'Site visit']);
        $second = $this->alice->post('/templates', ['id' => $id, 'name' => 'Site visit']);

        $this->assertSame(201, $second['status']);
        $this->assertSame($first['body']['data']['id'], $second['body']['data']['id']);
        $this->assertCount(1, $this->list($this->alice));
    }

    public function testATemplateNeedsANameAndAKnownScope(): void
    {
        $nameless = $this->alice->post('/templates', ['name' => '   ']);
        $this->assertSame(422, $nameless['status']);
        $this->assertSame('VALIDATION_FAILED', $nameless['body']['error']['code']);

        $this->assertSame(422, $this->alice->post('/templates', ['name' => 'x', 'scope' => 'global'])['status']);
        $this->assertSame(422, $this->alice->post('/templates', ['name' => 'x', 'note_type' => 'hologram'])['status']);

        // Somebody with no company cannot make a company template.
        $noCompany = $this->solo->post('/templates', ['name' => 'x', 'scope' => 'tenant']);
        $this->assertSame(422, $noCompany['status']);
    }

    public function testAMalformedTemplateIdIsNotFoundRatherThanAServerError(): void
    {
        $this->assertSame(404, $this->alice->get('/templates/not-a-uuid')['status']);
        $this->assertSame(404, $this->alice->patch('/templates/not-a-uuid', ['name' => 'x'])['status']);
        $this->assertSame(404, $this->alice->delete('/templates/' . Uuid::v4())['status']);
        $this->assertSame(404, $this->alice->post('/templates/' . Uuid::v4() . '/create-note')['status']);
    }

    // -- Save this note as a template ---------------------------------------

    public function testSavesANoteAsATemplate(): void
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Quarterly GST checklist',
            'document' => Support::checklist([['text' => 'Reconcile input credit'], ['text' => 'File the return']]),
            'note_type' => 'checklist',
            'tags' => ['GST', 'compliance'],
        ])['body']['data'];

        $saved = $this->alice->post('/templates', ['from_note_id' => $note['id']]);

        $this->assertSame(201, $saved['status']);
        $template = $saved['body']['data'];
        $this->assertSame('Quarterly GST checklist', $template['name'], 'the note’s title names the template');
        $this->assertSame('checklist', $template['note_type']);
        $this->assertSame(['gst', 'compliance'], $template['default_tags']);

        $document = $this->alice->get('/templates/' . $template['id'])['body']['data']['document'];
        $this->assertSame(2, count(array_filter(self::nodeTypes($document), static fn (string $t) => $t === 'taskItem')));

        // The note is untouched by having been copied.
        $this->assertSame(200, $this->alice->get('/notes/' . $note['id'])['status']);
    }

    public function testSavingSomebodyElsesNoteAsATemplateIsRefused(): void
    {
        $bobsNote = $this->bob->post('/notes', ['document' => Support::doc('bob’s private working papers')])['body']['data'];

        // Same company, but no grant on the note: 404, and nothing is created.
        $refused = $this->alice->post('/templates', ['from_note_id' => $bobsNote['id']]);
        $this->assertSame(404, $refused['status']);
        $this->assertCount(0, $this->list($this->alice));

        $this->assertSame(404, $this->alice->post('/templates', ['from_note_id' => 'not-a-uuid'])['status']);
        $this->assertSame(404, $this->alice->post('/templates', ['from_note_id' => Uuid::v4()])['status']);
    }

    /**
     * A company template publishes a document to everyone at the company, so
     * building one out of somebody else's note is a re-share of that note —
     * and re-sharing is the owner's decision, not a reader's.
     */
    public function testACompanyTemplateCannotRepublishSomebodyElsesNote(): void
    {
        $note = $this->alice->post('/notes', [
            'title' => 'Partner drawings',
            'document' => Support::doc('Alice 60, Bob 40'),
        ])['body']['data'];

        // Bob gets the strongest role short of ownership. Even that is not
        // enough: an editor may rewrite the note, not widen its audience.
        $this->alice->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'editor']);

        $refused = $this->bob->post('/templates', [
            'from_note_id' => $note['id'],
            'scope' => 'tenant',
            'name' => 'Acme drawings',
        ]);
        $this->assertSame(403, $refused['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $refused['body']['error']['code']);

        // A colleague with no grant on the note sees nothing appear.
        $dave = new ApiClient(Support::user('d', 'acme'));
        $this->assertCount(0, $this->list($dave), 'nothing was published to the company');

        // Bob may still keep a private copy: that is only what he could do by
        // selecting the note and pasting it.
        $own = $this->bob->post('/templates', ['from_note_id' => $note['id'], 'name' => 'My copy']);
        $this->assertSame(201, $own['status']);
        $this->assertSame('user', $own['body']['data']['scope']);
        $this->assertCount(0, $this->list($dave), 'a personal copy stays personal');

        // And the owner may publish her own note, which is the point of scope.
        $published = $this->alice->post('/templates', [
            'from_note_id' => $note['id'],
            'scope' => 'tenant',
            'name' => 'Acme drawings',
        ]);
        $this->assertSame(201, $published['status']);
        $this->assertSame(['Acme drawings'], $this->names($dave));
    }

    // -- Starting a note from a template ------------------------------------

    public function testCreatesANoteFromATemplate(): void
    {
        $this->seed();
        // 2026-09-06T22:30:00Z, chosen so the timezone below lands on the next
        // day: a daily note titled with yesterday's date is the bug this
        // parameter exists to prevent.
        Clock::freeze(1788733800);

        $meeting = $this->byKey($this->alice, 'meeting-notes');
        $created = $this->alice->post('/templates/' . $meeting['id'] . '/create-note');

        $this->assertSame(201, $created['status']);
        $note = $created['body']['data'];
        $this->assertSame('Meeting — 2026-09-06', $note['title']);
        $this->assertSame('meeting', $note['note_type']);
        $this->assertSame('meeting-notes', $note['template_key'], 'the note records which template made it');
        $this->assertSame(['meeting'], array_map(static fn (array $t): string => $t['slug'], $note['tags']));
        $this->assertTrue(in_array('taskList', self::nodeTypes($note['document']), true));

        // The checklist in the template became real actions on the note.
        $this->assertCount(2, $note['actions']);

        Clock::freeze(null);
    }

    public function testTitleTokensResolveInTheCallersTimezone(): void
    {
        $this->seed();
        Clock::freeze(1788733800); // 2026-09-06 22:30 UTC

        $call = $this->byKey($this->alice, 'phone-call');
        $utc = $this->alice->post('/templates/' . $call['id'] . '/create-note')['body']['data'];
        $this->assertSame('Call — 2026-09-06 22:30', $utc['title']);

        $kolkata = $this->alice->post('/templates/' . $call['id'] . '/create-note', [
            'timezone' => 'Asia/Kolkata',
        ])['body']['data'];
        $this->assertSame('Call — 2026-09-07 04:00', $kolkata['title']);

        // A timezone the server cannot resolve is said out loud, not quietly
        // swapped for UTC — a wrong date nobody was told about is unfixable.
        $bad = $this->alice->post('/templates/' . $call['id'] . '/create-note', ['timezone' => 'Mars/Olympus']);
        $this->assertSame(422, $bad['status']);

        Clock::freeze(null);
    }

    public function testATemplateWithoutATitleTemplateLeavesTheNoteUntitled(): void
    {
        $this->seed();

        $sop = $this->byKey($this->alice, 'sop');
        $note = $this->alice->post('/templates/' . $sop['id'] . '/create-note')['body']['data'];

        $this->assertNull($note['title'], 'the user names their own procedure');
        $this->assertSame('Purpose', $note['display_title'], 'until then the first line stands in');
    }

    public function testTheRequestMayOverrideWhatTheTemplateSuggests(): void
    {
        $this->seed();
        Clock::freeze(1788733800); // 2026-09-06 22:30 UTC
        $notebook = $this->alice->post('/notebooks', ['name' => 'Clients'])['body']['data'];
        $daily = $this->byKey($this->alice, 'daily-note');

        $note = $this->alice->post('/templates/' . $daily['id'] . '/create-note', [
            'title' => 'Standup {{date}}',
            'notebook_id' => $notebook['id'],
            'tags' => ['standup'],
        ])['body']['data'];

        // A title the request supplies gets the same tokens as the template's
        // own, so a "name this note" dialog can offer {{date}} and mean it.
        $this->assertSame('Standup 2026-09-06', $note['title']);
        $this->assertSame($notebook['id'], $note['notebook_id']);
        // An explicit list replaces the template's defaults rather than adding
        // to them, so "create it with exactly these tags" means what it says.
        $this->assertSame(['standup'], array_map(static fn (array $t): string => $t['slug'], $note['tags']));

        Clock::freeze(null);
    }

    // -- Editing and deleting your own --------------------------------------

    public function testEditsOnlyWhatTheRequestNames(): void
    {
        $template = $this->template($this->alice, [
            'name' => 'Site visit',
            'description' => 'For the quarterly walk-round.',
            'title_template' => 'Site visit — {{date}}',
            'default_tags' => ['audit'],
        ]);

        $renamed = $this->alice->patch('/templates/' . $template['id'], ['name' => 'Site inspection'])['body']['data'];
        $this->assertSame('Site inspection', $renamed['name']);
        $this->assertSame('For the quarterly walk-round.', $renamed['description'], 'a rename keeps the rest');
        $this->assertSame(['audit'], $renamed['default_tags']);

        $cleared = $this->alice->patch('/templates/' . $template['id'], ['description' => null])['body']['data'];
        $this->assertNull($cleared['description'], 'an explicit null is how a description is cleared');
        $this->assertSame('Site inspection', $cleared['name']);
    }

    public function testDeletingATemplateLeavesTheNotesMadeFromIt(): void
    {
        $template = $this->template($this->alice, [
            'name' => 'Site visit',
            'document' => Support::doc('Fire exits'),
        ]);
        $note = $this->alice->post('/templates/' . $template['id'] . '/create-note')['body']['data'];

        $this->assertSame(204, $this->alice->delete('/templates/' . $template['id'])['status']);
        $this->assertCount(0, $this->list($this->alice));
        $this->assertSame(404, $this->alice->get('/templates/' . $template['id'])['status']);

        $survivor = $this->alice->get('/notes/' . $note['id']);
        $this->assertSame(200, $survivor['status']);
        $this->assertContainsString('Fire exits', $survivor['body']['data']['excerpt']);
        $this->assertSame($template['id'], $survivor['body']['data']['template_key']);
    }
}
