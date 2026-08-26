<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalMapping;
use App\Domain\Inventory\Models\StockBalance;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the stock is worth, and whether the ledger agrees (schema §11).
 *
 * **Consigned-in goods are excluded.** They sit in our warehouse but belong to
 * the principal, and counting them would overstate the balance sheet by their
 * full value. The `OwnedStock` global scope does it here as everywhere else —
 * `includingConsigned()` is available and deliberately not used.
 *
 * The reconciliation is the point. Stock value and the inventory account are
 * kept by two different mechanisms, and if they disagree something posted
 * stock without a journal or a journal without stock.
 */
final class InventoryValuation
{
    public function __construct(private readonly GeneralLedger $ledger) {}

    /**
     * @return array{stock_value: string, account_balance: string, difference: string,
     *     reconciles: bool, consigned_excluded: string}
     */
    public function reconcile(?DateTimeInterface $on = null): array
    {
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now())->startOfDay();

        $owned = BigDecimal::of(StockBalance::query()->sum('value'))->toScale(2);
        $everything = BigDecimal::of(StockBalance::includingConsigned()->sum('value'))->toScale(2);

        $account = $this->inventoryAccount();
        $balance = $account === null ? BigDecimal::zero()->toScale(2) : $this->ledger->balanceAt($account, $on)->toScale(2);

        return [
            'stock_value' => (string) $owned,
            'account_balance' => (string) $balance,
            'difference' => (string) $owned->minus($balance)->toScale(2),
            'reconciles' => $owned->isEqualTo($balance),
            'consigned_excluded' => (string) $everything->minus($owned)->toScale(2),
        ];
    }

    private function inventoryAccount(): ?ChartOfAccount
    {
        // Read from the mapping, never hardcoded: this client's inventory
        // account is whatever they said it was.
        $accountId = JournalMapping::query()
            ->where('posting_key', 'inventory')
            ->value('account_id');

        return $accountId === null ? null : ChartOfAccount::find($accountId);
    }
}
