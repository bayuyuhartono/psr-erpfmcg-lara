<?php

namespace Tests\Unit;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Scopes\OwnedStock;
use App\Domain\Masters\Models\BusinessPartner;
use Brick\Math\BigDecimal;
use Tests\TestCase;

final class FoundationModelsTest extends TestCase
{
    public function test_decimal_columns_are_exposed_as_big_decimals(): void
    {
        $partner = new BusinessPartner(['credit_limit' => '123456.789']);

        $this->assertInstanceOf(BigDecimal::class, $partner->credit_limit);
        $this->assertSame('123456.79', (string) $partner->credit_limit);
    }

    public function test_owned_stock_scope_is_enabled_and_can_be_removed(): void
    {
        $scopes = (new StockBalance)->getGlobalScopes();

        $this->assertArrayHasKey(OwnedStock::class, $scopes);
        $this->assertContains(OwnedStock::class, StockBalance::includingConsigned()->removedScopes());
    }
}
