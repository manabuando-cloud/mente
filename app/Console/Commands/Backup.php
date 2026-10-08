<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * DB（SQLite）と写真のバックアップ。1台のPCで運用するとき用。
 *   <path>/db/navi-YYYYMMDD-HHMMSS.sqlite … その時点のDBの完全なコピー（VACUUM INTO なので使用中でも安全）
 *   <path>/photos/…                     … 写真（まだコピーしていないものだけ追加）
 * keep_days より古いDBのコピーは削除する。
 */
class Backup extends Command
{
    protected $signature = 'navi:backup {--path= : 保存先（既定は NAVI_BACKUP_PATH）}';

    protected $description = 'DB（SQLite）と写真をバックアップする';

    public function handle(): int
    {
        $base = rtrim($this->option('path') ?: config('navi.backup.path'), '/');
        File::ensureDirectoryExists("{$base}/db");
        File::ensureDirectoryExists("{$base}/photos");

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->warn('DB が SQLite ではないため、DB のバックアップはこのコマンドでは行いません（mysqldump などを使ってください）');
        } else {
            $stem = "{$base}/db/navi-".now()->format('Ymd-His');
            $file = "{$stem}.sqlite";
            for ($i = 2; is_file($file); $i++) {
                $file = "{$stem}-{$i}.sqlite";
            }
            DB::statement('VACUUM INTO ?', [$file]);
            $this->info('DB: '.$file.'（'.number_format(filesize($file) / 1024).' KB）');

            $cutoff = now()->subDays(config('navi.backup.keep_days'))->getTimestamp();
            foreach (File::glob("{$base}/db/navi-*.sqlite") as $old) {
                if (filemtime($old) < $cutoff) {
                    File::delete($old);
                }
            }
        }

        $disk = Storage::disk(config('navi.photos_disk'));
        $copied = 0;
        foreach ($disk->allFiles('photos') as $path) {
            $target = "{$base}/{$path}";
            if (! is_file($target)) {
                File::ensureDirectoryExists(dirname($target));
                File::put($target, $disk->get($path));
                $copied++;
            }
        }
        $this->info("写真: {$copied} 件を追加でコピー");

        return self::SUCCESS;
    }
}
