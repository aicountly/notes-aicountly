<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;

/**
 * The one place this API talks to AICOUNTLY Drive.
 *
 * Drive owns files across the whole suite; Notes borrows them. Two jobs live
 * here, and nothing else in the codebase does either of them:
 *
 *   1. **Choosing where new bytes go.** {@see defaultStore()} answers Drive
 *      when Drive is configured and the local disk when it is not, so no caller
 *      has to branch on a feature flag to save a file.
 *   2. **Resolving a file the user already has in Drive.** `link-drive`
 *      attaches an existing Drive file by id *without copying its bytes*, which
 *      only works if Drive confirms that this caller may see that file. The
 *      caller's user and tenant go with the request for exactly that reason:
 *      a Drive file id must never be treated as a capability on its own.
 *
 * Every method that reaches the network requires {@see Features::DRIVE}, which
 * is off until both NOTES_DRIVE_ENABLED and DRIVE_API_URL are set. An
 * unconfigured deployment gets FEATURE_DISABLED, never a fabricated file.
 */
final class DriveAttachmentService
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const REQUEST_TIMEOUT_SECONDS = 30;

    /** Drive is a service, not a mirror: it may legitimately be slow, so a failure is retryable. */
    private const SERVICE = 'drive';

    // -----------------------------------------------------------------------
    // Store selection
    // -----------------------------------------------------------------------

    /** Where bytes uploaded right now should be written. */
    public static function defaultStore(): ObjectStore
    {
        return Features::enabled(Features::DRIVE) ? new DriveObjectStore() : new LocalObjectStore();
    }

    /**
     * The store an existing attachment lives in.
     *
     * Driven by the row's `storage_provider`, never by the current flag: a file
     * written to disk before Drive was switched on is still on that disk, and
     * asking Drive for it would 404 a file the user can see in their note.
     */
    public static function storeFor(string $provider): ObjectStore
    {
        return match ($provider) {
            'drive' => new DriveObjectStore(),
            default => new LocalObjectStore(),
        };
    }

    public static function base(): string
    {
        return rtrim(Env::get('DRIVE_API_URL'), '/');
    }

    // -----------------------------------------------------------------------
    // Files the user already has in Drive
    // -----------------------------------------------------------------------

    /**
     * Metadata for a Drive file this caller is allowed to see.
     *
     * @return array{drive_file_id: string, filename: string, mime_type: string, byte_size: int, checksum: ?string}
     */
    public function file(Identity $identity, string $driveFileId): array
    {
        Features::require(Features::DRIVE);

        $response = $this->request('GET', '/files/' . rawurlencode($driveFileId), $identity);

        if ($response['status'] === 404 || $response['status'] === 403) {
            // Drive decides. "Not yours" and "not there" are one answer here
            // for the same reason they are one answer for notes.
            throw ApiException::notFound('That Drive file');
        }
        if ($response['status'] !== 200) {
            throw ApiException::upstream(self::SERVICE, 'Drive could not be reached — please try again.');
        }

        $file = json_decode($response['body'], true);
        $file = is_array($file['data'] ?? null) ? $file['data'] : (is_array($file) ? $file : []);

        $name = (string) ($file['name'] ?? $file['filename'] ?? '');
        $mime = (string) ($file['mime_type'] ?? $file['mimeType'] ?? '');
        if ($name === '' || $mime === '') {
            throw ApiException::upstream(self::SERVICE, 'Drive returned a file this app cannot describe.');
        }

        return [
            'drive_file_id' => $driveFileId,
            'filename' => Str::limit($name, 400),
            'mime_type' => Str::limit(strtolower($mime), 160),
            'byte_size' => (int) ($file['size'] ?? $file['byte_size'] ?? 0),
            'checksum' => isset($file['sha256']) ? Str::limit((string) $file['sha256'], 64) : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Object storage, on Drive's account rather than a user's
    // -----------------------------------------------------------------------

    public function putObject(string $key, string $bytes, string $mimeType): void
    {
        Features::require(Features::DRIVE);

        $response = $this->request('PUT', '/objects/' . self::encodeKey($key), null, $bytes, $mimeType);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('drive.put_failed', ['status' => $response['status']]);
            throw ApiException::upstream(self::SERVICE, 'The file could not be saved to Drive.');
        }
    }

    public function getObject(string $key): string
    {
        Features::require(Features::DRIVE);

        $response = $this->request('GET', '/objects/' . self::encodeKey($key));
        if ($response['status'] === 404) {
            throw ApiException::notFound('That file');
        }
        if ($response['status'] !== 200) {
            throw ApiException::upstream(self::SERVICE, 'The file could not be read from Drive.');
        }

        return $response['body'];
    }

    public function deleteObject(string $key): bool
    {
        Features::require(Features::DRIVE);

        $response = $this->request('DELETE', '/objects/' . self::encodeKey($key));

        // 404 means the object is already gone, which is the state the caller
        // asked for; only a real failure is worth a retry.
        if ($response['status'] === 404) {
            return false;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw ApiException::upstream(self::SERVICE, 'The file could not be removed from Drive.');
        }

        return true;
    }

    public function objectExists(string $key): bool
    {
        Features::require(Features::DRIVE);

        return $this->request('HEAD', '/objects/' . self::encodeKey($key))['status'] === 200;
    }

    /** A short-lived direct URL, or null when Drive does not offer one. */
    public function objectSignedUrl(string $key, int $ttlSeconds): ?string
    {
        Features::require(Features::DRIVE);

        $response = $this->request(
            'POST',
            '/objects/' . self::encodeKey($key) . '/signed-url',
            null,
            (string) json_encode(['expires_in' => max(30, min(3600, $ttlSeconds))]),
            'application/json',
        );
        if ($response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $url = $decoded['data']['url'] ?? $decoded['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    // -----------------------------------------------------------------------

    /** Keys are `a/b/c`; the separators are path structure, the segments are not. */
    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * One call to Drive.
     *
     * @return array{status: int, body: string}
     */
    private function request(
        string $method,
        string $path,
        ?Identity $identity = null,
        ?string $body = null,
        string $contentType = 'application/octet-stream',
    ): array {
        $base = self::base();
        if ($base === '') {
            throw ApiException::featureDisabled(Features::DRIVE);
        }

        $headers = ['Accept: application/json'];

        $token = Env::get('DRIVE_API_TOKEN');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($identity !== null) {
            // Drive applies its own sharing rules to these, which is the whole
            // point: this API never decides who may read a Drive file.
            $headers[] = 'X-Aicountly-User: ' . $identity->userId;
            if ($identity->tenantId !== null) {
                $headers[] = 'X-Aicountly-Tenant: ' . $identity->tenantId;
            }
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init($base . $path);
        if ($ch === false) {
            throw ApiException::upstream(self::SERVICE, 'Drive could not be reached.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_HEADER => false,
            // A redirect from a storage service can point anywhere; following
            // it with the Authorization header attached would hand the service
            // token to whatever host it names.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($ch);

        if ($failed || $status === 0) {
            Logger::warn('drive.unreachable', ['method' => $method]);
            throw ApiException::upstream(self::SERVICE, 'Drive is unavailable — please try again.');
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
