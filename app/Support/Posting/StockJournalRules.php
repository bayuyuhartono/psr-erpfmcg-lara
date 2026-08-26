<?php

namespace App\Support\Posting;

use App\Support\Posting\Exceptions\InvalidStockMovement;

/**
 * Which accounts the inventory side of a stock movement faces.
 *
 * Every movement debits or credits the inventory account; this table names the
 * counterpart. Only the *shape* of the entry lives here — the accounts behind
 * both posting keys come from journal_mappings, per client.
 *
 * Inbound  (qty > 0):  DR inventory   CR counterpart
 * Outbound (qty < 0):  DR counterpart CR inventory
 */
final class StockJournalRules
{
    public const INVENTORY_KEY = 'inventory';

    /** ref_type => the posting key facing inventory. */
    private const COUNTERPART = [
        'goods_receipt' => 'payable',
        'purchase_return' => 'payable',
        'delivery' => 'cogs',
        'sales_return' => 'cogs',
        'adjustment' => 'variance',
        // Settling inbound consignment moves the goods into our ownership and
        // raises the payable to the principal at that moment (schema §7).
        'consignment_settlement' => 'payable',
    ];

    /**
     * Both legs of a warehouse transfer hit the same inventory account, so the
     * entry would be a no-op. Location is not an accounting dimension here.
     */
    private const NO_JOURNAL = ['transfer_in', 'transfer_out'];

    public function counterpartFor(string $referenceType): ?string
    {
        if (in_array($referenceType, self::NO_JOURNAL, true)) {
            return null;
        }

        return self::COUNTERPART[$referenceType] ?? throw new InvalidStockMovement(sprintf(
            'No journal rule for stock movement type "%s". Add it to StockJournalRules, with its accounts in journal_mappings.',
            $referenceType,
        ));
    }
}
