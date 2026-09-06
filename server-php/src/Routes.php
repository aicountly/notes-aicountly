<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Controllers\NotesController;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Http\Router;

/**
 * The API surface, in one readable list.
 *
 * Handlers are closures so a controller is only constructed when its route
 * matches — with no dependency container, that is what keeps a request for
 * `/notes` from instantiating the AI stack.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        self::notes($router);
        self::notebooks($router);
        self::tags($router);
        self::search($router);
        self::attachments($router);
        self::collaboration($router);
        self::organisation($router);
        self::reminders($router);
        self::pulse($router);
        self::meetings($router);
        self::sync($router);
    }

    /**
     * Offline sync and export.
     *
     * `/sync/push` is the only endpoint that takes a *batch* of mutations, and
     * the only one that answers per-item rather than all-or-nothing: one
     * conflicted note must not stop the other nine from being saved.
     */
    private static function sync(Router $router): void
    {
        $sync = static fn (): Controllers\SyncController => new Controllers\SyncController();
        $router->post('/sync/push', static fn (Request $r, Identity $i) => $sync()->push($r, $i));
        $router->get('/sync/pull', static fn (Request $r, Identity $i) => $sync()->pull($r, $i));

        $export = static fn (): Controllers\ExportController => new Controllers\ExportController();
        $router->get('/notes/{id}/export', static fn (Request $r, Identity $i) => $export()->note($r, $i));
    }

    private static function notes(Router $router): void
    {
        $notes = static fn (): NotesController => new NotesController();

        $router->get('/notes', static fn (Request $r, Identity $i) => $notes()->index($r, $i));
        $router->get('/notes/counts', static fn (Request $r, Identity $i) => $notes()->counts($r, $i));
        $router->post('/notes', static fn (Request $r, Identity $i) => $notes()->store($r, $i));
        $router->post('/capture', static fn (Request $r, Identity $i) => $notes()->capture($r, $i));

        $router->get('/notes/{id}', static fn (Request $r, Identity $i) => $notes()->show($r, $i));
        $router->patch('/notes/{id}', static fn (Request $r, Identity $i) => $notes()->update($r, $i));
        $router->delete('/notes/{id}', static fn (Request $r, Identity $i) => $notes()->destroy($r, $i));

        $router->post('/notes/{id}/restore', static fn (Request $r, Identity $i) => $notes()->restore($r, $i));
        $router->post('/notes/{id}/duplicate', static fn (Request $r, Identity $i) => $notes()->duplicate($r, $i));

        // The shortcuts a UI actually calls. All of them route through the same
        // update path, so the rules cannot drift from PATCH /notes/{id}.
        $flags = [
            'pin' => ['is_pinned', true],
            'unpin' => ['is_pinned', false],
            'favourite' => ['is_favourite', true],
            'unfavourite' => ['is_favourite', false],
            'archive' => ['is_archived', true],
            'unarchive' => ['is_archived', false],
        ];
        foreach ($flags as $action => [$field, $value]) {
            $router->post(
                '/notes/{id}/' . $action,
                static fn (Request $r, Identity $i) => $notes()->setFlag($r, $i, $field, $value),
            );
        }

        $router->get('/notes/{id}/versions', static fn (Request $r, Identity $i) => $notes()->versions($r, $i));
        $router->get('/notes/{id}/versions/{versionId}', static fn (Request $r, Identity $i) => $notes()->showVersion($r, $i));
        $router->post('/notes/{id}/versions/{versionId}/restore', static fn (Request $r, Identity $i) => $notes()->restoreVersion($r, $i));

        $router->get('/notes/{id}/links', static fn (Request $r, Identity $i) => $notes()->backlinks($r, $i));

        $actions = static fn (): Controllers\ActionsController => new Controllers\ActionsController();
        $router->get('/notes/{id}/actions', static fn (Request $r, Identity $i) => $actions()->index($r, $i));
        $router->post('/notes/{id}/actions', static fn (Request $r, Identity $i) => $actions()->store($r, $i));
        $router->patch('/actions/{actionId}', static fn (Request $r, Identity $i) => $actions()->update($r, $i));
        $router->delete('/actions/{actionId}', static fn (Request $r, Identity $i) => $actions()->destroy($r, $i));
        $router->get('/actions', static fn (Request $r, Identity $i) => $actions()->open($r, $i));

        $router->get('/notes/{id}/activity', static fn (Request $r, Identity $i) => (new Controllers\ActivityController())->index($r, $i));
    }

    private static function notebooks(Router $router): void
    {
        $c = static fn (): Controllers\NotebooksController => new Controllers\NotebooksController();

        $router->get('/notebooks', static fn (Request $r, Identity $i) => $c()->index($r, $i));
        $router->post('/notebooks', static fn (Request $r, Identity $i) => $c()->store($r, $i));
        $router->get('/notebooks/{id}', static fn (Request $r, Identity $i) => $c()->show($r, $i));
        $router->patch('/notebooks/{id}', static fn (Request $r, Identity $i) => $c()->update($r, $i));
        $router->delete('/notebooks/{id}', static fn (Request $r, Identity $i) => $c()->destroy($r, $i));
        $router->post('/notebooks/{id}/move', static fn (Request $r, Identity $i) => $c()->move($r, $i));
        $router->get('/notebooks/{id}/members', static fn (Request $r, Identity $i) => $c()->members($r, $i));
        $router->post('/notebooks/{id}/members', static fn (Request $r, Identity $i) => $c()->addMember($r, $i));
        $router->delete('/notebooks/{id}/members/{userId}', static fn (Request $r, Identity $i) => $c()->removeMember($r, $i));
    }

    private static function tags(Router $router): void
    {
        $c = static fn (): Controllers\TagsController => new Controllers\TagsController();

        $router->get('/tags', static fn (Request $r, Identity $i) => $c()->index($r, $i));
        $router->post('/tags', static fn (Request $r, Identity $i) => $c()->store($r, $i));
        $router->patch('/tags/{id}', static fn (Request $r, Identity $i) => $c()->update($r, $i));
        $router->delete('/tags/{id}', static fn (Request $r, Identity $i) => $c()->destroy($r, $i));
        $router->post('/tags/merge', static fn (Request $r, Identity $i) => $c()->merge($r, $i));
    }

    private static function search(Router $router): void
    {
        $c = static fn (): Controllers\SearchController => new Controllers\SearchController();

        $router->get('/search/notes', static fn (Request $r, Identity $i) => $c()->notes($r, $i));
        $router->get('/search/suggest', static fn (Request $r, Identity $i) => $c()->suggest($r, $i));
        $router->post('/search/semantic', static fn (Request $r, Identity $i) => $c()->semantic($r, $i));
    }

    private static function attachments(Router $router): void
    {
        $c = static fn (): Controllers\AttachmentsController => new Controllers\AttachmentsController();

        $router->get('/notes/{id}/attachments', static fn (Request $r, Identity $i) => $c()->index($r, $i));
        $router->post('/notes/{id}/attachments', static fn (Request $r, Identity $i) => $c()->store($r, $i));
        $router->post('/notes/{id}/attachments/link-drive', static fn (Request $r, Identity $i) => $c()->linkDrive($r, $i));
        $router->get('/notes/{id}/attachments/{attachmentId}', static fn (Request $r, Identity $i) => $c()->show($r, $i));
        $router->get('/notes/{id}/attachments/{attachmentId}/content', static fn (Request $r, Identity $i) => $c()->download($r, $i));
        $router->delete('/notes/{id}/attachments/{attachmentId}', static fn (Request $r, Identity $i) => $c()->destroy($r, $i));
    }

    private static function collaboration(Router $router): void
    {
        $members = static fn (): Controllers\MembersController => new Controllers\MembersController();
        $router->get('/notes/{id}/members', static fn (Request $r, Identity $i) => $members()->index($r, $i));
        $router->post('/notes/{id}/members', static fn (Request $r, Identity $i) => $members()->store($r, $i));
        $router->patch('/notes/{id}/members/{userId}', static fn (Request $r, Identity $i) => $members()->update($r, $i));
        $router->delete('/notes/{id}/members/{userId}', static fn (Request $r, Identity $i) => $members()->destroy($r, $i));

        $comments = static fn (): Controllers\CommentsController => new Controllers\CommentsController();
        $router->get('/notes/{id}/comments', static fn (Request $r, Identity $i) => $comments()->index($r, $i));
        $router->post('/notes/{id}/comments', static fn (Request $r, Identity $i) => $comments()->store($r, $i));
        $router->patch('/comments/{commentId}', static fn (Request $r, Identity $i) => $comments()->update($r, $i));
        $router->post('/comments/{commentId}/resolve', static fn (Request $r, Identity $i) => $comments()->resolve($r, $i));
        $router->post('/comments/{commentId}/reopen', static fn (Request $r, Identity $i) => $comments()->reopen($r, $i));
        $router->delete('/comments/{commentId}', static fn (Request $r, Identity $i) => $comments()->destroy($r, $i));
    }

    private static function organisation(Router $router): void
    {
        $templates = static fn (): Controllers\TemplatesController => new Controllers\TemplatesController();
        $router->get('/templates', static fn (Request $r, Identity $i) => $templates()->index($r, $i));
        $router->post('/templates', static fn (Request $r, Identity $i) => $templates()->store($r, $i));
        $router->get('/templates/{id}', static fn (Request $r, Identity $i) => $templates()->show($r, $i));
        $router->patch('/templates/{id}', static fn (Request $r, Identity $i) => $templates()->update($r, $i));
        $router->delete('/templates/{id}', static fn (Request $r, Identity $i) => $templates()->destroy($r, $i));
        $router->post('/templates/{id}/create-note', static fn (Request $r, Identity $i) => $templates()->createNote($r, $i));

        $smart = static fn (): Controllers\SmartFoldersController => new Controllers\SmartFoldersController();
        $router->get('/smart-folders', static fn (Request $r, Identity $i) => $smart()->index($r, $i));
        $router->post('/smart-folders', static fn (Request $r, Identity $i) => $smart()->store($r, $i));
        $router->patch('/smart-folders/{id}', static fn (Request $r, Identity $i) => $smart()->update($r, $i));
        $router->delete('/smart-folders/{id}', static fn (Request $r, Identity $i) => $smart()->destroy($r, $i));
        $router->get('/smart-folders/{id}/notes', static fn (Request $r, Identity $i) => $smart()->notes($r, $i));
    }

    private static function reminders(Router $router): void
    {
        $c = static fn (): Controllers\RemindersController => new Controllers\RemindersController();

        $router->get('/reminders', static fn (Request $r, Identity $i) => $c()->index($r, $i));
        $router->post('/notes/{id}/reminders', static fn (Request $r, Identity $i) => $c()->store($r, $i));
        $router->patch('/reminders/{reminderId}', static fn (Request $r, Identity $i) => $c()->update($r, $i));
        $router->delete('/reminders/{reminderId}', static fn (Request $r, Identity $i) => $c()->destroy($r, $i));
        $router->post('/reminders/{reminderId}/snooze', static fn (Request $r, Identity $i) => $c()->snooze($r, $i));
        $router->post('/reminders/{reminderId}/complete', static fn (Request $r, Identity $i) => $c()->complete($r, $i));
    }

    private static function pulse(Router $router): void
    {
        $c = static fn (): Controllers\PulseController => new Controllers\PulseController();

        $router->post('/pulse/selection', static fn (Request $r, Identity $i) => $c()->selection($r, $i));
        $router->post('/pulse/note/{id}/ask', static fn (Request $r, Identity $i) => $c()->askNote($r, $i));
        $router->post('/pulse/note/{id}/summarize', static fn (Request $r, Identity $i) => $c()->summarizeNote($r, $i));
        $router->post('/pulse/note/{id}/extract-actions', static fn (Request $r, Identity $i) => $c()->extractActions($r, $i));
        $router->post('/pulse/note/{id}/meeting-summary', static fn (Request $r, Identity $i) => $c()->meetingSummary($r, $i));
        $router->post('/pulse/notebook/{id}/ask', static fn (Request $r, Identity $i) => $c()->askNotebook($r, $i));
        $router->post('/pulse/notes/ask', static fn (Request $r, Identity $i) => $c()->askNotes($r, $i));
        $router->get('/pulse/actions', static fn (Request $r, Identity $i) => $c()->catalogue($r, $i));
    }

    private static function meetings(Router $router): void
    {
        $c = static fn (): Controllers\MeetingsController => new Controllers\MeetingsController();

        $router->get('/notes/{id}/meeting', static fn (Request $r, Identity $i) => $c()->show($r, $i));
        $router->patch('/notes/{id}/meeting', static fn (Request $r, Identity $i) => $c()->update($r, $i));
        $router->get('/notes/{id}/transcripts', static fn (Request $r, Identity $i) => $c()->transcripts($r, $i));
        $router->patch('/transcripts/{transcriptId}', static fn (Request $r, Identity $i) => $c()->updateTranscript($r, $i));

        $entities = static fn (): Controllers\EntityLinksController => new Controllers\EntityLinksController();
        $router->get('/notes/{id}/entities', static fn (Request $r, Identity $i) => $entities()->index($r, $i));
        $router->post('/notes/{id}/entities', static fn (Request $r, Identity $i) => $entities()->store($r, $i));
        $router->delete('/notes/{id}/entities/{linkId}', static fn (Request $r, Identity $i) => $entities()->destroy($r, $i));
    }

    /** Unauthenticated: what this deployment can do, so the UI can adapt before sign-in. */
    public static function publicConfig(): Response
    {
        return Response::ok([
            'app' => 'Notes',
            'env' => Env::get('APP_ENV', 'unknown'),
            'features' => Features::all(),
            'limits' => [
                'max_attachment_bytes' => Features::int('NOTES_MAX_ATTACHMENT_SIZE', 26214400, 1024, 1073741824),
                'trash_retention_days' => Features::int('NOTES_TRASH_RETENTION_DAYS', 30, 1, 3650),
            ],
        ]);
    }
}
