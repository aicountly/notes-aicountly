<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Search;

use Aicountly\Api\Env;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;

/**
 * Text in, vector out — or nothing, which is a normal answer.
 *
 * Lifted out of the search service because there are now two callers with
 * opposite deadlines. A search box cannot wait: an embedding that has not
 * arrived in a few seconds is worth less than keyword results returned now.
 * The indexer behind it can wait, and should, because giving up turns into a
 * note nobody can find semantically until something edits it again.
 *
 * Returning null rather than raising is deliberate in both cases. There is
 * always a keyword answer for a query, and always another run for a job, and a
 * gateway that is down is not a reason to hand either of them a 502.
 *
 * Nothing about the text reaches the log — it is the user's note.
 */
final class EmbeddingProvider implements EmbeddingSource
{
    /** Where the indexer is prepared to wait until. */
    public const PATIENT_TIMEOUT = 30;

    /** What a search box will wait, which is much less. */
    public const INTERACTIVE_TIMEOUT = 8;

    public function __construct(private readonly int $timeoutSeconds = self::INTERACTIVE_TIMEOUT)
    {
    }

    /** True when a gateway is configured at all. */
    public static function configured(): bool
    {
        return rtrim(Env::get('PULSE_API_URL'), '/') !== '';
    }

    /**
     * @return array{vector: array<int, float>, model: string}|null
     */
    public function embed(string $text): ?array
    {
        $base = rtrim(Env::get('PULSE_API_URL'), '/');
        if ($base === '' || trim($text) === '') {
            return null;
        }

        $model = Env::get('PULSE_EMBEDDING_MODEL', 'text-embedding-3-small');
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (Env::get('PULSE_API_KEY') !== '') {
            $headers[] = 'Authorization: Bearer ' . Env::get('PULSE_API_KEY');
        }

        $handle = curl_init($base . '/embeddings');
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => (string) json_encode(['model' => $model, 'input' => $text]),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            Logger::warn('search.embedding_unavailable', ['status' => $status]);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::warn('search.embedding_unreadable', ['status' => $status]);

            return null;
        }

        $raw = $decoded['data'][0]['embedding'] ?? $decoded['embedding'] ?? null;
        if (!is_array($raw) || count($raw) < 8 || count($raw) > 4096) {
            Logger::warn('search.embedding_unreadable', ['status' => $status]);

            return null;
        }

        $vector = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                Logger::warn('search.embedding_unreadable', ['status' => $status]);

                return null;
            }
            $vector[] = (float) $value;
        }

        return [
            'vector' => $vector,
            // The provider's own name for what it returned, so the stored
            // chunks filtered on `model` are the ones this vector belongs with.
            'model' => Str::limit((string) ($decoded['model'] ?? $model), 120),
        ];
    }

    /**
     * Split a note into pieces small enough to embed and large enough to mean
     * something.
     *
     * Paragraph-aligned rather than a fixed character window: a chunk that
     * starts mid-sentence embeds the shape of the split as much as the
     * content, and the chunk text is what a search result shows as its
     * snippet. Oversized paragraphs are cut on a word boundary as a last
     * resort — a wall of text with no breaks is rare and still has to fit.
     *
     * @return array<int, string>
     */
    public static function chunk(string $text, int $maxCharacters = 1500): array
    {
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            while (mb_strlen($paragraph) > $maxCharacters) {
                $cut = mb_strrpos(mb_substr($paragraph, 0, $maxCharacters), ' ') ?: $maxCharacters;
                $chunks[] = trim(mb_substr($paragraph, 0, $cut));
                $paragraph = trim(mb_substr($paragraph, $cut));
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $maxCharacters) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
    }
}
