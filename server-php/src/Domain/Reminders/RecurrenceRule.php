<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Reminders;

use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;

/**
 * The slice of RFC 5545 RRULE this product actually needs.
 *
 * `FREQ` with `INTERVAL`, `BYDAY`, `COUNT` and `UNTIL` — enough for "every
 * weekday", "every other Friday", "the last working day of the month" and
 * "every year on the 15th". Nothing else: no BYSETPOS, no BYMONTHDAY, no
 * EXDATE.
 *
 * The important half of that sentence is the *nothing else*. An unsupported
 * part is **rejected at write time**, not dropped, because the failure mode of
 * dropping it is a reminder that silently fires on the wrong days for a year —
 * the user believing they set something the server never agreed to. A refusal
 * with a message they can act on is the only honest answer for a subset.
 *
 * Occurrences are computed on the **local calendar** of the reminder's IANA
 * zone and only then converted to an instant. That is what keeps a weekly
 * 09:00 reminder at 09:00 through a daylight-saving change: the wall clock is
 * carried forward and the UTC offset is re-derived, rather than 604800 seconds
 * being added to an instant and landing at 08:00 for half the year.
 */
final class RecurrenceRule
{
    public const DAILY = 'DAILY';
    public const WEEKLY = 'WEEKLY';
    public const MONTHLY = 'MONTHLY';
    public const YEARLY = 'YEARLY';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY];

    /** The whole vocabulary. Anything else in a rule is a rejection. */
    private const SUPPORTED_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL'];

    private const WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    private const MAX_INTERVAL = 999;
    private const MAX_COUNT = 999;
    private const MAX_BY_DAY = 14;

    /**
     * Ceilings on the search for a next occurrence.
     *
     * A rule may name a date that some periods do not contain — `BYDAY=5MO` in
     * a month with four Mondays, or the 31st in February — so the walk has to
     * be allowed to skip periods. These bound it: without them a rule whose
     * date never occurs would spin until the request timed out.
     */
    private const MAX_PERIODS = 1200;
    private const MAX_OCCURRENCES = 1200;

    /** @param array<int, array{ordinal: int|null, weekday: int}> $byDay */
    private function __construct(
        public readonly string $frequency,
        public readonly int $interval,
        public readonly array $byDay,
        public readonly ?int $count,
        public readonly ?\DateTimeImmutable $until,
    ) {
    }

    // -----------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------

    /**
     * Parse an RRULE, or fail with a message naming what is wrong.
     *
     * @throws ApiException 422, with the offending part named.
     */
    public static function parse(string $rule): self
    {
        $text = strtoupper(trim($rule));
        // Calendars serialise the property as `RRULE:FREQ=…`; accept the
        // prefix so a rule copied out of an .ics file works.
        if (str_starts_with($text, 'RRULE:')) {
            $text = substr($text, 6);
        }
        if ($text === '') {
            throw self::invalid('A recurrence rule cannot be empty.');
        }
        if (strlen($text) > 300) {
            throw self::invalid('That recurrence rule is too long.');
        }

        $parts = [];
        foreach (explode(';', $text) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (!str_contains($segment, '=')) {
                throw self::invalid(sprintf('`%s` is not a `NAME=VALUE` pair.', Str::limit($segment, 40)));
            }
            [$name, $value] = explode('=', $segment, 2);
            $name = trim($name);
            if (!in_array($name, self::SUPPORTED_PARTS, true)) {
                throw self::invalid(sprintf(
                    '`%s` is not supported. This API understands %s.',
                    Str::limit($name, 40),
                    implode(', ', self::SUPPORTED_PARTS),
                ));
            }
            if (array_key_exists($name, $parts)) {
                throw self::invalid(sprintf('`%s` appears twice.', $name));
            }
            $parts[$name] = trim($value);
        }

        $frequency = $parts['FREQ'] ?? '';
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw self::invalid(sprintf('`FREQ` must be one of %s.', implode(', ', self::FREQUENCIES)));
        }

        $interval = 1;
        if (isset($parts['INTERVAL'])) {
            if (preg_match('/^\d{1,3}$/', $parts['INTERVAL']) !== 1 || (int) $parts['INTERVAL'] < 1) {
                throw self::invalid(sprintf('`INTERVAL` must be a whole number from 1 to %d.', self::MAX_INTERVAL));
            }
            $interval = (int) $parts['INTERVAL'];
        }

        $count = null;
        if (isset($parts['COUNT'])) {
            if (preg_match('/^\d{1,3}$/', $parts['COUNT']) !== 1 || (int) $parts['COUNT'] < 1) {
                throw self::invalid(sprintf('`COUNT` must be a whole number from 1 to %d.', self::MAX_COUNT));
            }
            $count = (int) $parts['COUNT'];
        }

        $until = isset($parts['UNTIL']) ? self::parseUntil($parts['UNTIL']) : null;

        // RFC 5545 §3.3.10: the two ways of ending a series are mutually
        // exclusive. Accepting both would leave the shorter one silently
        // winning, which is exactly the quiet wrongness this class refuses.
        if ($count !== null && $until !== null) {
            throw self::invalid('A rule may end with `COUNT` or with `UNTIL`, not both.');
        }

        return new self($frequency, $interval, self::parseByDay($parts['BYDAY'] ?? null, $frequency), $count, $until);
    }

    /** @return array<int, array{ordinal: int|null, weekday: int}> */
    private static function parseByDay(?string $value, string $frequency): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($frequency === self::DAILY) {
            throw self::invalid('`BYDAY` cannot be combined with `FREQ=DAILY` — use `FREQ=WEEKLY` instead.');
        }

        $days = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if (preg_match('/^([+-]?[1-5])?(MO|TU|WE|TH|FR|SA|SU)$/', $token, $matches) !== 1) {
                throw self::invalid(sprintf(
                    '`%s` is not a weekday. Use MO, TU, WE, TH, FR, SA or SU, optionally with a position such as 2TU or -1FR.',
                    Str::limit($token, 20),
                ));
            }
            $ordinal = $matches[1] === '' ? null : (int) $matches[1];
            if ($ordinal !== null && $frequency === self::WEEKLY) {
                throw self::invalid(sprintf(
                    '`%s` numbers a weekday within a month, which `FREQ=WEEKLY` has no room for.',
                    Str::limit($token, 20),
                ));
            }
            // Keyed so `BYDAY=MO,MO` is one Monday rather than two.
            $days[$token] = ['ordinal' => $ordinal, 'weekday' => self::WEEKDAYS[$matches[2]]];
        }

        if (count($days) > self::MAX_BY_DAY) {
            throw self::invalid(sprintf('`BYDAY` may name at most %d days.', self::MAX_BY_DAY));
        }

        return array_values($days);
    }

    /**
     * `UNTIL` in either RFC 5545 form.
     *
     * A bare `DATE` has no time, and the RFC treats the series as ending with
     * that day, so it is read as the last instant of that day in UTC — the
     * reading that never cuts a final occurrence off early.
     */
    private static function parseUntil(string $value): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');

        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $value, $m) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]),
                $utc,
            );
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                sprintf('%s-%s-%s 23:59:59', $m[1], $m[2], $m[3]),
                $utc,
            );
        } else {
            throw self::invalid('`UNTIL` must be a UTC timestamp such as 20261231T090000Z, or a date such as 20261231.');
        }

        if ($parsed === false || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::invalid(sprintf('`UNTIL=%s` is not a real date.', Str::limit($value, 30)));
        }

        return $parsed;
    }

    private static function invalid(string $message): ApiException
    {
        return ApiException::validation(['recurrence_rule' => $message]);
    }

    // -----------------------------------------------------------------------
    // Occurrences
    // -----------------------------------------------------------------------

    /**
     * The first occurrence strictly after `$after`, or null when the series
     * has run out.
     *
     * `$start` anchors the pattern — it is the occurrence the other ones are
     * counted from — and `$after` is where to resume. They are separate
     * arguments because completing a reminder late must not produce a backlog:
     * the caller passes the current due time as the anchor and `now()` as the
     * resume point, and gets the next occurrence that is genuinely in front of
     * the user.
     */
    public function next(
        \DateTimeImmutable $after,
        \DateTimeImmutable $start,
        \DateTimeZone $timezone,
    ): ?\DateTimeImmutable {
        foreach ($this->occurrences($start, $timezone) as $occurrence) {
            if ($occurrence > $after) {
                return $occurrence;
            }
        }

        return null;
    }

    /**
     * Rewrite a `COUNT` rule as the equivalent `UNTIL`.
     *
     * `COUNT` is anchored to the start of the series, and `note_reminders` has
     * one timestamp column — the *current* occurrence — with nowhere to record
     * where the series began or how many have already fired. Resolving the
     * count once, at write time, against the first occurrence turns it into an
     * absolute end date that stays correct however many times the row is later
     * advanced. The alternative, decrementing a stored COUNT on every
     * completion, quietly loses the whole series if one completion is replayed.
     */
    public function withCountResolved(\DateTimeImmutable $start, \DateTimeZone $timezone): self
    {
        if ($this->count === null) {
            return $this;
        }

        $last = $start;
        foreach ($this->occurrences($start, $timezone) as $occurrence) {
            $last = $occurrence;
        }

        return new self($this->frequency, $this->interval, $this->byDay, null, $last);
    }

    /**
     * Every occurrence from `$start` onwards, in order.
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function occurrences(\DateTimeImmutable $start, \DateTimeZone $timezone): \Generator
    {
        if ($this->until !== null && $start > $this->until) {
            return;
        }

        // The anchor is always the first occurrence, even when it does not
        // itself match BYDAY: it is the moment the user asked to be reminded,
        // and the pattern describes what happens *after* it.
        yield $start;
        $emitted = 1;
        $limit = $this->count ?? self::MAX_OCCURRENCES;
        if ($emitted >= $limit) {
            return;
        }

        $local = $start->setTimezone($timezone);
        // Carried forward as a wall clock, never as an offset from UTC.
        $time = $local->format('H:i:s');
        $anchor = self::dateOnly($local->format('Y-m-d'));

        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            foreach ($this->datesIn($anchor, $period) as $date) {
                $instant = self::instantAt($date, $time, $timezone);
                if ($instant === null || $instant <= $start) {
                    continue;
                }
                if ($this->until !== null && $instant > $this->until) {
                    return;
                }

                yield $instant;

                if (++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * The local dates in one period of the pattern, ascending.
     *
     * `$anchor` is a date-only value in UTC so the arithmetic below is pure
     * calendar arithmetic — adding a day here can never gain or lose an hour.
     *
     * @return array<int, string> `Y-m-d`
     */
    private function datesIn(\DateTimeImmutable $anchor, int $period): array
    {
        $step = $period * $this->interval;

        if ($this->frequency === self::DAILY) {
            return [$anchor->modify(sprintf('+%d days', $step))->format('Y-m-d')];
        }

        if ($this->frequency === self::WEEKLY) {
            // Weeks are measured from the Monday of the anchor's week (the
            // RFC's default WKST), so `INTERVAL=2` means the same alternating
            // weeks whichever day of the week the reminder was created on.
            $weekStart = $anchor
                ->modify(sprintf('-%d days', (int) $anchor->format('N') - 1))
                ->modify(sprintf('+%d weeks', $step));

            $weekdays = $this->byDay === []
                ? [(int) $anchor->format('N')]
                : array_map(static fn (array $day): int => $day['weekday'], $this->byDay);
            sort($weekdays);

            return array_map(
                static fn (int $weekday): string => $weekStart->modify(sprintf('+%d days', $weekday - 1))->format('Y-m-d'),
                array_values(array_unique($weekdays)),
            );
        }

        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');

        if ($this->frequency === self::MONTHLY) {
            // Months are added to a month counter rather than to the date, so
            // the 31st of January plus one month is February (which has no
            // 31st, and is therefore skipped) instead of the 3rd of March.
            $absolute = $year * 12 + ($month - 1) + $step;
            $year = intdiv($absolute, 12);
            $month = $absolute % 12 + 1;
        } else {
            $year += $step;
        }

        if ($this->byDay === []) {
            $day = (int) $anchor->format('j');

            return checkdate($month, $day, $year) ? [sprintf('%04d-%02d-%02d', $year, $month, $day)] : [];
        }

        return array_map(
            static fn (int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day),
            $this->daysMatchingByDay($year, $month),
        );
    }

    /**
     * The days of one month that `BYDAY` selects, ascending.
     *
     * @return array<int, int>
     */
    private function daysMatchingByDay(int $year, int $month): array
    {
        $first = self::dateOnly(sprintf('%04d-%02d-01', $year, $month));
        $firstWeekday = (int) $first->format('N');
        $daysInMonth = (int) $first->format('t');

        $selected = [];
        foreach ($this->byDay as $spec) {
            $candidates = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                if (($firstWeekday - 1 + $day - 1) % 7 + 1 === $spec['weekday']) {
                    $candidates[] = $day;
                }
            }

            if ($spec['ordinal'] === null) {
                foreach ($candidates as $day) {
                    $selected[$day] = true;
                }
                continue;
            }

            // A negative position counts back from the end, so `-1FR` is the
            // last Friday whether the month holds four of them or five.
            $index = $spec['ordinal'] > 0 ? $spec['ordinal'] - 1 : count($candidates) + $spec['ordinal'];
            if (isset($candidates[$index])) {
                $selected[$candidates[$index]] = true;
            }
        }

        $days = array_keys($selected);
        sort($days);

        return $days;
    }

    /** A date with no time and no daylight saving, for calendar arithmetic only. */
    private static function dateOnly(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * A local date and wall-clock time as an instant.
     *
     * On the morning a zone springs forward there is an hour that does not
     * exist locally; PHP resolves such a time to the same wall clock on the
     * other side of the jump, which is the behaviour a person expects from a
     * 02:30 alarm on that day — it rings, once.
     */
    private static function instantAt(string $date, string $time, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $timezone);

        return $local === false ? null : $local->setTimezone(new \DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /** The canonical RRULE text. This, not the caller's spelling, is what is stored. */
    public function toString(): string
    {
        $parts = ['FREQ=' . $this->frequency];
        if ($this->interval > 1) {
            $parts[] = 'INTERVAL=' . $this->interval;
        }
        if ($this->byDay !== []) {
            $parts[] = 'BYDAY=' . implode(',', $this->byDayTokens());
        }
        if ($this->count !== null) {
            $parts[] = 'COUNT=' . $this->count;
        }
        if ($this->until !== null) {
            $parts[] = 'UNTIL=' . $this->until->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        return implode(';', $parts);
    }

    /** @return array<int, string> */
    private function byDayTokens(): array
    {
        $names = array_flip(self::WEEKDAYS);

        return array_map(
            static fn (array $day): string => ($day['ordinal'] === null ? '' : (string) $day['ordinal']) . $names[$day['weekday']],
            $this->byDay,
        );
    }

    /**
     * The parsed rule, for a client that would rather render "every 2 weeks on
     * Friday" than re-implement this parser in TypeScript.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'by_day' => $this->byDayTokens(),
            'count' => $this->count,
            'until' => $this->until?->format(\DateTimeInterface::RFC3339),
        ];
    }
}
