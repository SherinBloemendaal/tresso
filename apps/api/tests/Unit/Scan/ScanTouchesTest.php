<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Scan\ScanTouches;
use PHPUnit\Framework\TestCase;

final class ScanTouchesTest extends TestCase
{
    public function testOwnersInOrderDraftsBeforeMessagesIdsSorted(): void
    {
        $plan = ScanTouches::plan(
            [['id' => 'd2', 'owner_id' => 'u2'], ['id' => 'd1', 'owner_id' => 'u2']],
            [['id' => 'm3', 'owner_id' => 'u2'], ['id' => 'm2', 'owner_id' => 'u1'], ['id' => 'm1', 'owner_id' => 'u1'], ['id' => 'm1', 'owner_id' => 'u1']],
        );

        self::assertSame([
            ['message', 'u1', ['m1', 'm2']],
            ['draft', 'u2', ['d1', 'd2']],
            ['message', 'u2', ['m3']],
        ], $plan);
    }

    public function testSameOrderWhateverOrderTheRowsCameIn(): void
    {
        $drafts = [['id' => 'd1', 'owner_id' => 'u3'], ['id' => 'd2', 'owner_id' => 'u1']];
        $messages = [['id' => 'm1', 'owner_id' => 'u2'], ['id' => 'm2', 'owner_id' => 'u3']];

        self::assertSame(ScanTouches::plan($drafts, $messages), ScanTouches::plan(array_reverse($drafts), array_reverse($messages)));
    }
}
