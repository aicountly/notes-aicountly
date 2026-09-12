<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

/**
 * What a provider answered.
 *
 * Deliberately smaller than any vendor's response: text, which blocks of the
 * context it says it used, and the model that ran. Everything else a provider
 * returns — token counts, finish reasons, tool calls, safety verdicts — stops
 * at {@see HttpPulseProvider}, so swapping the provider cannot change the shape
 * of what the rest of the API reasons about.
 */
final class AiResult
{
    /**
     * @param array<int, int> $citedContext 1-based context block numbers the provider stated it used.
     *        Empty means "the provider did not say", not "it used nothing" —
     *        {@see NotesAIService} then reads the citation markers out of the text.
     * @param array<string, mixed> $data Structured payload, when the provider returned one of its own.
     */
    public function __construct(
        public readonly string $text,
        public readonly array $citedContext = [],
        public readonly ?string $model = null,
        public readonly array $data = [],
    ) {
    }
}
