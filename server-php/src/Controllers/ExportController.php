<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Export\NoteExportService;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for note export.
 *
 * Thin on purpose: which formats exist, how a note is rendered and how a title
 * becomes a safe filename all belong to {@see NoteExportService}.
 *
 * The one thing this class owns is the response, which is the other one in this
 * API that is not JSON. {@see Response} carries a JSON envelope and a header
 * list, so the body is written into an output buffer *before* returning:
 * nothing has been sent while the buffer is open, `Response::send()` therefore
 * still gets to set the headers, and PHP flushes the bytes after it.
 *
 * `Content-Disposition: attachment` is not optional. An HTML export is escaped,
 * but it is still a page built from one user's words, and serving it inline
 * would render it on this API's own origin.
 */
final class ExportController
{
    public function __construct(private readonly NoteExportService $exports = new NoteExportService())
    {
    }

    public function note(Request $request, Identity $identity): Response
    {
        $export = $this->exports->export(
            $identity,
            $request->uuidParam('id'),
            $request->queryString('format', 'md'),
        );

        ob_start();
        echo $export['content'];

        return (new Response(200, null))
            ->withHeader('Content-Type', $export['content_type'])
            ->withHeader('Content-Length', (string) ob_get_length())
            ->withHeader('Content-Disposition', self::disposition($export['filename']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * `Content-Disposition`, safe for any title a user chose.
     *
     * The filename is already stripped of separators and control characters by
     * the service; this adds the two header forms different browsers read — a
     * quoted ASCII fallback with everything unusual replaced, and the RFC 5987
     * form that carries the real name. Quotes, backslashes and semicolons go
     * from the fallback because a filename inside a header that can be broken
     * out of is a response-splitting bug.
     */
    private static function disposition(string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $filename);
        $ascii = str_replace(['\\', '"', ';'], '_', $ascii);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii === '' ? 'note' : $ascii,
            rawurlencode($filename),
        );
    }
}
