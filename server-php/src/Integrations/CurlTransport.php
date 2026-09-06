<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

/**
 * The real {@see HttpTransport}: cURL, with the safety options this API insists
 * on everywhere it leaves the process.
 *
 *   - **No redirect following.** A redirect can name any host, and following one
 *     with the request's headers attached is how a session key reaches a server
 *     nobody chose to trust.
 *   - **A bounded response.** A runaway answer is a fault, not a payload, so the
 *     progress callback aborts the transfer rather than filling memory.
 *   - **Separate connect and total timeouts**, so a slow upload is not confused
 *     with a host that is not answering at all.
 */
final class CurlTransport implements HttpTransport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['connection_failed' => true, 'status' => 0, 'body' => ''];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($resource, $downloadSize, $downloaded): int
                => $downloaded > $maxResponseBytes ? 1 : 0,
        ];
        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

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
}
