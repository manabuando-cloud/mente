<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Services\Drive\DriveLocator;
use Illuminate\Console\Command;

/**
 * Driveの機械フォルダ（<機械番号>_<型式>）から機種マスタを補完する。
 * 設備マスタでシリアルNOが空欄の機械や、履歴の移行時に仮登録された機械の型式・拠点を埋め、
 * drive_folder_id を記録する。すでに正しい型式が入っている機械は上書きしない。
 */
class SyncDriveMachines extends Command
{
    protected $signature = 'navi:sync-drive-machines {--dry-run : 変更内容を表示するだけで保存しない}';

    protected $description = 'Driveの機械フォルダ名から機種マスタ（型式・拠点・フォルダID）を補完する';

    public function handle(DriveLocator $locator): int
    {
        $created = $updated = 0;
        foreach ($locator->machineFolders() as $mf) {
            $machine = Machine::find($mf['machine_id']);
            $placeholder = ! $machine || $machine->source === 'import' || $machine->model === $machine->id;

            $attrs = ['drive_folder_id' => $mf['folder']['id']];
            if ($placeholder && $mf['model'] !== '') {
                $attrs['model'] = $mf['model'];
            }
            if (! $machine?->site) {
                $attrs['site'] = $mf['site'];
            }
            if ($machine && $machine->source === 'import') {
                $attrs['source'] = 'drive';
            }

            if (! $machine) {
                $this->line("  追加: {$mf['machine_id']} {$mf['model']}（{$mf['site']}）");
                $this->option('dry-run') || Machine::create(['id' => $mf['machine_id'], 'source' => 'drive', 'model' => $mf['model'] ?: $mf['machine_id'], ...$attrs]);
                $created++;
            } elseif (array_diff_assoc($attrs, $machine->only(array_keys($attrs)))) {
                if (isset($attrs['model'])) {
                    $this->line("  補完: {$machine->id} {$machine->model} → {$attrs['model']}");
                }
                $this->option('dry-run') || $machine->update($attrs);
                $updated++;
            }
        }
        $this->info("追加: {$created}件 / 更新: {$updated}件".($this->option('dry-run') ? '（dry-run のため保存していません）' : ''));

        return self::SUCCESS;
    }
}
