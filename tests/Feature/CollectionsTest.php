<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Finance\Actions\AssembleInvoiceExchange;
use App\Domain\Finance\Actions\ReceivePostdatedCheque;
use App\Domain\Finance\Actions\RecordCustomerPayment;
use App\Domain\Finance\Actions\RecordSupplierPayment;
use App\Domain\Finance\Actions\ResolvePostdatedCheque;
use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\NothingToExchange;
use App\Domain\Finance\Models\PostdatedCheque;
use App\Domain\Finance\Services\ExchangeSchedule;
use App\Domain\Finance\Services\ReceivablesAging;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Salesperson;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Sales\Models\SalesInvoice;
use App\Support\Documents\Exceptions\IllegalTransition;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private BusinessPartner $customer;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
        $this->today = new CarbonImmutable('2026-08-26');
        $this->customer = BusinessPartner::factory()->create([
            'code' => 'C-00123', 'name' => 'Toko Berkah',
            'invoice_exchange_days' => [1, 15, 26], 'payment_days' => [10, 25],
            'credit_limit' => '50000000', 'payment_terms_days' => 30,
        ]);
    }

    // 6.6 — allocation

    public function test_one_payment_closes_several_invoices(): void
    {
        $first = $this->invoice('1000000');
        $second = $this->invoice('600000');

        app(RecordCustomerPayment::class)($this->customer, '1600000', [
            $first->id => '1000000', $second->id => '600000',
        ], paidOn: $this->today);

        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame('1600000.00', (string) JournalLine::whereRelation('account', 'code', '1101')->value('debit'));
    }

    public function test_one_invoice_can_be_paid_in_instalments(): void
    {
        $invoice = $this->invoice('1000000');

        app(RecordCustomerPayment::class)($this->customer, '400000', [$invoice->id => '400000'], paidOn: $this->today);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('400000.00', (string) $invoice->fresh()->paid_amount);

        app(RecordCustomerPayment::class)($this->customer, '600000', [$invoice->id => '600000'], paidOn: $this->today);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_the_remainder_is_held_as_an_advance_not_as_income(): void
    {
        $invoice = $this->invoice('1000000');

        app(RecordCustomerPayment::class)($this->customer, '1500000', [$invoice->id => '1000000'], paidOn: $this->today);

        // The extra 500,000 is still the customer's money.
        $this->assertSame('500000.00', (string) JournalLine::whereRelation('account', 'code', '2104')->value('credit'));
        $this->assertSame('1000000.00', (string) JournalLine::whereRelation('account', 'code', '1102')->value('credit'));
    }

    public function test_money_cannot_be_applied_twice(): void
    {
        $invoice = $this->invoice('1000000');

        $this->expectException(InvalidPayment::class);
        $this->expectExceptionMessageMatches('/cannot be applied twice/');

        app(RecordCustomerPayment::class)($this->customer, '500000', [$invoice->id => '900000'], paidOn: $this->today);
    }

    public function test_an_invoice_cannot_be_overpaid_into_itself(): void
    {
        $invoice = $this->invoice('1000000');

        $this->expectException(InvalidPayment::class);
        $this->expectExceptionMessageMatches('/is only owed/');

        app(RecordCustomerPayment::class)($this->customer, '1500000', [$invoice->id => '1500000'], paidOn: $this->today);
    }

    // 6.4 / 6.5 — cheques

    public function test_a_cheque_is_not_cash_until_it_clears(): void
    {
        $invoice = $this->invoice('1000000');

        $cheque = app(ReceivePostdatedCheque::class)(
            $this->customer, 'CHQ-001', '1000000',
            new CarbonImmutable('2026-09-25'), [$invoice->id => '1000000'],
            receivedOn: $this->today,
        );

        $this->assertSame('held', $cheque->status);
        // The invoice reads as settled to the outlet, but no cash has moved.
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('1000000.00', (string) JournalLine::whereRelation('account', 'code', '1105')->value('debit'));
        $this->assertSame(0, JournalLine::whereRelation('account', 'code', '1101')->count());
    }

    public function test_clearing_a_cheque_is_when_it_becomes_money(): void
    {
        $cheque = $this->cheque('1000000');

        app(ResolvePostdatedCheque::class)->clear($cheque, new CarbonImmutable('2026-09-25'));

        $this->assertSame('cleared', $cheque->fresh()->status);
        $this->assertSame('1000000.00', (string) JournalLine::whereRelation('account', 'code', '1101')->value('debit'));
        // 1105 nets to zero: in when received, out when cleared.
        $this->assertSame('0.00', (string) $this->netOf('1105'));
    }

    public function test_a_bounced_cheque_reopens_the_invoice_and_the_debt(): void
    {
        $invoice = $this->invoice('1000000');
        $cheque = app(ReceivePostdatedCheque::class)(
            $this->customer, 'CHQ-002', '1000000',
            new CarbonImmutable('2026-09-25'), [$invoice->id => '1000000'],
            receivedOn: $this->today,
        );

        app(ResolvePostdatedCheque::class)->bounce(
            $cheque, 'Insufficient funds', [$invoice->id], new CarbonImmutable('2026-09-26'),
        );

        $this->assertSame('bounced', $cheque->fresh()->status);
        $this->assertSame('Insufficient funds', $cheque->fresh()->bounce_reason);

        // Nothing was ever paid, so the invoice owes again.
        $this->assertSame('posted', $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->paid_amount);
        // The debt is back with the customer and off the cheque.
        $this->assertSame('0.00', (string) $this->netOf('1105'));
        $this->assertSame('0.00', (string) $this->netOf('1102'));
    }

    public function test_a_cleared_cheque_is_final(): void
    {
        $cheque = $this->cheque('500000');
        app(ResolvePostdatedCheque::class)->clear($cheque, $this->today);

        $this->expectException(IllegalTransition::class);
        app(ResolvePostdatedCheque::class)->bounce($cheque->fresh(), 'too late');
    }

    // 6.1 / 6.2 / 6.3 — exchange

    public function test_the_schedule_knows_whose_day_it_is(): void
    {
        BusinessPartner::factory()->create(['invoice_exchange_days' => [5]]);

        $due = app(ExchangeSchedule::class)->dueOn($this->today);   // the 26th

        $this->assertCount(1, $due);
        $this->assertSame('C-00123', $due->first()->code);
    }

    public function test_an_exchange_gathers_the_invoices_and_promises_a_payment_day(): void
    {
        $this->invoice('1000000');
        $this->invoice('250000');

        $exchange = app(AssembleInvoiceExchange::class)($this->customer, $this->today);

        $this->assertSame('1250000.00', (string) $exchange->total_amount);
        $this->assertSame(2, $exchange->salesInvoices()->count());
        // Exchanged on the 26th, and the next payment day is the 10th.
        $this->assertSame('2026-09-10', $exchange->promised_payment_date->format('Y-m-d'));
    }

    public function test_handing_over_marks_the_invoices_as_being_with_the_customer(): void
    {
        $invoice = $this->invoice('1000000');
        $exchange = app(AssembleInvoiceExchange::class)($this->customer, $this->today);

        app(AssembleInvoiceExchange::class)->handOver($exchange, 'Ibu Sari');

        $this->assertSame('handed_over', $exchange->fresh()->status);
        $this->assertSame('Ibu Sari', $exchange->fresh()->received_by);
        $this->assertSame('exchanged', $invoice->fresh()->status);
    }

    public function test_an_exchange_with_nothing_to_hand_over_is_refused(): void
    {
        $this->expectException(NothingToExchange::class);

        app(AssembleInvoiceExchange::class)($this->customer, $this->today);
    }

    public function test_the_day_list_names_overdue_promises(): void
    {
        // Invoiced in June, exchanged in July, still unpaid in August.
        $this->invoice('1000000', dueOn: '2026-07-31', issuedOn: '2026-06-30');
        $exchange = app(AssembleInvoiceExchange::class)($this->customer, new CarbonImmutable('2026-07-26'));
        app(AssembleInvoiceExchange::class)->handOver($exchange, 'Ibu Sari');

        $this->artisan('collections:today', ['--on' => '2026-08-26'])
            ->expectsOutputToContain('promised payment(s) overdue')
            ->assertExitCode(0);
    }

    // 6.7 — aging

    public function test_aging_splits_by_how_late_and_by_who_sold_it(): void
    {
        $rep = Salesperson::create(['code' => 'S-01', 'name' => 'Budi']);
        $this->customer->update(['salesperson_id' => $rep->id]);

        $this->invoice('1000000', dueOn: '2026-09-30');   // not yet due
        $this->invoice('500000', dueOn: '2026-08-10');    // 16 days late
        $this->invoice('300000', dueOn: '2026-06-01');    // 86 days late
        $this->invoice('200000', dueOn: '2026-01-01');    // long gone

        $aging = app(ReceivablesAging::class)($this->today);

        $this->assertSame('1000000.00', $aging['buckets']['Not yet due']);
        $this->assertSame('500000.00', $aging['buckets']['1–30 days']);
        $this->assertSame('300000.00', $aging['buckets']['61–90 days']);
        $this->assertSame('200000.00', $aging['buckets']['Over 90 days']);
        $this->assertSame('2000000.00', $aging['total']);
        $this->assertSame('Toko Berkah', $aging['by_customer'][0]['name']);
        $this->assertSame('Budi', $aging['by_salesperson'][0]['name']);
    }

    // 6.8 — payables

    public function test_paying_a_supplier_clears_the_payable(): void
    {
        $supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $invoice = SupplierInvoice::create([
            'document_number' => 'SI-'.Str::upper(Str::random(6)),
            'supplier_id' => $supplier->id, 'invoice_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'subtotal' => '900000', 'vat_amount' => '99000', 'total' => '999000', 'status' => 'posted',
        ]);

        app(RecordSupplierPayment::class)($supplier, [$invoice->id => '999000'], paidOn: $this->today);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('999000.00', (string) JournalLine::whereRelation('account', 'code', '2101')->value('debit'));
        $this->assertSame('999000.00', (string) JournalLine::whereRelation('account', 'code', '1101')->value('credit'));
    }

    private function netOf(string $code): BigDecimal
    {
        return JournalLine::whereRelation('account', 'code', $code)->get()
            ->reduce(fn (BigDecimal $carry, $line) => $carry->plus($line->debit)->minus($line->credit), BigDecimal::zero())
            ->toScale(2);
    }

    private function cheque(string $amount): PostdatedCheque
    {
        return app(ReceivePostdatedCheque::class)(
            $this->customer, 'CHQ-'.Str::upper(Str::random(4)), $amount,
            new CarbonImmutable('2026-09-25'), receivedOn: $this->today,
        );
    }

    private function invoice(string $total, string $dueOn = '2026-09-25', string $issuedOn = '2026-08-01'): SalesInvoice
    {
        return SalesInvoice::create([
            'document_number' => 'INV-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'invoice_date' => $issuedOn,
            'due_date' => $dueOn,
            'subtotal' => $total, 'total' => $total, 'status' => 'posted',
        ]);
    }
}
