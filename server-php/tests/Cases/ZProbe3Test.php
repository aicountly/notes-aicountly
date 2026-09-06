<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

final class ZProbe3Test extends TestCase
{
    public function name(): string { return 'ZProbe3'; }

    public function testDueAtEdges(): void
    {
        $alice = new ApiClient(Support::user('a'));
        $note = $alice->post('/notes', ['document' => Support::doc('x')])['body']['data'];

        $values = [
            '294277-01-01T00:00:00Z',
            '999999-01-01T00:00:00Z',
            '+999999999 years',
            '@99999999999999',
            'now',
            '-4713-01-01T00:00:00Z',
            str_repeat('9', 300),
            'infinity',
            '0000-00-00',
            'first day of next month',
        ];
        foreach ($values as $v) {
            try {
                $r = $alice->post('/notes/' . $note['id'] . '/actions', ['text' => 'x', 'due_at' => $v]);
                fwrite(STDERR, "\nDUE " . var_export($v, true) . " -> " . $r['status'] . ' ' . json_encode($r['body']['data']['due_at'] ?? $r['body']['error']['code'] ?? null) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nDUE " . var_export($v, true) . " CRASH " . get_debug_type($e) . ': ' . substr($e->getMessage(), 0, 160) . "\n");
            }
        }
        $this->pass();
    }
}
