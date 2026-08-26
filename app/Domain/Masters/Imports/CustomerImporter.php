<?php

namespace App\Domain\Masters\Imports;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\CustomerTier;
use App\Support\Import\ImportRow;
use App\Support\Import\RowProblem;
use App\Support\Import\SpreadsheetImporter;

/**
 * Customers, with the terms that drive credit hold (F-30) and the invoice
 * exchange schedule (F-50).
 */
final class CustomerImporter extends SpreadsheetImporter
{
    public function columns(): array
    {
        return [
            'code' => 'Code',
            'name' => 'Name',
            'tax_id' => 'NPWP',
            'contact_person' => 'Contact',
            'phone' => 'Phone',
            'tier' => 'Tier',
            'credit_limit' => 'Credit limit',
            'payment_terms_days' => 'Payment terms (days)',
            'invoice_exchange_days' => 'Exchange days',
            'payment_days' => 'Payment days',
        ];
    }

    public function example(): array
    {
        return [
            'code' => 'C-00123', 'name' => 'Toko Berkah', 'tax_id' => '',
            'contact_person' => 'Ibu Sari', 'phone' => '0812xxxx',
            'tier' => 'Wholesale', 'credit_limit' => '25000000',
            'payment_terms_days' => '30', 'invoice_exchange_days' => '1, 15',
            'payment_days' => '10, 25',
        ];
    }

    protected function check(ImportRow $row): array
    {
        $problems = [];

        foreach (['code', 'name'] as $required) {
            if ($row->get($required) === null) {
                $problems[] = new RowProblem($row->line, $this->columns()[$required], 'is required');
            }
        }

        foreach (['credit_limit', 'payment_terms_days'] as $numeric) {
            $value = $row->get($numeric);
            if ($value !== null && ! is_numeric($value)) {
                $problems[] = new RowProblem($row->line, $this->columns()[$numeric], sprintf('"%s" is not a number', $value));
            }
        }

        if (($tier = $row->get('tier')) !== null && ! CustomerTier::where('name', $tier)->exists()) {
            $problems[] = new RowProblem($row->line, 'Tier', sprintf('there is no tier called "%s"', $tier));
        }

        foreach (['invoice_exchange_days', 'payment_days'] as $days) {
            foreach ($row->list($days) as $day) {
                if (! ctype_digit($day)) {
                    $problems[] = new RowProblem($row->line, $this->columns()[$days], sprintf('"%s" is not a day of the month', $day));
                }
            }
        }

        return $problems;
    }

    protected function persist(array $rows): int
    {
        return $this->eachRow($rows, function (ImportRow $row): void {
            BusinessPartner::updateOrCreate(['code' => $row->get('code')], [
                'name' => $row->get('name'),
                'tax_id' => $row->get('tax_id'),
                'contact_person' => $row->get('contact_person'),
                'phone' => $row->get('phone'),
                'is_customer' => true,
                'tier_id' => CustomerTier::where('name', $row->get('tier'))->value('id'),
                'credit_limit' => $row->get('credit_limit') ?? '0',
                'payment_terms_days' => $row->get('payment_terms_days') ?? 0,
                'invoice_exchange_days' => array_map('intval', $row->list('invoice_exchange_days')) ?: null,
                'payment_days' => array_map('intval', $row->list('payment_days')) ?: null,
                'active' => true,
            ]);
        });
    }
}
