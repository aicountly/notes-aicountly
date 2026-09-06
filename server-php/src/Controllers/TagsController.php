<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Tags\TagService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Support\Uuid;

/**
 * HTTP for tags.
 *
 * Thin on purpose. The rules that make a tag list trustworthy — folding "GST",
 * "gst" and "#GST" to one slug, treating a rename onto an existing slug as a
 * merge, counting only notes the caller can actually open — all live in
 * {@see TagService}, so this class only decides what the request asked for.
 */
final class TagsController
{
    public function __construct(private readonly TagService $tags = new TagService())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        unset($request);

        return Response::ok($this->tags->listForUser($identity));
    }

    public function store(Request $request, Identity $identity): Response
    {
        return Response::created($this->tags->create($identity, $request->string('name'), self::color($request)));
    }

    /**
     * Rename, recolour, or both.
     *
     * Each field is optional and independent: the colour picker sends only a
     * colour, and a rename must not wipe the colour a tag already has. Sending
     * `color: null` is how a colour is cleared — omitting it cannot be, or
     * every rename would strip it.
     */
    public function update(Request $request, Identity $identity): Response
    {
        return Response::ok($this->tags->update(
            $identity,
            $request->uuidParam('id'),
            $request->has('name') ? $request->string('name') : null,
            self::color($request),
        ));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        $this->tags->delete($identity, $request->uuidParam('id'));

        return Response::noContent();
    }

    /** Fold several tags into one: `{"source_ids": [...], "target_id": "…"}`. */
    public function merge(Request $request, Identity $identity): Response
    {
        $targetId = $request->string('target_id');
        if (!Uuid::isValid($targetId)) {
            // Rejecting the shape here keeps a malformed id out of a query
            // Postgres would refuse with a 500, and answers it the way an
            // unknown tag is answered — the id tells the caller nothing.
            throw ApiException::notFound('That tag');
        }

        $sourceIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $request->array('source_ids')),
            static fn (string $id): bool => Uuid::isValid($id),
        ));
        if ($sourceIds === []) {
            throw ApiException::validation(['source_ids' => 'Choose at least one tag to merge.']);
        }

        return Response::ok($this->tags->merge($identity, $sourceIds, $targetId));
    }

    /** Absent leaves the colour alone; `null` or `""` clears it. */
    private static function color(Request $request): ?string
    {
        return $request->has('color') ? $request->string('color') : null;
    }
}
