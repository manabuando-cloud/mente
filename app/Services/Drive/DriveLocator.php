<?php

namespace App\Services\Drive;

use App\Models\Machine;

/**
 * 拠点フォルダ → 機械フォルダ（<機械番号>_<型式>）→ 報告書/見積 を辿るヘルパー。
 */
class DriveLocator
{
    /** @var array<string, list<array>> */
    private array $memo = [];

    /** @var list<string>|null "_" を含む登録済み機械番号 */
    private ?array $knownIds = null;

    public function __construct(private DriveClient $drive) {}

    public function children(string $folderId): array
    {
        return $this->memo[$folderId] ??= $this->drive->listChildren($folderId);
    }

    /**
     * 全拠点の機械フォルダを列挙する。
     *
     * @return list<array{site: string, machine_id: string, model: string, folder: array}>
     */
    public function machineFolders(): array
    {
        $result = [];
        foreach (config('navi.drive.site_folders') as $site => $siteFolderId) {
            if (! $siteFolderId) {
                continue;
            }
            foreach ($this->children($siteFolderId) as $f) {
                if ($f['mimeType'] !== DriveClient::FOLDER_MIME) {
                    continue;
                }
                [$machineId, $model] = $this->parseFolderName($f['name']);
                if ($machineId === '') {
                    continue;
                }
                $result[] = ['site' => $site, 'machine_id' => $machineId, 'model' => $model, 'folder' => $f];
            }
        }

        return $result;
    }

    /**
     * "<機械番号>_<型式>" を分解する。機械番号自体に "_" を含むもの（例: L_0987）があるので、
     * 登録済みの機械番号に前方一致するものを優先し、無ければ最初の "_" で分ける。
     *
     * @return array{0: string, 1: string}
     */
    public function parseFolderName(string $name): array
    {
        $this->knownIds ??= Machine::query()->pluck('id')->filter(fn ($id) => str_contains($id, '_'))
            ->sortByDesc(fn ($id) => strlen($id))->values()->all();
        foreach ($this->knownIds as $id) {
            if ($name === $id || str_starts_with($name, $id.'_')) {
                return [$id, (string) substr($name, strlen($id) + 1)];
            }
        }

        return array_pad(explode('_', $name, 2), 2, '');
    }

    /** 機械フォルダ直下の作業報告書PDF */
    public function activityReports(string $machineFolderId): array
    {
        return array_values(array_filter(
            $this->children($machineFolderId),
            fn ($f) => self::isPdf($f) && preg_match('/ActivityReport/i', $f['name'])
        ));
    }

    /** 機械フォルダ内「見積」サブフォルダのPDF */
    public function quotes(string $machineFolderId): array
    {
        $sub = collect($this->children($machineFolderId))->first(
            fn ($f) => $f['mimeType'] === DriveClient::FOLDER_MIME && $f['name'] === config('navi.drive.quote_subfolder_name')
        );

        return $sub ? array_values(array_filter($this->children($sub['id']), fn ($f) => self::isPdf($f))) : [];
    }

    /** フォルダ配下のPDFを再帰的に列挙（メーカー作業報告書見積りフォルダ用） */
    public function pdfsRecursive(string $folderId, int $depth = 4): array
    {
        $out = [];
        foreach ($this->children($folderId) as $f) {
            if ($f['mimeType'] === DriveClient::FOLDER_MIME) {
                if ($depth > 0) {
                    array_push($out, ...$this->pdfsRecursive($f['id'], $depth - 1));
                }
            } elseif (self::isPdf($f)) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** 機械のDriveフォルダIDを解決し、machines.drive_folder_id にキャッシュする */
    public function folderFor(Machine $machine): ?string
    {
        if ($machine->drive_folder_id) {
            return $machine->drive_folder_id;
        }
        foreach ($this->machineFolders() as $mf) {
            if ($mf['machine_id'] === $machine->id) {
                $machine->forceFill(['drive_folder_id' => $mf['folder']['id']])->save();

                return $mf['folder']['id'];
            }
        }

        return null;
    }

    /**
     * "20240315_1030_ActivityReport.pdf" / "20260824-#B0702A0033_..." → "2024-03-15"。
     * 未来の日付（"20270726" のような打ち間違い）は信用しない。
     */
    public static function reportDate(string $fileName): ?string
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(?!\d)/', $fileName, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";

        return $date <= now()->addDay()->format('Y-m-d') ? $date : null;
    }

    /**
     * 見積書番号の正規化。"est_23285574" / "EST23285574_事後見積り.pdf" / "２３２８５５７４" を同一視する。
     * 全角→半角 → 先頭の数字列（"est" 接頭辞は任意）を採用。数字列が無ければ英数字のみ小文字化。
     */
    public static function normQuoteNo(?string $value): string
    {
        $v = mb_convert_kana(trim((string) $value), 'as');
        $v = (string) preg_replace('/\.pdf$/i', '', $v);
        if (preg_match('/^(?:est)?[\s_\-]*(\d{4,})/i', $v, $m)) {
            return $m[1];
        }

        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
    }

    public static function webLink(array $file): string
    {
        return $file['webViewLink'] ?? "https://drive.google.com/file/d/{$file['id']}/view";
    }

    private static function isPdf(array $f): bool
    {
        return ($f['mimeType'] ?? '') === 'application/pdf' || str_ends_with(strtolower($f['name']), '.pdf');
    }
}
