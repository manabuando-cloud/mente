<?php

namespace Tests\Feature;

use App\Models\TroubleCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/navi-backup-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_backs_up_sqlite_and_new_photos_and_prunes_old_copies(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('photos/c1/a.jpg', 'jpeg-bytes');
        TroubleCase::factory()->create(['symptom' => 'バックアップ確認']);
        File::ensureDirectoryExists("{$this->dir}/db");
        touch($old = "{$this->dir}/db/navi-20200101-000000.sqlite", now()->subDays(60)->getTimestamp());

        $this->artisan('navi:backup', ['--path' => $this->dir])->expectsOutputToContain('写真: 1 件')->assertSuccessful();
        $this->artisan('navi:backup', ['--path' => $this->dir])->expectsOutputToContain('写真: 0 件')->assertSuccessful();

        $copies = File::glob("{$this->dir}/db/navi-*.sqlite");
        $this->assertNotContains($old, $copies);
        $this->assertNotEmpty($copies);
        $this->assertSame('jpeg-bytes', file_get_contents("{$this->dir}/photos/c1/a.jpg"));

        // コピーしたDBが開けて中身が入っている
        $pdo = new \PDO('sqlite:'.$copies[0]);
        $this->assertSame('バックアップ確認', $pdo->query('select symptom from trouble_cases')->fetchColumn());
    }
}
