<?php

namespace App\Support\Import;

/**
 * One line of a spreadsheet, keeping the line number it came from so a problem
 * can be reported as "row 412" rather than "somewhere in your file".
 */
final readonly class ImportRow
{
    /** @param  array<string, string>  $values  column key => trimmed cell value */
    public function __construct(
        public int $line,
        public array $values,
    ) {}

    public function get(string $column): ?string
    {
        $value = $this->values[$column] ?? '';

        return $value === '' ? null : $value;
    }

    public function boolean(string $column, bool $default = false): bool
    {
        $value = $this->get($column);

        return $value === null
            ? $default
            : in_array(mb_strtolower($value), ['1', 'y', 'yes', 'true', 'ya'], true);
    }

    /** @return list<string> */
    public function list(string $column): array
    {
        $value = $this->get($column);

        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value)), fn ($p) => $p !== ''));
    }

    public function isEmpty(): bool
    {
        return array_filter($this->values, fn (string $value): bool => $value !== '') === [];
    }
}
