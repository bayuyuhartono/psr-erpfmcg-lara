<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starting layouts, meant to be edited.
 *
 * The dot matrix ones are 80 columns of fixed-width text, which is what a
 * continuous-form printer takes. Column positions come from `pad` and `rpad`
 * so nothing drifts — a column one character out puts the whole run out of
 * alignment with pre-printed stationery, which is the failure the PRD warns
 * about.
 *
 * These are guesses until someone has run them through the client's actual
 * printer (8.5). They exist so that test is possible, not so it is skipped.
 */
final class PrintTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            $key = [
                'document_type' => $template['document_type'],
                'format' => $template['format'],
                'name' => $template['name'],
            ];
            $existing = DB::table('print_templates')->where($key)->first();

            DB::table('print_templates')->updateOrInsert($key, [
                'id' => $existing?->id ?? (string) Str::uuid7(),
                'width_chars' => $template['width_chars'],
                'lines_per_page' => $template['lines_per_page'] ?? 66,
                'copies' => $template['copies'],
                'body' => $template['body'],
                'active' => true,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function templates(): array
    {
        return [
            [
                'document_type' => 'sales_invoice', 'format' => 'dotmatrix',
                'name' => 'Continuous form 80 col', 'width_chars' => 80, 'copies' => 3,
                'body' => <<<'TXT'
{{ company.name | upper }}
{{ company.address }}
NPWP: {{ company.tax_id }}

{{ copy }}
                              F A K T U R                              
--------------------------------------------------------------------------------
No     : {{ invoice.number | pad:22 }} Tanggal   : {{ invoice.date | date }}
Pajak  : {{ invoice.tax_number | pad:22 }} Jatuh tempo: {{ invoice.due_date | date }}
Kepada : {{ customer.code | pad:10 }} {{ customer.name }}
--------------------------------------------------------------------------------
KODE       NAMA BARANG              SAT     QTY      HARGA        JUMLAH
--------------------------------------------------------------------------------
{{# lines }}{{ sku | pad:10 }} {{ name | pad:24 }} {{ uom | pad:4 }} {{ qty | rpad:7 }} {{ unit_price | money | rpad:12 }} {{ line_total | money | rpad:14 }}
{{/ lines }}--------------------------------------------------------------------------------
{{ invoice.subtotal | money | rpad:78 }}
Potongan {{ invoice.discount | money | rpad:69 }}
PPN {{ invoice.vat | money | rpad:74 }}
JUMLAH {{ invoice.total | money | rpad:71 }}


Diterima oleh,                                        Hormat kami,



(............................)                  (............................)
TXT,
            ],
            [
                'document_type' => 'sales_invoice', 'format' => 'a4',
                'name' => 'A4 invoice', 'width_chars' => 0, 'copies' => 1,
                'body' => <<<'HTML'
<article class="invoice">
  <header>
    <h1>{{ company.name }}</h1>
    <p>{{ company.address }}<br>NPWP {{ company.tax_id }}</p>
  </header>
  <section class="meta">
    <div><strong>Faktur</strong> {{ invoice.number }}</div>
    <div><strong>Tanggal</strong> {{ invoice.date | date }}</div>
    <div><strong>Jatuh tempo</strong> {{ invoice.due_date | date }}</div>
    <div><strong>Kepada</strong> {{ customer.name }} ({{ customer.code }})</div>
  </section>
  <table>
    <thead><tr><th>Kode</th><th>Nama barang</th><th>Sat</th><th class="n">Qty</th><th class="n">Harga</th><th class="n">Jumlah</th></tr></thead>
    <tbody>
      {{# lines }}<tr><td>{{ sku }}</td><td>{{ name }}</td><td>{{ uom }}</td><td class="n">{{ qty | qty }}</td><td class="n">{{ unit_price | money }}</td><td class="n">{{ line_total | money }}</td></tr>
      {{/ lines }}
    </tbody>
    <tfoot>
      <tr><td colspan="5">Subtotal</td><td class="n">{{ invoice.subtotal | money }}</td></tr>
      <tr><td colspan="5">Potongan</td><td class="n">{{ invoice.discount | money }}</td></tr>
      <tr><td colspan="5">PPN</td><td class="n">{{ invoice.vat | money }}</td></tr>
      <tr class="total"><td colspan="5">Jumlah</td><td class="n">{{ invoice.total | money }}</td></tr>
    </tfoot>
  </table>
</article>
HTML,
            ],
            [
                'document_type' => 'delivery', 'format' => 'dotmatrix',
                'name' => 'Surat jalan 80 col', 'width_chars' => 80, 'copies' => 3,
                'body' => <<<'TXT'
{{ company.name | upper }}
                            S U R A T   J A L A N
{{ copy }}
--------------------------------------------------------------------------------
No      : {{ delivery.number | pad:20 }} Tanggal : {{ delivery.date | date }}
Kepada  : {{ customer.code | pad:10 }} {{ customer.name }}
Sopir   : {{ delivery.driver | pad:20 }} Kendaraan: {{ delivery.vehicle }}
--------------------------------------------------------------------------------
KODE       NAMA BARANG                   BATCH            QTY
--------------------------------------------------------------------------------
{{# lines }}{{ sku | pad:10 }} {{ name | pad:29 }} {{ batch | pad:16 }} {{ qty | rpad:10 }}
{{/ lines }}--------------------------------------------------------------------------------

Barang diterima dalam keadaan baik dan cukup.


Penerima,                     Sopir,                    Gudang,



(..................)          (..................)      (..................)
TXT,
            ],
            [
                'document_type' => 'invoice_exchange', 'format' => 'dotmatrix',
                'name' => 'Tanda terima faktur 80 col', 'width_chars' => 80, 'copies' => 2,
                'body' => <<<'TXT'
{{ company.name | upper }}
                     T A N D A   T E R I M A   F A K T U R
{{ copy }}
--------------------------------------------------------------------------------
No       : {{ exchange.number | pad:20 }} Tanggal tukar : {{ exchange.date | date }}
Kepada   : {{ customer.code | pad:10 }} {{ customer.name }}
Janji bayar : {{ exchange.promised | date }}
--------------------------------------------------------------------------------
NO FAKTUR            TANGGAL      JATUH TEMPO           JUMLAH
--------------------------------------------------------------------------------
{{# lines }}{{ number | pad:20 }} {{ date | date | pad:12 }} {{ due_date | date | pad:12 }} {{ total | money | rpad:18 }}
{{/ lines }}--------------------------------------------------------------------------------
TOTAL {{ exchange.total | money | rpad:72 }}

Faktur di atas telah diterima untuk diproses pembayarannya.


Diterima oleh,                                        Diserahkan oleh,



(............................)                  (............................)
TXT,
            ],
        ];
    }
}
