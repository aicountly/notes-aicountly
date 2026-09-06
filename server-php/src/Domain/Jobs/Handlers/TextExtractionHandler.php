<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Support\Str;

/**
 * Reading the words out of a file, with PHP alone.
 *
 * Two formats can be done honestly here and both are done for real:
 *
 *   - **Plain text, Markdown and CSV** — the bytes, repaired to UTF-8.
 *   - **PDF** — the content streams are inflated with ext-zlib and the
 *     text-showing operators inside them are tokenised. That covers the PDFs
 *     people actually attach: exports from Word, Google Docs, accounting
 *     software and every "print to PDF".
 *
 * What it does *not* do is pretend. A scanned PDF is a page of images with no
 * text layer, and a PDF using a Type0 font with a custom CMap produces glyph
 * ids rather than characters. Both come out as "no text layer" — measured, not
 * assumed, by {@see looksLikeText()} — and the job hands the file to OCR
 * instead of storing gibberish that would then be indexed and searched.
 */
final class TextExtractionHandler implements JobHandler
{
    private const PLAIN_TEXT_TYPES = ['text/plain', 'text/markdown', 'text/csv'];

    /** A kern wider than this much of an em is a word break rather than letter spacing. */
    private const WORD_BREAK_KERN = -100.0;

    /** Enough characters to judge whether what came out is language. */
    private const MIN_TEXT_CHARS = 16;

    public function __construct(
        private readonly AttachmentService $attachments = new AttachmentService(),
        private readonly JobQueue $jobs = new JobQueue(),
    ) {
    }

    public function handle(array $job): array
    {
        $attachmentId = $job['attachment_id'] ?? null;
        $attachment = is_string($attachmentId) ? $this->attachments->find($attachmentId) : null;
        if ($attachment === null || $attachment['deleted_at'] !== null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'attachment_missing'];
        }

        $mime = (string) $attachment['mime_type'];
        $isPlainText = in_array($mime, self::PLAIN_TEXT_TYPES, true);
        if (!$isPlainText && $mime !== 'application/pdf') {
            // Nothing about this file will change; a fifth attempt reads the
            // same bytes with the same library.
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'unsupported_mime'];
        }

        $bytes = $this->attachments->storeFor($attachment)->get((string) $attachment['storage_key']);

        $text = $isPlainText
            ? self::normaliseText($bytes)
            : self::normaliseText(self::pdfText($bytes));

        if (!$isPlainText && !self::looksLikeText($text)) {
            // A scan, or a font this parser cannot map. Either way the pixels
            // are the only copy of the words, so the file goes to OCR.
            $this->jobs->enqueue(
                (string) $job['requested_by'],
                JobQueue::OCR,
                (string) $attachment['note_id'],
                (string) $attachment['id'],
            );

            return ['outcome' => self::COMPLETED, 'reason' => 'no_text_layer', 'characters' => 0];
        }

        $text = Str::limit($text, AttachmentService::MAX_EXTRACTED_CHARS);
        $this->attachments->recordExtractedText((string) $attachment['id'], $text);
        $this->jobs->enqueue(
            (string) $job['requested_by'],
            JobQueue::DERIVED_TEXT,
            (string) $attachment['note_id'],
        );

        return ['outcome' => self::COMPLETED, 'characters' => mb_strlen($text, 'UTF-8')];
    }

    // -----------------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------------

    /**
     * Every content stream in the file, inflated and read.
     *
     * Scanned with `strpos` rather than one big regular expression: a 25 MB PDF
     * would run PCRE past `pcre.backtrack_limit` on a lazy `.*?`, and the
     * failure mode of that is a silent empty match rather than an error.
     */
    private static function pdfText(string $bytes): string
    {
        // An encrypted PDF needs a key this API does not have, and the streams
        // would inflate to noise.
        if (str_contains($bytes, '/Encrypt')) {
            return '';
        }

        $text = '';
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length && mb_strlen($text, 'UTF-8') < AttachmentService::MAX_EXTRACTED_CHARS) {
            $streamAt = strpos($bytes, 'stream', $offset);
            if ($streamAt === false) {
                break;
            }
            $endAt = strpos($bytes, 'endstream', $streamAt);
            if ($endAt === false) {
                break;
            }

            $dictionary = self::dictionaryBefore($bytes, $streamAt);
            $dataAt = $streamAt + 6;
            // The keyword is followed by CRLF or LF, and that EOL is not data.
            if (($bytes[$dataAt] ?? '') === "\r") {
                $dataAt++;
            }
            if (($bytes[$dataAt] ?? '') === "\n") {
                $dataAt++;
            }

            $decoded = self::decodeStream($dictionary, substr($bytes, $dataAt, max(0, $endAt - $dataAt)));
            if ($decoded !== null) {
                $text .= self::textFromContentStream($decoded);
            }

            $offset = $endAt + 9;
        }

        return $text;
    }

    /** The `<< … >>` immediately preceding a stream, which says how it is encoded. */
    private static function dictionaryBefore(string $bytes, int $streamAt): string
    {
        // Negative offset: search backwards, ending at the stream keyword,
        // without copying the megabytes in front of it.
        $dictAt = strrpos($bytes, '<<', $streamAt - strlen($bytes));
        if ($dictAt === false || $streamAt - $dictAt > 4096) {
            return '';
        }

        return substr($bytes, $dictAt, $streamAt - $dictAt);
    }

    /** @return string|null Null when the stream is not text this parser can read. */
    private static function decodeStream(string $dictionary, string $data): ?string
    {
        // Images, fonts and colour profiles are streams too, and none of them
        // contain text-showing operators.
        foreach (['/Image', 'DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode', '/FontFile'] as $marker) {
            if (str_contains($dictionary, $marker)) {
                return null;
            }
        }

        if (!str_contains($dictionary, 'FlateDecode')) {
            // An uncompressed content stream, which is what a hand-written or
            // linearised-for-debugging PDF has.
            return str_contains($dictionary, 'Decode') ? null : $data;
        }

        $data = trim($data, "\r\n");
        $inflated = @gzuncompress($data);
        if ($inflated === false) {
            // Some writers emit raw deflate with no zlib header.
            $inflated = @gzinflate($data);
        }

        return $inflated === false ? null : $inflated;
    }

    /**
     * The text inside one content stream.
     *
     * A small PDF tokeniser rather than a regular expression, because the two
     * things that make extracted text readable are both positional: strings
     * only count between `BT` and `ET`, and the *number* between two strings in
     * a `TJ` array is what tells a word break from letter spacing. Joining
     * strings without it turns "Quarterly GST summary" into
     * "QuarterlyGSTsummary".
     */
    private static function textFromContentStream(string $content): string
    {
        $out = '';
        $length = strlen($content);
        $index = 0;
        $inText = false;

        while ($index < $length) {
            $char = $content[$index];

            if ($char === '%') {
                $newline = strcspn($content, "\r\n", $index);
                $index += max(1, $newline);
                continue;
            }
            if ($char === '(') {
                [$string, $index] = self::readLiteralString($content, $index);
                if ($inText) {
                    $out .= $string;
                }
                continue;
            }
            if ($char === '<') {
                if (($content[$index + 1] ?? '') === '<') {
                    $index += 2;
                    continue;
                }
                [$string, $index] = self::readHexString($content, $index);
                if ($inText) {
                    $out .= $string;
                }
                continue;
            }
            if ($char === '/') {
                $index += 1 + strcspn($content, " \t\r\n\f\0/[]<>()", $index + 1);
                continue;
            }
            if ($char === '-' || $char === '+' || $char === '.' || ($char >= '0' && $char <= '9')) {
                $span = strspn($content, '0123456789+-.eE', $index);
                if ($inText && (float) substr($content, $index, $span) <= self::WORD_BREAK_KERN) {
                    $out .= ' ';
                }
                $index += max(1, $span);
                continue;
            }
            if ($char === "'" || $char === '"') {
                $out .= "\n";
                $index++;
                continue;
            }
            if (ctype_alpha($char)) {
                $span = strspn($content, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz*', $index);
                $operator = substr($content, $index, $span);
                $index += max(1, $span);

                if ($operator === 'BT') {
                    $inText = true;
                    $out .= "\n";
                } elseif ($operator === 'ET') {
                    $inText = false;
                    $out .= "\n";
                } elseif (in_array($operator, ['Td', 'TD', 'T*'], true)) {
                    $out .= "\n";
                }
                continue;
            }

            $index++;
        }

        return $out;
    }

    /**
     * A `( … )` string: balanced parentheses, backslash escapes, octal.
     *
     * @return array{0: string, 1: int} The decoded text and the index just past it.
     */
    private static function readLiteralString(string $content, int $index): array
    {
        $length = strlen($content);
        $depth = 1;
        $raw = '';

        for ($index++; $index < $length; $index++) {
            $char = $content[$index];

            if ($char === '\\') {
                $next = $content[++$index] ?? '';
                if ($next >= '0' && $next <= '7') {
                    $octal = $next;
                    while (strlen($octal) < 3 && (($content[$index + 1] ?? '') >= '0') && (($content[$index + 1] ?? '') <= '7')) {
                        $octal .= $content[++$index];
                    }
                    $raw .= chr(octdec($octal) % 256);
                    continue;
                }
                $raw .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0c",
                    // A backslash before a newline is a line continuation.
                    "\n", "\r" => '',
                    default => $next,
                };
                continue;
            }
            if ($char === '(') {
                $depth++;
                $raw .= $char;
                continue;
            }
            if ($char === ')') {
                if (--$depth === 0) {
                    $index++;
                    break;
                }
                $raw .= $char;
                continue;
            }

            $raw .= $char;
        }

        return [self::decodeString($raw), $index];
    }

    /**
     * A `< … >` hex string.
     *
     * @return array{0: string, 1: int}
     */
    private static function readHexString(string $content, int $index): array
    {
        $end = strpos($content, '>', $index);
        if ($end === false) {
            return ['', strlen($content)];
        }

        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $index + 1, $end - $index - 1)) ?? '';
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return [self::decodeString((string) hex2bin($hex)), $end + 1];
    }

    /** PDF strings are UTF-16BE when they carry the BOM and WinAnsi otherwise. */
    private static function decodeString(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    // -----------------------------------------------------------------------

    /**
     * Is this language, or is it the shape of language?
     *
     * A PDF whose fonts this parser cannot map still produces *characters* —
     * they are simply the wrong ones. Storing them would put nonsense in the
     * search index and, worse, would make the file look successfully read so
     * OCR never ran.
     */
    private static function looksLikeText(string $text): bool
    {
        $stripped = preg_replace('/\s+/u', '', $text) ?? '';
        $total = mb_strlen($stripped, 'UTF-8');
        if ($total < self::MIN_TEXT_CHARS) {
            return false;
        }

        return preg_match_all('/[\p{L}\p{N}]/u', $stripped) / $total >= 0.6;
    }

    /** Repair encoding, drop control bytes, collapse the whitespace a PDF produces. */
    private static function normaliseText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t\x0b\f]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
