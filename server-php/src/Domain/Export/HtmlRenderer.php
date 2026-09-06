<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Export;

use Aicountly\Api\Support\Uuid;

/**
 * ProseMirror document → a standalone HTML file.
 *
 * The document tree is the source, not a browser's DOM: the server has no DOM,
 * and asking the client for "the HTML it is showing" would mean trusting a
 * string assembled somewhere this code cannot see. Every element below is
 * emitted by name from a node type this renderer recognises.
 *
 * **Every piece of text goes through `escape()`, without exception.** An export
 * is a file that gets mailed, opened from a downloads folder and sometimes
 * pasted into another system — all places with no sanitiser between it and a
 * parser. A note whose text is `<script>alert(1)</script>` comes back as
 * `&lt;script&gt;…`, and the same is true of every attribute value, every
 * class name and the title in the `<head>`.
 *
 * URLs are re-validated here rather than trusted from storage. `NoteDocument`
 * already refuses `javascript:` on the way in; this is the second gate, because
 * the cost of one being wrong is a file that runs script when opened.
 */
final class HtmlRenderer
{
    private const MAX_DEPTH = 40;

    private const CALLOUT_TONES = ['note', 'info', 'tip', 'success', 'warning', 'danger', 'important', 'caution'];

    /** Marks, and the element each becomes. Nothing outside this map is rendered. */
    private const MARK_TAGS = [
        'code' => 'code',
        'bold' => 'strong',
        'italic' => 'em',
        'underline' => 'u',
        'strike' => 's',
        'highlight' => 'mark',
    ];

    private function __construct(private readonly string $noteId)
    {
    }

    public static function render(array $document, ?string $title = null, string $noteId = ''): string
    {
        return (new self($noteId))->page($document, $title);
    }

    // -----------------------------------------------------------------------

    private function page(array $document, ?string $title): string
    {
        $heading = $title === null ? '' : trim($title);
        $documentTitle = $heading === '' ? 'Note' : $heading;

        $body = '';
        if ($heading !== '') {
            $body .= '<h1>' . self::escape($heading) . "</h1>\n";
        }
        $body .= $this->blocks($document, 0);

        return '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::escape($documentTitle) . '</title>' . "\n"
            . '<style>' . self::STYLE . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '<article class="note">' . "\n"
            . $body
            . '</article>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /** The children of a container, each rendered as a block. */
    private function blocks(array $node, int $depth): string
    {
        $out = '';
        foreach (self::children($node) as $child) {
            $out .= $this->block($child, $depth + 1);
        }

        return $out;
    }

    private function block(array $node, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $type = (string) ($node['type'] ?? '');

        return match ($type) {
            'paragraph' => self::wrap('p', $this->inline(self::children($node))),
            'heading' => $this->heading($node),
            'blockquote' => "<blockquote>\n" . $this->blocks($node, $depth) . "</blockquote>\n",
            'codeBlock' => $this->codeBlock($node),
            'bulletList' => "<ul>\n" . $this->listItems($node, $depth) . "</ul>\n",
            'orderedList' => $this->orderedList($node, $depth),
            'taskList' => "<ul class=\"task-list\">\n" . $this->taskItems($node, $depth) . "</ul>\n",
            'horizontalRule' => "<hr>\n",
            'table' => $this->table($node, $depth),
            'callout' => $this->callout($node, $depth),
            'details' => $this->details($node, $depth),
            'image' => self::wrap('p', $this->image($node)),
            'attachment', 'audio' => self::wrap('p', $this->attachment($node)),
            // No text form exists for a drawing surface, and pretending
            // otherwise would make an export look complete while a part of the
            // note is missing from it.
            'canvasEmbed' => "<p class=\"placeholder\">[Canvas drawing — open the note to view]</p>\n",
            'text', 'hardBreak', 'noteLink', 'mention', 'dateChip' => self::wrap('p', $this->inline([$node])),
            default => $this->blocks($node, $depth),
        };
    }

    private function heading(array $node): string
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));

        return self::wrap('h' . $level, $this->inline(self::children($node)));
    }

    private function codeBlock(array $node): string
    {
        $language = (string) ($node['attrs']['language'] ?? '');
        $class = preg_match('/^[A-Za-z0-9+#_-]{1,30}$/', $language) === 1
            ? ' class="language-' . self::escape($language) . '"'
            : '';

        return '<pre><code' . $class . '>' . self::escape(self::rawText($node)) . "</code></pre>\n";
    }

    private function listItems(array $node, int $depth): string
    {
        $out = '';
        foreach (self::children($node) as $item) {
            $out .= "<li>\n" . $this->blocks($item, $depth + 1) . "</li>\n";
        }

        return $out;
    }

    private function orderedList(array $node, int $depth): string
    {
        $start = max(1, (int) ($node['attrs']['start'] ?? 1));
        $attribute = $start === 1 ? '' : ' start="' . $start . '"';

        return '<ol' . $attribute . ">\n" . $this->listItems($node, $depth) . "</ol>\n";
    }

    /**
     * Checklist items keep their box.
     *
     * The input is `disabled`, so an exported file shows the state of the list
     * without pretending to be a working copy of it: ticking a box in a
     * downloaded file would change nothing and say nothing about that.
     */
    private function taskItems(array $node, int $depth): string
    {
        $out = '';
        foreach (self::children($node) as $item) {
            $checked = ($item['attrs']['checked'] ?? false) ? ' checked' : '';
            $out .= '<li><input type="checkbox" disabled' . $checked . ">\n"
                . $this->blocks($item, $depth + 1)
                . "</li>\n";
        }

        return $out;
    }

    private function table(array $node, int $depth): string
    {
        $rows = '';
        foreach (self::children($node) as $row) {
            if ((string) ($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = '';
            foreach (self::children($row) as $cell) {
                $tag = (string) ($cell['type'] ?? '') === 'tableHeader' ? 'th' : 'td';
                $cells .= '<' . $tag . '>' . $this->cell($cell, $depth) . '</' . $tag . '>';
            }
            $rows .= '<tr>' . $cells . "</tr>\n";
        }

        return $rows === '' ? '' : "<table>\n" . $rows . "</table>\n";
    }

    /**
     * The contents of one cell.
     *
     * A cell holds block content, but the overwhelmingly common cell is a
     * single paragraph — wrapping that in `<p>` gives every table a row of
     * paragraph margins and nothing else, so the inline content goes in
     * directly and anything richer keeps its blocks.
     */
    private function cell(array $cell, int $depth): string
    {
        $children = self::children($cell);
        if (count($children) === 1 && (string) ($children[0]['type'] ?? '') === 'paragraph') {
            return $this->inline(self::children($children[0]));
        }

        return trim($this->blocks($cell, $depth + 1));
    }

    private function callout(array $node, int $depth): string
    {
        $tone = strtolower((string) ($node['attrs']['tone'] ?? 'note'));
        $tone = in_array($tone, self::CALLOUT_TONES, true) ? $tone : 'note';

        return '<aside class="callout callout-' . $tone . "\">\n" . $this->blocks($node, $depth) . "</aside>\n";
    }

    private function details(array $node, int $depth): string
    {
        $summary = '';
        $body = '';

        foreach (self::children($node) as $child) {
            if ((string) ($child['type'] ?? '') === 'detailsSummary') {
                $summary = $this->inline(self::children($child));
                continue;
            }
            $body .= $this->block($child, $depth + 1);
        }

        $open = ($node['attrs']['open'] ?? false) ? ' open' : '';

        return '<details' . $open . ">\n<summary>" . $summary . "</summary>\n" . $body . "</details>\n";
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
            'text' => $this->decorate(self::escape((string) ($node['text'] ?? '')), $marks),
            'hardBreak' => '<br>',
            'image' => $this->image($node),
            'attachment', 'audio' => $this->attachment($node),
            'noteLink' => $this->noteLink($node),
            'mention' => '<span class="mention">'
                . self::escape('@' . (string) ($node['attrs']['label'] ?? 'mention')) . '</span>',
            'dateChip' => $this->dateChip($node),
            default => $this->inline(self::children($node)),
        };
    }

    /**
     * Wrap escaped text in the elements its marks name.
     *
     * `$text` arrives already escaped — the caller does that, so this method
     * can never be handed raw text by mistake and emit it.
     *
     * @param array<int, array<string, mixed>> $marks
     */
    private function decorate(string $text, array $marks): string
    {
        if ($text === '') {
            return '';
        }

        $href = null;
        foreach ($marks as $mark) {
            $type = (string) ($mark['type'] ?? '');
            if ($type === 'link') {
                $href = self::url($mark['attrs']['href'] ?? null);
                continue;
            }
            $tag = self::MARK_TAGS[$type] ?? null;
            if ($tag !== null) {
                $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
            }
        }

        // The link goes outermost so the emphasis sits inside the anchor, and
        // it carries the rel every external link in this product carries.
        return $href === null
            ? $text
            : '<a href="' . self::escape($href) . '" rel="noopener noreferrer nofollow">' . $text . '</a>';
    }

    private function image(array $node): string
    {
        $alt = (string) ($node['attrs']['alt'] ?? ($node['attrs']['title'] ?? ''));
        $src = self::url($node['attrs']['src'] ?? null);

        if ($src === null) {
            return $alt === '' ? '' : '<span class="placeholder">' . self::escape($alt) . '</span>';
        }

        return '<img src="' . self::escape($src) . '" alt="' . self::escape($alt) . '">';
    }

    /**
     * An attached file, as a link rather than as bytes.
     *
     * The target re-checks permission on every request, so the link stops
     * working for anyone who loses access to the note — which an inlined copy
     * of the file never would.
     */
    private function attachment(array $node): string
    {
        $name = trim((string) ($node['attrs']['filename'] ?? ''));
        $name = $name === '' ? 'Attachment' : $name;
        $attachmentId = $node['attrs']['attachmentId'] ?? null;

        if ($this->noteId === '' || !Uuid::isValid($attachmentId)) {
            return '<span class="placeholder">' . self::escape($name) . '</span>';
        }

        $href = sprintf('/notes/%s/attachments/%s/content', $this->noteId, strtolower((string) $attachmentId));

        return '<a class="attachment" href="' . self::escape($href) . '">' . self::escape($name) . '</a>';
    }

    private function noteLink(array $node): string
    {
        $label = trim((string) ($node['attrs']['label'] ?? ''));
        $label = $label === '' ? 'Linked note' : $label;
        $target = $node['attrs']['noteId'] ?? null;

        if (!Uuid::isValid($target)) {
            return self::escape($label);
        }

        return '<a href="/notes/' . self::escape(strtolower((string) $target)) . '">' . self::escape($label) . '</a>';
    }

    private function dateChip(array $node): string
    {
        $label = (string) ($node['attrs']['label'] ?? '');
        $date = (string) ($node['attrs']['date'] ?? '');
        $text = $label !== '' ? $label : $date;

        if ($text === '') {
            return '';
        }

        return $date === ''
            ? '<span class="date">' . self::escape($text) . '</span>'
            : '<time datetime="' . self::escape($date) . '">' . self::escape($text) . '</time>';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The only way text becomes HTML in this class.
     *
     * `ENT_QUOTES` because the same helper is used for attribute values, and
     * `ENT_SUBSTITUTE` so a byte sequence that is not valid UTF-8 becomes a
     * replacement character instead of an empty string — silently dropping a
     * whole paragraph because one byte was wrong is the worse failure.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function wrap(string $tag, string $inner): string
    {
        return trim($inner) === '' ? '' : '<' . $tag . '>' . $inner . '</' . $tag . ">\n";
    }

    /** @return array<int, array<string, mixed>> */
    private static function children(array $node): array
    {
        $content = $node['content'] ?? [];

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    private static function rawText(array $node): string
    {
        $parts = [];
        foreach (self::children($node) as $child) {
            $parts[] = (string) ($child['type'] ?? '') === 'text'
                ? (string) ($child['text'] ?? '')
                : self::rawText($child);
        }

        return implode('', $parts);
    }

    /**
     * A URL that may appear in an `href` or `src`.
     *
     * Whitespace and control characters are stripped before the scheme is read,
     * because `java\nscript:` is the oldest way past a check that reads the
     * scheme off the raw string. Scheme-relative URLs are refused outright:
     * `//evil.example` in a file opened from disk is not the origin anyone
     * expects it to be.
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

    /**
     * Enough style for the file to be readable on its own.
     *
     * Deliberately small and self-contained: an export that pulled a stylesheet
     * off the network would be a blank page on a laptop with no connection, and
     * a note that phones home when opened.
     */
    private const STYLE = <<<'CSS'
    :root { color-scheme: light dark; }
    body { margin: 0; padding: 2rem 1.25rem; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
    .note { max-width: 44rem; margin: 0 auto; }
    h1, h2, h3, h4, h5, h6 { line-height: 1.25; margin: 1.6em 0 .5em; }
    h1 { margin-top: 0; }
    blockquote { margin: 1em 0; padding: .25rem 0 .25rem 1rem; border-left: 3px solid currentColor; opacity: .85; }
    pre { padding: .75rem 1rem; overflow-x: auto; background: rgba(127,127,127,.14); border-radius: .375rem; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .925em; }
    table { border-collapse: collapse; width: 100%; margin: 1em 0; display: block; overflow-x: auto; }
    th, td { border: 1px solid rgba(127,127,127,.4); padding: .4rem .6rem; text-align: left; vertical-align: top; }
    img { max-width: 100%; height: auto; }
    hr { border: 0; border-top: 1px solid rgba(127,127,127,.4); margin: 2em 0; }
    .task-list { list-style: none; padding-left: 1.2rem; }
    .task-list input { margin-right: .4rem; }
    .callout { margin: 1em 0; padding: .75rem 1rem; border-left: 4px solid rgba(127,127,127,.6); background: rgba(127,127,127,.1); }
    .placeholder { opacity: .7; font-style: italic; }
    .mention { font-weight: 600; }
    CSS;
}
