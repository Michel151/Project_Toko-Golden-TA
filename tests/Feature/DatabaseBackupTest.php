<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Storage::fake('local');
    }

    public function test_backup_can_restore_database_rows(): void
    {
        $category = Category::create(['name' => 'Minuman']);
        $service = app(DatabaseBackupService::class);
        $backup = $service->create();

        $category->update(['name' => 'Nama Berubah']);
        Category::create(['name' => 'Data Baru']);

        $service->restore($backup['filename']);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Minuman']);
        $this->assertDatabaseMissing('categories', ['name' => 'Data Baru']);
    }

    public function test_modified_backup_is_rejected(): void
    {
        Category::create(['name' => 'Makanan']);
        $service = app(DatabaseBackupService::class);
        $backup = $service->create();
        $path = 'database-backups/'.$backup['filename'];
        $document = json_decode(gzdecode(Storage::disk('local')->get($path)), true);
        $document['payload']['tables']['categories'][0]['name'] = 'Dimanipulasi';
        Storage::disk('local')->put($path, gzencode(json_encode($document)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integritas file backup tidak valid.');

        $service->restore($backup['filename']);
    }

    public function test_only_owner_can_open_backup_page(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $cashier = User::create([
            'name' => 'Kasir', 'email' => 'kasir@example.com', 'password' => 'password', 'role' => 'kasir',
        ]);

        $this->actingAs($owner)->get(route('database-backups.index'))->assertOk();
        $this->actingAs($cashier)->get(route('database-backups.index'))->assertForbidden();
    }
}
