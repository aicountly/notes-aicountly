<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Collaboration\PresenceService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for "who else is here", polled while a note is open.
 *
 * See {@see PresenceService} for why this is polling rather than a pushed
 * stream, and for what it does and does not promise about live collaboration.
 */
final class PresenceController
{
    public function __construct(private readonly PresenceService $presence = new PresenceService())
    {
    }

    public function sync(Request $request, Identity $identity): Response
    {
        $result = $this->presence->sync($identity, $request->uuidParam('id'));

        return Response::ok($result['viewers'], [
            'version' => $result['version'],
            'updated_at' => $result['updated_at'],
        ]);
    }

    public function leave(Request $request, Identity $identity): Response
    {
        $this->presence->leave($identity, $request->uuidParam('id'));

        return Response::noContent();
    }
}
