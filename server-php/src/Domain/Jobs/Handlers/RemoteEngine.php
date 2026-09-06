<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Logger;

/**
 * The adapter for the two things PHP on cPanel cannot do by itself.
 *
 * Reading text off a photograph and turning speech into words both need a model
 * this server does not have and cannot install. There are exactly two honest
 * options for that: call something that can, or say so. This class is the
 * first, and it is careful to make the second visible rather than silent —
 * `available()` is false when the feature flag is off *or* when the endpoint
 * was never configured, and a handler that gets false skips the job and records
 * why. Nothing here ever returns an empty string dressed up as a result.
 *
 * The contract with the endpoint is deliberately small, because every OCR and
 * speech-to-text service can satisfy it behind a thin proxy:
 *
 *   POST <endpoint>
 *   { "filename": …, "mime_type": …, "content_base64": … }
 *   → 200 { "text": …, "language": …, "segments": [ … ], "model": … }
 */
final class RemoteEngine
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $feature,
        private readonly string $urlKey,
        private readonly string $tokenKey,
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public static function ocr(): self
    {
        return new self(Features::OCR, 'NOTES_OCR_API_URL', 'NOTES_OCR_API_TOKEN', 60);
    }

    public static function transcription(): self
    {
        // Transcribing an hour of audio is not a 60-second request.
        return new self(Features::TRANSCRIPTION, 'NOTES_TRANSCRIPTION_API_URL', 'NOTES_TRANSCRIPTION_API_TOKEN', 300);
    }

    /** Off, or on but pointed at nothing — the caller must skip either way. */
    public function available(): bool
    {
        return Features::enabled($this->feature) && $this->endpoint() !== '';
    }

    public function unavailableReason(): string
    {
        return Features::enabled($this->feature) ? 'endpoint_not_configured' : 'feature_disabled';
    }

    public function endpoint(): string
    {
        return trim(Env::get($this->urlKey));
    }

    /**
     * Send one file and read back what the engine made of it.
     *
     * @return array{status: int, text: string, language: ?string, segments: array<int, mixed>, model: ?string}
     */
    public function process(string $filename, string $mimeType, string $bytes): array
    {
        $payload = json_encode([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'content_base64' => base64_encode($bytes),
        ], JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $token = Env::get($this->tokenKey);
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($this->endpoint());
        if ($ch === false) {
            throw new \RuntimeException('engine_unreachable');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            // The bearer token must not follow a redirect to a host the
            // configured endpoint did not name.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($ch);

        if ($failed || $status === 0) {
            // Transport trouble is the retryable kind, and the queue's backoff
            // is what keeps a struggling engine from being hammered.
            Logger::warn('engine.unreachable', ['feature' => $this->feature]);
            throw new \RuntimeException('engine_unreachable');
        }

        $decoded = json_decode((string) $response, true);
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : []);

        return [
            'status' => $status,
            'text' => is_string($data['text'] ?? null) ? $data['text'] : '',
            'language' => is_string($data['language'] ?? null) ? substr($data['language'], 0, 16) : null,
            'segments' => is_array($data['segments'] ?? null) ? $data['segments'] : [],
            'model' => is_string($data['model'] ?? null) ? substr($data['model'], 0, 120) : null,
        ];
    }
}
