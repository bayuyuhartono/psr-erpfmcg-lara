<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\License;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_someone_who_can_sign_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('app:create-admin', [
            '--name' => 'Ibu Sari',
            '--email' => 'sari@toko.co.id',
            '--password' => 'a-good-long-password',
        ])->assertExitCode(0);

        $user = User::where('email', 'sari@toko.co.id')->firstOrFail();

        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('a-good-long-password', $user->password));
        $this->assertTrue($user->hasPermission('journal.post'), 'Administrator should hold every permission');
        // The panel checks both of these.
        $this->assertTrue($user->roles()->exists());
    }

    public function test_it_says_what_to_do_when_the_roles_are_not_there_yet(): void
    {
        // A fresh database, before db:seed. The message has to point at the
        // fix rather than at the missing row.
        $this->artisan('app:create-admin', [
            '--name' => 'Ibu Sari', '--email' => 'sari@toko.co.id', '--password' => 'a-good-long-password',
        ])
            ->expectsOutputToContain('db:seed')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('app:create-admin', [
            '--name' => 'Ibu Sari', '--email' => 'sari@toko.co.id', '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_same_address_cannot_be_used_twice(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['email' => 'sari@toko.co.id']);

        $this->artisan('app:create-admin', [
            '--name' => 'Someone Else', '--email' => 'sari@toko.co.id', '--password' => 'a-good-long-password',
        ])->assertExitCode(1);

        $this->assertSame(1, User::where('email', 'sari@toko.co.id')->count());
    }

    public function test_it_respects_the_seats_that_were_bought(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        License::create([
            'license_key' => 'PSR-0001', 'max_users' => 1,
            'issued_at' => new CarbonImmutable('2026-01-01'),
        ]);
        User::factory()->create(['active' => true]);

        $this->artisan('app:create-admin', [
            '--name' => 'One Too Many', '--email' => 'extra@toko.co.id', '--password' => 'a-good-long-password',
        ])
            ->expectsOutputToContain('buy more seats')
            ->assertExitCode(1);
    }

    public function test_another_role_can_be_given_instead(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('app:create-admin', [
            '--name' => 'Pak Budi', '--email' => 'budi@toko.co.id',
            '--password' => 'a-good-long-password', '--role' => 'Warehouse Supervisor',
        ])->assertExitCode(0);

        $user = User::where('email', 'budi@toko.co.id')->firstOrFail();

        $this->assertTrue($user->hasPermission('stock.adjust.approve'));
        $this->assertFalse($user->hasPermission('journal.post'));
    }
}
