<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Search;

use Aicountly\Api\Support\Str;

/**
 * The two text shapes search produces: a highlighted snippet, and a chunk.
 *
 * The highlight markers are the reason this is a class rather than a format
 * string inside a query. `ts_headline` is asked for `[[hl]]…[[/hl]]` and never
 * for `<b>…</b>`, because the thing being highlighted is a note body — text a
 * user wrote, or a collaborator wrote. A server that returned HTML would be
 * telling the client to `innerHTML` user content, which is stored XSS with
 * extra steps. The client splits on these markers and renders each piece as a
 * text node, so the worst a hostile note can do is show square brackets.
 *
 * That defence only holds if a marker in a snippet always came from *us*, so
 * every path that produces a snippet strips markers out of the source text
 * first — see `stripMarkers()` and the `replace()` calls in the search SQL.
 */
final class SearchSnippet
{
    public const HIGHLIGHT_OPEN = '[[hl]]';
    public const HIGHLIGHT_CLOSE = '[[/hl]]';

    /**
     * How much of a note `ts_headline` reads.
     *
     * It re-parses the text it is given, so handing it a 200 KB meeting
     * transcript to find one word is the slowest part of a search. The first
     * 12 000 characters hold the match in practice, and the excerpt is two
     * fragments long either way.
     */
    public const HEADLINE_SOURCE_CHARS = 12000;

    /** Target chunk size for retrieval. Small enough that a handful fit in a prompt. */
    public const CHUNK_CHARS = 1200;

    /**
     * `ts_headline` options.
     *
     * `MaxFragments` > 0 selects the fragment-based algorithm, which shows the
     * neighbourhood of each match instead of the head of the document — the
     * difference between a result you can judge and a result you must open.
     */
    public static function headlineOptions(): string
    {
        return sprintf(
            'StartSel="%s", StopSel="%s", MaxFragments=2, MaxWords=22, MinWords=8, FragmentDelimiter=" … "',
            self::HIGHLIGHT_OPEN,
            self::HIGHLIGHT_CLOSE,
        );
    }

    /**
     * Remove highlight markers a user may have typed.
     *
     * Used on every snippet the server does not highlight itself (semantic
     * chunks, AI context), so a note containing the literal text `[[hl]]` can
     * never make a client render a highlight that no match produced.
     */
    public static function stripMarkers(string $text): string
    {
        return str_replace([self::HIGHLIGHT_OPEN, self::HIGHLIGHT_CLOSE], '', $text);
    }

    /** A plain one-line excerpt: collapsed whitespace, no markers, bounded. */
    public static function preview(string $text, int $max = 400): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', self::stripMarkers($text)) ?? $text;

        return Str::limit(trim($collapsed), $max);
    }

    /**
     * Split text into retrieval-sized pieces at sentence boundaries.
     *
     * Chunking matters more than it looks: an answer is only as good as the
     * fragment it was given, and a chunk cut mid-sentence hands the model half
     * a fact. So sentences are kept whole, and only a sentence that is longer
     * than a whole chunk on its own — a wall-of-text paste, a transcript with
     * no punctuation — is broken, and then at a word boundary.
     *
     * @return array<int, string>
     */
    public static function chunk(string $text, int $target = self::CHUNK_CHARS): array
    {
        $target = max(200, $target);
        $normalised = trim(preg_replace('/[ \t]+/u', ' ', self::stripMarkers($text)) ?? $text);
        if ($normalised === '') {
            return [];
        }

        // Sentence ends, and hard line breaks: in a note, a list item or a
        // heading is a boundary even without a full stop.
        $sentences = preg_split('/(?<=[.!?…])\s+|\R+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            foreach (self::splitLongSentence(trim($sentence), $target) as $piece) {
                if ($piece === '') {
                    continue;
                }
                if ($current !== '' && mb_strlen($current, 'UTF-8') + 1 + mb_strlen($piece, 'UTF-8') > $target) {
                    $chunks[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $piece : $current . ' ' . $piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @return array<int, string> */
    private static function splitLongSentence(string $sentence, int $target): array
    {
        if (mb_strlen($sentence, 'UTF-8') <= $target) {
            return [$sentence];
        }

        $pieces = [];
        $current = '';
        foreach (preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // A single "word" longer than a chunk (a base64 blob, a hash) is
            // cut by length; there is no boundary left to respect.
            while (mb_strlen($word, 'UTF-8') > $target) {
                $pieces[] = mb_substr($word, 0, $target, 'UTF-8');
                $word = mb_substr($word, $target, null, 'UTF-8');
            }
            if ($current !== '' && mb_strlen($current, 'UTF-8') + 1 + mb_strlen($word, 'UTF-8') > $target) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $word : $current . ' ' . $word;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
