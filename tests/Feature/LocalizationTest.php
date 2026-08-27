<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Localisation\Locales;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));

        return $user;
    }

    public function test_indonesian_is_the_default(): void
    {
        $this->assertSame('id', Locales::default());
        $this->assertSame('id', config('app.locale'));

        // English is the fallback, so a key with no Indonesian translation
        // shows readable English rather than the raw key.
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_the_panel_renders_in_indonesian(): void
    {
        $this->actingAs($this->administrator());

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Penjualan')       // navigation group
            ->assertSee('Katalog')
            ->assertSee('Sistem');
    }

    public function test_switching_to_english_holds_across_requests(): void
    {
        $this->actingAs($this->administrator());

        $this->get(route('locale.switch', 'en'))->assertRedirect();
        $this->assertSame('en', session(Locales::SESSION_KEY));

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Catalogue')
            ->assertDontSee('Katalog');
    }

    public function test_switching_back_to_indonesian_works(): void
    {
        $this->actingAs($this->administrator());

        $this->get(route('locale.switch', 'en'));
        $this->get(route('locale.switch', 'id'));

        $this->get('/admin')->assertSuccessful()->assertSee('Katalog');
    }

    public function test_an_unsupported_locale_is_ignored(): void
    {
        $this->actingAs($this->administrator());

        // Not an error, and not obeyed: the URL is user input, and a locale
        // taken from it unchecked is a path into the translation loader.
        $this->get(route('locale.switch', 'de'))->assertRedirect();

        $this->assertNull(session(Locales::SESSION_KEY));
        $this->get('/admin')->assertSuccessful()->assertSee('Katalog');
    }

    public function test_the_login_screen_carries_the_switcher(): void
    {
        // Before signing in: someone who cannot read the form cannot be asked
        // to sign in first in order to change its language.
        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('Masuk')
            ->assertSee(route('locale.switch', 'en'), escape: false)
            ->assertSee(route('locale.switch', 'id'), escape: false);
    }

    public function test_the_panel_carries_the_switcher_too(): void
    {
        $this->actingAs($this->administrator());

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee(route('locale.switch', 'en'), escape: false);
    }

    public function test_filaments_own_chrome_is_translated(): void
    {
        $this->actingAs($this->administrator());

        // Filament ships Indonesian for its own strings; this holds that the
        // locale actually reaches it rather than only our lang file.
        $this->get('/admin/products')->assertSuccessful()->assertSee('Cari');
    }

    public function test_every_translated_key_is_used_somewhere(): void
    {
        $keys = array_keys(json_decode(file_get_contents(lang_path('id.json')), true));

        $haystack = '';
        foreach (['app/Filament', 'app/Providers', 'resources/views/filament'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->isFile()) {
                    $haystack .= file_get_contents($file->getPathname());
                }
            }
        }

        // Looked up as `__($group)` inside a loop, so the literal
        // `__('Sales')` never appears in the source.
        $dynamic = ['Sales', 'Partners', 'Catalogue', 'Pricing', 'Warehouse', 'Accounting', 'System'];

        // A translation nobody looks up is dead weight that reads as coverage.
        $orphans = array_values(array_filter(
            $keys,
            // Matched up to the closing quote, not the closing paren: a key
            // taking replacements ends `__('...', [...])` and would otherwise
            // read as unused.
            fn (string $key): bool => ! in_array($key, $dynamic, true)
                && ! str_contains($haystack, "__('".$key."')")
                && ! str_contains($haystack, "__('".$key."',"),
        ));

        $this->assertSame([], $orphans, 'Translated but never used: '.implode(', ', $orphans));
    }
}
