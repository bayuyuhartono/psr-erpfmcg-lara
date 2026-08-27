<?php

namespace App\Filament\Pages;

use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Services\CompanyIdentity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * The client's name and logo, which is the whole of what "branding" means here.
 *
 * Filament's own logo component shows the logo when there is one and the name
 * when there is not — never both — so uploading a logo replaces the wordmark on
 * the header and the login screen without this page having to say so.
 */
class CompanyProfile extends Page
{
    protected string $view = 'filament.pages.company-profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    // Above Backups: this is the first screen touched on a new installation.
    protected static ?int $navigationSort = 5;

    public function getTitle(): string
    {
        return __('Company profile');
    }

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $settings = CompanySetting::query()->first();

        $this->form->fill([
            'legal_name' => $settings?->legal_name,
            'logo_url' => $settings?->logo_url,
            'favicon_url' => $settings?->favicon_url,
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Shown on the header, the login screen, and every printed document.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Identity'))
                    ->description(__('Used on invoices and delivery notes as well as on screen, so it should be the registered name.'))
                    ->schema([
                        TextInput::make('legal_name')
                            ->label(__('Company name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(config('app.name')),
                    ]),

                Section::make(__('Logo'))
                    ->description(__('Replaces the company name in the header and on the login screen. Leave it empty to show the name instead.'))
                    ->schema([
                        FileUpload::make('logo_url')
                            ->hiddenLabel()
                            ->image()
                            ->disk(CompanyIdentity::DISK)
                            ->directory(CompanyIdentity::DIRECTORY)
                            ->visibility('public')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                            // Kept out of the way of whatever else lands in the
                            // directory, and never named after the upload: a
                            // client's file called `logo.png` would otherwise
                            // overwrite the one already there.
                            ->getUploadedFileNameForStorageUsing(
                                fn ($file): string => Str::uuid7().'.'.$file->getClientOriginalExtension(),
                            )
                            ->helperText(__('PNG, JPEG, SVG or WebP, up to 2 MB. It is displayed about 32 pixels tall, so a wide logo reads better than a tall one.')),
                    ]),

                Section::make(__('Browser tab icon'))
                    ->description(__('Shown on the tab and in bookmarks. Leave it empty to use the logo, which works if the logo is roughly square.'))
                    ->schema([
                        FileUpload::make('favicon_url')
                            ->hiddenLabel()
                            ->image()
                            ->disk(CompanyIdentity::DISK)
                            ->directory(CompanyIdentity::DIRECTORY)
                            ->visibility('public')
                            ->maxSize(512)
                            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/x-icon', 'image/webp'])
                            ->getUploadedFileNameForStorageUsing(
                                fn ($file): string => Str::uuid7().'.'.$file->getClientOriginalExtension(),
                            )
                            ->helperText(__('Square, ideally 32×32 or larger. A wide logo shrunk to a tab is unreadable, which is why this is separate.')),
                    ]),
            ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save'))
                ->icon(Heroicon::OutlinedCheck)
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // There is exactly one row, and the rest of it — VAT rate, match
        // tolerances — belongs to other screens, so this updates its two
        // columns rather than replacing the record.
        $settings = CompanySetting::query()->first();

        if ($settings === null) {
            CompanySetting::query()->create($data);
        } else {
            $settings->fill($data)->save();
        }

        // The header and login screen read through the singleton, which has
        // already cached the old row for this request.
        app(CompanyIdentity::class)->forget();

        Notification::make()->title('Saved')->success()->send();
    }
}
