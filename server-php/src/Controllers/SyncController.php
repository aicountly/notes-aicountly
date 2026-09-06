<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Sync\SyncService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for the offline queue.
 *
 * Thin on purpose: parse, delegate, present. Idempotency, conflict handling and
 * every permission check live in {@see SyncService} — a controller that decided
 * any of it would be a second copy of the rules the online endpoints already
 * go through.
 *
 * `POST /sync/push` is the one endpoint in this API that answers **200 with a
 * mixed result**. A batch is not a transaction: one conflicted note must not
 * stop the other nine from saving, so the HTTP status describes the request
 * being understood and each operation carries its own status. A client reads
 * `data[]`, not the code.
 */
final class SyncController
{
    public function __construct(private readonly SyncService $sync = new SyncService())
    {
    }

    public function push(Request $request, Identity $identity): Response
    {
        $result = $this->sync->push($identity, $request->array('operations'));

        return Response::ok($result['results'], [
            'applied' => $result['applied'],
            'conflicts' => $result['conflicts'],
            'rejected' => $result['rejected'],
        ]);
    }

    /**
     * What changed since the client last synced.
     *
     * `since` takes the cursor from the previous pull, or a plain ISO timestamp
     * for a client that has only ever kept a clock. Sending neither is a full
     * sync.
     */
    public function pull(Request $request, Identity $identity): Response
    {
        $result = $this->sync->pull(
            $identity,
            $request->queryString('since'),
            $request->queryInt('limit', SyncService::PULL_DEFAULT, 1, SyncService::PULL_MAX),
        );

        return Response::ok([
            'notes' => $result['notes'],
            // Ids only: a client needs to drop these from its cache, and the
            // contents of a note someone deleted are not part of that.
            'deleted_note_ids' => $result['deleted_note_ids'],
        ], [
            'cursor' => $result['cursor'],
            'has_more' => $result['has_more'],
        ]);
    }
}
