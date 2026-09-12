<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Search;

/**
 * Something that turns text into a vector.
 *
 * An interface because there are two implementations that matter: the real
 * gateway client, and whatever a test substitutes for it. Embedding is the one
 * part of semantic search that cannot run in a test — there is no gateway —
 * and the alternative to a seam here is a suite that either skips the indexer
 * entirely or pretends to reach a service it cannot.
 */
interface EmbeddingSource
{
    /**
     * @return array{vector: array<int, float>, model: string}|null
     *         Null when no embedding could be produced, which is an ordinary
     *         answer: there is always a keyword result for a query, and always
     *         another run for a job.
     */
    public function embed(string $text): ?array;
}
