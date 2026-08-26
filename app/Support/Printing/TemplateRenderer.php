<?php

namespace App\Support\Printing;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Fills a stored layout with a document's data.
 *
 * Deliberately not Blade. These templates are edited by whoever installs the
 * system at a client, and a Blade template from the database is arbitrary PHP
 * execution by anyone with access to the layout screen. What is supported is
 * exactly what a print layout needs and nothing else:
 *
 *   {{ invoice.number }}             a value, dotted for nesting
 *   {{ invoice.total | money }}      a formatter
 *   {{ total | money | rpad:14 }}    formatters chain, left to right
 *   {{ customer.name | pad:30 }}     fixed-width columns, for continuous form
 *   {{# lines }} … {{/ lines }}      one block repeated per row
 *
 * Fixed-width padding is the part that matters for dot matrix: a column that
 * drifts by one character puts the whole invoice out of alignment with
 * pre-printed stationery.
 */
final class TemplateRenderer
{
    /** @param  array<string, mixed>  $data */
    public function render(string $template, array $data): string
    {
        $rendered = $this->renderSections($template, $data);

        return $this->renderPlaceholders($rendered, $data);
    }

    /** @param  array<string, mixed>  $data */
    private function renderSections(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{#\s*([\w.]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/s',
            function (array $match) use ($data): string {
                $rows = $this->lookup($data, $match[1]);

                if (! is_iterable($rows)) {
                    return '';
                }

                $out = '';

                foreach ($rows as $index => $row) {
                    $context = is_array($row) ? $row : (array) $row;
                    $context['_index'] = $index + 1;
                    $out .= $this->renderPlaceholders($match[2], $context);
                }

                return $out;
            },
            $template,
        ) ?? $template;
    }

    /** @param  array<string, mixed>  $data */
    private function renderPlaceholders(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*((?:\|\s*[\w:.\-]+\s*)*)\}\}/',
            function (array $match) use ($data): string {
                $value = $this->lookup($data, $match[1]);

                // Formatters chain, left to right: format the number, then pad
                // the result. Honouring only the first leaves a raw
                // placeholder on the paper, which is worse than an error.
                foreach ($this->formatters($match[2] ?? '') as $formatter) {
                    $value = $this->format($value, $formatter);
                }

                return $this->format($value, null);
            },
            $template,
        ) ?? $template;
    }

    /** @return list<string> */
    private function formatters(string $chain): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $chain))));
    }

    /** @param  array<string, mixed>  $data */
    private function lookup(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];

                continue;
            }

            return null;
        }

        return $data;
    }

    private function format(mixed $value, ?string $formatter): string
    {
        if ($value === null) {
            $value = '';
        }

        if ($formatter === null) {
            return (string) $value;
        }

        [$name, $argument] = array_pad(explode(':', $formatter, 2), 2, null);

        return match ($name) {
            // Indonesian convention: 1.234.567,89
            'money' => number_format((float) (string) BigDecimal::of($value === '' ? 0 : $value), 2, ',', '.'),
            'qty' => rtrim(rtrim(number_format((float) (string) $value, 4, ',', '.'), '0'), ','),
            'pad' => str_pad(mb_substr((string) $value, 0, (int) $argument), (int) $argument),
            // Right-aligned, which is what a money column needs.
            'rpad' => str_pad(mb_substr((string) $value, 0, (int) $argument), (int) $argument, ' ', STR_PAD_LEFT),
            'upper' => mb_strtoupper((string) $value),
            'date' => $value === '' ? '' : CarbonImmutable::parse((string) $value)->format($argument ?: 'd/m/Y'),
            default => (string) $value,
        };
    }
}
