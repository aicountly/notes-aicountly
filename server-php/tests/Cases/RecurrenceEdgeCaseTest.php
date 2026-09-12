<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Domain\Reminders\RecurrenceRule;
use Aicountly\Api\Tests\TestCase;

/**
 * The recurrence cases that only surface twice a year, or once a month.
 *
 * A recurring reminder is a promise about wall-clock time — "every Monday at
 * nine" — and the ways that promise breaks are all invisible in an ordinary
 * week's testing: the hour that does not exist in spring, the hour that
 * happens twice in autumn, and the months with no 31st.
 *
 * Dates here are fixed rather than relative, so these keep testing the same
 * boundaries next year.
 */
final class RecurrenceEdgeCaseTest extends TestCase
{
    private const NEW_YORK = 'America/New_York';

    public function name(): string
    {
        return 'Recurrence edge cases';
    }

    /** @return array<int, string> Local wall-clock times of the next $count occurrences. */
    private function localSequence(string $rule, string $startLocal, string $zone, int $count): array
    {
        $timezone = new \DateTimeZone($zone);
        $parsed = RecurrenceRule::parse($rule);
        $start = new \DateTimeImmutable($startLocal, $timezone);

        $out = [];
        $cursor = $start;
        for ($i = 0; $i < $count; $i++) {
            $next = $parsed->next($cursor, $start, $timezone);
            if ($next === null) {
                break;
            }
            $out[] = $next->setTimezone($timezone)->format('Y-m-d H:i');
            $cursor = $next;
        }

        return $out;
    }

    public function testAWeeklyReminderKeepsItsWallClockTimeAcrossSpringForward(): void
    {
        // US DST begins 2026-03-08. A 09:00 Monday reminder must stay at 09:00.
        $occurrences = $this->localSequence('FREQ=WEEKLY;BYDAY=MO', '2026-03-02 09:00:00', self::NEW_YORK, 2);

        $this->assertSame(['2026-03-09 09:00', '2026-03-16 09:00'], $occurrences);
    }

    public function testTheUtcInstantShiftsWhenTheOffsetDoes(): void
    {
        $timezone = new \DateTimeZone(self::NEW_YORK);
        $rule = RecurrenceRule::parse('FREQ=WEEKLY;BYDAY=MO');
        $start = new \DateTimeImmutable('2026-03-02 09:00:00', $timezone);

        $next = $rule->next($start, $start, $timezone);
        $this->assertNotNull($next);

        // 14:00Z before the change, 13:00Z after — the same wall clock, a
        // different instant. Storing the instant and never recomputing it is
        // how a reminder drifts an hour every spring.
        $this->assertSame('14:00', $start->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
        $this->assertSame('13:00', $next->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
    }

    public function testAReminderInTheHourThatDoesNotExistStillFires(): void
    {
        // 02:30 on 2026-03-08 is skipped by the clock entirely.
        $occurrences = $this->localSequence('FREQ=DAILY', '2026-03-06 02:30:00', self::NEW_YORK, 3);

        $this->assertCount(3, $occurrences);
        $this->assertSame('2026-03-07 02:30', $occurrences[0]);
        // Rolled forward into the new offset rather than dropped: a reminder
        // that silently does not fire is worse than one an hour late.
        $this->assertContainsString('2026-03-08', $occurrences[1]);
        $this->assertSame('2026-03-09 02:30', $occurrences[2]);
    }

    public function testAReminderInTheHourThatHappensTwiceFiresOnce(): void
    {
        // US DST ends 2026-11-01; 01:30 occurs twice that morning.
        $occurrences = $this->localSequence('FREQ=DAILY', '2026-10-30 01:30:00', self::NEW_YORK, 4);

        $onThatDay = array_filter($occurrences, static fn (string $o) => str_starts_with($o, '2026-11-01'));
        $this->assertCount(1, $onThatDay, 'the repeated hour must not produce two reminders');
    }

    public function testMonthlyOnThe31stSkipsMonthsWithoutOne(): void
    {
        $occurrences = $this->localSequence('FREQ=MONTHLY', '2026-01-31 09:00:00', self::NEW_YORK, 4);

        // February, April and June have no 31st. Clamping to the 28th or 30th
        // would move the reminder to a date the user never chose.
        $this->assertSame(
            ['2026-03-31 09:00', '2026-05-31 09:00', '2026-07-31 09:00', '2026-08-31 09:00'],
            $occurrences,
        );
    }

    public function testFebruary29thRecursOnlyInLeapYears(): void
    {
        $occurrences = $this->localSequence('FREQ=YEARLY', '2024-02-29 09:00:00', self::NEW_YORK, 2);

        $this->assertSame(['2028-02-29 09:00', '2032-02-29 09:00'], $occurrences);
    }

    public function testAnIntervalIsRespected(): void
    {
        $occurrences = $this->localSequence('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO', '2026-06-01 09:00:00', 'UTC', 3);

        $this->assertSame(['2026-06-15 09:00', '2026-06-29 09:00', '2026-07-13 09:00'], $occurrences);
    }

    public function testUntilEndsTheSeries(): void
    {
        $occurrences = $this->localSequence(
            'FREQ=DAILY;UNTIL=20260604T090000Z',
            '2026-06-01 09:00:00',
            'UTC',
            10,
        );

        $this->assertCount(3, $occurrences);
        $this->assertSame('2026-06-04 09:00', $occurrences[2]);
    }

    public function testAnUnsupportedRuleIsRefusedRatherThanQuietlyIgnored(): void
    {
        foreach (['FREQ=SECONDLY', 'FREQ=MINUTELY', 'FREQ=HOURLY', 'NONSENSE', 'FREQ=', ''] as $rule) {
            $this->assertApiError(
                'VALIDATION_FAILED',
                static fn () => RecurrenceRule::parse($rule),
                'rule `' . $rule . '` must be refused',
            );
        }
    }

    public function testARuleRoundTripsThroughItsStringForm(): void
    {
        foreach (['FREQ=DAILY', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE', 'FREQ=MONTHLY;COUNT=6'] as $rule) {
            $this->assertSame($rule, RecurrenceRule::parse($rule)->toString());
        }
    }
}
