<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Activity\ActivityQuery;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for a note's activity trail.
 *
 * Offset paging rather than a cursor: the trail is read newest-first by a
 * person scrolling a short panel, not streamed, and an offset survives the row
 * that {@see \Aicountly\Api\Domain\Activity\ActivityRecorder} keeps re-dating
 * as an editing session continues.
 */
final class ActivityController
{
    public function __construct(private readonly ActivityQuery $activity = new ActivityQuery())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        $limit = $request->queryInt('limit', 50, 1, 200);
        $offset = $request->queryInt('offset', 0, 0, 100000);

        $page = $this->activity->forNote($identity, $request->uuidParam('id'), $limit, $offset);

        return Response::ok($page['entries'], [
            'total' => $page['total'],
            'has_more' => $offset + count($page['entries']) < $page['total'],
        ]);
    }
}
