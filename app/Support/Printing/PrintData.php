<?php

namespace App\Support\Printing;

use App\Domain\Finance\Models\InvoiceExchange;
use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * The values a layout can refer to.
 *
 * Flattened to arrays on purpose: a template should not be able to reach into
 * a model and trigger a query, or reach anything nobody meant to print.
 */
final class PrintData
{
    /** @return array<string, mixed> */
    public function for(Model $document): array
    {
        $company = CompanySetting::query()->first();

        return [
            'company' => [
                'name' => (string) ($company?->legal_name ?? ''),
                'tax_id' => (string) ($company?->tax_id ?? ''),
                'address' => (string) ($company?->address ?? ''),
            ],
            ...match (true) {
                $document instanceof SalesInvoice => $this->invoice($document),
                $document instanceof Delivery => $this->delivery($document),
                $document instanceof InvoiceExchange => $this->exchange($document),
                default => [],
            },
        ];
    }

    /** @return array<string, mixed> */
    private function invoice(SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'salesInvoiceLines.product']);

        return [
            'invoice' => [
                'number' => $invoice->document_number,
                'tax_number' => (string) ($invoice->tax_invoice_number ?? ''),
                'date' => $invoice->invoice_date->format('Y-m-d'),
                'due_date' => $invoice->due_date->format('Y-m-d'),
                'subtotal' => (string) $invoice->subtotal,
                'discount' => (string) $invoice->discount_amount,
                'vat' => (string) $invoice->vat_amount,
                'total' => (string) $invoice->total,
            ],
            'customer' => [
                'code' => $invoice->customer->code,
                'name' => $invoice->customer->name,
                'tax_id' => (string) ($invoice->customer->tax_id ?? ''),
            ],
            'lines' => $invoice->salesInvoiceLines->map(fn ($line): array => [
                'sku' => $line->product->sku,
                'name' => $line->product->name,
                'uom' => $line->uom,
                'qty' => (string) $line->qty,
                'unit_price' => (string) $line->unit_price,
                'line_total' => (string) $line->line_total,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(Delivery $delivery): array
    {
        $delivery->loadMissing(['customer', 'deliveryLines.product', 'deliveryLines.batch', 'driver']);

        return [
            'delivery' => [
                'number' => $delivery->document_number,
                'date' => $delivery->delivery_date->format('Y-m-d'),
                'driver' => (string) ($delivery->driver?->full_name ?? ''),
                'vehicle' => (string) ($delivery->vehicle_plate ?? ''),
            ],
            'customer' => [
                'code' => $delivery->customer->code,
                'name' => $delivery->customer->name,
            ],
            'lines' => $delivery->deliveryLines->map(fn ($line): array => [
                'sku' => $line->product->sku,
                'name' => $line->product->name,
                'batch' => (string) ($line->batch?->batch_number ?? ''),
                'qty' => (string) $line->qty_base,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function exchange(InvoiceExchange $exchange): array
    {
        $exchange->loadMissing(['customer', 'salesInvoices']);

        return [
            'exchange' => [
                'number' => $exchange->document_number,
                'date' => $exchange->exchange_date->format('Y-m-d'),
                'promised' => $exchange->promised_payment_date?->format('Y-m-d') ?? '',
                'total' => (string) $exchange->total_amount,
                'received_by' => (string) ($exchange->received_by ?? ''),
            ],
            'customer' => [
                'code' => $exchange->customer->code,
                'name' => $exchange->customer->name,
            ],
            'lines' => $exchange->salesInvoices->map(fn ($invoice): array => [
                'number' => $invoice->document_number,
                'date' => $invoice->invoice_date->format('Y-m-d'),
                'due_date' => $invoice->due_date->format('Y-m-d'),
                'total' => (string) $invoice->total,
            ])->all(),
        ];
    }
}
