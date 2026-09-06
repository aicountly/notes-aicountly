<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Export;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Domain\Notes\NotePresenter;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;

/**
 * One note, as a file someone can keep.
 *
 * Export is the promise that a note is not trapped here. Three formats, all
 * rendered from the stored ProseMirror document by {@see MarkdownRenderer} and
 * {@see HtmlRenderer} — never from HTML the client sent, and never by pasting
 * strings together, which is how a note's text ends up being parsed as markup
 * by whatever opens the file.
 *
 * **PDF is not one of the formats.** Producing a real PDF server-side means a
 * layout engine, and this API ships with no dependencies at all. Rather than a
 * "PDF" that is an HTML file with the wrong extension, `?format=pdf` is refused
 * with a message that says where PDF does happen: the browser's print dialog,
 * which already has the fonts, the page size and the user's printer.
 */
final class NoteExportService
{
    /** @var array<string, string> format => content type. */
    private const CONTENT_TYPES = [
        'md' => 'text/markdown; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
    ];

    /** What a client might reasonably type for each format. */
    private const ALIASES = [
        'markdown' => 'md',
        'mdown' => 'md',
        'htm' => 'html',
        'text' => 'txt',
        'plain' => 'txt',
    ];

    /** Long enough to keep a real title, short enough for every filesystem. */
    private const MAX_FILENAME = 80;

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
    ) {
    }

    /**
     * Render a note the caller may read.
     *
     * @return array{format: string, content: string, content_type: string, filename: string, title: string}
     */
    public function export(Identity $identity, string $noteId, string $format): array
    {
        $format = self::format($format);

        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: 'n.id, n.title, n.document_json, n.extracted_text, n.privacy_mode',
        );

        if ((string) ($note['privacy_mode'] ?? 'standard') === 'private') {
            // The document is ciphertext this server holds no key for, so there
            // is nothing here to render. Answering with the bytes anyway would
            // hand back a file of noise labelled as the note.
            throw new ApiException(
                400,
                'EXPORT_UNAVAILABLE',
                'This note is encrypted on the device that wrote it, so it can only be exported from the app.',
            );
        }

        $document = $note['document_json'];
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : NoteDocument::empty();
        }
        if (!is_array($document)) {
            $document = NoteDocument::empty();
        }

        // An untitled note exports under its first line, the same title the
        // list shows — a folder of files called "Untitled" is not an export.
        $title = NotePresenter::displayTitle(
            $note['title'] === null ? null : (string) $note['title'],
            (string) ($note['extracted_text'] ?? ''),
        );

        $content = match ($format) {
            'html' => HtmlRenderer::render($document, $title, $noteId),
            'txt' => MarkdownRenderer::plainText($document, $title, $noteId),
            default => MarkdownRenderer::render($document, $title, $noteId),
        };

        return [
            'format' => $format,
            'content' => $content,
            'content_type' => self::CONTENT_TYPES[$format],
            'filename' => self::filename($title, $format),
            'title' => $title,
        ];
    }

    /** The formats this API renders, for a client that wants to offer a menu. */
    public static function formats(): array
    {
        return array_keys(self::CONTENT_TYPES);
    }

    private static function format(string $requested): string
    {
        $format = strtolower(trim($requested));
        $format = self::ALIASES[$format] ?? $format;

        if ($format === '') {
            return 'md';
        }

        if ($format === 'pdf') {
            throw new ApiException(
                400,
                'EXPORT_FORMAT_UNSUPPORTED',
                'PDF is produced by printing the note from the app. The API exports md, html and txt.',
            );
        }

        if (!isset(self::CONTENT_TYPES[$format])) {
            throw ApiException::badRequest('Export format must be one of: md, html, txt.');
        }

        return $format;
    }

    /**
     * A filename derived from a title the user chose.
     *
     * A title is free text, and this value ends up in a header and then as a
     * name on someone's disk, so it is rebuilt rather than cleaned: path
     * separators, the characters Windows refuses, and every control character
     * go first, then runs of dots collapse so a title of "../../etc/passwd"
     * cannot come back as anything that traverses. What is left is the words.
     */
    public static function filename(string $title, string $format): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $title);
        // Separators and the reserved set become spaces rather than being
        // deleted, so "a/b" reads as "a b" instead of collapsing into "ab".
        $name = (string) preg_replace('#[/\\\\:*?"<>|]+#u', ' ', $name);
        $name = (string) preg_replace('/\.{2,}/u', '.', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        // A leading dot would make the file hidden on every Unix desktop, and
        // is what is left of a traversal attempt by this point.
        $name = trim($name, " .\t\n\r\0\x0B");
        $name = Str::limit($name, self::MAX_FILENAME);
        $name = trim($name, " .");

        return ($name === '' ? 'note' : $name) . '.' . $format;
    }
}
