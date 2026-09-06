<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Controllers\ExportController;
use Aicountly\Api\Domain\Export\NoteExportService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Exporting a note, over the real router.
 *
 * An export leaves this server. It gets mailed, opened from a downloads folder,
 * pasted into another system — all places with no sanitiser between the file
 * and a parser. So the cases that matter most here are not about formatting:
 * they are that a note's own text can never become markup, and that a title
 * someone chose can never become a path or a second header.
 */
final class ExportTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Export';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function item(string $text): array
    {
        return ['type' => 'listItem', 'content' => [self::paragraph($text)]];
    }

    private static function task(string $text, bool $checked): array
    {
        return [
            'type' => 'taskItem',
            'attrs' => ['checked' => $checked],
            'content' => [self::paragraph($text)],
        ];
    }

    private static function cell(string $type, string $text): array
    {
        return ['type' => $type, 'content' => [self::paragraph($text)]];
    }

    /** One document exercising every block type the exporter claims to support. */
    private static function richDocument(string $attachmentId, string $linkedNoteId): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Quarterly close']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Numbers are '],
                ['type' => 'text', 'text' => 'final', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ', '],
                ['type' => 'text', 'text' => 'checked', 'marks' => [['type' => 'italic']]],
                ['type' => 'text', 'text' => ', not '],
                ['type' => 'text', 'text' => 'draft', 'marks' => [['type' => 'strike']]],
                ['type' => 'text', 'text' => '. Run '],
                ['type' => 'text', 'text' => 'php bin/worker.php', 'marks' => [['type' => 'code']]],
                ['type' => 'text', 'text' => ' and see '],
                [
                    'type' => 'text',
                    'text' => 'the portal',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://my.aicountly.test/close']]],
                ],
                ['type' => 'text', 'text' => '.'],
                ['type' => 'hardBreak'],
                ['type' => 'noteLink', 'attrs' => ['noteId' => $linkedNoteId, 'label' => 'Last quarter']],
            ]],
            ['type' => 'bulletList', 'content' => [self::item('GST'), self::item('TDS')]],
            ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [
                self::item('Reconcile the ledger'),
                self::item('File the return'),
            ]],
            ['type' => 'taskList', 'content' => [
                self::task('Ledger tied out', true),
                self::task('Signatures collected', false),
            ]],
            ['type' => 'blockquote', 'content' => [self::paragraph('Filed on time, every time.')]],
            ['type' => 'codeBlock', 'attrs' => ['language' => 'sql'], 'content' => [
                ['type' => 'text', 'text' => "SELECT count(*)\nFROM ledger;"],
            ]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    self::cell('tableHeader', 'Head'),
                    self::cell('tableHeader', 'Amount'),
                ]],
                ['type' => 'tableRow', 'content' => [
                    self::cell('tableCell', 'Professional fees'),
                    self::cell('tableCell', '1,200'),
                ]],
            ]],
            ['type' => 'horizontalRule'],
            ['type' => 'image', 'attrs' => ['src' => 'https://cdn.example.test/chart.png', 'alt' => 'Revenue chart']],
            ['type' => 'attachment', 'attrs' => [
                'attachmentId' => $attachmentId,
                'filename' => 'ledger.pdf',
                'kind' => 'pdf',
            ]],
            ['type' => 'callout', 'attrs' => ['tone' => 'warning'], 'content' => [
                self::paragraph('Do not file before the audit signs off.'),
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    private function note(array $document, ?string $title = null): array
    {
        return $this->alice->post('/notes', ['title' => $title, 'document' => $document])['body']['data'];
    }

    /**
     * Export through the router.
     *
     * The handler writes the body into an output buffer so `Response::send()`
     * still owns the headers, exactly as the attachment download does; the
     * buffer is collected here.
     *
     * @return array{status: int, body: array<string, mixed>, content: string}
     */
    private function export(ApiClient $api, string $noteId, array $query = []): array
    {
        $result = $api->get('/notes/' . $noteId . '/export', $query);
        $result['content'] = $result['status'] === 200 ? (string) ob_get_clean() : '';

        return $result;
    }

    /** The headers are not visible through the router, so this calls the handler directly. */
    private function exportDirectly(Identity $identity, string $noteId, string $format): Response
    {
        $previousGet = $_GET;
        $_GET = ['format' => $format];

        try {
            $request = Request::forTesting('GET', 'notes/' . $noteId . '/export', ['format' => $format]);
            $request->routeParams = ['id' => $noteId];

            return (new ExportController())->note($request, $identity);
        } finally {
            $_GET = $previousGet;
        }
    }

    /** @return array<string, string> */
    private static function headersOf(Response $response): array
    {
        $property = (new \ReflectionClass($response))->getProperty('headers');

        /** @var array<string, string> $headers */
        $headers = $property->getValue($response);

        return $headers;
    }

    // -- Markdown -----------------------------------------------------------

    public function testMarkdownCarriesEveryBlockTypeTheEditorCanProduce(): void
    {
        $attachmentId = Uuid::v4();
        $linked = Uuid::v4();
        $note = $this->note(self::richDocument($attachmentId, $linked), 'Q3 close');

        $markdown = $this->export($this->alice, $note['id'], ['format' => 'md'])['content'];

        $this->assertContainsString('# Q3 close', $markdown);
        $this->assertContainsString('## Quarterly close', $markdown);
        $this->assertContainsString('**final**', $markdown);
        $this->assertContainsString('*checked*', $markdown);
        $this->assertContainsString('~~draft~~', $markdown);
        $this->assertContainsString('`php bin/worker.php`', $markdown);
        $this->assertContainsString('[the portal](https://my.aicountly.test/close)', $markdown);
        $this->assertContainsString('[Last quarter](/notes/' . $linked . ')', $markdown);
        $this->assertContainsString("- GST\n- TDS", $markdown);
        $this->assertContainsString("1. Reconcile the ledger\n2. File the return", $markdown);
        $this->assertContainsString('- [x] Ledger tied out', $markdown);
        $this->assertContainsString('- [ ] Signatures collected', $markdown);
        $this->assertContainsString('> Filed on time, every time.', $markdown);
        $this->assertContainsString("```sql\nSELECT count(*)\nFROM ledger;\n```", $markdown);
        $this->assertContainsString('| Head | Amount |', $markdown);
        $this->assertContainsString('| --- | --- |', $markdown);
        $this->assertContainsString('| Professional fees | 1,200 |', $markdown);
        $this->assertContainsString("\n---\n", $markdown);
        $this->assertContainsString('![Revenue chart](https://cdn.example.test/chart.png)', $markdown);
        $this->assertContainsString(
            '[ledger.pdf](/notes/' . $note['id'] . '/attachments/' . $attachmentId . '/content)',
            $markdown,
        );
        $this->assertContainsString('> [!WARNING]', $markdown);
    }

    public function testNestedListsKeepTheirShape(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    self::paragraph('Direct tax'),
                    ['type' => 'bulletList', 'content' => [self::item('Advance tax'), self::item('TDS')]],
                ]],
            ]],
        ]]);

        $markdown = $this->export($this->alice, $note['id'], ['format' => 'md'])['content'];

        $this->assertContainsString("- Direct tax\n  - Advance tax\n  - TDS", $markdown);
    }

    public function testAParagraphThatLooksLikeAListStaysAParagraph(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('- not a list item')]]);

        $markdown = $this->export($this->alice, $note['id'], ['format' => 'md'])['content'];

        $this->assertContainsString('\\- not a list item', $markdown);
    }

    // -- Escaping -----------------------------------------------------------

    public function testAScriptTagInANoteIsTextInEveryFormat(): void
    {
        $payload = '<script>alert(1)</script>';
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph($payload)]], $payload);

        $html = $this->export($this->alice, $note['id'], ['format' => 'html'])['content'];
        $markdown = $this->export($this->alice, $note['id'], ['format' => 'md'])['content'];
        $text = $this->export($this->alice, $note['id'], ['format' => 'txt'])['content'];

        // HTML: escaped in the body and in the <title>, and the raw tag appears
        // nowhere in the file.
        $this->assertContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertContainsString('<title>&lt;script&gt;alert(1)&lt;/script&gt;</title>', $html);
        $this->assertFalse(str_contains($html, '<script>'), 'the export must not contain a script tag');
        $this->assertFalse(str_contains($html, '</script>'));

        // Markdown passes raw HTML through, so the angle brackets are escaped:
        // whatever renders this file must show the characters, not run them.
        $this->assertContainsString('\\<script\\>alert(1)\\</script\\>', $markdown);
        $this->assertFalse(str_contains($markdown, '<script>'));

        // Plain text is not a markup language; the characters stand as typed.
        $this->assertContainsString('<script>alert(1)</script>', $text);
    }

    public function testAJavascriptUrlNeverSurvivesIntoAnExport(): void
    {
        // The document sanitiser drops this on the way in; the renderers refuse
        // it again on the way out, because an export is opened where nothing
        // else is checking.
        $note = $this->note(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]]],
        ]]);

        $html = $this->export($this->alice, $note['id'], ['format' => 'html'])['content'];
        $markdown = $this->export($this->alice, $note['id'], ['format' => 'md'])['content'];

        $this->assertFalse(str_contains($html, 'javascript:'));
        $this->assertFalse(str_contains($markdown, 'javascript:'));
        $this->assertContainsString('click me', $html, 'the words survive; only the link goes');
        $this->assertContainsString('click me', $markdown);
    }

    // -- HTML ---------------------------------------------------------------

    public function testHtmlIsAStandaloneDocumentWithTheStructureIntact(): void
    {
        $note = $this->note(self::richDocument(Uuid::v4(), Uuid::v4()), 'Q3 close');

        $html = $this->export($this->alice, $note['id'], ['format' => 'html'])['content'];

        $this->assertContainsString('<!doctype html>', $html);
        $this->assertContainsString('<meta charset="utf-8">', $html);
        $this->assertContainsString('<h1>Q3 close</h1>', $html);
        $this->assertContainsString('<h2>Quarterly close</h2>', $html);
        $this->assertContainsString('<strong>final</strong>', $html);
        $this->assertContainsString('<em>checked</em>', $html);
        $this->assertContainsString('<s>draft</s>', $html);
        $this->assertContainsString('<code>php bin/worker.php</code>', $html);
        $this->assertContainsString('<a href="https://my.aicountly.test/close" rel="noopener noreferrer nofollow">', $html);
        $this->assertContainsString('<ul>', $html);
        $this->assertContainsString('<ol>', $html);
        $this->assertContainsString('<input type="checkbox" disabled checked>', $html);
        $this->assertContainsString('<blockquote>', $html);
        $this->assertContainsString('<pre><code class="language-sql">', $html);
        $this->assertContainsString('<th>Head</th>', $html);
        $this->assertContainsString('<td>1,200</td>', $html);
        $this->assertContainsString('<hr>', $html);
        $this->assertContainsString('<img src="https://cdn.example.test/chart.png" alt="Revenue chart">', $html);
        $this->assertContainsString('class="attachment"', $html);
        $this->assertContainsString('callout-warning', $html);
    }

    public function testHtmlPullsNothingOffTheNetwork(): void
    {
        $note = $this->note(self::richDocument(Uuid::v4(), Uuid::v4()), 'Q3 close');

        $html = $this->export($this->alice, $note['id'], ['format' => 'html'])['content'];

        // A stylesheet or script fetched at open time would make the file blank
        // offline and would tell someone the note had been read.
        $this->assertFalse(str_contains($html, '<link'));
        $this->assertFalse(str_contains($html, '<script'));
    }

    // -- Plain text ---------------------------------------------------------

    public function testPlainTextKeepsTheWordsAndDropsTheMarkup(): void
    {
        $note = $this->note(self::richDocument(Uuid::v4(), Uuid::v4()), 'Q3 close');

        $text = $this->export($this->alice, $note['id'], ['format' => 'txt'])['content'];

        $this->assertContainsString('Q3 close', $text);
        $this->assertContainsString('Numbers are final, checked, not draft.', $text);
        $this->assertContainsString('- GST', $text);
        $this->assertContainsString('- [x] Ledger tied out', $text);
        $this->assertContainsString('> Filed on time, every time.', $text);
        // A link's address is kept, because losing it would make the export say
        // less than the note did.
        $this->assertContainsString('the portal (https://my.aicountly.test/close)', $text);
        $this->assertFalse(str_contains($text, '**'));
        $this->assertFalse(str_contains($text, '```'));
    }

    // -- Filenames ----------------------------------------------------------

    public function testATitleCannotEscapeIntoAPath(): void
    {
        $filename = NoteExportService::filename('../../etc/passwd', 'md');

        $this->assertSame('etc passwd.md', $filename);
        $this->assertFalse(str_contains($filename, '/'));
        $this->assertFalse(str_contains($filename, '..'));
    }

    public function testTheDownloadFilenameIsBuiltFromASafeTitle(): void
    {
        $note = $this->note(self::richDocument(Uuid::v4(), Uuid::v4()), '../../etc/passwd');

        $response = $this->exportDirectly(Support::user('a'), $note['id'], 'md');
        $headers = self::headersOf($response);
        ob_end_clean();

        $this->assertSame(200, $response->status);
        $this->assertSame('text/markdown; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        // Never inline: an export is a document made of one person's words and
        // must not be rendered as a page on this origin.
        $this->assertContainsString('attachment; filename="etc passwd.md"', $headers['Content-Disposition']);
        $this->assertFalse(str_contains($headers['Content-Disposition'], '../'));
    }

    public function testAFilenameCannotBreakOutOfItsHeader(): void
    {
        $note = $this->note(
            ['type' => 'doc', 'content' => [self::paragraph('body')]],
            "Q3\r\nX-Injected: yes\"; drop=1",
        );

        $response = $this->exportDirectly(Support::user('a'), $note['id'], 'txt');
        $disposition = self::headersOf($response)['Content-Disposition'];
        ob_end_clean();

        $this->assertFalse(str_contains($disposition, "\r"));
        $this->assertFalse(str_contains($disposition, "\n"));
        $this->assertContainsString('.txt"', $disposition);
    }

    public function testAnUntitledNoteExportsUnderItsFirstLine(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('Standing items for Monday')]]);

        $response = $this->exportDirectly(Support::user('a'), $note['id'], 'md');
        $disposition = self::headersOf($response)['Content-Disposition'];
        ob_end_clean();

        $this->assertContainsString('Standing items for Monday.md', $disposition);
    }

    // -- Formats ------------------------------------------------------------

    public function testMarkdownIsTheDefaultFormat(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('plain body')]], 'Untitled work');

        $result = $this->export($this->alice, $note['id']);

        $this->assertSame(200, $result['status']);
        $this->assertContainsString('# Untitled work', $result['content']);
    }

    public function testPdfIsRefusedWithAnAnswerRatherThanAPretendFile(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('body')]]);

        $result = $this->export($this->alice, $note['id'], ['format' => 'pdf']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('EXPORT_FORMAT_UNSUPPORTED', $result['body']['error']['code']);
        $this->assertContainsString('printing the note', $result['body']['error']['message']);
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('body')]]);

        $result = $this->export($this->alice, $note['id'], ['format' => 'docx']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('BAD_REQUEST', $result['body']['error']['code']);
    }

    public function testFormatAliasesAreAccepted(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('body')]], 'Aliases');

        $this->assertContainsString(
            '<!doctype html>',
            $this->export($this->alice, $note['id'], ['format' => 'HTM'])['content'],
        );
        $this->assertContainsString(
            'Aliases',
            $this->export($this->alice, $note['id'], ['format' => 'markdown'])['content'],
        );
    }

    // -- Other people -------------------------------------------------------

    public function testAnotherUserCannotExportANoteTheyCannotSee(): void
    {
        $note = $this->note(
            ['type' => 'doc', 'content' => [self::paragraph('numbers nobody else should read')]],
            'Alice only',
        );

        $result = $this->export($this->bob, $note['id'], ['format' => 'md']);

        // 404, not 403: "you may not read this" and "there is no such note" are
        // indistinguishable from outside.
        $this->assertSame(404, $result['status']);
        $this->assertSame('NOT_FOUND', $result['body']['error']['code']);
        $this->assertSame('', $result['content'], 'not one byte of the note is written');
    }

    public function testExportingANoteThatDoesNotExistIsAlsoAPlainNotFound(): void
    {
        $result = $this->export($this->bob, Uuid::v4(), ['format' => 'md']);

        $this->assertSame(404, $result['status']);
    }

    public function testAViewerMayExportTheNoteTheyWereGiven(): void
    {
        $note = $this->note(['type' => 'doc', 'content' => [self::paragraph('shared reading')]], 'Shared');
        $this->alice->post('/notes/' . $note['id'] . '/members', ['user_id' => 'user-b', 'role' => 'viewer']);

        $result = $this->export($this->bob, $note['id'], ['format' => 'md']);

        $this->assertSame(200, $result['status']);
        $this->assertContainsString('shared reading', $result['content']);
    }
}
