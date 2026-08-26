<?php

namespace App\Support\Printing;

use App\Domain\Finance\Models\InvoiceExchange;
use App\Domain\Masters\Models\PrintTemplate;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\SalesInvoice;
use App\Support\Printing\Exceptions\NoTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a document into something a printer can take.
 *
 * Two formats, for two machines. `dotmatrix` is plain text on continuous form,
 * repeated for however many parts the paper has — the invoice a distributor
 * actually hands over at the exchange desk. `a4` is HTML for a laser printer
 * or a browser's print dialog.
 *
 * The layout comes from `print_templates`, so a client whose stationery is
 * different edits a row.
 */
final class DocumentPrinter
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly PrintData $data,
    ) {}

    /** @return array{body: string, copies: int, format: string, width: int} */
    public function print(Model $document, string $format = 'dotmatrix'): array
    {
        $type = $this->typeOf($document);
        $template = PrintTemplate::query()
            ->where('document_type', $type)
            ->where('format', $format)
            ->where('active', true)
            ->orderBy('name')
            ->first();

        if ($template === null) {
            throw new NoTemplate(sprintf(
                'No active %s layout for a %s. Add one under print templates — layouts are configuration, not code.',
                $format,
                str_replace('_', ' ', $type),
            ));
        }

        $data = $this->data->for($document);

        return [
            'body' => $format === 'dotmatrix'
                ? $this->repeat($template, $data)
                : $this->renderer->render($template->body, $data),
            'copies' => (int) $template->copies,
            'format' => $format,
            'width' => (int) $template->width_chars,
        ];
    }

    /**
     * Continuous form is fed once and printed through: the copies are parts of
     * the same paper, separated by a form feed rather than sent as separate
     * jobs.
     */
    /** @param  array<string, mixed>  $data */
    private function repeat(PrintTemplate $template, array $data): string
    {
        $labels = ['ORIGINAL', 'COPY', 'FILE COPY'];
        $pages = [];

        foreach (range(1, max(1, (int) $template->copies)) as $copy) {
            // Rendered once per copy with the label in the data, rather than
            // rendered once and patched afterwards: a placeholder the renderer
            // has already blanked cannot be filled in later.
            $pages[] = $this->renderer->render($template->body, [
                ...$data,
                'copy' => $labels[$copy - 1] ?? 'COPY '.$copy,
            ]);
        }

        return implode("\f", $pages);
    }

    private function typeOf(Model $document): string
    {
        return match (true) {
            $document instanceof SalesInvoice => 'sales_invoice',
            $document instanceof Delivery => 'delivery',
            $document instanceof InvoiceExchange => 'invoice_exchange',
            default => throw new NoTemplate(sprintf('%s is not a printable document.', class_basename($document))),
        };
    }
}
