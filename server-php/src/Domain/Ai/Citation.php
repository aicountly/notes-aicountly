<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

use Aicountly\Api\Domain\Search\SearchSnippet;
use Aicountly\Api\Support\Str;

/**
 * Where an answer came from.
 *
 * An assistant inside a notes app is only worth trusting if the user can get
 * back to the note behind a sentence. So an answer drawn from someone's notes
 * carries structured citations — a note id they can open, the block when the
 * retrieval knew it, and the snippet that was actually put in front of the
 * model — rather than a prose "according to your notes".
 *
 * The snippet is quoted from what was sent, never from what the model wrote
 * back. A model that paraphrases a note into something it does not say must
 * not be able to make that paraphrase look like a quotation.
 */
final class Citation
{
    /** How much of the source travels back to the client. Enough to recognise, not to re-read. */
    private const SNIPPET_CHARS = 320;

    public function __construct(
        public readonly string $noteId,
        public readonly ?string $title,
        public readonly ?string $blockId,
        public readonly string $snippet,
    ) {
    }

    /**
     * One retrieval chunk, as returned by
     * {@see \Aicountly\Api\Domain\Search\NotesSearchService::retrieveForAi()}.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRetrieval(array $row): self
    {
        return new self(
            (string) ($row['note_id'] ?? ''),
            isset($row['title']) && $row['title'] !== null ? (string) $row['title'] : null,
            isset($row['block_id']) && $row['block_id'] !== null ? (string) $row['block_id'] : null,
            SearchSnippet::preview((string) ($row['snippet'] ?? ''), self::SNIPPET_CHARS),
        );
    }

    /** A whole note as one source — what a summary or a rewrite is grounded in. */
    public static function forNote(string $noteId, ?string $title, string $text, ?string $blockId = null): self
    {
        return new self(
            $noteId,
            $title === null || trim($title) === '' ? null : Str::limit($title, 500),
            $blockId,
            SearchSnippet::preview($text, self::SNIPPET_CHARS),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'note_id' => $this->noteId,
            'title' => $this->title,
            'block_id' => $this->blockId,
            'snippet' => $this->snippet,
        ];
    }

    /**
     * @param array<int, self> $citations
     * @return array<int, array<string, mixed>>
     */
    public static function toArrayList(array $citations): array
    {
        return array_values(array_map(static fn (self $citation): array => $citation->toArray(), $citations));
    }

    /**
     * The context blocks a model claims it used.
     *
     * Providers that return a citation list of their own are believed first;
     * this is the fallback for the ones that only mark the prose, which is why
     * the prompt asks for `[1]`-style markers. Both `[2]` and `[1, 3]` are
     * accepted because models produce both.
     *
     * @return array<int, int> 1-based block numbers, ascending and unique.
     */
    public static function referencedIndexes(string $answer): array
    {
        if (preg_match_all('/\[(\d{1,3}(?:\s*,\s*\d{1,3})*)\]/', $answer, $matches) === false) {
            return [];
        }

        $indexes = [];
        foreach ($matches[1] ?? [] as $group) {
            foreach (explode(',', $group) as $number) {
                $index = (int) trim($number);
                if ($index > 0) {
                    $indexes[$index] = true;
                }
            }
        }

        $found = array_keys($indexes);
        sort($found);

        return $found;
    }

    /**
     * Pick the cited blocks out of what was sent.
     *
     * An index pointing past the context is dropped rather than clamped: a
     * model that invented `[7]` for a four-block prompt has not told us which
     * note it used, and guessing one would be worse than reporting none.
     *
     * @param array<int, self> $citations
     * @param array<int, int> $indexes
     * @return array<int, self>
     */
    public static function select(array $citations, array $indexes): array
    {
        $ordered = array_values($citations);
        $picked = [];

        foreach ($indexes as $index) {
            if (isset($ordered[$index - 1])) {
                $picked[] = $ordered[$index - 1];
            }
        }

        return $picked;
    }
}
