<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Services\CompanyIdentity;
use App\Filament\Pages\CompanyProfile;
use App\Models\User;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(CompanyIdentity::DISK);

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));

        return $user;
    }

    private function brand(): array
    {
        // The singleton holds the row for the life of a request; a test makes
        // several against one container, so this is what a fresh request sees.
        app(CompanyIdentity::class)->forget();
        $panel = Filament::getPanel('admin');

        return ['name' => $panel->getBrandName(), 'logo' => $panel->getBrandLogo()];
    }

    public function test_the_page_loads_with_the_current_settings(): void
    {
        CompanySetting::query()->first()->update(['legal_name' => 'PT Sumber Rejeki']);

        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->assertSuccessful()
            ->assertSchemaStateSet(['legal_name' => 'PT Sumber Rejeki'], 'form');
    }

    public function test_the_company_name_is_what_the_header_shows(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm(['legal_name' => 'PT Sumber Rejeki'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('company_settings', ['legal_name' => 'PT Sumber Rejeki']);
        $this->assertSame('PT Sumber Rejeki', $this->brand()['name']);
        $this->assertNull($this->brand()['logo'], 'With no logo the name is what is shown.');
    }

    public function test_an_uploaded_logo_replaces_the_name(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm([
                'legal_name' => 'PT Sumber Rejeki',
                'logo_url' => UploadedFile::fake()->image('logo.png', 400, 120),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = CompanySetting::query()->first()->logo_url;

        $this->assertNotNull($stored);
        Storage::disk(CompanyIdentity::DISK)->assertExists($stored);

        // The file keeps its own name, not the one it was uploaded under: two
        // clients both sending `logo.png` must not overwrite each other.
        $this->assertStringNotContainsString('logo.png', $stored);

        $this->assertNotNull($this->brand()['logo']);
    }

    public function test_the_logo_appears_on_the_header_and_the_login_screen(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm([
                'legal_name' => 'PT Sumber Rejeki',
                'logo_url' => UploadedFile::fake()->image('logo.png', 400, 120),
            ])
            ->call('save');

        app(CompanyIdentity::class)->forget();
        $url = Filament::getPanel('admin')->getBrandLogo();

        $this->assertLogoNotWordmark($this->get('/admin')->assertSuccessful()->getContent(), $url);

        // The login screen is unauthenticated, and the one place the branding
        // has to work for someone with no session at all.
        auth()->logout();
        $this->assertLogoNotWordmark($this->get('/admin/login')->assertSuccessful()->getContent(), $url);
    }

    public function test_removing_the_logo_brings_the_name_back(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm(['legal_name' => 'PT Sumber Rejeki', 'logo_url' => UploadedFile::fake()->image('logo.png')])
            ->call('save');

        $this->assertNotNull($this->brand()['logo']);

        Livewire::test(CompanyProfile::class)
            ->fillForm(['legal_name' => 'PT Sumber Rejeki', 'logo_url' => null])
            ->call('save');

        $this->assertNull($this->brand()['logo']);
        $this->assertSame('PT Sumber Rejeki', $this->brand()['name']);
    }

    public function test_a_missing_logo_file_falls_back_to_the_name(): void
    {
        CompanySetting::query()->first()->update([
            'legal_name' => 'PT Sumber Rejeki',
            'logo_url' => 'branding/deleted-by-hand.png',
        ]);

        // A broken image in the header reads as a broken installation; the
        // company name reads as one nobody has branded yet.
        $this->assertNull($this->brand()['logo']);
        $this->assertSame('PT Sumber Rejeki', $this->brand()['name']);
    }

    public function test_branding_survives_the_table_not_existing(): void
    {
        // A fresh install renders the login screen before `migrate` runs.
        DB::statement('DROP TABLE company_settings');

        $identity = app(CompanyIdentity::class);
        $identity->forget();

        $this->assertSame(config('app.name'), $identity->name());
        $this->assertNull($identity->logoUrl());
    }

    public function test_the_favicon_is_its_own_upload(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm([
                'legal_name' => 'PT Sumber Rejeki',
                'logo_url' => UploadedFile::fake()->image('logo.png', 400, 120),
                'favicon_url' => UploadedFile::fake()->image('icon.png', 32, 32),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = CompanySetting::query()->first();

        $this->assertNotNull($settings->favicon_url);
        $this->assertNotSame($settings->logo_url, $settings->favicon_url);

        app(CompanyIdentity::class)->forget();
        $favicon = Filament::getPanel('admin')->getFavicon();

        $this->assertNotNull($favicon);

        // Logged out: the tab icon is wanted before anyone has signed in.
        auth()->logout();
        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('<link rel="icon" href="'.$favicon.'" />', escape: false);
    }

    public function test_the_logo_stands_in_for_a_missing_favicon(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CompanyProfile::class)
            ->fillForm([
                'legal_name' => 'PT Sumber Rejeki',
                'logo_url' => UploadedFile::fake()->image('logo.png', 400, 120),
            ])
            ->call('save');

        app(CompanyIdentity::class)->forget();
        $panel = Filament::getPanel('admin');

        // Not ideal at sixteen pixels, but this client's mark rather than
        // somebody else's default.
        $this->assertSame($panel->getBrandLogo(), $panel->getFavicon());
    }

    public function test_no_branding_at_all_leaves_the_favicon_unset(): void
    {
        CompanySetting::query()->first()->update(['logo_url' => null, 'favicon_url' => null]);

        app(CompanyIdentity::class)->forget();

        $this->assertNull(Filament::getPanel('admin')->getFavicon());
    }

    /**
     * The logo is shown as an image, and the company name is never used as a
     * stand-in for it.
     *
     * Not tied to Filament's `fi-logo` markup: the login screen has a layout of
     * its own and does not use that component. What has to hold on every screen
     * is the substance — an `<img>` for the logo, and no wordmark fallback
     * rendered in its place. The name appearing in the tab title, or as a
     * caption beneath the panel copy, is wanted and is not that.
     */
    private function assertLogoNotWordmark(string $html, string $url): void
    {
        $this->assertMatchesRegularExpression(
            '/<img[^>]*src="'.preg_quote($url, '/').'"/',
            $html,
            'The logo should render as an image.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*fi-logo[^"]*"[^>]*>\s*PT Sumber Rejeki/',
            $html,
            'The company name should not be shown in place of the logo.',
        );
    }
}
