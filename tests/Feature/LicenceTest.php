<?php

namespace Tests\Feature;

use App\Domain\Masters\Exceptions\SeatsExhausted;
use App\Domain\Masters\Models\License;
use App\Domain\Masters\Services\Licence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The software is sold, not rented. These tests exist mostly to pin that
 * down — the failure mode being guarded against is a future change that makes
 * a distributor's ERP stop working over a renewal.
 */
final class LicenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_installation_past_its_support_date_keeps_working(): void
    {
        $this->licence(['supported_until' => '2020-01-01', 'max_users' => 10]);

        $licence = app(Licence::class);

        $this->assertTrue($licence->supportHasLapsed());
        // Lapsed support means no more updates. It does not mean no more
        // software: everything below still works.
        $this->assertTrue($licence->hasSeatFor());
        $this->assertTrue($licence->allows('sales'));

        $user = User::factory()->create(['active' => true]);
        $this->assertTrue($user->exists);
        $this->assertTrue($user->fresh()->active);
    }

    public function test_an_installation_with_no_licence_row_runs_unrestricted(): void
    {
        // A missing record must never read as "nothing is permitted". A client
        // whose licence row was lost in a restore still has a business to run.
        $licence = app(Licence::class);

        $this->assertNull($licence->seats());
        $this->assertTrue($licence->hasSeatFor());
        $this->assertTrue($licence->allows('anything'));
        $this->assertFalse($licence->supportHasLapsed());
    }

    public function test_seats_are_counted_and_the_next_activation_is_refused(): void
    {
        $this->licence(['max_users' => 2]);

        User::factory()->create(['active' => true]);
        User::factory()->create(['active' => true]);

        $this->assertSame(0, app(Licence::class)->seatsLeft());

        try {
            User::factory()->create(['active' => true]);
            $this->fail('Expected the third seat to be refused.');
        } catch (SeatsExhausted $exception) {
            $this->assertStringContainsString('licensed for 2 user(s)', $exception->getMessage());
            $this->assertStringContainsString('buy more seats', $exception->getMessage());
        }

        $this->assertSame(2, User::where('active', true)->count());
    }

    public function test_the_people_already_working_keep_working(): void
    {
        $this->licence(['max_users' => 1]);
        $existing = User::factory()->create(['active' => true, 'full_name' => 'Ibu Sari']);

        // Over the limit now — but nobody gets locked out of a day's work.
        License::query()->update(['max_users' => 0]);

        $existing->update(['full_name' => 'Ibu Sari Dewi']);

        $this->assertSame('Ibu Sari Dewi', $existing->fresh()->full_name);
        $this->assertTrue($existing->fresh()->active);
    }

    public function test_a_freed_seat_can_be_used_again(): void
    {
        $this->licence(['max_users' => 1]);
        $first = User::factory()->create(['active' => true]);

        $first->update(['active' => false]);
        $second = User::factory()->create(['active' => true]);

        $this->assertTrue($second->fresh()->active);
        $this->assertSame(0, app(Licence::class)->seatsLeft());
    }

    public function test_modules_that_were_not_bought_are_not_offered(): void
    {
        $this->licence(['enabled_modules' => ['masters', 'purchasing', 'inventory', 'sales', 'finance', 'accounting']]);

        $licence = app(Licence::class);

        $this->assertTrue($licence->allows('sales'));
        // Phase 2. Not sold, so not shown — which is a different thing from
        // switching off something they paid for.
        $this->assertFalse($licence->allows('salesperson_app'));
    }

    public function test_support_dates_are_reported_not_enforced(): void
    {
        $this->licence(['supported_until' => '2027-12-31']);

        $this->assertSame('2027-12-31', app(Licence::class)->supportedUntil()->format('Y-m-d'));
        $this->assertFalse(app(Licence::class)->supportHasLapsed());
    }

    /** @param  array<string, mixed>  $attributes */
    private function licence(array $attributes): License
    {
        return License::create($attributes + [
            'license_key' => 'PSR-DEMO-0001',
            'issued_at' => new CarbonImmutable('2026-01-01'),
        ]);
    }
}
