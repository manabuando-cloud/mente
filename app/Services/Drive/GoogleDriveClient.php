<?php

namespace App\Services\Drive;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * サービスアカウントで Drive API v3 を叩く実装。
 * 共有ルートフォルダをサービスアカウントのメールアドレスに「閲覧者」で共有しておくこと。
 */
class GoogleDriveClient implements DriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    public function __construct(private ?string $credentialsPath, private bool $useAdc = false) {}

    public function listChildren(string $folderId): array
    {
        $files = [];
        $pageToken = null;
        do {
            $response = Http::withToken($this->token())->timeout(60)->get(self::API.'/files', array_filter([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, createdTime, modifiedTime, webViewLink)',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]))->throw();
            array_push($files, ...$response->json('files', []));
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $files;
    }

    public function download(string $fileId): string
    {
        return Http::withToken($this->token())->timeout(120)
            ->get(self::API."/files/{$fileId}", ['alt' => 'media', 'supportsAllDrives' => 'true'])
            ->throw()
            ->body();
    }

    private function token(): string
    {
        return Cache::remember('navi.drive.token', now()->addMinutes(50), function () {
            if ($this->credentialsPath && is_file($this->credentialsPath)) {
                $creds = new ServiceAccountCredentials(self::SCOPE, json_decode(file_get_contents($this->credentialsPath), true));
            } elseif ($this->useAdc) {
                // Cloud Run のメタデータサーバーから、サービスに割り当てたサービスアカウントのトークンを得る
                $creds = ApplicationDefaultCredentials::getCredentials(self::SCOPE);
            } else {
                throw new RuntimeException('Driveの認証情報がありません（GOOGLE_APPLICATION_CREDENTIALS か NAVI_DRIVE_USE_ADC=true を設定してください）');
            }
            $token = $creds->fetchAuthToken();

            return $token['access_token'] ?? throw new RuntimeException('Driveのアクセストークンを取得できませんでした');
        });
    }
}
