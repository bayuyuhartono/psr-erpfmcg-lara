<?php

namespace App\Support\Import;

use App\Support\Import\Exceptions\DryRunComplete;
use App\Support\Import\Exceptions\ImportHasProblems;
use DomainException;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row as SheetRow;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Writer\XLSX\Writer;

/**
 * An all-or-nothing spreadsheet import (F-02).
 *
 * Partial imports are the thing to avoid here. A client onboarding 3,000
 * products who ends up with 1,800 of them and no reliable account of which is
 * worse off than one whose import failed outright — so every row is checked
 * before anything is written, and a single bad row saves nothing.
 *
 * `preview()` proves that by doing the real work inside a transaction and then
 * throwing it away. Shape checks alone cannot tell you a row will be accepted;
 * the domain rules decide that, and the only way to ask them is to try.
 */
abstract class SpreadsheetImporter
{
    /** @return array<string, string> column key => the heading written in the template */
    abstract public function columns(): array;

    /** @return array<string, string> one example row, so the template shows the expected shape */
    abstract public function example(): array;

    /**
     * Shape checks only — required, numeric, a code that resolves. Domain
     * rules are not restated here; they run for real during `persist()`.
     *
     * @return list<RowProblem>
     */
    abstract protected function check(ImportRow $row): array;

    /**
     * Write every row. Runs inside a transaction that is rolled back on any
     * problem, and thrown away entirely during a preview.
     *
     * @param  list<ImportRow>  $rows
     * @return int rows written
     */
    abstract protected function persist(array $rows): int;

    public function preview(string $path): ImportPreview
    {
        $rows = $this->read($path);
        $problems = $this->checkAll($rows);

        if ($problems === [] && $rows !== []) {
            $problems = $this->dryRun($rows);
        }

        return new ImportPreview(count($rows), $problems, array_slice($rows, 0, 20));
    }

    public function import(string $path): int
    {
        $rows = $this->read($path);

        if ($rows === []) {
            throw new ImportHasProblems([new RowProblem(1, null, 'The file has no rows below the heading.')]);
        }

        $problems = $this->checkAll($rows);

        if ($problems !== []) {
            throw new ImportHasProblems($problems);
        }

        return DB::transaction(fn (): int => $this->persist($rows));
    }

    /** Writes the blank template, headings and one example row. */
    public function writeTemplate(string $path): string
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(SheetRow::fromValues(array_values($this->columns())));
        $writer->addRow(SheetRow::fromValues(array_values($this->example())));
        $writer->close();

        return $path;
    }

    /**
     * Loop the rows, collecting a problem per row that the domain refuses
     * rather than stopping at the first.
     *
     * @param  list<ImportRow>  $rows
     * @param  callable(ImportRow): void  $write
     */
    protected function eachRow(array $rows, callable $write): int
    {
        $problems = [];
        $written = 0;

        foreach ($rows as $row) {
            try {
                $write($row);
                $written++;
            } catch (DomainException $exception) {
                $problems[] = new RowProblem($row->line, null, $exception->getMessage());
            }
        }

        if ($problems !== []) {
            throw new ImportHasProblems($problems);
        }

        return $written;
    }

    /** @return list<ImportRow> */
    protected function read(string $path): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $keys = array_keys($this->columns());
        $rows = [];
        $line = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $sheetRow) {
                $line++;

                // The first line is the heading the template wrote.
                if ($line === 1) {
                    continue;
                }

                $cells = array_map(
                    fn ($cell): string => trim((string) $cell->getValue()),
                    $sheetRow->getCells(),
                );

                $values = [];
                foreach ($keys as $index => $key) {
                    $values[$key] = $cells[$index] ?? '';
                }

                $row = new ImportRow($line, $values);

                // Trailing blank lines are how spreadsheets end, not an error.
                if (! $row->isEmpty()) {
                    $rows[] = $row;
                }
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return list<RowProblem>
     */
    private function checkAll(array $rows): array
    {
        return array_merge(...array_map(fn (ImportRow $row): array => $this->check($row), $rows)) ?: [];
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return list<RowProblem>
     */
    private function dryRun(array $rows): array
    {
        try {
            DB::transaction(function () use ($rows): void {
                $this->persist($rows);

                throw new DryRunComplete;
            });
        } catch (DryRunComplete) {
            return [];
        } catch (ImportHasProblems $exception) {
            return $exception->problems;
        }

        return [];
    }
}
