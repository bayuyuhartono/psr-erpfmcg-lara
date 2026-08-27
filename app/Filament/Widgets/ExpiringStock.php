<?php

namespace App\Filament\Widgets;

use App\Domain\Inventory\Models\StockBalance;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batches near their expiry date that still have stock on them.
 *
 * The one thing on this screen that is actionable today. Shelf-life rules
 * block a receipt and remove a batch from picking, but neither tells anyone
 * that goods already in the racks are about to become unsellable — by the time
 * the picking pool refuses them, the loss has happened.
 */
class ExpiringStock extends TableWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** Far enough out to still discount or return the goods. */
    private const HORIZON_DAYS = 60;

    public function getTableHeading(): string
    {
        return __('Expiring within 60 days');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('stock_batches.expiry_date')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('Nothing expiring soon'))
            ->emptyStateDescription(__('No batch with stock on hand reaches its expiry date within 60 days.'))
            ->columns([
                TextColumn::make('product.sku')->label(__('SKU'))->fontFamily('mono')->size('xs'),
                TextColumn::make('product.name')->label(__('Product'))->wrap(),
                TextColumn::make('batch.batch_number')->label(__('Batch'))->fontFamily('mono')->size('xs'),
                TextColumn::make('warehouse.name')->label(__('Warehouse')),
                TextColumn::make('qty_base')
                    ->label(__('On hand'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(
                        number_format((float) (string) $state, 4, ',', '.'), '0'), ',')),
                TextColumn::make('batch.expiry_date')
                    ->label(__('Expires'))
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn ($state): string => CarbonImmutable::parse($state)->diffInDays(CarbonImmutable::today(), false) >= -14
                        ? 'danger'
                        : 'warning'),
            ]);
    }

    private function query(): Builder
    {
        return StockBalance::query()
            ->withoutGlobalScopes()
            // Ours only. A principal's consigned goods expiring is their
            // problem to price, not a loss on our balance sheet.
            ->whereNull('owner_id')
            ->where('qty_base', '>', 0)
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_balances.batch_id')
            ->whereNotNull('stock_batches.expiry_date')
            ->whereDate('stock_batches.expiry_date', '<=', CarbonImmutable::today()->addDays(self::HORIZON_DAYS)->toDateString())
            ->with(['product:id,sku,name', 'batch:id,batch_number,expiry_date', 'warehouse:id,name'])
            ->select('stock_balances.*');
    }
}
