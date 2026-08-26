<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
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

    protected static ?string $title = 'Order entry';

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

    public ?string $creditNote = null;

    public function mount(): void
    {
        $this->warehouseId = Warehouse::query()->where('active', true)->value('id');
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

        $this->matches = app(ProductSearch::class)($this->search, 8)
            ->map(fn (Product $product): array => [
                'id' => $product->getKey(),
                'label' => "{$product->sku} — {$product->name}",
                'uom' => $product->productUoms->firstWhere('default_for_sale', true)?->uom ?? $product->base_uom,
            ])
            ->values()
            ->all();
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
    }

    /**
     * Enter in the search box. With one match it takes it without asking —
     * the whole point is not to reach for the mouse.
     */
    public function addLine(): void
    {
        $productId = $this->selectedProductId
            ?? ($this->matches[$this->highlight]['id'] ?? null);

        if ($productId === null || $this->customerId === null) {
            return;
        }

        $product = Product::find($productId);
        $customer = BusinessPartner::find($this->customerId);
        $uom = $this->selectedUom ?? $product->base_uom;

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
        $this->creditNote = null;
    }

    public function updatedCustomerId(): void
    {
        $this->refreshCredit();
    }

    /** Shown as soon as a customer is chosen, before anything is typed. */
    private function refreshCredit(): void
    {
        $this->lines = [];

        if ($this->customerId === null) {
            $this->creditNote = null;

            return;
        }

        $status = app(CreditCheck::class)(BusinessPartner::findOrFail($this->customerId));
        $this->creditNote = sprintf('Limit %s · owes %s · available %s', $status->limit, $status->outstanding, $status->available());
    }
}
