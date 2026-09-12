<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Integrations\DriveAttachmentService;

/**
 * Delete the bytes of a detached attachment.
 *
 * Deleting an attachment is a soft delete — the row stays so the note's history
 * and activity trail still make sense — but the file itself has no reason to
 * survive, and a store that only ever grows is a storage bill and a privacy
 * problem at the same time.
 *
 * The job carries its keys in the payload and references neither the note nor
 * the attachment. That is deliberate: `note_processing_jobs.attachment_id`
 * cascades, so a job that pointed at the row it was cleaning up after would be
 * deleted along with it the moment the note was emptied from Trash — taking the
 * only record of which object to remove with it.
 */
final class ObjectPurgeHandler implements JobHandler
{
    public function handle(array $job): array
    {
        $payload = $job['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $keys = array_values(array_filter(
            (array) ($payload['keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
        if ($keys === []) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'no_keys'];
        }

        $store = DriveAttachmentService::storeFor((string) ($payload['storage_provider'] ?? 'local'));

        $removed = 0;
        foreach ($keys as $key) {
            // An object that is already gone is the state this job wanted, so
            // a second run is a no-op rather than a failure.
            $removed += $store->delete($key) ? 1 : 0;
        }

        // Nothing should be able to reach a key whose object no longer exists,
        // so the row stops naming one.
        $attachmentId = $payload['attachment_id'] ?? null;
        if (is_string($attachmentId)) {
            Connection::execute(
                'UPDATE note_attachments
                    SET storage_key = \'\', thumbnail_key = NULL, updated_at = now()
                  WHERE id = :id AND deleted_at IS NOT NULL',
                ['id' => $attachmentId],
            );
        }

        return ['outcome' => self::COMPLETED, 'objects_removed' => $removed, 'keys' => count($keys)];
    }
}
