<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Links\EntityLinkService;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Links from a note out to the rest of AICOUNTLY.
 *
 * The two properties worth a test each:
 *
 *   - **The type is an allowlist.** A link whose type nothing can render is not
 *     a harmless row; it is a link that opens nowhere, and it is the field the
 *     note filters index on.
 *   - **A link the document wrote belongs to the document.** Mentions typed
 *     into a note are reconciled on every save, so deleting one through the API
 *     would produce a link that comes back on the next keystroke. It is refused
 *     with the place to actually remove it.
 *
 * Plus the one that is not about this domain at all: another user's note is a
 * 404 here, whatever they try.
 */
final class EntityLinksTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'EntityLinks';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function note(): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Acme retainer',
            'document' => Support::doc('Renewal in April.'),
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

    /** A document with an inline mention — the shape the editor produces. */
    private function docMentioning(string $entityType, string $entityId, string $label): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['blockId' => Uuid::v4()],
                'content' => [
                    ['type' => 'text', 'text' => 'Spoke with '],
                    ['type' => 'mention', 'attrs' => [
                        'entityType' => $entityType,
                        'entityId' => $entityId,
                        'label' => $label,
                    ]],
                ],
            ]],
        ];
    }

    // -- Creating and listing ------------------------------------------------

    public function testALinkIsCreatedAndListed(): void
    {
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'invoice',
            'entity_id' => 'INV-2030-114',
            'label' => 'Acme retainer — April',
            'metadata' => ['amount_currency' => 'INR'],
        ]);

        $this->assertSame(201, $created['status']);
        $link = $created['body']['data'];
        $this->assertSame('invoice', $link['entity_type']);
        $this->assertSame('INV-2030-114', $link['entity_id']);
        $this->assertSame('Acme retainer — April', $link['label']);
        $this->assertSame('INR', $link['metadata']['amount_currency']);
        $this->assertFalse($link['via_document']);
        $this->assertSame('user-a', $link['created_by']);

        $listed = $this->alice->get('/notes/' . $note['id'] . '/entities');
        $this->assertSame(200, $listed['status']);
        $this->assertCount(1, $listed['body']['data']);
        $this->assertSame($link['id'], $listed['body']['data'][0]['id']);
    }

    public function testTheEntityTypeComesFromTheAllowlist(): void
    {
        $note = $this->note();

        $refused = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'bank_account',
            'entity_id' => 'acct-1',
        ]);

        $this->assertSame(422, $refused['status']);
        $this->assertSame('VALIDATION_FAILED', $refused['body']['error']['code']);
        $this->assertContainsString(
            'connect_meeting',
            (string) $refused['body']['error']['details']['fields']['entity_type'],
            'the message should say what is allowed',
        );
        $this->assertCount(0, Connection::select('SELECT id FROM note_entity_links'));

        // Every documented type is in fact accepted.
        foreach (EntityLinkService::ENTITY_TYPES as $index => $type) {
            $accepted = $this->alice->post('/notes/' . $note['id'] . '/entities', [
                'entity_type' => $type,
                'entity_id' => 'ext-' . $index,
            ]);
            $this->assertSame(201, $accepted['status'], $type . ' should be linkable');
        }
    }

    public function testALinkNeedsTheIdOfWhatItPointsAt(): void
    {
        $note = $this->note();

        $refused = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => '   ',
        ]);

        $this->assertSame(422, $refused['status']);
        $this->assertCount(0, Connection::select('SELECT id FROM note_entity_links'));
    }

    public function testLinkingTheSameRecordTwiceRefreshesItInsteadOfDuplicating(): void
    {
        $note = $this->note();
        $first = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => 'contact-11',
            'label' => 'R. Sharma',
        ])['body']['data'];

        $second = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => 'contact-11',
            'label' => 'Ravi Sharma',
        ]);

        $this->assertSame(201, $second['status']);
        $this->assertSame($first['id'], $second['body']['data']['id'], 'the same record is the same link');
        $this->assertSame('Ravi Sharma', $second['body']['data']['label']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data']);

        // A cached label is never replaced with nothing.
        $third = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => 'contact-11',
        ]);
        $this->assertSame('Ravi Sharma', $third['body']['data']['label']);
    }

    public function testALabelIsLeftEmptyRatherThanInventedWhenContactsIsOff(): void
    {
        $this->assertFalse(Features::enabled(Features::CONTACTS));
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => 'contact-11',
        ]);

        // The link is still worth recording; the name is simply not known here.
        $this->assertSame(201, $created['status']);
        $this->assertNull($created['body']['data']['label']);
    }

    // -- Links the document owns ---------------------------------------------

    public function testALinkWrittenByTheDocumentCannotBeDeletedThroughTheApi(): void
    {
        $note = $this->note();

        $this->alice->patch('/notes/' . $note['id'], [
            'document' => $this->docMentioning('contact', 'contact-11', 'Ravi Sharma'),
            'version' => $note['version'],
        ]);

        $links = $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data'];
        $this->assertCount(1, $links);
        $this->assertTrue($links[0]['via_document'], 'the mention is the source of this link');

        $refused = $this->alice->delete('/notes/' . $note['id'] . '/entities/' . $links[0]['id']);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('ENTITY_LINK_FROM_DOCUMENT', $refused['body']['error']['code']);
        $this->assertContainsString('mention', (string) $refused['body']['error']['message']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data']);

        // Removing the mention from the text is what removes the link, and it
        // works — which is why refusing above is not a dead end.
        $current = $this->alice->get('/notes/' . $note['id'])['body']['data'];
        $this->alice->patch('/notes/' . $note['id'], [
            'document' => Support::doc('Spoke with someone.'),
            'version' => $current['version'],
        ]);
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data']);
    }

    public function testAddingTheSameLinkByHandDoesNotTakeItFromTheDocument(): void
    {
        $note = $this->note();
        $this->alice->patch('/notes/' . $note['id'], [
            'document' => $this->docMentioning('contact', 'contact-11', 'Ravi Sharma'),
            'version' => $note['version'],
        ]);

        $reAdded = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'contact',
            'entity_id' => 'contact-11',
            'label' => 'Ravi S',
        ]);

        $this->assertSame(201, $reAdded['status']);
        $this->assertSame('Ravi S', $reAdded['body']['data']['label'], 'the label may still be refreshed');
        $this->assertTrue($reAdded['body']['data']['via_document'], 'ownership stays with the text');
        $this->assertSame(
            409,
            $this->alice->delete('/notes/' . $note['id'] . '/entities/' . $reAdded['body']['data']['id'])['status'],
        );
    }

    public function testAClientCannotClaimALinkCameFromTheDocument(): void
    {
        $note = $this->note();

        $refused = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'project',
            'entity_id' => 'proj-9',
            'metadata' => ['via_document' => 'true'],
        ]);

        $this->assertSame(422, $refused['status']);
        $this->assertCount(0, Connection::select('SELECT id FROM note_entity_links'));
    }

    // -- Removing ------------------------------------------------------------

    public function testAHandMadeLinkIsRemovable(): void
    {
        $note = $this->note();
        $link = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'drive_file',
            'entity_id' => 'file-77',
        ])['body']['data'];

        $this->assertSame(204, $this->alice->delete('/notes/' . $note['id'] . '/entities/' . $link['id'])['status']);
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data']);

        // Deleting it again is a 404, not a silent success.
        $this->assertSame(404, $this->alice->delete('/notes/' . $note['id'] . '/entities/' . $link['id'])['status']);
    }

    public function testALinkIdIsScopedToItsOwnNote(): void
    {
        $note = $this->note();
        $other = $this->note();
        $link = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'company',
            'entity_id' => 'acme',
        ])['body']['data'];

        // The id is real and the caller owns both notes; it still does not
        // belong to the note named in the path.
        $this->assertSame(404, $this->alice->delete('/notes/' . $other['id'] . '/entities/' . $link['id'])['status']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/entities')['body']['data']);
    }

    // -- Who may reach them --------------------------------------------------

    public function testAnotherUserCannotReachTheLinksOnANoteTheyCannotSee(): void
    {
        $note = $this->note();
        $link = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'invoice',
            'entity_id' => 'INV-2030-114',
        ])['body']['data'];

        // 404 throughout: the note's existence is not theirs to learn either.
        $this->assertSame(404, $this->bob->get('/notes/' . $note['id'] . '/entities')['status']);
        $this->assertSame(404, $this->bob->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'invoice',
            'entity_id' => 'INV-MINE',
        ])['status']);
        $this->assertSame(404, $this->bob->delete('/notes/' . $note['id'] . '/entities/' . $link['id'])['status']);

        $rows = Connection::select('SELECT entity_id FROM note_entity_links');
        $this->assertCount(1, $rows);
        $this->assertSame('INV-2030-114', (string) $rows[0]['entity_id']);
    }

    public function testAViewerReadsTheLinksButCannotChangeThem(): void
    {
        $note = $this->note();
        $link = $this->alice->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'voucher',
            'entity_id' => 'VCH-1',
        ])['body']['data'];
        $this->share($note['id'], 'viewer');

        $this->assertCount(1, $this->bob->get('/notes/' . $note['id'] . '/entities')['body']['data']);

        $refused = $this->bob->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'voucher',
            'entity_id' => 'VCH-2',
        ]);
        $this->assertSame(403, $refused['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $refused['body']['error']['code']);
        $this->assertSame(403, $this->bob->delete('/notes/' . $note['id'] . '/entities/' . $link['id'])['status']);

        $this->assertCount(1, Connection::select('SELECT id FROM note_entity_links'));
    }

    public function testAnEditorMayLinkAndUnlink(): void
    {
        $note = $this->note();
        $this->share($note['id'], 'editor');

        $created = $this->bob->post('/notes/' . $note['id'] . '/entities', [
            'entity_type' => 'calendar_event',
            'entity_id' => 'evt-3',
        ]);

        $this->assertSame(201, $created['status']);
        $this->assertSame('user-b', $created['body']['data']['created_by']);
        $this->assertSame(204, $this->bob->delete(
            '/notes/' . $note['id'] . '/entities/' . $created['body']['data']['id'],
        )['status']);
    }
}
