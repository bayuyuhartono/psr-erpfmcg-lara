<?php

namespace Tests\Feature;

use App\Domain\Masters\Models\License;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(DB::table('roles')->where('name', $role)->value('id'));

        return $user;
    }

    private function licence(int $maxUsers): License
    {
        return License::create([
            'license_key' => 'PSR-TEST-0001',
            'max_users' => $maxUsers,
            'enabled_modules' => ['*'],
            'issued_at' => now(),
        ]);
    }

    private function roleId(string $name): string
    {
        return DB::table('roles')->where('name', $name)->value('id');
    }

    public function test_an_administrator_can_reach_the_screen(): void
    {
        $this->actingAs($this->userWithRole('Administrator'));

        $this->assertTrue(UserResource::canAccess());
        $this->get(UserResource::getUrl('index'))->assertSuccessful();
    }

    public function test_nobody_else_can(): void
    {
        // Not by role name — by the grant. Every other seeded role lacks it.
        foreach (['Sales Admin', 'Sales Supervisor', 'Finance Manager', 'Warehouse Supervisor', 'Purchasing'] as $role) {
            $this->actingAs($this->userWithRole($role));

            $this->assertFalse(UserResource::canAccess(), $role.' should not manage users.');
            $this->get(UserResource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_granting_the_permission_is_a_data_change_not_a_code_change(): void
    {
        $user = $this->userWithRole('Finance Manager');
        $this->actingAs($user);

        $this->assertFalse(UserResource::canAccess());

        // What a client does when they want their office manager creating
        // accounts: one row, no fork.
        DB::table('role_permissions')->insert([
            'role_id' => $this->roleId('Finance Manager'),
            'permission' => 'user.manage',
            'threshold' => null,
        ]);

        $this->assertTrue(UserResource::canAccess());
    }

    public function test_a_user_is_created_with_a_hashed_password_and_a_role(): void
    {
        $this->actingAs($this->userWithRole('Administrator'));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'full_name' => 'Siti Rahayu',
                'email' => 'siti@client.co.id',
                'password' => 'a-real-password',
                'roles' => [$this->roleId('Sales Admin')],
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'siti@client.co.id')->firstOrFail();

        $this->assertTrue(Hash::check('a-real-password', $created->password));
        $this->assertSame(['Sales Admin'], $created->roles()->pluck('name')->all());
    }

    public function test_a_blank_password_on_edit_keeps_the_existing_one(): void
    {
        $this->actingAs($this->userWithRole('Administrator'));
        $target = $this->userWithRole('Sales Admin', ['password' => 'original-password']);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['full_name' => 'Renamed', 'password' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        // Without the dehydration guard this saves a hash of the empty string
        // and locks the account out on an unrelated edit.
        $this->assertTrue(Hash::check('original-password', $target->fresh()->password));
        $this->assertSame('Renamed', $target->fresh()->full_name);
    }

    public function test_running_out_of_seats_is_reported_not_crashed(): void
    {
        $this->actingAs($administrator = $this->userWithRole('Administrator'));

        $this->licence(maxUsers: 1);   // the administrator already holds it

        Livewire::test(CreateUser::class)
            ->fillForm([
                'full_name' => 'One Too Many',
                'email' => 'extra@client.co.id',
                'password' => 'a-real-password',
                'roles' => [$this->roleId('Sales Admin')],
                'active' => true,
            ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseMissing('users', ['email' => 'extra@client.co.id']);
        $this->assertTrue($administrator->fresh()->active, 'Nobody already working is affected.');
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $this->actingAs($administrator = $this->userWithRole('Administrator'));

        // Locking yourself out of the only screen that could let you back in.
        Livewire::test(EditUser::class, ['record' => $administrator->getKey()])
            ->assertFormFieldDisabled('active');
    }

    public function test_the_seat_count_is_shown_before_it_refuses(): void
    {
        $this->actingAs($this->userWithRole('Administrator'));

        $this->licence(maxUsers: 10);

        Livewire::test(ListUsers::class)->assertSee(__(':used of :seats seat(s) in use.', ['used' => 1, 'seats' => 10]));
    }
}
