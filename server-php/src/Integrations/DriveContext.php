<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\CompanyContext;

/**
 * Everything AICOUNTLY Drive needs to know about *whose* file this is.
 *
 * Drive stores objects for the whole suite, so every call has to say which
 * product, which tenant scope, which module and which business record the bytes
 * belong to. None of that is derivable inside {@see DriveObjectStore} — it is a
 * property of the note and of the request — so it is gathered once, here, and
 * handed to the store.
 *
 * It also carries the **caller's own ses_key**. Notes runs Drive's upload
 * sequence in Drive's "proxy" integration mode (§9 of Drive's
 * AICOUNTLY_DRIVE_STORAGE_ARCHITECTURE.md, the mode Books and HRMS use): the
 * bytes go through this backend, but the *authority* is the person who asked.
 * Drive re-checks them on every call. A background job therefore has no context
 * and cannot reach Drive at all, which is the honest answer rather than a shared
 * service token that would make every Notes user as powerful as the
 * integration.
 */
final class DriveContext
{
    // -----------------------------------------------------------------------
    // Identifiers Drive writes into object keys.
    //
    // ALL OF THESE ARE PERMANENT. Drive's §29 is explicit: a product_code or a
    // module_code, once written into an object key, can never be corrected —
    // nothing validates it on the way in, so an old value is simply a fact in
    // S3, and changing it would mean copying every object and rewriting every
    // row that references it. Add a new module here; never rename one.
    // -----------------------------------------------------------------------

    /**
     * Notes' product code, as registered in §10 of Drive's architecture doc
     * ("Notes | notes.aicountly.com | product_code: notes | Generic key shape").
     *
     * Not to be confused with Drive's own code, which is `docs` — the two are
     * kept apart by {@see SiblingApi}, and nothing here spells either by hand
     * except this one constant, which is Notes' own.
     */
    public const PRODUCT_CODE = 'notes';

    /** Drive's `entity_type`. The `entity_id` is the note's UUID. */
    public const ENTITY_TYPE = 'note';

    /** Anything a person attached to a note: a photo, a PDF, a spreadsheet. */
    public const MODULE_ATTACHMENTS = 'attachments';

    /** Audio captured on a voice note. */
    public const MODULE_VOICE_NOTES = 'voice-notes';

    /** A page or receipt captured with the camera on a scan note. */
    public const MODULE_SCANS = 'scans';

    /** Audio or video captured against a meeting note. */
    public const MODULE_MEETING_RECORDINGS = 'meeting-recordings';

    /** Generated previews. Derived from an object, never uploaded by a person. */
    public const MODULE_THUMBNAILS = 'thumbnails';

    /**
     * Drive's documented "no company" sentinel.
     *
     * `cmp_id`, `fy_id` and `bo_id` are required on every Drive call, and all
     * three set to literal `0` — never one or two of them — is how a caller says
     * "this is genuinely company-independent". Drive reserves it for personal
     * scope and refuses to combine it with `scope=company`, which is exactly the
     * shape of a personal note.
     */
    private const NO_COMPANY = '0';

    private function __construct(
        public readonly string $sesKey,
        public readonly string $noteId,
        public readonly string $moduleCode,
        public readonly string $scope,
        /** @var array<string, string> */
        private readonly array $company,
    ) {
    }

    /**
     * The context for one note's files.
     *
     * `$noteTenantId` is the **note's** tenant, not the caller's: a note that
     * was created as a company note stays one whoever opens it later, and the
     * scope decides the object key. Null means a personal note — Notes'
     * `tenant_id` is nullable precisely so that "mine" and "my company's" are
     * different things rather than one default.
     */
    public static function forNote(
        string $sesKey,
        string $noteId,
        ?string $noteTenantId,
        string $moduleCode = self::MODULE_ATTACHMENTS,
    ): self {
        if ($noteTenantId === null) {
            return new self($sesKey, $noteId, $moduleCode, 'personal', [
                'cmp_id' => self::NO_COMPANY,
                'fy_id' => self::NO_COMPANY,
                'bo_id' => self::NO_COMPANY,
            ]);
        }

        // A company-scope object key is built from cmp_id / fy_id / bo_id, and
        // Drive validates all three against the company the session may see.
        // Notes cannot supply them from its own `tenant_id`: that is the
        // portal's company **UUID**, while Drive keys on the numeric `cmp_id`,
        // and the two are not interchangeable (see CompanyContext). So they
        // come from what the caller sent, or the upload does not happen — a
        // guessed company would write the file into somebody else's tree.
        $company = CompanyContext::params();
        foreach (['cmp_id', 'fy_id', 'bo_id'] as $key) {
            if (($company[$key] ?? '') === '') {
                throw ApiException::badRequest(
                    'This note belongs to a company, so its files need the company context '
                    . '(cmp_id, fy_id and bo_id) on the request.',
                );
            }
        }

        return new self($sesKey, $noteId, $moduleCode, 'company', $company);
    }

    /**
     * The module a capture belongs in.
     *
     * Chosen from what Notes actually stores rather than from the MIME type
     * alone: the note's own type says whether this audio is a voice memo or the
     * recording of a meeting, and those are different things to look for in
     * Drive later. Anything that is not one of those captures is a plain
     * attachment.
     */
    public static function moduleFor(string $noteType, string $kind): string
    {
        $isRecording = $kind === 'audio' || $kind === 'video';

        return match (true) {
            $noteType === 'meeting' && $isRecording => self::MODULE_MEETING_RECORDINGS,
            $noteType === 'voice' && $isRecording => self::MODULE_VOICE_NOTES,
            $noteType === 'scan' && ($kind === 'image' || $kind === 'pdf') => self::MODULE_SCANS,
            default => self::MODULE_ATTACHMENTS,
        };
    }

    /** The same context, for a different module of the same note. */
    public function withModule(string $moduleCode): self
    {
        return new self($this->sesKey, $this->noteId, $moduleCode, $this->scope, $this->company);
    }

    /**
     * `cmp_id` / `fy_id` / `bo_id`, as Drive requires on every call.
     *
     * Returned explicitly rather than left to {@see CompanyContext}'s automatic
     * merge, because the personal-scope sentinel has to *override* whatever the
     * request carried: a personal note is not filed under the company the user
     * happened to have selected.
     *
     * @return array<string, string>
     */
    public function companyParams(): array
    {
        return $this->company;
    }
}
