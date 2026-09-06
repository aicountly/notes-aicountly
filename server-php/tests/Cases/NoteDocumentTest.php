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
