<?php

namespace App\Console\Commands;

use App\Support\Posting\BalanceDiscrepancy;
use App\Support\Posting\BalanceRebuilder;
use Illuminate\Console\Command;

final class RebuildStockBalances extends Command
{
    protected $signature = 'stock:rebuild-balances
        {--check : Report what disagrees without changing anything}
        {--product= : Limit to one product ID}
        {--warehouse= : Limit to one warehouse ID}';

    protected $description = 'Recompute stock_balances from stock_ledger. The ledger is the truth; this proves it.';

    public function handle(BalanceRebuilder $rebuilder): int
    {
        $product = $this->option('product');
        $warehouse = $this->option('warehouse');
        $checking = (bool) $this->option('check');

        $differences = $checking
            ? $rebuilder->check($product, $warehouse)
            : $rebuilder->rebuild($product, $warehouse);

        if ($differences === []) {
            $this->info('Stock balances agree with the ledger.');

            return self::SUCCESS;
        }

        $this->table(
            ['Warehouse', 'Product', 'Batch', 'Owner', 'What', 'Cached qty', 'Ledger qty', 'Cached value', 'Ledger value'],
            array_map(fn (BalanceDiscrepancy $difference): array => [
                $difference->warehouseId,
                $difference->productId,
                $difference->batchId ?? '—',
                $difference->ownerId ?? 'us',
                $difference->kind(),
                (string) ($difference->cachedQuantity ?? '—'),
                (string) ($difference->ledgerQuantity ?? '—'),
                (string) ($difference->cachedValue ?? '—'),
                (string) ($difference->ledgerValue ?? '—'),
            ], $differences),
        );

        $count = count($differences);

        if ($checking) {
            $this->error("{$count} balance(s) disagree with the ledger. Run without --check to rebuild them.");

            // Non-zero so this is usable as a scheduled health check: anything
            // reported here means something wrote stock outside the kernel.
            return self::FAILURE;
        }

        $this->info("Rebuilt {$count} balance(s) from the ledger.");

        return self::SUCCESS;
    }
}
