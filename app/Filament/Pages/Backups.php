<?php

namespace App\Filament\Pages;

use App\Support\Backup\DatabaseBackup;
use App\Support\Backup\Exceptions\BackupFailed;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Backups, taken by hand.
 *
 * Scheduling them needs answers nobody has yet — which server, where offsite,
 * who administers it. What an administrator needs today is the button they
 * press before an upgrade, and a way to prove a file restores while it still
 * does not matter.
 *
 * "Check it restores" is the reason this screen exists rather than a cron
 * line. A backup nobody has restored is a file, not a backup.
 */
class Backups extends Page
{
    protected string $view = 'filament.pages.backups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public function getTitle(): string
    {
        return __('Backups');
    }

    public ?string $verified = null;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Take one before every upgrade. A backup nobody has restored is a file, not a backup.';
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('Take a backup now'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function (): void {
                    try {
                        $backup = app(DatabaseBackup::class)->create();
                    } catch (BackupFailed $exception) {
                        Notification::make()->title('Backup failed')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title($backup['name'])
                        ->body(sprintf('%s written.', $this->humanBytes($backup['bytes'])))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return list<array<string, string>> */
    public function backups(): array
    {
        return array_map(fn (array $backup): array => [
            ...$backup,
            'size' => $this->humanBytes($backup['bytes']),
        ], app(DatabaseBackup::class)->all());
    }

    public function download(string $name): BinaryFileResponse
    {
        return response()->download(app(DatabaseBackup::class)->directory().DIRECTORY_SEPARATOR.basename($name));
    }

    /**
     * Restores into a scratch database and counts what came back.
     *
     * Never into the live one. The question this answers is "would this file
     * work", and answering it by overwriting the database being backed up is
     * not an improvement on not knowing.
     */
    public function verify(string $name): void
    {
        $backup = app(DatabaseBackup::class);
        $path = $backup->directory().DIRECTORY_SEPARATOR.basename($name);

        try {
            $backup->restore($path);
        } catch (BackupFailed $exception) {
            $this->verified = null;
            Notification::make()->title('That backup does not restore')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $target = config('database.connections.'.config('database.default'))['database'].'_restore_check';
        $rows = DB::selectOne(
            'SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?) AS tables',
            [$target],
        );

        $this->verified = sprintf('%s restored into %s with %d tables.', basename($name), $target, $rows->tables);

        Notification::make()->title('Restored successfully')->body($this->verified)->success()->persistent()->send();
    }

    public function delete(string $name): void
    {
        app(DatabaseBackup::class)->delete($name);

        Notification::make()->title('Backup deleted')->body(basename($name))->send();
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
