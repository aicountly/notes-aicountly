<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Reminders;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Notes\NotePresenter;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Reminders, which belong to a person rather than to a note.
 *
 * Two collaborators on the same note each keep their own: one wants nudging on
 * Friday morning, the other the night before, and neither may see or change the
 * other's. A note is the *subject* of a reminder, never its owner, so every
 * lookup here is keyed on `(id, user_id)` and a reminder someone else set is a
 * 404 — indistinguishable from one that never existed.
 *
 * Access is decided in two independent steps, both required:
 *
 *   1. **The row is yours.** `user_id` must be the caller, and the row's tenant
 *      must match the company they are acting in.
 *   2. **The note is still readable.** Every operation but deletion re-checks
 *      the note through {@see NotePermissionService}, so a reminder set while a
 *      note was shared stops being reachable when the share is withdrawn.
 *
 * Deletion is the deliberate exception. Losing access to a note must not strand
 * a row in the caller's own dispatch queue with no way to remove it, and
 * deleting your own reminder tells you nothing about a note you can no longer
 * open.
 *
 * Setting one needs only VIEW: being reminded about a note is reading it on a
 * schedule, not editing it, and a viewer who cannot set a reminder simply keeps
 * one in another app instead.
 */
final class ReminderService
{
    /**
     * The note columns the permission check needs.
     *
     * `privacy_mode` travels with the id because requireNote refuses a private
     * note to anyone but its owner from that column — selecting the id alone
     * would quietly skip that guard.
     */
    private const NOTE_COLUMNS = 'n.id, n.privacy_mode';

    /** Statuses that still have a firing ahead of them. */
    private const OPEN_STATUSES = ['scheduled', 'snoozed'];

    /**
     * Named views over the caller's list, as SQL that never sees user text.
     *
     * The caller picks a key; the clause is ours. That is the same reason
     * {@see \Aicountly\Api\Domain\Notes\NoteQuery} exists — a filter is chosen
     * from a fixed set, never assembled from a request.
     */
    private const STATUS_FILTERS = [
        'open' => "r.status IN ('scheduled','snoozed')",
        'overdue' => "r.status IN ('scheduled','snoozed') AND coalesce(r.snoozed_until, r.due_at) <= now()",
        'upcoming' => "r.status IN ('scheduled','snoozed') AND coalesce(r.snoozed_until, r.due_at) > now()",
        'completed' => "r.status = 'completed'",
        'cancelled' => "r.status = 'cancelled'",
        'all' => 'TRUE',
    ];

    /** One person's reminders on one note. A bound list, not a scheduler. */
    private const MAX_PER_NOTE = 25;

    /** Thirty days. Beyond that a snooze is really a new due date. */
    private const MAX_SNOOZE_MINUTES = 43200;

    private const DEFAULT_TIMEZONE = 'UTC';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * The caller's own reminders, across every note they can still see.
     *
     * Ordered by the time the user will actually be interrupted —
     * `coalesce(snoozed_until, due_at)` — so a reminder pushed to Thursday
     * sorts under Thursday and not under the Monday it was first set for.
     * Overdue items come first for the same reason: they are simply the ones
     * whose effective time has already passed.
     *
     * @param array<string, mixed> $options `status` and `limit`.
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(Identity $identity, array $options = []): array
    {
        $status = (string) ($options['status'] ?? 'open');
        if (!array_key_exists($status, self::STATUS_FILTERS)) {
            throw ApiException::badRequest(sprintf(
                '`status` must be one of %s.',
                implode(', ', array_keys(self::STATUS_FILTERS)),
            ));
        }

        $rows = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . '
             SELECT r.*, n.title AS note_title,
                    -- Only enough of the body to derive a display title for an
                    -- untitled note; a reminders list is not a place to ship
                    -- note content.
                    left(n.extracted_text, 200) AS note_text,
                    n.note_type AS note_type,
                    coalesce(r.snoozed_until, r.due_at) AS due_effective_at
             FROM note_reminders r
             JOIN notes n ON n.id = r.note_id AND n.deleted_at IS NULL
             JOIN note_access a ON a.note_id = n.id
             WHERE r.deleted_at IS NULL AND r.user_id = :auth_user
               AND ' . self::STATUS_FILTERS[$status] . '
             ORDER BY coalesce(r.snoozed_until, r.due_at), r.created_at
             LIMIT :limit',
            [
                'auth_user' => $identity->userId,
                'auth_tenant' => $identity->tenantId,
                'limit' => max(1, min(200, (int) ($options['limit'] ?? 100))),
            ],
        );

        return array_map(fn (array $row): array => $this->present($row, withNote: true), $rows);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Identity $identity, string $noteId, array $input): array
    {
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: self::NOTE_COLUMNS);

        $dueAt = $this->timestamp($input['due_at'] ?? null, 'due_at');
        $timezone = $this->timezone($input['timezone'] ?? null);
        $rule = $this->recurrence($input['recurrence_rule'] ?? null, $dueAt, $timezone);

        $existing = Connection::selectOne(
            'SELECT count(*) AS total FROM note_reminders
             WHERE note_id = :note_id AND user_id = :user AND deleted_at IS NULL',
            ['note_id' => $noteId, 'user' => $identity->userId],
        );
        if ((int) ($existing['total'] ?? 0) >= self::MAX_PER_NOTE) {
            throw ApiException::validation([
                'note_id' => sprintf('You already have %d reminders on this note.', self::MAX_PER_NOTE),
            ]);
        }

        $id = Uuid::v4();
        Connection::execute(
            'INSERT INTO note_reminders
                (id, note_id, action_id, tenant_id, user_id, reminder_type, due_at, timezone,
                 recurrence_rule, status, created_by)
             VALUES
                (:id, :note_id, :action_id, :tenant_id, :user, :type, :due_at::timestamptz, :timezone,
                 :rule, \'scheduled\', :actor)',
            [
                'id' => $id,
                'note_id' => $noteId,
                'action_id' => $this->actionId($noteId, $input['action_id'] ?? null),
                'tenant_id' => $identity->tenantId,
                'user' => $identity->userId,
                'type' => $rule === null ? 'datetime' : 'recurring',
                'due_at' => $dueAt->format(\DateTimeInterface::RFC3339),
                'timezone' => $timezone->getName(),
                'rule' => $rule?->toString(),
                'actor' => $identity->userId,
            ],
        );

        // Context carries ids and shape, never the note or the reminder text —
        // this trail is visible to every collaborator on the note.
        $this->activity->record($identity, ActivityRecorder::REMINDER_SET, $noteId, null, [
            'reminder_id' => $id,
            'recurring' => $rule !== null,
        ]);

        return $this->present($this->reload($id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Identity $identity, string $reminderId, array $input): array
    {
        $row = $this->requireOwnReminder($identity, $reminderId);

        $updates = [];
        $bindings = ['id' => $reminderId, 'user' => $identity->userId];
        $timingChanged = false;

        $dueAt = self::instant($row['due_at']);
        if (array_key_exists('due_at', $input)) {
            $dueAt = $this->timestamp($input['due_at'], 'due_at');
            $updates[] = 'due_at = :due_at::timestamptz';
            $bindings['due_at'] = $dueAt->format(\DateTimeInterface::RFC3339);
            $timingChanged = true;
        }

        // The zone governs where the *next* occurrence falls, not when this one
        // does: `due_at` is an instant and stays exactly where it is. Changing
        // "Europe/London" to "Asia/Kolkata" therefore re-homes the series
        // without moving the reminder the user can already see.
        $timezone = $this->zone((string) $row['timezone']);
        if (array_key_exists('timezone', $input)) {
            $timezone = $this->timezone($input['timezone']);
            $updates[] = 'timezone = :timezone';
            $bindings['timezone'] = $timezone->getName();
            $timingChanged = true;
        }

        // The rule is re-resolved whenever the anchor moves as well as when the
        // rule itself changes: a `COUNT` was turned into an absolute end date
        // against the old start (see RecurrenceRule::withCountResolved), and
        // leaving it there would end the series in the wrong place.
        $storedRule = $row['recurrence_rule'] === null ? null : (string) $row['recurrence_rule'];
        $ruleInput = array_key_exists('recurrence_rule', $input) ? $input['recurrence_rule'] : $storedRule;
        if (array_key_exists('recurrence_rule', $input) || ($storedRule !== null && $timingChanged)) {
            $rule = $this->recurrence($ruleInput, $dueAt, $timezone);
            $updates[] = 'recurrence_rule = :rule';
            $updates[] = 'reminder_type = :type';
            $bindings['rule'] = $rule?->toString();
            $bindings['type'] = $rule === null ? 'datetime' : 'recurring';
            $timingChanged = true;
        }

        if (array_key_exists('action_id', $input)) {
            $updates[] = 'action_id = :action_id';
            $bindings['action_id'] = $this->actionId((string) $row['note_id'], $input['action_id']);
        }

        if (array_key_exists('status', $input)) {
            $status = is_scalar($input['status']) ? strtolower(trim((string) $input['status'])) : '';
            if (!in_array($status, ['scheduled', 'cancelled'], true)) {
                throw ApiException::validation([
                    'status' => 'A reminder can be set back to scheduled or cancelled here; use /complete to finish one.',
                ]);
            }
            // Every mention of :status is cast, so Postgres infers one type for
            // the placeholder instead of refusing with "inconsistent types
            // deduced for parameter".
            $updates[] = 'status = :status::text';
            $updates[] = 'completed_at = NULL';
            $updates[] = 'snoozed_until = CASE WHEN :status::text = \'scheduled\' THEN NULL ELSE snoozed_until END';
            $bindings['status'] = $status;
            $timingChanged = $timingChanged || $status === 'scheduled';
        }

        if ($updates === []) {
            return $this->present($row);
        }

        // Rescheduling invalidates the fact that this already fired: a reminder
        // moved to next week has to ring again next week. A change that does
        // not move it — relinking an action — must not re-ring it.
        if ($timingChanged) {
            $updates[] = 'notified_at = NULL';
        }
        $updates[] = 'updated_at = now()';

        Connection::execute(
            'UPDATE note_reminders SET ' . implode(', ', $updates) . '
             WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
            $bindings,
        );

        return $this->present($this->reload($reminderId));
    }

    public function delete(Identity $identity, string $reminderId): void
    {
        $row = $this->requireOwnReminder($identity, $reminderId, requireNoteAccess: false);

        Connection::execute(
            'UPDATE note_reminders SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
            ['id' => $reminderId, 'user' => $identity->userId],
        );

        $this->activity->record($identity, ActivityRecorder::REMINDER_CLEARED, (string) $row['note_id'], null, [
            'reminder_id' => $reminderId,
        ]);
    }

    /**
     * Push a reminder out, by a number of minutes or to a given moment.
     *
     * `snoozed_until` is written rather than `due_at` so the original intent
     * survives: a weekly reminder snoozed by an hour on Monday still belongs to
     * Monday's series, and the next occurrence is computed from the day the
     * user chose, not from the moment they were busy.
     *
     * @param array<string, mixed> $input `minutes` or `until`.
     * @return array<string, mixed>
     */
    public function snooze(Identity $identity, string $reminderId, array $input): array
    {
        $row = $this->requireOwnReminder($identity, $reminderId);
        $this->requireOpen($row, 'snoozed');

        $now = Clock::now();
        $hasMinutes = array_key_exists('minutes', $input) && $input['minutes'] !== null && $input['minutes'] !== '';
        $hasUntil = array_key_exists('until', $input) && $input['until'] !== null && $input['until'] !== '';

        if ($hasMinutes === $hasUntil) {
            throw ApiException::validation([
                'minutes' => 'Send either `minutes` or `until` — one of the two, not both.',
            ]);
        }

        if ($hasMinutes) {
            $minutes = is_numeric($input['minutes']) ? (int) $input['minutes'] : 0;
            if ($minutes < 1 || $minutes > self::MAX_SNOOZE_MINUTES) {
                throw ApiException::validation([
                    'minutes' => sprintf('Snooze by 1 to %d minutes.', self::MAX_SNOOZE_MINUTES),
                ]);
            }
            $until = $now->add(new \DateInterval('PT' . $minutes . 'M'));
        } else {
            $until = $this->timestamp($input['until'], 'until');
            if ($until <= $now) {
                throw ApiException::validation(['until' => 'Snooze to a moment in the future.']);
            }
        }

        Connection::execute(
            'UPDATE note_reminders
             SET snoozed_until = :until::timestamptz, status = \'snoozed\', notified_at = NULL, updated_at = now()
             WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
            [
                'until' => $until->format(\DateTimeInterface::RFC3339),
                'id' => $reminderId,
                'user' => $identity->userId,
            ],
        );

        return $this->present($this->reload($reminderId));
    }

    /**
     * Tick a reminder off.
     *
     * A one-off is finished. A recurring one **advances**: ending the series
     * because someone dealt with today's instance is the bug that makes people
     * stop trusting a repeating reminder, so the row moves to its next
     * occurrence and stays scheduled until the rule itself runs out.
     *
     * The next occurrence is the first one after *now*, not after the due date.
     * Completing a daily reminder a week late otherwise leaves it still overdue
     * and needing six more taps to catch up.
     *
     * @return array<string, mixed>
     */
    public function complete(Identity $identity, string $reminderId): array
    {
        $row = $this->requireOwnReminder($identity, $reminderId);
        $this->requireOpen($row, 'completed');

        $dueAt = self::instant($row['due_at']);
        $rule = $row['recurrence_rule'] === null
            ? null
            : RecurrenceRule::parse((string) $row['recurrence_rule']);

        $next = $rule?->next(
            max($dueAt, Clock::now()),
            $dueAt,
            $this->zone((string) $row['timezone']),
        );

        if ($next === null) {
            Connection::execute(
                'UPDATE note_reminders
                 SET status = \'completed\', completed_at = now(), snoozed_until = NULL, updated_at = now()
                 WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
                ['id' => $reminderId, 'user' => $identity->userId],
            );

            return $this->present($this->reload($reminderId));
        }

        Connection::execute(
            'UPDATE note_reminders
             SET due_at = :due_at::timestamptz, status = \'scheduled\', snoozed_until = NULL,
                 completed_at = NULL, notified_at = NULL, updated_at = now()
             WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
            [
                'due_at' => $next->format(\DateTimeInterface::RFC3339),
                'id' => $reminderId,
                'user' => $identity->userId,
            ],
        );

        return $this->present($this->reload($reminderId));
    }

    // -----------------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------------

    /**
     * Claim the reminders whose time has come, for a worker to deliver.
     *
     * This method **finds and marks**; it does not notify. There is no
     * AICOUNTLY notification service configured against this API, and a
     * delivery adapter that wrote to a log and returned success would be a
     * reminder product that quietly reminds nobody. So the honest surface is
     * this: the rows are returned, `notified_at` records that they were handed
     * out, and delivery is a caller's job once a channel exists.
     *
     * Claiming is one statement with `FOR UPDATE OF due SKIP LOCKED`, so two
     * workers in the same minute — the normal state of affairs under cron —
     * split the batch instead of both delivering it. `OF due` narrows the lock
     * to the reminder rows: locking the joined notes as well would block
     * whoever is editing one while the dispatcher runs.
     *
     * There is no {@see Identity} here on purpose: a worker acts for nobody, so
     * the caller-scoped access CTE cannot apply. The gate that does apply is
     * the note being alive, which is why a trashed note stops firing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimDue(int $limit = 100): array
    {
        $rows = Connection::select(
            'UPDATE note_reminders r
             SET notified_at = now(), updated_at = now()
             FROM notes n
             WHERE n.id = r.note_id
               AND r.id IN (
                   SELECT due.id
                   FROM note_reminders due
                   JOIN notes dn ON dn.id = due.note_id AND dn.deleted_at IS NULL
                   WHERE due.deleted_at IS NULL
                     AND due.notified_at IS NULL
                     AND due.status IN (\'scheduled\', \'snoozed\')
                     AND coalesce(due.snoozed_until, due.due_at) <= now()
                   ORDER BY coalesce(due.snoozed_until, due.due_at)
                   LIMIT :limit
                   FOR UPDATE OF due SKIP LOCKED
               )
             RETURNING r.*, n.title AS note_title, left(n.extracted_text, 200) AS note_text,
                       n.note_type AS note_type, coalesce(r.snoozed_until, r.due_at) AS due_effective_at',
            ['limit' => max(1, min(1000, $limit))],
        );

        return array_map(fn (array $row): array => $this->present($row, withNote: true), $rows);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Load a reminder the caller owns, or fail with 404.
     *
     * The tenant condition is here rather than only on the note, because
     * deletion deliberately skips the note check and must not therefore become
     * the one door that opens across a company boundary.
     *
     * @return array<string, mixed>
     */
    private function requireOwnReminder(
        Identity $identity,
        string $reminderId,
        bool $requireNoteAccess = true,
    ): array {
        $row = Connection::selectOne(
            'SELECT * FROM note_reminders
             WHERE id = :id AND user_id = :user AND deleted_at IS NULL
               AND (tenant_id IS NULL OR tenant_id = :tenant)',
            ['id' => $reminderId, 'user' => $identity->userId, 'tenant' => $identity->tenantId],
        );

        // Deliberately not 403: someone else's reminder and a reminder that
        // never existed must be indistinguishable from outside.
        if ($row === null) {
            throw ApiException::notFound('That reminder');
        }

        if ($requireNoteAccess) {
            $this->permissions->requireNote(
                $identity,
                (string) $row['note_id'],
                NotePermissionService::VIEW,
                columns: self::NOTE_COLUMNS,
            );
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function requireOpen(array $row, string $verb): void
    {
        $status = (string) $row['status'];
        if (in_array($status, self::OPEN_STATUSES, true)) {
            return;
        }

        throw ApiException::validation([
            'status' => sprintf('A %s reminder cannot be %s. Reschedule it first.', $status, $verb),
        ]);
    }

    /** @return array<string, mixed> */
    private function reload(string $reminderId): array
    {
        $row = Connection::selectOne('SELECT * FROM note_reminders WHERE id = :id', ['id' => $reminderId]);
        if ($row === null) {
            throw ApiException::notFound('That reminder');
        }

        return $row;
    }

    /**
     * Parse and normalise a recurrence rule, or null for a one-off.
     *
     * `null` clears the rule; an empty string does the same, because that is
     * what a cleared form field sends.
     */
    private function recurrence(mixed $value, \DateTimeImmutable $dueAt, \DateTimeZone $timezone): ?RecurrenceRule
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validation(['recurrence_rule' => 'A recurrence rule must be an RRULE string.']);
        }

        return RecurrenceRule::parse($value)->withCountResolved($dueAt, $timezone);
    }

    /** An action on this note, or null. Bound to the note so an id cannot borrow another's. */
    private function actionId(string $noteId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!Uuid::isValid($value)) {
            throw ApiException::validation(['action_id' => 'That is not an action id.']);
        }

        $row = Connection::selectOne(
            'SELECT id FROM note_actions WHERE id = :id AND note_id = :note_id AND deleted_at IS NULL',
            ['id' => strtolower((string) $value), 'note_id' => $noteId],
        );
        if ($row === null) {
            throw ApiException::validation(['action_id' => 'That action is not on this note.']);
        }

        return (string) $row['id'];
    }

    private function timestamp(mixed $value, string $field): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            throw ApiException::validation([$field => 'A reminder needs a date and time.']);
        }
        if (!is_scalar($value)) {
            throw ApiException::validation([$field => 'Use an ISO-8601 date and time.']);
        }

        try {
            return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw ApiException::validation([$field => 'Use an ISO-8601 date and time.']);
        }
    }

    /**
     * The IANA zone a recurring reminder is anchored to.
     *
     * Checked against the tz database rather than handed to `DateTimeZone`,
     * which also accepts abbreviations like `EST` and fixed offsets like
     * `+05:30`. Neither of those knows when the clocks change, so a rule stored
     * against one would drift by an hour twice a year — precisely the bug the
     * column exists to prevent.
     */
    private function timezone(mixed $value): \DateTimeZone
    {
        if ($value === null || $value === '') {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
        if (!is_scalar($value)) {
            throw ApiException::validation(['timezone' => 'Use an IANA time zone name such as Asia/Kolkata.']);
        }

        return $this->zone(Str::limit(trim((string) $value), 64));
    }

    private function zone(string $name): \DateTimeZone
    {
        static $identifiers = null;
        $identifiers ??= array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL));

        if (!isset($identifiers[$name])) {
            throw ApiException::validation([
                'timezone' => sprintf('`%s` is not an IANA time zone name such as Asia/Kolkata.', Str::limit($name, 64)),
            ]);
        }

        return new \DateTimeZone($name);
    }

    private static function instant(mixed $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Row → API resource.
     *
     * The rule is returned both as the stored RRULE and as its parsed parts, so
     * a client can render "every other Friday" without shipping a second copy
     * of {@see RecurrenceRule} to the browser.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, bool $withNote = false): array
    {
        $rule = $row['recurrence_rule'] === null ? null : (string) $row['recurrence_rule'];

        $resource = [
            'id' => (string) $row['id'],
            'note_id' => (string) $row['note_id'],
            'action_id' => $row['action_id'] === null ? null : (string) $row['action_id'],
            'user_id' => (string) $row['user_id'],
            'reminder_type' => (string) $row['reminder_type'],
            'due_at' => self::iso($row['due_at']),
            'due_effective_at' => self::iso($row['due_effective_at'] ?? ($row['snoozed_until'] ?? $row['due_at'])),
            'timezone' => (string) $row['timezone'],
            'recurrence_rule' => $rule,
            'recurrence' => $rule === null ? null : RecurrenceRule::parse($rule)->toArray(),
            'status' => (string) $row['status'],
            'snoozed_until' => self::iso($row['snoozed_until']),
            'completed_at' => self::iso($row['completed_at']),
            'notified_at' => self::iso($row['notified_at']),
            'created_at' => self::iso($row['created_at']),
            'updated_at' => self::iso($row['updated_at']),
        ];

        if ($withNote) {
            $title = $row['note_title'] === null ? null : (string) $row['note_title'];
            $resource['note_title'] = $title;
            // The same rule the note list uses, so an untitled note reads the
            // same here as it does on its own card.
            $resource['note_display_title'] = NotePresenter::displayTitle($title, (string) ($row['note_text'] ?? ''));
            $resource['note_type'] = (string) ($row['note_type'] ?? 'document');
        }

        return $resource;
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            return null;
        }
    }
}
