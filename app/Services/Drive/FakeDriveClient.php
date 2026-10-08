<?php

namespace App\Services\Drive;

/** テスト用のインメモリDrive。 */
class FakeDriveClient implements DriveClient
{
    /** @var array<string, list<array>> */
    public array $children = [];

    /** @var array<string, string> */
    public array $contents = [];

    public function folder(string $parentId, string $id, string $name): static
    {
        $this->children[$parentId][] = ['id' => $id, 'name' => $name, 'mimeType' => self::FOLDER_MIME];

        return $this;
    }

    public function file(string $parentId, string $id, string $name, string $content = '%PDF-fake', string $mime = 'application/pdf', ?string $createdTime = null): static
    {
        $this->children[$parentId][] = array_filter([
            'id' => $id, 'name' => $name, 'mimeType' => $mime,
            'createdTime' => $createdTime, 'modifiedTime' => $createdTime,
            'webViewLink' => "https://drive.google.com/file/d/{$id}/view",
        ]);
        $this->contents[$id] = $content;

        return $this;
    }

    public function listChildren(string $folderId): array
    {
        return $this->children[$folderId] ?? [];
    }

    public function download(string $fileId): string
    {
        return $this->contents[$fileId] ?? '';
    }
}
