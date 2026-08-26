<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Support\Posting\BalanceRebuilder;
use App\Support\Posting\StockMovement;
use App\Support\Posting\StockPosting;
use App\Support\Posting\StockReversal;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BalanceRebuildTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create();
    }

    public function test_a_cache_written_only_by_the_kernel_always_agrees(): void
    {
        // The invariant, exercised over the whole range of movements the kernel
        // can make: receipts, an issue at moving average, and a reversal.
        $reference = $this->receipt('10', '100');
        $this->receipt('10', '200');
        $this->issue('-6');
        app(StockReversal::class)->reverse('goods_receipt', $reference, date: new DateTimeImmutable('2026-08-26'));

        $this->assertSame([], app(BalanceRebuilder::class)->check());
    }

    public function test_drift_is_reported_without_being_written(): void
    {
        $this->receipt('10', '100');

        // Something wrote stock outside the kernel — the failure this command
        // exists to surface.
        DB::table('stock_balances')->update(['qty_base' => '7.0000', 'value' => '700.00']);

        $differences = app(BalanceRebuilder::class)->check();

        $this->assertCount(1, $differences);
        $this->assertSame('disagrees', $differences[0]->kind());
        $this->assertSame('7.0000', (string) $differences[0]->cachedQuantity);
        $this->assertSame('10.0000', (string) $differences[0]->ledgerQuantity);
        $this->assertSame('7.0000', (string) StockBalance::firstOrFail()->qty_base);
    }

    public function test_rebuilding_restores_the_cache_from_the_ledger(): void
    {
        $this->receipt('10', '100');
        DB::table('stock_balances')->update(['qty_base' => '7.0000', 'value' => '700.00']);

        $corrected = app(BalanceRebuilder::class)->rebuild();

        $this->assertCount(1, $corrected);
        $this->assertSame('10.0000', (string) StockBalance::firstOrFail()->qty_base);
        $this->assertSame('1000.00', (string) StockBalance::firstOrFail()->value);
        $this->assertSame([], app(BalanceRebuilder::class)->check());
    }

    public function test_a_cached_balance_with_no_ledger_behind_it_is_removed(): void
    {
        StockBalance::withoutGlobalScopes()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty_base' => '5',
            'value' => '500',
        ]);

        $differences = app(BalanceRebuilder::class)->rebuild();

        $this->assertSame('no ledger rows', $differences[0]->kind());
        $this->assertSame(0, StockBalance::withoutGlobalScopes()->count());
    }

    public function test_consigned_balances_are_rebuilt_not_discarded(): void
    {
        // The trap: StockBalance filters owner_id IS NULL by default. A rebuild
        // that inherited that scope would call every consigned balance unbacked
        // and delete the principal's stock out of the warehouse.
        $owner = BusinessPartner::factory()->create(['is_principal' => true]);
        $this->receipt('10', '100');
        app(StockPosting::class)->postDocument([new StockMovement(
            $this->warehouse->id, $this->product->id, '4', '75', 'goods_receipt',
            (string) Str::uuid7(), new DateTimeImmutable('2026-08-26'), ownerId: $owner->id,
        )]);

        DB::table('stock_balances')->where('owner_id', $owner->id)->update(['qty_base' => '1.0000']);

        $differences = app(BalanceRebuilder::class)->rebuild();

        $this->assertCount(1, $differences);
        $this->assertSame($owner->id, $differences[0]->ownerId);
        $this->assertSame(2, StockBalance::withoutGlobalScopes()->count());
        $this->assertSame('4.0000', (string) StockBalance::withoutGlobalScopes()->where('owner_id', $owner->id)->firstOrFail()->qty_base);
    }

    public function test_the_command_reports_a_healthy_cache_and_fails_a_check_on_drift(): void
    {
        $this->receipt('10', '100');

        $this->artisan('stock:rebuild-balances', ['--check' => true])
            ->expectsOutputToContain('agree with the ledger')
            ->assertExitCode(0);

        DB::table('stock_balances')->update(['qty_base' => '7.0000']);

        $this->artisan('stock:rebuild-balances', ['--check' => true])->assertExitCode(1);
        $this->artisan('stock:rebuild-balances')->assertExitCode(0);
        $this->artisan('stock:rebuild-balances', ['--check' => true])->assertExitCode(0);
    }

    private function receipt(string $quantity, string $unitCost): string
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->postDocument([new StockMovement(
            $this->warehouse->id, $this->product->id, $quantity, $unitCost, 'goods_receipt',
            $reference, new DateTimeImmutable('2026-08-26'),
        )]);

        return $reference;
    }

    private function issue(string $quantity): string
    {
        $reference = (string) Str::uuid7();
        app(StockPosting::class)->postDocument([new StockMovement(
            $this->warehouse->id, $this->product->id, $quantity, null, 'delivery',
            $reference, new DateTimeImmutable('2026-08-26'),
        )]);

        return $reference;
    }
}
