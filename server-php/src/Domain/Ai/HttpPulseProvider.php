<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * The only file in this repository that knows what a model provider looks like.
 *
 * PHP on cPanel cannot run a model, so there are two honest options: call
 * something that can, or say the feature is off. This is the first, and
 * {@see Features::AI} is the second — a deployment without `PULSE_API_URL`
 * answers 503 rather than pretending.
 *
 * The contract with the endpoint is the OpenAI-compatible chat shape, because
 * every hosted gateway and any thin proxy in front of a self-hosted model can
 * satisfy it:
 *
 *   POST <PULSE_API_URL>/chat/completions
 *   { "model": …, "messages": [{"role": "system"|"user", "content": …}] }
 *   → 200 { "model": …, "choices": [{"message": {"content": …}}] }
 *
 * Three rules this class exists to keep:
 *
 *   - **Roles stay separate.** The bundle's instructions and the note text it
 *     quotes are different messages. Nothing here concatenates them.
 *   - **Nothing is logged but shape.** Not the prompt, not the answer, not a
 *     truncated preview of either. Counts, status codes and durations only.
 *   - **One retry, and only for a connection that never opened.** A refused
 *     TCP connect or a DNS failure is worth trying again; a 400 or a 401 is
 *     the provider telling us something, and repeating it would double the
 *     load on a service that already answered.
 */
final class HttpPulseProvider implements PulseProvider
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_TIMEOUT_SECONDS = 45;

    /** A model that will not stop talking must not fill the response buffer. */
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(private readonly ?int $timeoutSeconds = null)
    {
    }

    public function complete(PromptBundle $bundle): AiResult
    {
        $endpoint = self::endpoint();
        if ($endpoint === '') {
            // Reachable only if the flag was forced on without the URL. The
            // honest answer is the same one Features gives.
            throw ApiException::featureDisabled(Features::AI);
        }

        $payload = json_encode([
            'model' => Env::get('PULSE_MODEL', 'gpt-4o-mini'),
            'messages' => $bundle->messages(),
            // Notes work is extraction and rewriting, not invention.
            'temperature' => 0.2,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $attempt = $this->send($endpoint, (string) $payload);
        if ($attempt['connection_failed']) {
            Logger::warn('pulse.unreachable', ['attempt' => 1] + $bundle->describe());
            $attempt = $this->send($endpoint, (string) $payload);
        }

        if ($attempt['connection_failed']) {
            Logger::warn('pulse.unreachable', ['attempt' => 2] + $bundle->describe());
            throw ApiException::upstream('pulse', 'Pulse could not be reached. Try again in a moment.');
        }

        if ($attempt['status'] < 200 || $attempt['status'] >= 300) {
            // The provider's own error text may quote the prompt back, so it is
            // read for nothing and never forwarded.
            Logger::warn('pulse.upstream_error', ['status' => $attempt['status']] + $bundle->describe());
            throw ApiException::upstream('pulse', 'Pulse could not answer this request.');
        }

        return self::parse($attempt['body'], $bundle);
    }

    public static function endpoint(): string
    {
        $base = rtrim(trim(Env::get('PULSE_API_URL')), '/');

        return $base === '' ? '' : $base . '/chat/completions';
    }

    // -----------------------------------------------------------------------

    /**
     * One HTTP attempt.
     *
     * `connection_failed` is the retryable case and is kept distinct from an
     * HTTP status: cURL reports both as a failed `exec`, and treating a 500
     * like a refused connection is how a struggling provider gets hit twice.
     *
     * @return array{connection_failed: bool, status: int, body: string}
     */
    private function send(string $endpoint, string $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $key = Env::get('PULSE_API_KEY');
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        $handle = curl_init($endpoint);
        if ($handle === false) {
            return ['connection_failed' => true, 'status' => 0, 'body' => ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => $this->timeout(),
            // The API key must never follow a redirect to a host the configured
            // endpoint did not name.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_BUFFERSIZE => 16384,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($resource, $downloadSize, $downloaded): int
                => $downloaded > self::MAX_RESPONSE_BYTES ? 1 : 0,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($handle);

        return [
            // No status at all means the exchange never got as far as an
            // answer: DNS, TCP, TLS or a timeout.
            'connection_failed' => $failed && $status === 0,
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }

    private function timeout(): int
    {
        if ($this->timeoutSeconds !== null) {
            return max(1, $this->timeoutSeconds);
        }

        return Features::int('PULSE_TIMEOUT_SECONDS', self::DEFAULT_TIMEOUT_SECONDS, 5, 300);
    }

    /**
     * Read the answer out of whatever the gateway wraps it in.
     *
     * Three shapes are accepted — the OpenAI `choices` array, this API's own
     * `{success, data}` envelope for a proxy that reuses it, and a bare
     * `{answer}` — because the endpoint is somebody's proxy as often as it is a
     * vendor. A response with no text at all is an upstream failure, not an
     * empty answer: returning "" would show the user a blank result as though
     * the model had nothing to say.
     */
    private static function parse(string $body, PromptBundle $bundle): AiResult
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::warn('pulse.unparseable_response', $bundle->describe());
            throw ApiException::upstream('pulse', 'Pulse returned something this server could not read.');
        }

        $payload = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;

        $text = '';
        $choice = $payload['choices'][0] ?? null;
        if (is_array($choice)) {
            $content = $choice['message']['content'] ?? $choice['text'] ?? '';
            $text = is_string($content) ? $content : '';
        }
        if ($text === '' && is_string($payload['answer'] ?? null)) {
            $text = (string) $payload['answer'];
        }
        if ($text === '' && is_string($payload['content'] ?? null)) {
            $text = (string) $payload['content'];
        }

        $text = trim($text);
        if ($text === '') {
            Logger::warn('pulse.empty_answer', $bundle->describe());
            throw ApiException::upstream('pulse', 'Pulse returned an empty answer.');
        }

        return new AiResult(
            $text,
            self::citedContext($payload['citations'] ?? null),
            is_string($payload['model'] ?? null) ? substr((string) $payload['model'], 0, 120) : null,
            is_array($payload['structured'] ?? null) ? $payload['structured'] : [],
        );
    }

    /**
     * A provider's own citation list, when it has one.
     *
     * Accepts `[1, 2]` and `[{"index": 1}]`; anything else is ignored rather
     * than guessed at, and the fallback is reading the `[n]` markers out of the
     * answer text.
     *
     * @return array<int, int>
     */
    private static function citedContext(mixed $citations): array
    {
        if (!is_array($citations)) {
            return [];
        }

        $indexes = [];
        foreach ($citations as $citation) {
            $index = is_array($citation) ? ($citation['index'] ?? $citation['block'] ?? null) : $citation;
            if (is_int($index) || (is_string($index) && ctype_digit($index))) {
                $number = (int) $index;
                if ($number > 0) {
                    $indexes[$number] = true;
                }
            }
        }

        $found = array_keys($indexes);
        sort($found);

        return $found;
    }
}
