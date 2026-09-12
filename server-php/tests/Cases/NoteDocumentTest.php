<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\TestCase;

/**
 * The sanitiser is the store's XSS boundary: whatever survives here is later
 * rendered into the DOM for every collaborator on the note.
 */
final class NoteDocumentTest extends TestCase
{
    public function name(): string
    {
        return 'Note document';
    }

    public function testRejectsANonDocRoot(): void
    {
        $this->assertApiError('BAD_REQUEST', static fn () => NoteDocument::sanitize(['type' => 'paragraph']));
        $this->assertApiError('BAD_REQUEST', static fn () => NoteDocument::sanitize('<p>hello</p>'));
    }

    public function testStripsUnknownNodeTypes(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [
                ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'safe']]],
            ],
        ]);

        $this->assertCount(1, $clean['content'], 'only the paragraph survives');
        $this->assertSame('paragraph', $clean['content'][0]['type']);
    }

    public function testDropsJavascriptUrlsFromLinks(): void
    {
        foreach (['javascript:alert(1)', "java\nscript:alert(1)", 'JaVaScRiPt:alert(1)', 'data:text/html,<script>'] as $href) {
            $clean = NoteDocument::sanitize([
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'click me',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
                    ]],
                ]],
            ]);

            $marks = $clean['content'][0]['content'][0]['marks'] ?? [];
            $this->assertCount(0, $marks, 'unsafe href ' . $href . ' must not survive');
            // The words the user wrote are kept — only the link is removed.
            $this->assertSame('click me', $clean['content'][0]['content'][0]['text']);
        }
    }

    public function testKeepsSafeLinksAndForcesSafeRel(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'aicountly',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://aicountly.com', 'rel' => 'me']]],
                ]],
            ]],
        ]);

        $attrs = $clean['content'][0]['content'][0]['marks'][0]['attrs'];
        $this->assertSame('https://aicountly.com', $attrs['href']);
        $this->assertSame('noopener noreferrer nofollow', $attrs['rel'], 'rel is forced, not taken from the client');
    }

    public function testRejectsSchemeRelativeUrls(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => '//evil.example.com']]],
                ]],
            ]],
        ]);

        $this->assertCount(0, $clean['content'][0]['content'][0]['marks'] ?? []);
    }

    public function testDropsUnknownAttributes(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['onclick' => 'steal()', 'style' => 'background:url(x)', 'blockId' => Uuid::v4()],
                'content' => [['type' => 'text', 'text' => 'hi']],
            ]],
        ]);

        $attrs = $clean['content'][0]['attrs'];
        $this->assertFalse(array_key_exists('onclick', $attrs), 'onclick must be dropped');
        $this->assertFalse(array_key_exists('style', $attrs), 'style must be dropped');
        $this->assertTrue(Uuid::isValid($attrs['blockId']));
    }

    public function testAssignsABlockIdWhenTheClientOmitsOne(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]]],
        ]);

        $this->assertTrue(Uuid::isValid($clean['content'][0]['attrs']['blockId'] ?? null));
    }

    public function testStripsControlCharactersFromText(): void
    {
        $clean = NoteDocument::sanitize([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "he\x00llo\x1F"]]]],
        ]);

        $this->assertSame('hello', $clean['content'][0]['content'][0]['text']);
    }

    public function testRejectsAnOversizedDocument(): void
    {
        $huge = ['type' => 'doc', 'content' => []];
        for ($i = 0; $i < 40000; $i++) {
            $huge['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 200)]]];
        }

        $this->assertApiError('BAD_REQUEST', static fn () => NoteDocument::sanitize($huge));
    }

    public function testExtractsPlainTextForSearch(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Meeting with ABC']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Discussed GST reconciliation.']]],
            ],
        ];

        $text = NoteDocument::extractText($document);
        $this->assertContainsString('Meeting with ABC', $text);
        $this->assertContainsString('GST reconciliation', $text);
    }

    public function testExtractsMentionLabelsSoTheyAreSearchable(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Owner: '],
                    ['type' => 'mention', 'attrs' => ['entityType' => 'contact', 'entityId' => 'c-1', 'label' => 'Rahul']],
                ],
            ]],
        ];

        $this->assertContainsString('Rahul', NoteDocument::extractText($document));
    }

    /**
     * A bolded middle does not put a space inside the word.
     *
     * ProseMirror splits a run of text at every mark boundary, so "Aicountly"
     * with `count` bolded is three sibling text nodes. Joining those with
     * spaces indexed `Ai count ly`: the word the reader can plainly see was
     * unsearchable, and every excerpt showed it broken apart.
     */
    public function testAWordSplitByAMarkStaysOneWord(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Ai'],
                    ['type' => 'text', 'text' => 'count', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => 'ly is live'],
                ],
            ]],
        ];

        $this->assertSame('Aicountly is live', NoteDocument::extractText($document));
    }

    /** And blocks beside each other still do not run together. */
    public function testAdjacentBlocksAreStillSeparated(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]],
                ['type' => 'table', 'content' => [[
                    'type' => 'tableRow',
                    'content' => [
                        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Alice']]]]],
                        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bob']]]]],
                    ],
                ]]],
            ],
        ];

        $text = NoteDocument::extractText($document);
        $this->assertFalse(str_contains($text, 'FirstSecond'), 'paragraphs keep their boundary');
        $this->assertFalse(str_contains($text, 'AliceBob'), 'so do cells in one row');
    }

    /**
     * A note long enough to break its own index does not break it.
     *
     * `notes.search_vector` is GENERATED, and `to_tsvector` refuses a string
     * over 1,048,575 bytes. Because the column is recomputed on every write,
     * crossing that line did not merely stop the note being searchable: every
     * later save, rename, pin, trash and restore of it failed, and only an
     * UPDATE run by hand could bring it back. 4 MB of varied prose is inside
     * what the API accepts, so this is reachable by writing a long note.
     */
    public function testTheIndexedTextIsBoundedSoALongNoteStaysWritable(): void
    {
        $paragraphs = [];
        for ($i = 0; $i < 40000; $i++) {
            // Distinct words, because identical ones collapse into one lexeme
            // and never reach the limit however many times they repeat.
            $paragraphs[] = ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => 'reconciliation' . $i . ' invoice' . $i . ' ' . md5((string) $i),
            ]]];
        }

        $text = NoteDocument::extractText(['type' => 'doc', 'content' => $paragraphs]);

        $this->assertTrue(
            strlen($text) <= NoteDocument::MAX_INDEXED_BYTES,
            'bounded in bytes, which is the unit PostgreSQL counts',
        );
        // Cut on a word boundary, so the last thing indexed is a word and not
        // half of one.
        $this->assertFalse(str_ends_with($text, ' '));
    }

    /** Multibyte text is cut by bytes without splitting a character. */
    public function testTheBoundDoesNotCutAMultibyteCharacterInHalf(): void
    {
        $text = NoteDocument::boundForIndexing(str_repeat('नमस्ते ', 200000));

        $this->assertTrue(strlen($text) <= NoteDocument::MAX_INDEXED_BYTES);
        $this->assertSame($text, mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 'still valid UTF-8');
    }

    public function testExtractsChecklistItemsInOrder(): void
    {
        $document = \Aicountly\Api\Tests\Support::checklist([
            ['text' => 'Send GST return', 'checked' => false],
            ['text' => 'Call ABC', 'checked' => true],
        ]);

        $items = NoteDocument::extractChecklistItems($document);
        $this->assertCount(2, $items);
        $this->assertSame('Send GST return', $items[0]['text']);
        $this->assertFalse($items[0]['checked']);
        $this->assertTrue($items[1]['checked']);
        $this->assertSame(1, $items[1]['position']);
    }

    public function testCountsWordsFromTextOnly(): void
    {
        $counts = NoteDocument::counts(\Aicountly\Api\Tests\Support::doc('one two three'));
        $this->assertSame(3, $counts['words']);
        $this->assertSame(13, $counts['characters']);
    }

    public function testIgnoresNoteLinksWithABadTargetId(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['blockId' => Uuid::v4()],
                'content' => [['type' => 'noteLink', 'attrs' => ['noteId' => 'not-a-uuid', 'label' => 'x']]],
            ]],
        ];

        $this->assertCount(0, NoteDocument::extractNoteLinks($document));
    }
}
