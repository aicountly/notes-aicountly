<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

final class Str
{
    /**
     * Fold a tag to its lookup form.
     *
     * "GST", "gst" and " G S T " must not become three tags, so uniqueness is
     * enforced on this value while `tags.name` keeps whatever the user typed.
     */
    public static function tagSlug(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $collapsed = preg_replace('/[\s_]+/u', '-', $lower) ?? $lower;
        $cleaned = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $collapsed) ?? $collapsed;

        return trim($cleaned, '-');
    }

    /** Stable content fingerprint — drives revision de-duplication and re-embedding. */
    public static function contentHash(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function limit(string $value, int $max): string
    {
        return mb_strlen($value, 'UTF-8') <= $max ? $value : mb_substr($value, 0, $max, 'UTF-8');
    }

    /**
     * First non-empty line, used as a note's display title when it has none.
     * Google Keep's behaviour, and the reason `notes.title` is nullable.
     */
    public static function firstLine(string $text, int $max = 120): string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return self::limit($trimmed, $max);
            }
        }

        return '';
    }

    public static function wordCount(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/[\s\p{Z}]+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
