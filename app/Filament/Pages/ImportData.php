<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\Imports\OpeningStockImporter;
use App\Domain\Masters\Imports\CustomerImporter;
use App\Domain\Masters\Imports\ProductImporter;
use App\Support\Import\Exceptions\ImportHasProblems;
use App\Support\Import\ImportPreview;
use App\Support\Import\RowProblem;
use App\Support\Import\SpreadsheetImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk import for onboarding (F-02).
 *
 * Two steps on purpose: check, then commit. The check is a real import that is
 * thrown away, so the problem list it produces is the one the commit would
 * produce — not a weaker set of guesses about it.
 */
class ImportData extends Page
{
    protected string $view = 'filament.pages.import-data';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Import from Excel';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The preview, flattened.
     *
     * Livewire can only carry primitives and arrays across a request, so the
     * page translates `ImportPreview` rather than holding it — the same reason
     * domain objects do not leak into Filament classes anywhere else.
     *
     * @var list<string>
     */
    public array $problems = [];

    /** @var list<array{line: int, values: array<string, string>}> */
    public array $sample = [];

    public int $rowCount = 0;

    public bool $checked = false;

    /** @var class-string<SpreadsheetImporter>[] */
    private const IMPORTERS = [
        'products' => ProductImporter::class,
        'customers' => CustomerImporter::class,
        'opening_stock' => OpeningStockImporter::class,
    ];

    public function mount(): void
    {
        $this->form->fill(['type' => 'products']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('type')
                    ->label('What are you importing')
                    ->options([
                        'products' => 'Products',
                        'customers' => 'Customers',
                        'opening_stock' => 'Opening stock',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->reset(['checked', 'problems', 'sample', 'rowCount']))
                    ->helperText('Opening stock needs its products imported first.'),
                FileUpload::make('file')
                    ->label('Spreadsheet')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                    ])
                    ->required()
                    ->afterStateUpdated(fn () => $this->reset(['checked', 'problems', 'sample', 'rowCount'])),
            ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): BinaryFileResponse => $this->downloadTemplate()),
        ];
    }

    public function check(): void
    {
        $path = $this->uploadedPath();

        if ($path === null) {
            return;
        }

        $this->show($this->importer()->preview($path));

        if ($this->isImportable()) {
            Notification::make()
                ->title(sprintf('%d row(s) ready to import', $this->rowCount))
                ->success()
                ->send();
        }
    }

    public function commit(): void
    {
        $path = $this->uploadedPath();

        if ($path === null) {
            return;
        }

        try {
            $written = $this->importer()->import($path);
        } catch (ImportHasProblems $exception) {
            $this->show(new ImportPreview(0, $exception->problems, []));

            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->reset();
        $this->form->fill(['type' => $this->data['type'] ?? 'products']);

        Notification::make()->title(sprintf('%d row(s) imported', $written))->success()->send();
    }

    public function isImportable(): bool
    {
        return $this->checked && $this->rowCount > 0 && $this->problems === [];
    }

    private function show(ImportPreview $preview): void
    {
        $this->checked = true;
        $this->rowCount = $preview->rowCount;
        $this->problems = array_map(fn (RowProblem $problem): string => $problem->describe(), $preview->problems);
        $this->sample = array_map(
            fn ($row): array => ['line' => $row->line, 'values' => $row->values],
            $preview->sample,
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Nothing is saved until every row is accepted. One bad row imports nothing.';
    }

    private function importer(): SpreadsheetImporter
    {
        return app(self::IMPORTERS[$this->data['type'] ?? 'products']);
    }

    private function uploadedPath(): ?string
    {
        $file = $this->data['file'] ?? null;
        $file = is_array($file) ? reset($file) : $file;

        if (! $file) {
            Notification::make()->title('Choose a spreadsheet first')->warning()->send();

            return null;
        }

        return is_string($file)
            ? Storage::disk('local')->path($file)
            : $file->getRealPath();
    }

    private function downloadTemplate(): BinaryFileResponse
    {
        $type = $this->data['type'] ?? 'products';
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        $this->importer()->writeTemplate($path);

        return response()->download($path, "{$type}-template.xlsx")->deleteFileAfterSend();
    }
}
