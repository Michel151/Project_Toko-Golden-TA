<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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
        $this->actingAs($cashier)->get(route('database-backups.export-sql'))->assertForbidden();
        $this->actingAs($cashier)->post(route('database-backups.import-sql'))->assertForbidden();
    }

    public function test_owner_can_download_compressed_sql_export(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        Category::create(['name' => "O'Brien"]);

        $response = $this->actingAs($owner)->get(route('database-backups.export-sql'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/gzip');
        $this->assertStringContainsString('.sql.gz', $response->headers->get('Content-Disposition'));

        $sql = gzdecode($response->getContent());

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO "categories"', $sql);
        $this->assertStringContainsString("'O''Brien'", $sql);
        $this->assertStringNotContainsString('CREATE TABLE "sessions"', $sql);

        $restoredDatabase = new \PDO('sqlite::memory:');
        $restoredDatabase->exec($sql);

        $this->assertSame("O'Brien", $restoredDatabase->query('SELECT name FROM categories')->fetchColumn());
    }

    public function test_sql_backup_is_listed_and_can_be_downloaded_restored_and_deleted(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Versi Backup']);
        $export = $this->actingAs($owner)->get(route('database-backups.export-sql'));
        preg_match('/filename="([^"]+)"/', $export->headers->get('Content-Disposition'), $matches);
        $filename = $matches[1];

        $this->actingAs($owner)->get(route('database-backups.index'))
            ->assertOk()
            ->assertSee($filename);
        $this->actingAs($owner)->get(route('database-backups.sql-download', $filename))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/gzip');

        $category->update(['name' => 'Versi Berubah']);

        $restore = $this->actingAs($owner)->post(route('database-backups.sql-restore', $filename), [
            'current_password' => 'password',
            'restore_confirmation' => '1',
        ]);

        $restore->assertRedirect(route('database-backups.index'));
        $restore->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Versi Backup']);
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));

        $delete = $this->actingAs($owner)->delete(route('database-backups.sql-destroy', $filename), [
            'current_password' => 'password',
        ]);

        $delete->assertSessionHas('success');
        Storage::disk('local')->assertMissing('database-backups/sql/'.$filename);
    }

    public function test_owner_can_restore_database_from_signed_sql_export(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Data SQL']);
        $export = $this->actingAs($owner)->get(route('database-backups.export-sql'));

        $category->update(['name' => 'Data Berubah']);

        $response = $this->actingAs($owner)->post(route('database-backups.import-sql'), [
            'sql_backup_file' => UploadedFile::fake()->createWithContent('backup.sql.gz', $export->getContent()),
            'current_password' => 'password',
            'restore_confirmation' => '1',
        ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Data SQL']);
    }

    public function test_unsigned_sql_import_is_rejected(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);

        $response = $this->actingAs($owner)->from(route('database-backups.index'))
            ->post(route('database-backups.import-sql'), [
                'sql_backup_file' => UploadedFile::fake()->createWithContent('backup.sql', 'DROP TABLE users;'),
                'current_password' => 'password',
                'restore_confirmation' => '1',
            ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHas('error', 'Import SQL gagal: File SQL bukan ekspor resmi dari aplikasi ini.');
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_owner_can_restore_database_from_uploaded_backup(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Data Backup']);
        $backup = app(DatabaseBackupService::class)->create();
        $contents = Storage::disk('local')->get('database-backups/'.$backup['filename']);

        $category->update(['name' => 'Data Berubah']);

        $response = $this->actingAs($owner)->post(route('database-backups.restore-upload'), [
            'backup_file' => UploadedFile::fake()->createWithContent('backup.json.gz', $contents),
            'current_password' => 'password',
            'restore_confirmation' => '1',
        ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Data Backup']);
    }

    public function test_uploaded_backup_requires_valid_signature(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $invalidBackup = gzencode(json_encode([
            'signature' => 'invalid',
            'payload' => ['format' => 'toko-golden-backup-v1', 'tables' => []],
        ]));

        $response = $this->actingAs($owner)->from(route('database-backups.index'))
            ->post(route('database-backups.restore-upload'), [
                'backup_file' => UploadedFile::fake()->createWithContent('backup.json.gz', $invalidBackup),
                'current_password' => 'password',
                'restore_confirmation' => '1',
            ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHas('error', 'Pemulihan dari file gagal: Integritas file backup tidak valid.');
    }

    public function test_uploaded_backup_rejects_wrong_owner_password(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);

        $response = $this->actingAs($owner)->from(route('database-backups.index'))
            ->post(route('database-backups.restore-upload'), [
                'backup_file' => UploadedFile::fake()->createWithContent('backup.json.gz', gzencode('{}')),
                'current_password' => 'salah',
                'restore_confirmation' => '1',
            ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHasErrors(['current_password']);
    }

    public function test_uploaded_backup_requires_restore_confirmation(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);

        $response = $this->actingAs($owner)->from(route('database-backups.index'))
            ->post(route('database-backups.restore-upload'), [
                'backup_file' => UploadedFile::fake()->createWithContent('backup.json.gz', gzencode('{}')),
                'current_password' => 'password',
            ]);

        $response->assertRedirect(route('database-backups.index'));
        $response->assertSessionHasErrors(['restore_confirmation']);
    }
}
