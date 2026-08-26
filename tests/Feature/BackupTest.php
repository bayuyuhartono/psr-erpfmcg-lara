<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Masters\Models\Product;
use App\Domain\Purchasing\Actions\DraftGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Filament\Pages\Backups;
use App\Models\User;
use App\Support\Backup\DatabaseBackup;
use App\Support\Backup\Exceptions\BackupFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CompanySettingsSeeder;
use Database\Seeders\JournalMappingsSeeder;
use Database\Seeders\NumberSequencesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deliberately without `RefreshDatabase`.
 *
 * That trait wraps each test in a transaction it never commits, and
 * `mysqldump` connects separately — so a backup taken inside one contains none
 * of the data the test just wrote. Testing a backup means committing first,
 * the same reason the posting concurrency test does.
 */
final class BackupTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    private ?string $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        if ($this->scratch !== null) {
            DB::statement("DROP DATABASE IF EXISTS `{$this->scratch}`");
        }

        // Committed rows, so the database is left as the next test expects it.
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }

    #[Group('slow')]
    public function test_a_backup_restores_with_the_data_still_in_it(): void
    {
        // 9.5 asks for a restore procedure that has actually been tested, not
        // one that has been written down. So this dumps a database with real
        // posted documents in it, restores the file into a scratch schema, and
        // counts what came back.
        $this->seed([
            CompanySettingsSeeder::class, RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class, JournalMappingsSeeder::class, NumberSequencesSeeder::class,
        ]);
        $this->postAReceipt();

        $expected = [
            'stock_ledger' => DB::table('stock_ledger')->count(),
            'journal_entries' => DB::table('journal_entries')->count(),
            'chart_of_accounts' => DB::table('chart_of_accounts')->count(),
        ];
        $this->assertGreaterThan(0, $expected['stock_ledger'], 'nothing was posted, so the test proves nothing');

        $backup = app(DatabaseBackup::class);
        $file = $backup->create('test');
        $this->written[] = $file['path'];

        $this->assertGreaterThan(1024, $file['bytes'], 'the dump is suspiciously small');

        $this->scratch = config('database.connections.'.config('database.default'))['database'].'_restore_check';
        $backup->restore($file['path']);

        foreach ($expected as $table => $count) {
            $restored = DB::selectOne("SELECT COUNT(*) AS n FROM `{$this->scratch}`.`{$table}`")->n;
            $this->assertSame($count, (int) $restored, "{$table} did not survive the restore");
        }

        // And the ledger rows are the same rows, not merely the same number.
        $original = DB::table('stock_ledger')->orderBy('id')->pluck('value')->all();
        $restored = DB::table($this->scratch.'.stock_ledger')->orderBy('id')->pluck('value')->all();
        $this->assertSame($original, $restored);
    }

    public function test_a_file_that_is_not_a_backup_fails_loudly(): void
    {
        $path = app(DatabaseBackup::class)->directory().'/not-a-backup.sql';
        file_put_contents($path, "this is not SQL at all\n");
        $this->written[] = $path;
        $this->scratch = config('database.connections.'.config('database.default'))['database'].'_restore_check';

        $this->expectException(BackupFailed::class);

        app(DatabaseBackup::class)->restore($path);
    }

    public function test_restoring_a_file_that_is_not_there_says_so(): void
    {
        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessageMatches('/No backup file at/');

        app(DatabaseBackup::class)->restore('/tmp/nothing-here.sql');
    }

    #[Group('slow')]
    public function test_the_screen_takes_a_backup_and_lists_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(DB::table('roles')->where('name', 'Administrator')->value('id'));
        $this->actingAs($user);

        $before = count(app(DatabaseBackup::class)->all());

        Livewire::test(Backups::class)
            ->callAction('create')
            ->assertNotified();

        $after = app(DatabaseBackup::class)->all();
        $this->assertCount($before + 1, $after);
        $this->written[] = $after[0]['path'];

        Livewire::test(Backups::class)->assertSee($after[0]['name']);
    }

    private function postAReceipt(): void
    {
        $supplier = BusinessPartner::factory()->create(['is_supplier' => true]);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['sku' => 'KOPI-01', 'base_uom' => 'PCS']);

        $receipt = app(DraftGoodsReceipt::class)($supplier, $warehouse->id, [
            ['product' => $product, 'qty' => '100', 'unit_cost' => '3000'],
        ], null, new CarbonImmutable('2026-08-26'));

        app(PostGoodsReceipt::class)($receipt);
    }
}
