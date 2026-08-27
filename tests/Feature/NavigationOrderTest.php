<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sidebar order is declared in the panel, but the group names live on the
 * resources — a rename on either side silently drops a group to the bottom
 * instead of erroring, which is exactly how System ended up in the middle.
 */
final class NavigationOrderTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['Sales', 'Partners', 'Catalogue', 'Pricing', 'Warehouse', 'Accounting', 'System'];

    public function test_the_sidebar_groups_are_in_the_declared_order(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        // The key is what orders a group and what the resources declare; the
        // label is translated and would pin this test to one language.
        $groups = array_keys(Filament::getPanel('admin')->getNavigationGroups());

        $this->assertSame(self::EXPECTED, $groups);
        $this->assertSame('System', end($groups));

        // The rendered sidebar follows that order, under the labels a user
        // actually reads.
        $rendered = collect(Filament::getNavigation())
            ->map(fn ($group): ?string => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertSame(array_map(fn (string $g): string => __($g), self::EXPECTED), $rendered);
    }

    public function test_every_declared_group_is_one_a_resource_actually_uses(): void
    {
        $used = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource) => $resource::getNavigationGroup())
            ->merge(collect(Filament::getPanel('admin')->getPages())
                ->map(fn (string $page) => $page::getNavigationGroup()))
            ->filter()
            ->unique()
            ->values();

        // A group listed in the panel but spelled differently on the resource
        // is not an error anywhere — it just quietly stops ordering it.
        foreach (self::EXPECTED as $group) {
            $this->assertTrue(
                $used->contains($group),
                sprintf('Panel declares the group "%s" but nothing is in it.', $group),
            );
        }

        foreach ($used as $group) {
            $this->assertContains(
                $group,
                self::EXPECTED,
                sprintf('"%s" is used but not in the panel order, so it sorts last by accident.', $group),
            );
        }
    }
}
