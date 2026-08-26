<?php

namespace App\Support\Posting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;

final readonly class StockPostingResult
{
    public function __construct(
        public StockLedger $ledger,
        public StockBalance $balance,
        public ProductCost $productCost,
        // Null where the movement has no accounting consequence: consigned
        // goods we do not own, warehouse transfers, zero-value movements.
        public ?JournalEntry $journal = null,
    ) {}
}
