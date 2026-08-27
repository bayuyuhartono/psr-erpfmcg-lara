<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\CompanySetting;
use App\Domain\Masters\Services\CompanyIdentity;
use App\Filament\Auth\Login;
use App\Models\User;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The login screen is on a custom layout, which is the kind of change that
 * looks fine and quietly breaks the thing the page is for. Everything that
 * authenticates is inherited, so what these hold is that it still is.
 */
final class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class]);
        CompanySetting::query()->first()->update(['legal_name' => 'PT Sumber Rejeki']);
        app(CompanyIdentity::class)->forget();
    }

    private function user(string $password = 'correct-horse'): User
    {
        $user = User::factory()->create(['password' => $password, 'active' => true]);
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));

        return $user;
    }

    public function test_the_screen_renders_with_its_branding_panel(): void
    {
        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('Sign in')
            // No logo uploaded, so the panel falls back to the company name.
            ->assertSee('PT Sumber Rejeki');
    }

    public function test_correct_credentials_still_sign_in(): void
    {
        $user = $this->user();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'correct-horse'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_still_refused(): void
    {
        $user = $this->user();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'not-the-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_reach_the_panel(): void
    {
        $user = $this->user();
        $user->forceFill(['active' => false])->save();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'correct-horse'])
            ->call('authenticate');

        // Refused at the login itself rather than let in and stopped later:
        // Filament checks `canAccessPanel` before establishing the session, so
        // a deactivated account never gets one.
        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_form_the_page_inherits_is_untouched(): void
    {
        Livewire::test(Login::class)
            ->assertSchemaComponentExists('email', 'form')
            ->assertSchemaComponentExists('password', 'form')
            ->assertSchemaComponentExists('remember', 'form');
    }
}
