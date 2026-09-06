<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Export;

use Aicountly\Api\Support\Uuid;

/**
 * ProseMirror document → Markdown, or → plain text.
 *
 * The source is the stored document tree, never rendered HTML and never a
 * string the client assembled. That matters twice over: the server has no DOM
 * to serialise, and text that arrives from a user has to be *escaped* on the
 * way out or an export is a second place a payload can be smuggled through. A
 * note whose text is `<script>alert(1)</script>` exports as those characters,
 * not as a tag.
 *
 * Markdown and plain text share this class because they share the whole block
 * skeleton — headings, lists, quotes, tables all sit in the same places — and
 * differ only in whether the inline decoration is written out. Two walkers
 * would be two places for the tree to be traversed slightly differently.
 */
final class MarkdownRenderer
{
    /** Guardrail against a pathological tree; the same ceiling the sanitizer uses. */
    private const MAX_DEPTH = 40;

    private const CALLOUT_TONES = ['note', 'info', 'tip', 'success', 'warning', 'danger', 'important', 'caution'];

    private function __construct(
        private readonly bool $plain,
        private readonly string $noteId,
    ) {
    }

    public static function render(array $document, ?string $title = null, string $noteId = ''): string
    {
        return (new self(false, $noteId))->document($document, $title);
    }

    /** The same walk without the markup — what `?format=txt` returns. */
    public static function plainText(array $document, ?string $title = null, string $noteId = ''): string
    {
        return (new self(true, $noteId))->document($document, $title);
    }

    // -----------------------------------------------------------------------

    private function document(array $document, ?string $title): string
    {
        $blocks = [];

        $heading = $title === null ? '' : trim($title);
        if ($heading !== '') {
            $blocks[] = $this->plain ? $heading : '# ' . $this->escape($heading);
        }

        foreach ($this->children($document) as $node) {
            $block = $this->block($node, 0);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * One block-level node.
     *
     * Returns '' for anything that produces nothing, so the caller can drop it
     * rather than emit a run of blank lines.
     */
    private function block(array $node, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $type = (string) ($node['type'] ?? '');

        return match ($type) {
            'paragraph' => $this->guard($this->inline($this->children($node))),
            'heading' => $this->heading($node),
            'blockquote' => $this->prefixed($this->blocks($node, $depth), '> '),
            'codeBlock' => $this->codeBlock($node),
            'bulletList' => $this->bulletList($node, $depth),
            'orderedList' => $this->orderedList($node, $depth),
            'taskList' => $this->taskList($node, $depth),
            'horizontalRule' => $this->plain ? '----------' : '---',
            'table' => $this->table($node),
            'callout' => $this->callout($node, $depth),
            'details' => $this->details($node, $depth),
            'image' => $this->image($node),
            'attachment', 'audio' => $this->attachment($node),
            // A canvas is a drawing surface with no text form. Saying so is
            // honest; silently dropping it would make an export look complete
            // when part of the note is missing from it.
            'canvasEmbed' => $this->plain ? '[Canvas drawing]' : '_[Canvas drawing — open the note to view]_',
            // Inline nodes reached at block level, and any container this
            // renderer has no special shape for, still contribute their text.
            'text', 'hardBreak', 'noteLink', 'mention', 'dateChip' => $this->guard($this->inline([$node])),
            default => $this->blocks($node, $depth),
        };
    }

    /** Children rendered as blocks and joined with a blank line. */
    private function blocks(array $node, int $depth): string
    {
        $parts = [];
        foreach ($this->children($node) as $child) {
            $block = $this->block($child, $depth + 1);
            if ($block !== '') {
                $parts[] = $block;
            }
        }

        return implode("\n\n", $parts);
    }

    private function heading(array $node): string
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
        $text = $this->inline($this->children($node));
        if (trim($text) === '') {
            return '';
        }

        return $this->plain ? $text : str_repeat('#', $level) . ' ' . $text;
    }

    /**
     * A fenced block.
     *
     * The fence grows past any run of backticks inside the code, because a
     * three-backtick fence around code that contains three backticks ends the
     * block early and spills the rest of the note into it.
     */
    private function codeBlock(array $node): string
    {
        $code = $this->rawText($node);
        if ($this->plain) {
            return $code;
        }

        $language = (string) ($node['attrs']['language'] ?? '');
        $language = preg_match('/^[A-Za-z0-9+#_-]{1,30}$/', $language) === 1 ? $language : '';
        $fence = str_repeat('`', max(3, self::longestRun($code, '`') + 1));

        return $fence . $language . "\n" . $code . "\n" . $fence;
    }

    private function bulletList(array $node, int $depth): string
    {
        $lines = [];
        foreach ($this->children($node) as $item) {
            $lines[] = self::hang('- ', $this->itemBody($item, $depth));
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function orderedList(array $node, int $depth): string
    {
        $number = max(1, (int) ($node['attrs']['start'] ?? 1));
        $lines = [];
        foreach ($this->children($node) as $item) {
            $lines[] = self::hang($number . '. ', $this->itemBody($item, $depth));
            $number++;
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function taskList(array $node, int $depth): string
    {
        $lines = [];
        foreach ($this->children($node) as $item) {
            $marker = ($item['attrs']['checked'] ?? false) ? '- [x] ' : '- [ ] ';
            $lines[] = self::hang($marker, $this->itemBody($item, $depth));
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * The contents of one list item.
     *
     * Joined with a single newline rather than a blank line: a blank line
     * between an item's paragraphs turns a tight list into a loose one, and a
     * checklist rendered loose is a page of gaps.
     */
    private function itemBody(array $item, int $depth): string
    {
        $parts = [];
        foreach ($this->children($item) as $child) {
            $block = $this->block($child, $depth + 1);
            if ($block !== '') {
                $parts[] = $block;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * A GitHub-flavoured table.
     *
     * The delimiter row is not optional in GFM, so a table whose first row is
     * ordinary cells still gets a header — an empty one — rather than being
     * rendered as a run of pipes that no reader parses as a table.
     */
    private function table(array $node): string
    {
        $rows = [];
        foreach ($this->children($node) as $row) {
            if ((string) ($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = [];
            foreach ($this->children($row) as $cell) {
                $cells[] = $this->cell($cell);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        if ($this->plain) {
            return implode("\n", array_map(
                static fn (array $cells): string => implode("\t", $cells),
                $rows,
            ));
        }

        $width = max(array_map('count', $rows));
        $lines = [];
        $header = array_shift($rows) ?? [];
        $lines[] = self::row($header, $width);
        $lines[] = self::row(array_fill(0, $width, '---'), $width);
        foreach ($rows as $cells) {
            $lines[] = self::row($cells, $width);
        }

        return implode("\n", $lines);
    }

    private function cell(array $cell): string
    {
        $parts = [];
        foreach ($this->children($cell) as $child) {
            $parts[] = $this->inline($this->children($child));
        }
        $text = trim(implode(' ', $parts));

        // A newline or a pipe inside a cell ends the row early; both become
        // something that stays inside it.
        $text = (string) preg_replace('/\s*\R\s*/u', ' ', $text);

        return $this->plain ? $text : str_replace('|', '\\|', $text);
    }

    /** @param array<int, string> $cells */
    private static function row(array $cells, int $width): string
    {
        $padded = array_pad(array_slice($cells, 0, $width), $width, '');

        return '| ' . implode(' | ', $padded) . ' |';
    }

    /**
     * A callout, as the alert syntax GitHub and Obsidian both read.
     *
     * It degrades to an ordinary quote everywhere else, which is the right
     * failure: the words survive even where the box does not.
     */
    private function callout(array $node, int $depth): string
    {
        $tone = strtolower((string) ($node['attrs']['tone'] ?? 'note'));
        $tone = in_array($tone, self::CALLOUT_TONES, true) ? $tone : 'note';
        $body = $this->blocks($node, $depth);

        if ($this->plain) {
            return '[' . strtoupper($tone) . ']' . ($body === '' ? '' : "\n" . $body);
        }

        return $this->prefixed('[!' . strtoupper($tone) . ']' . ($body === '' ? '' : "\n" . $body), '> ');
    }

    private function details(array $node, int $depth): string
    {
        $summary = '';
        $parts = [];

        foreach ($this->children($node) as $child) {
            $type = (string) ($child['type'] ?? '');
            if ($type === 'detailsSummary') {
                $summary = $this->inline($this->children($child));
                continue;
            }
            $block = $this->block($child, $depth + 1);
            if ($block !== '') {
                $parts[] = $block;
            }
        }

        $body = implode("\n\n", $parts);
        if ($summary === '') {
            return $body;
        }

        return ($this->plain ? $summary : '**' . $summary . '**') . ($body === '' ? '' : "\n\n" . $body);
    }

    private function image(array $node): string
    {
        $alt = (string) ($node['attrs']['alt'] ?? ($node['attrs']['title'] ?? 'Image'));
        $src = self::url($node['attrs']['src'] ?? null);

        if ($src === null) {
            return $this->plain ? $alt : $this->escape($alt);
        }

        return $this->plain
            ? trim($alt . ' (' . $src . ')')
            : '![' . $this->escape($alt) . '](' . self::link($src) . ')';
    }

    /**
     * An attached file, as a link to the endpoint that re-checks permission.
     *
     * The bytes are not inlined: an export is a text document, and embedding a
     * 20 MB PDF as base64 would make one unopenable.
     */
    private function attachment(array $node): string
    {
        $name = trim((string) ($node['attrs']['filename'] ?? 'Attachment'));
        $name = $name === '' ? 'Attachment' : $name;
        $href = self::attachmentUrl($this->noteId, $node['attrs']['attachmentId'] ?? null);

        if ($href === null) {
            return $this->plain ? $name : $this->escape($name);
        }

        return $this->plain
            ? $name . ' (' . $href . ')'
            : '[' . $this->escape($name) . '](' . self::link($href) . ')';
    }

    // -----------------------------------------------------------------------
    // Inline
    // -----------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $nodes */
    private function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->inlineNode($node);
        }

        return $out;
    }

    private function inlineNode(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];

        return match ($type) {
            'text' => $this->decorate((string) ($node['text'] ?? ''), $marks),
            // Two trailing spaces are what makes a single newline a line break
            // rather than a paragraph join in Markdown.
            'hardBreak' => $this->plain ? "\n" : "  \n",
            'image' => $this->image($node),
            'attachment', 'audio' => $this->attachment($node),
            'noteLink' => $this->noteLink($node),
            'mention' => $this->decorate('@' . (string) ($node['attrs']['label'] ?? 'mention'), $marks),
            'dateChip' => $this->decorate(
                (string) ($node['attrs']['label'] ?? ($node['attrs']['date'] ?? '')),
                $marks,
            ),
            default => $this->inline($this->children($node)),
        };
    }

    private function noteLink(array $node): string
    {
        $label = trim((string) ($node['attrs']['label'] ?? ''));
        $label = $label === '' ? 'Linked note' : $label;
        $target = $node['attrs']['noteId'] ?? null;

        if (!Uuid::isValid($target)) {
            return $this->plain ? $label : $this->escape($label);
        }

        $href = '/notes/' . strtolower((string) $target);

        return $this->plain
            ? $label . ' (' . $href . ')'
            : '[' . $this->escape($label) . '](' . $href . ')';
    }

    /**
     * Wrap one run of text in whatever its marks say.
     *
     * Order is not cosmetic: `code` is applied to the *unescaped* text and
     * innermost, because backslashes inside a code span are literal — escaping
     * first would put them on screen. Everything else wraps outwards, and the
     * link goes last so its label carries the emphasis rather than the other
     * way round.
     *
     * @param array<int, array<string, mixed>> $marks
     */
    private function decorate(string $raw, array $marks): string
    {
        if ($raw === '') {
            return '';
        }

        $types = [];
        $href = null;
        foreach ($marks as $mark) {
            $type = (string) ($mark['type'] ?? '');
            $types[$type] = true;
            if ($type === 'link') {
                $href = self::url($mark['attrs']['href'] ?? null);
            }
        }

        if ($this->plain) {
            // Plain text keeps the words and, where a link points somewhere the
            // words do not say, the address after them — losing it would make
            // the export less informative than the note.
            return $href !== null && !str_contains($raw, $href) ? $raw . ' (' . $href . ')' : $raw;
        }

        if (isset($types['code'])) {
            $ticks = str_repeat('`', self::longestRun($raw, '`') + 1);
            $pad = str_starts_with($raw, '`') || str_ends_with($raw, '`') ? ' ' : '';
            $text = $ticks . $pad . $raw . $pad . $ticks;
        } else {
            $text = $this->escape($raw);
        }

        if (isset($types['bold'])) {
            $text = '**' . $text . '**';
        }
        if (isset($types['italic'])) {
            $text = '*' . $text . '*';
        }
        if (isset($types['strike'])) {
            $text = '~~' . $text . '~~';
        }
        if (isset($types['highlight'])) {
            $text = '==' . $text . '==';
        }
        if ($href !== null) {
            $text = '[' . $text . '](' . self::link($href) . ')';
        }

        return $text;
    }

    // -----------------------------------------------------------------------
    // Escaping
    // -----------------------------------------------------------------------

    /**
     * Make a run of text mean itself.
     *
     * `<` and `>` are in the set because Markdown passes raw HTML through: a
     * note containing `<script>` would otherwise export as a script tag, and
     * whatever renders that Markdown would run it.
     */
    private function escape(string $text): string
    {
        if ($this->plain) {
            return $text;
        }

        return (string) preg_replace('/([\\\\`*_\[\]<>|~])/u', '\\\\$1', $text);
    }

    /**
     * Stop a line from becoming a block it is not.
     *
     * A paragraph that begins "- see below" or "1. first" is a paragraph; left
     * alone it would come back as a list. The leading marker is escaped, and
     * nothing else about the line changes.
     */
    private function guard(string $text): string
    {
        if ($this->plain || $text === '') {
            return $text;
        }

        return (string) preg_replace('/^(\s*)([#+-]|\d+[.)])(\s)/u', '$1\\\\$2$3', $text);
    }

    /**
     * Prefix every line, including the blank ones a quote has to keep.
     *
     * Plain text quotes with '>' too — it is the one convention the two share,
     * and dropping it would leave a quotation indistinguishable from the
     * paragraph before it.
     */
    private function prefixed(string $body, string $prefix): string
    {
        if (trim($body) === '') {
            return '';
        }

        $lines = explode("\n", $body);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? rtrim($prefix) : $prefix . $line,
            $lines,
        ));
    }

    /** A list marker with its continuation lines indented under it. */
    private static function hang(string $marker, string $body): string
    {
        $lines = explode("\n", $body);
        $first = array_shift($lines) ?? '';
        $pad = str_repeat(' ', mb_strlen($marker, 'UTF-8'));

        $out = $marker . $first;
        foreach ($lines as $line) {
            $out .= "\n" . ($line === '' ? '' : $pad . $line);
        }

        return rtrim($out);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function children(array $node): array
    {
        $content = $node['content'] ?? [];
        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }

    /** The text of a node with no escaping and no marks — what a code block holds. */
    private function rawText(array $node): string
    {
        $parts = [];
        foreach ($this->children($node) as $child) {
            $parts[] = (string) ($child['type'] ?? '') === 'text'
                ? (string) ($child['text'] ?? '')
                : $this->rawText($child);
        }

        return implode('', $parts);
    }

    /**
     * A URL safe to put in a link.
     *
     * The document was sanitised on the way in, so this is the second check
     * rather than the only one — an export is a file that leaves this server
     * and gets opened somewhere with no sanitiser at all.
     */
    private static function url(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $cleaned = (string) preg_replace('/[\x00-\x20]/', '', trim($value));
        if ($cleaned === '' || strlen($cleaned) > 2048 || str_starts_with($cleaned, '//')) {
            return null;
        }
        if (str_starts_with($cleaned, '/') || str_starts_with($cleaned, '#')) {
            return $cleaned;
        }

        $scheme = strtolower((string) parse_url($cleaned, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $cleaned : null;
    }

    /** Parentheses and spaces end a Markdown destination early; angle brackets do not. */
    private static function link(string $url): string
    {
        return preg_match('/[\s()<>]/', $url) === 1
            ? '<' . str_replace(['<', '>'], ['%3C', '%3E'], $url) . '>'
            : $url;
    }

    private static function attachmentUrl(string $noteId, mixed $attachmentId): ?string
    {
        if ($noteId === '' || !Uuid::isValid($attachmentId)) {
            return null;
        }

        // The same path the app uses, so the link keeps working for anyone who
        // can already open the note — and only for them.
        return sprintf('/notes/%s/attachments/%s/content', $noteId, strtolower((string) $attachmentId));
    }

    private static function longestRun(string $text, string $character): int
    {
        preg_match_all('/' . preg_quote($character, '/') . '+/', $text, $matches);

        return $matches[0] === [] ? 0 : max(array_map('strlen', $matches[0]));
    }
}
