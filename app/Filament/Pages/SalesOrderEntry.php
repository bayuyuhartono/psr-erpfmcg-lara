<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\PriceListItem;
use App\Domain\Masters\Models\Product;
use App\Domain\Masters\Services\CustomerSearch;
use App\Domain\Masters\Services\ProductSearch;
use App\Domain\Sales\Actions\CopyPreviousOrder;
use App\Domain\Sales\Actions\DraftSalesOrder;
use App\Domain\Sales\Actions\SubmitSalesOrder;
use App\Domain\Sales\Services\CreditCheck;
use App\Support\Pricing\PriceResolver;
use BackedEnum;
use Brick\Math\BigDecimal;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Order entry, built for the keyboard (F-31).
 *
 * The acceptance criterion is ten lines in under two minutes, which rules out
 * the standard resource cycle: a form that reloads, validates and redirects
 * between lines cannot get there however fast the server is. So this is a
 * single screen holding its own state, where a line is: type, choose, quantity,
 * Enter — and the cursor is already back in the search box.
 *
 * Everything it saves goes through the same actions the API and the importer
 * use. The screen is fast; it is not a second set of rules.
 */
class SalesOrderEntry extends Page
{
    protected string $view = 'filament.pages.sales-order-entry';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return __('Order entry');
    }

    public ?string $customerId = null;

    public string $customerSearch = '';

    /** @var list<array{id: string, label: string}> */
    public array $customerMatches = [];

    public ?string $warehouseId = null;

    public string $search = '';

    public string $quantity = '1';

    public ?string $selectedProductId = null;

    public ?string $selectedUom = null;

    /** @var list<array<string, string>> */
    public array $lines = [];

    /** @var list<array{id: string, label: string, uom: string}> */
    public array $matches = [];

    /**
     * Which match the arrow keys are sitting on. F-31 requires the whole form
     * to be operable without touching the mouse, and a list you can only pick
     * from by clicking fails that at the busiest moment on the screen.
     */
    public int $highlight = 0;

    public function mount(): void
    {
        $this->warehouseId = Warehouse::query()->where('active', true)->value('id');
    }

    /**
     * What is missing before an order can be taken at all.
     *
     * An empty screen that quietly refuses to do anything is the worst version
     * of a new installation: nothing is broken, and there is no way to tell.
     *
     * @return list<string>
     */
    public function setupNeeded(): array
    {
        $missing = [];

        if ($this->warehouseId === null) {
            $missing[] = 'No active warehouse — orders have nowhere to ship from.';
        }

        if (! BusinessPartner::query()->customers()->where('active', true)->exists()) {
            $missing[] = 'No customers yet. Add them under Partners, or import them from Excel.';
        }

        if (! Product::query()->where('active', true)->exists()) {
            $missing[] = 'No products yet. Add them under Catalogue, or import them from Excel.';
        } elseif (! PriceListItem::query()->exists()) {
            $missing[] = 'No prices yet. A product needs a price on the list its customer’s tier uses.';
        }

        return $missing;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Type to find a product, Enter to add it. Ten lines in under two minutes is the target.';
    }

    /** As-you-type lookup across code, name and barcode. */
    public function updatedSearch(): void
    {
        $this->selectedProductId = null;
        $this->highlight = 0;
        $this->matches = $this->describe(app(ProductSearch::class)($this->search, 8));
    }

    /**
     * Opening the list before anything is typed.
     *
     * A picker that shows nothing until you guess a code is a text box wearing
     * a costume. Showing what is there makes it a list you can browse, while
     * typing still narrows it.
     */
    public function openList(): void
    {
        if ($this->matches === [] && $this->search === '') {
            $this->matches = $this->describe(
                Product::query()->where('active', true)->orderBy('name')->limit(8)->get(),
            );
        }
    }

    public function closeList(): void
    {
        $this->matches = [];
    }

    /**
     * What a sales admin needs to see about a product before choosing it: what
     * it costs this customer, and whether there is any.
     *
     * @param  Collection<int, Product>  $products
     * @return list<array<string, string>>
     */
    private function describe($products): array
    {
        $customer = $this->customerId === null ? null : BusinessPartner::find($this->customerId);

        return $products->map(function (Product $product) use ($customer): array {
            $uom = $product->productUoms->firstWhere('default_for_sale', true)?->uom ?? $product->base_uom;
            $available = $this->warehouseId === null ? null : StockBalance::query()
                ->where('warehouse_id', $this->warehouseId)
                ->where('product_id', $product->getKey())
                ->sum('qty_base');

            $price = null;

            if ($customer !== null) {
                try {
                    $price = (string) app(PriceResolver::class)->quote($product, $uom, '1', $customer)->unitPrice;
                } catch (DomainException) {
                    // No price on this customer's list. Say so in the row
                    // rather than hiding the product — "why can I not find it"
                    // is a worse question than "why has it no price".
                    $price = null;
                }
            }

            return [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'label' => "{$product->sku} — {$product->name}",
                'uom' => $uom,
                'price' => $price === null ? '' : number_format((float) $price, 0, ',', '.'),
                'available' => $available === null ? '' : rtrim(rtrim(number_format((float) $available, 2, ',', '.'), '0'), ','),
            ];
        })->values()->all();
    }

    public function moveHighlight(int $by): void
    {
        if ($this->matches === []) {
            return;
        }

        $this->highlight = max(0, min(count($this->matches) - 1, $this->highlight + $by));
    }

    public function updatedCustomerSearch(): void
    {
        $this->customerMatches = app(CustomerSearch::class)($this->customerSearch)
            ->map(fn (BusinessPartner $customer): array => [
                'id' => $customer->getKey(),
                'label' => "{$customer->code} — {$customer->name}",
            ])
            ->values()
            ->all();
    }

    /** Enter in the customer box with one match takes it, as with products. */
    public function chooseCustomer(?string $customerId = null): void
    {
        $customerId ??= count($this->customerMatches) === 1 ? $this->customerMatches[0]['id'] : null;

        if ($customerId === null) {
            return;
        }

        $this->customerId = $customerId;
        $this->customerSearch = collect($this->customerMatches)->firstWhere('id', $customerId)['label'] ?? $this->customerSearch;
        $this->customerMatches = [];
        $this->refreshCredit();
        $this->dispatch('focus-search');
    }

    public function choose(string $productId): void
    {
        $this->selectedProductId = $productId;
        $match = collect($this->matches)->firstWhere('id', $productId);
        $this->selectedUom = $match['uom'] ?? null;
        $this->search = $match['label'] ?? $this->search;
        $this->matches = [];
        $this->dispatch('focus-quantity');
    }

    /**
     * The units this product can be sold in.
     *
     * The same line sells by piece, pack and carton, and the difference
     * between five cartons and five pieces is twenty-four times the order. A
     * default with no way to change it is a mistake waiting to be made.
     *
     * @return array<string, string>
     */
    public function unitOptions(): array
    {
        $productId = $this->selectedProductId ?? ($this->matches[$this->highlight]['id'] ?? null);

        if ($productId === null) {
            return [];
        }

        $product = Product::find($productId);

        if ($product === null) {
            return [];
        }

        $units = [$product->base_uom => $product->base_uom];

        foreach ($product->productUoms as $unit) {
            $units[$unit->uom] = sprintf('%s (%s %s)', $unit->uom, rtrim(rtrim((string) $unit->qty_in_base, '0'), '.'), $product->base_uom);
        }

        return $units;
    }

    /**
     * Enter in the search box. With one match it takes it without asking —
     * the whole point is not to reach for the mouse.
     */
    public function addLine(): void
    {
        $productId = $this->selectedProductId
            ?? ($this->matches[$this->highlight]['id'] ?? null);

        // Say why nothing happened. Pressing Enter and getting silence is
        // indistinguishable from the screen being broken.
        if ($this->customerId === null) {
            Notification::make()->title('Choose a customer first')->warning()->send();

            return;
        }

        if ($productId === null) {
            Notification::make()
                ->title($this->search === '' ? 'Type a product code or name' : "Nothing matches “{$this->search}”")
                ->body($this->search === '' ? null : 'Check the code, or add the product under Catalogue.')
                ->warning()
                ->send();

            return;
        }

        $product = Product::find($productId);
        $customer = BusinessPartner::find($this->customerId);
        $uom = $this->selectedUom
            ?? $product->productUoms->firstWhere('default_for_sale', true)?->uom
            ?? $product->base_uom;

        try {
            $quote = app(PriceResolver::class)->quote($product, $uom, $this->quantity, $customer);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->lines[] = [
            'product_id' => $productId,
            'sku' => $product->sku,
            'name' => $product->name,
            'uom' => $uom,
            'qty' => $this->quantity,
            'unit_price' => (string) $quote->unitPrice,
            'line_total' => (string) $quote->lineTotal,
        ];

        // Reset for the next line and hand the cursor back.
        $this->search = '';
        $this->quantity = '1';
        $this->matches = [];
        $this->highlight = 0;
        $this->selectedProductId = null;
        $this->selectedUom = null;
        $this->dispatch('focus-search');
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Re-price a line whose quantity changed.
     *
     * Re-quoted rather than multiplied out, because the volume bands and the
     * discount rules both turn on quantity — five cartons and fifty are not
     * the same price per carton.
     */
    public function updatedLines(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field !== 'qty' || ! isset($this->lines[(int) $index])) {
            return;
        }

        $line = $this->lines[(int) $index];
        $product = Product::find($line['product_id']);
        $customer = $this->customerId === null ? null : BusinessPartner::find($this->customerId);

        if ($product === null || $customer === null || ! is_numeric($line['qty']) || (float) $line['qty'] <= 0) {
            return;
        }

        try {
            $quote = app(PriceResolver::class)->quote($product, $line['uom'], $line['qty'], $customer);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->lines[(int) $index]['unit_price'] = (string) $quote->unitPrice;
        $this->lines[(int) $index]['line_total'] = (string) $quote->lineTotal;
    }

    /**
     * Where this order leaves the customer's credit (F-30).
     *
     * Shown while the order is being typed, not after saving it. An admin who
     * only learns about a hold once the order is on the screen has already
     * spent the two minutes.
     *
     * @return array{limit: string, owed: string, order: string, remaining: string, over: bool}|null
     */
    public function creditPosition(): ?array
    {
        if ($this->customerId === null) {
            return null;
        }

        $status = app(CreditCheck::class)(BusinessPartner::findOrFail($this->customerId));
        $order = BigDecimal::of($this->total());
        $remaining = $status->available()->minus($order);

        return [
            'limit' => (string) $status->limit,
            'owed' => (string) $status->outstanding,
            'order' => (string) $order,
            'remaining' => (string) $remaining,
            'over' => $remaining->isNegative() || $status->overdueInvoices > 0 || $status->blocked,
            'reason' => $status->explain($order),
        ];
    }

    public function copyPrevious(): void
    {
        if ($this->customerId === null) {
            return;
        }

        $customer = BusinessPartner::findOrFail($this->customerId);

        try {
            // The lines only. Raising an order here would burn a document
            // number for a button somebody pressed to see what was on it.
            $previous = app(CopyPreviousOrder::class)->linesFor($customer);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->warning()->send();

            return;
        }

        // Loaded into the working list rather than saved, so it can be edited
        // before it is committed — reorders are rarely identical. Prices are
        // re-quoted at today's list on the way in.
        $this->lines = [];

        foreach ($previous as $line) {
            $quote = app(PriceResolver::class)->quote($line['product'], $line['uom'], $line['qty'], $customer);

            $this->lines[] = [
                'product_id' => $line['product']->getKey(),
                'sku' => $line['product']->sku,
                'name' => $line['product']->name,
                'uom' => $line['uom'],
                'qty' => $line['qty'],
                'unit_price' => (string) $quote->unitPrice,
                'line_total' => (string) $quote->lineTotal,
            ];
        }

        $this->dispatch('focus-search');
    }

    public function total(): string
    {
        return (string) collect($this->lines)
            ->reduce(fn (BigDecimal $carry, array $line) => $carry->plus($line['line_total']), BigDecimal::zero())
            ->toScale(2);
    }

    public function save(): void
    {
        if ($this->customerId === null || $this->lines === []) {
            Notification::make()->title('Choose a customer and add at least one line')->warning()->send();

            return;
        }

        if ($this->warehouseId === null) {
            Notification::make()
                ->title('There is no warehouse to ship from')
                ->body('Add one under Warehouse before taking orders.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $customer = BusinessPartner::findOrFail($this->customerId);

        try {
            $order = app(DraftSalesOrder::class)(
                $customer,
                $this->warehouseId,
                array_map(fn (array $line): array => [
                    'product' => Product::find($line['product_id']),
                    'uom' => $line['uom'],
                    'qty' => $line['qty'],
                ], $this->lines),
            );

            $order = app(SubmitSalesOrder::class)($order);
        } catch (DomainException $exception) {
            Notification::make()->title('That is not allowed')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        if ($order->status === 'credit_hold') {
            Notification::make()
                ->title("Order {$order->document_number} is on credit hold")
                ->body($order->hold_detail)
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()->title("Order {$order->document_number} approved")->success()->send();
        }

        $this->lines = [];
    }

    public function updatedCustomerId(): void
    {
        $this->refreshCredit();
    }

    /** A new customer means a new order; the credit panel follows from it. */
    private function refreshCredit(): void
    {
        $this->lines = [];
    }
}
