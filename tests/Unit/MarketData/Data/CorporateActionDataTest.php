<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Data;

use App\MarketData\Data\CorporateActionData;
use App\MarketData\Enums\CorporateActionTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CorporateActionData::class)]
final class CorporateActionDataTest extends TestCase
{
    public function testFakeSplit(): void
    {
        $action = CorporateActionData::fake([
            'symbol' => 'AAPL',
            'type' => CorporateActionTypeEnum::SPLIT,
            'exDate' => '2020-08-31',
            'ratio' => 4.0,
            'amount' => null,
        ]);

        $this->assertSame(CorporateActionTypeEnum::SPLIT, $action->type);
        $this->assertSame('2020-08-31', $action->exDate->toDateString());
        $this->assertSame(4.0, $action->ratio);
        $this->assertNull($action->amount);
    }

    public function testFakeDividend(): void
    {
        $action = CorporateActionData::fake([
            'type' => CorporateActionTypeEnum::DIVIDEND,
            'ratio' => null,
            'amount' => 0.82,
        ]);

        $this->assertSame(0.82, $action->amount);
        $this->assertNull($action->ratio);
    }
}
