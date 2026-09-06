<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * The note document: a ProseMirror/Tiptap JSON tree.
 *
 * This class is the reason a note is not one big HTML field. Because the
 * document is a typed tree, the server can read it: pull the plain text out for
 * search, find the checklist items, find the `[[wiki links]]`, count the words —
 * all without parsing markup or trusting the client's summary of its own
 * content.
 *
 * It is also the sanitisation boundary. Everything here is an **allowlist**:
 * an unknown node type, an unknown mark, an unknown attribute or a `javascript:`
 * URL does not survive `sanitize()`. The frontend renders this JSON into the
 * DOM, so anything that got through here would be stored XSS against every
 * collaborator on the note.
 */
final class NoteDocument
{
    public const SCHEMA_VERSION = 1;

    /** Guardrail against a pathological document DoSing the recursive walks. */
    private const MAX_DEPTH = 40;
    private const MAX_NODES = 20000;
    private const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * Node types the editor may produce.
     *
     * Adding an entry here is the one place a new block type becomes storable,
     * which is what keeps the schema and the editor from drifting apart.
     */
    private const ALLOWED_NODES = [
        'doc', 'paragraph', 'text', 'heading', 'blockquote', 'codeBlock',
        'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
        'horizontalRule', 'hardBreak', 'image', 'table', 'tableRow',
        'tableCell', 'tableHeader', 'callout', 'details', 'detailsSummary',
        'detailsContent', 'attachment', 'audio', 'noteLink', 'mention',
        'dateChip', 'canvasEmbed',
    ];

    /** Marks, and the attributes each may carry. */
    private const ALLOWED_MARKS = [
        'bold' => [],
        'italic' => [],
        'underline' => [],
        'strike' => [],
        'code' => [],
        'highlight' => ['color'],
        'textStyle' => ['color'],
        'link' => ['href', 'target', 'rel'],
        'comment' => ['commentId'],
    ];

    /** Attributes each node type may carry. Anything else is dropped. */
    private const ALLOWED_ATTRS = [
        'heading' => ['level', 'blockId'],
        'paragraph' => ['blockId', 'textAlign'],
        'codeBlock' => ['language', 'blockId'],
        'blockquote' => ['blockId'],
        'bulletList' => ['blockId'],
        'orderedList' => ['blockId', 'start'],
        'listItem' => ['blockId'],
        'taskList' => ['blockId'],
        'taskItem' => ['blockId', 'checked'],
        'image' => ['src', 'alt', 'title', 'width', 'height', 'attachmentId', 'blockId'],
        'attachment' => ['attachmentId', 'filename', 'mimeType', 'byteSize', 'kind', 'blockId'],
        'audio' => ['attachmentId', 'duration', 'blockId'],
        'callout' => ['tone', 'blockId'],
        'details' => ['open', 'blockId'],
        'table' => ['blockId'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
        'noteLink' => ['noteId', 'label'],
        'mention' => ['entityType', 'entityId', 'label'],
        'dateChip' => ['date', 'label'],
        'canvasEmbed' => ['canvasId', 'height', 'blockId'],
        'horizontalRule' => ['blockId'],
    ];

    /** URL schemes a link may use. `javascript:` and `data:` are not among them. */
    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /** A one-paragraph document — what Quick Capture produces. */
    public static function fromPlainText(string $text): array
    {
        $blocks = [];
        foreach (preg_split('/\R{1,}/u', $text) ?: [] as $line) {
            $trimmed = rtrim($line);
            $blocks[] = $trimmed === ''
                ? ['type' => 'paragraph', 'attrs' => ['blockId' => Uuid::v4()]]
                : [
                    'type' => 'paragraph',
                    'attrs' => ['blockId' => Uuid::v4()],
                    'content' => [['type' => 'text', 'text' => $trimmed]],
                ];
        }

        return ['type' => 'doc', 'content' => $blocks === [] ? [] : $blocks];
    }

    /**
     * Validate, prune and normalise a client-supplied document.
     *
     * @param mixed $document Whatever arrived in the request body.
     * @return array<string, mixed> A document safe to store and to render.
     */
    public static function sanitize(mixed $document): array
    {
        if (!is_array($document) || ($document['type'] ?? null) !== 'doc') {
            throw ApiException::badRequest('The note document must be a ProseMirror `doc` node.');
        }

        $encoded = json_encode($document);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw ApiException::badRequest('This note is too large to save. Split it into several notes.');
        }

        $budget = self::MAX_NODES;
        $clean = self::sanitizeNode($document, 0, $budget);
        if ($clean === null) {
            return self::empty();
        }

        // A doc with no content at all confuses the editor; give it one
        // paragraph to put the cursor in.
        if (($clean['content'] ?? []) === []) {
            $clean['content'] = [];
        }

        return $clean;
    }

    /**
     * @param mixed $node
     * @return array<string, mixed>|null Null when the node is not storable.
     */
    private static function sanitizeNode(mixed $node, int $depth, int &$budget): ?array
    {
        if (!is_array($node) || $depth > self::MAX_DEPTH || $budget-- <= 0) {
            return null;
        }

        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::ALLOWED_NODES, true)) {
            return null;
        }

        $clean = ['type' => $type];

        if ($type === 'text') {
            $text = $node['text'] ?? '';
            if (!is_string($text) || $text === '') {
                return null;
            }
            // Control characters other than tab/newline have no place in a
            // document and are a classic way to smuggle payloads past a filter.
            $clean['text'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
            $marks = self::sanitizeMarks($node['marks'] ?? null);
            if ($marks !== []) {
                $clean['marks'] = $marks;
            }

            return $clean['text'] === '' ? null : $clean;
        }

        $attrs = self::sanitizeAttrs($type, $node['attrs'] ?? null);
        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $children = [];
            foreach ($node['content'] as $child) {
                $sanitized = self::sanitizeNode($child, $depth + 1, $budget);
                if ($sanitized !== null) {
                    $children[] = $sanitized;
                }
            }
            if ($children !== []) {
                $clean['content'] = $children;
            }
        }

        $marks = self::sanitizeMarks($node['marks'] ?? null);
        if ($marks !== []) {
            $clean['marks'] = $marks;
        }

        return $clean;
    }

    /** @return array<int, array<string, mixed>> */
    private static function sanitizeMarks(mixed $marks): array
    {
        if (!is_array($marks)) {
            return [];
        }

        $clean = [];
        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $type = $mark['type'] ?? null;
            if (!is_string($type) || !array_key_exists($type, self::ALLOWED_MARKS)) {
                continue;
            }

            $entry = ['type' => $type];
            $attrs = [];
            foreach (self::ALLOWED_MARKS[$type] as $name) {
                $value = $mark['attrs'][$name] ?? null;
                if ($value === null || !is_scalar($value)) {
                    continue;
                }
                if ($name === 'href') {
                    $href = self::safeUrl((string) $value);
                    if ($href === null) {
                        // A link whose URL is unsafe loses the link, not the
                        // text: the words the user wrote still survive.
                        continue 2;
                    }
                    $attrs['href'] = $href;
                    continue;
                }
                if ($name === 'color') {
                    $color = self::safeColor((string) $value);
                    if ($color !== null) {
                        $attrs['color'] = $color;
                    }
                    continue;
                }
                $attrs[$name] = is_string($value) ? Str::limit($value, 200) : $value;
            }

            // Force a safe rel on anything that opens a new tab.
            if ($type === 'link' && isset($attrs['href'])) {
                $attrs['target'] = '_blank';
                $attrs['rel'] = 'noopener noreferrer nofollow';
            }

            if ($attrs !== []) {
                $entry['attrs'] = $attrs;
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function sanitizeAttrs(string $type, mixed $attrs): array
    {
        // A node that arrives with no `attrs` at all still needs its blockId
        // minted below, so an absent map is treated as an empty one rather
        // than as a reason to return early.
        $attrs = is_array($attrs) ? $attrs : [];

        $allowed = self::ALLOWED_ATTRS[$type] ?? [];
        $clean = [];

        foreach ($allowed as $name) {
            if (!array_key_exists($name, $attrs)) {
                continue;
            }
            $value = $attrs[$name];
            if ($value === null) {
                continue;
            }

            $clean[$name] = match ($name) {
                'src' => self::safeUrl((string) $value, allowAppRelative: true),
                'color' => self::safeColor((string) $value),
                'level' => max(1, min(6, (int) $value)),
                'checked', 'open' => (bool) $value,
                'width', 'height', 'start', 'colspan', 'rowspan', 'duration', 'byteSize' => (int) $value,
                'colwidth' => is_array($value) ? array_map('intval', array_slice($value, 0, 50)) : null,
                default => is_scalar($value) ? Str::limit((string) $value, 500) : null,
            };

            if ($clean[$name] === null) {
                unset($clean[$name]);
            }
        }

        // Every block-level node carries a stable id. Comments anchor to it,
        // actions mirror it, and backlinks scroll to it — none of which can be
        // done against a node identified only by its position in the tree.
        if (in_array('blockId', $allowed, true) && !Uuid::isValid($clean['blockId'] ?? null)) {
            $clean['blockId'] = Uuid::v4();
        }

        return $clean;
    }

    /**
     * A URL that is safe to put in an href or src.
     *
     * Scheme-relative (`//evil.com`) and `javascript:` are the two that matter;
     * whitespace and control characters are stripped first because
     * `java\nscript:` is the oldest trick in the file.
     */
    private static function safeUrl(string $url, bool $allowAppRelative = false): ?string
    {
        $cleaned = preg_replace('/[\x00-\x20]/', '', trim($url)) ?? '';
        if ($cleaned === '' || strlen($cleaned) > 2048) {
            return null;
        }
        if (str_starts_with($cleaned, '//')) {
            return null;
        }
        if (str_starts_with($cleaned, '/') || str_starts_with($cleaned, '#')) {
            return $allowAppRelative || str_starts_with($cleaned, '#') ? $cleaned : null;
        }

        $scheme = strtolower((string) parse_url($cleaned, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, self::ALLOWED_URL_SCHEMES, true)) {
            return null;
        }

        return $cleaned;
    }

    /** Hex or a plain CSS keyword — never an arbitrary value that could carry `url(...)`. */
    private static function safeColor(string $color): ?string
    {
        $trimmed = trim($color);

        return preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20})$/i', $trimmed) === 1 ? $trimmed : null;
    }

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    /**
     * Flatten to plain text for `notes.extracted_text`, which is what the
     * `search_vector` generated column indexes.
     */
    public static function extractText(array $document): string
    {
        $parts = [];
        self::walk($document, static function (array $node) use (&$parts): void {
            if (($node['type'] ?? '') === 'text' && is_string($node['text'] ?? null)) {
                $parts[] = $node['text'];
                return;
            }
            // Mention and date chips carry their text in an attribute, not in
            // a child text node — without this they would be unsearchable.
            $label = $node['attrs']['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $parts[] = $label;
            }
            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'listItem', 'taskItem', 'tableRow', 'blockquote'], true)) {
                $parts[] = "\n";
            }
        });

        $text = preg_replace('/[ \t]+/u', ' ', implode(' ', $parts)) ?? '';
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Checklist items, in document order.
     *
     * @return array<int, array{block_id: string, text: string, checked: bool, position: int}>
     */
    public static function extractChecklistItems(array $document): array
    {
        $items = [];
        $position = 0;

        self::walk($document, static function (array $node) use (&$items, &$position): void {
            if (($node['type'] ?? '') !== 'taskItem') {
                return;
            }
            $blockId = $node['attrs']['blockId'] ?? null;
            if (!Uuid::isValid($blockId)) {
                return;
            }
            $items[] = [
                'block_id' => (string) $blockId,
                'text' => Str::limit(self::extractText(['type' => 'doc', 'content' => $node['content'] ?? []]), 2000),
                'checked' => (bool) ($node['attrs']['checked'] ?? false),
                'position' => $position++,
            ];
        });

        return $items;
    }

    /**
     * Internal note references, from `noteLink` nodes.
     *
     * Links are stored as rows keyed by note id, so renaming the target note
     * does not break them — which is exactly what parsing `[[Title]]` at render
     * time would do.
     *
     * @return array<int, array{note_id: string, block_id: string|null, label: string}>
     */
    public static function extractNoteLinks(array $document): array
    {
        $links = [];
        $blockId = null;

        self::walk($document, static function (array $node) use (&$links, &$blockId): void {
            $candidate = $node['attrs']['blockId'] ?? null;
            if (Uuid::isValid($candidate)) {
                $blockId = (string) $candidate;
            }
            if (($node['type'] ?? '') !== 'noteLink') {
                return;
            }
            $noteId = $node['attrs']['noteId'] ?? null;
            if (!Uuid::isValid($noteId)) {
                return;
            }
            $links[] = [
                'note_id' => strtolower((string) $noteId),
                'block_id' => $blockId,
                'label' => Str::limit((string) ($node['attrs']['label'] ?? ''), 500),
            ];
        });

        return $links;
    }

    /**
     * `mention` nodes — people and other AICOUNTLY records referenced inline.
     *
     * @return array<int, array{entity_type: string, entity_id: string, label: string}>
     */
    public static function extractEntityMentions(array $document): array
    {
        $mentions = [];

        self::walk($document, static function (array $node) use (&$mentions): void {
            if (($node['type'] ?? '') !== 'mention') {
                return;
            }
            $entityType = (string) ($node['attrs']['entityType'] ?? '');
            $entityId = (string) ($node['attrs']['entityId'] ?? '');
            if ($entityType === '' || $entityId === '') {
                return;
            }
            $mentions[] = [
                'entity_type' => Str::limit($entityType, 40),
                'entity_id' => Str::limit($entityId, 128),
                'label' => Str::limit((string) ($node['attrs']['label'] ?? ''), 400),
            ];
        });

        return $mentions;
    }

    /** @return array<int, string> Attachment ids referenced by the document. */
    public static function extractAttachmentIds(array $document): array
    {
        $ids = [];
        self::walk($document, static function (array $node) use (&$ids): void {
            $id = $node['attrs']['attachmentId'] ?? null;
            if (Uuid::isValid($id)) {
                $ids[strtolower((string) $id)] = true;
            }
        });

        return array_keys($ids);
    }

    /** @return array{words: int, characters: int} */
    public static function counts(array $document): array
    {
        $text = self::extractText($document);

        return [
            'words' => Str::wordCount($text),
            // Document structure is not content: only the text is counted.
            'characters' => mb_strlen($text, 'UTF-8'),
        ];
    }

    /** Depth-first pre-order walk. */
    private static function walk(array $node, callable $visitor, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $visitor($node);
        foreach (($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                self::walk($child, $visitor, $depth + 1);
            }
        }
    }
}
