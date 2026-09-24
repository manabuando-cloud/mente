<?php

namespace App\Services\Drive;

/**
 * Google Drive の読み取り専用操作。テストでは FakeDriveClient に差し替える。
 *
 * ファイルは ['id', 'name', 'mimeType', 'modifiedTime', 'webViewLink'] の配列で表す。
 */
interface DriveClient
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** @return list<array{id: string, name: string, mimeType: string, modifiedTime?: string, webViewLink?: string}> */
    public function listChildren(string $folderId): array;

    public function download(string $fileId): string;
}
