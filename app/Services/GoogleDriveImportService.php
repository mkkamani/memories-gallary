<?php

namespace App\Services;

use Google_Client;
use Google_Service_Drive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media;
use App\Models\Album;
use App\Support\MediaDimensionExtractor;
use GuzzleHttp\Client as GuzzleClient;

class GoogleDriveImportService
{
    protected $client;
    protected $drive;

    public function __construct()
    {
        if (!class_exists(Google_Client::class)) {
            throw new \RuntimeException('google/apiclient is required. Run: composer require google/apiclient');
        }

        $this->client = new Google_Client();

        // Allow toggling SSL certificate verification via env for temporary debugging only
        $verify = env('GOOGLE_DRIVE_VERIFY_SSL', true);
        $verify = filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verify === null) {
            $verify = true;
        }

        $guzzle = new GuzzleClient([
            'verify' => $verify,
            'timeout' => 120,
        ]);
        $this->client->setHttpClient($guzzle);

        // Configure client: prefer credentials json path in env
        $credentialsPath = env('GOOGLE_DRIVE_CREDENTIALS_PATH');
        $credentialsJson = env('GOOGLE_DRIVE_CREDENTIALS_JSON');
        $apiKey = env('GOOGLE_DRIVE_API_KEY');

        if ($credentialsPath && file_exists($credentialsPath)) {
            $this->client->setAuthConfig($credentialsPath);

            // Optional impersonation (service account acting as user)
            $impersonate = env('GOOGLE_DRIVE_IMPERSONATE');
            if ($impersonate) {
                $this->client->setSubject($impersonate);
            }

            $this->client->addScope(Google_Service_Drive::DRIVE_READONLY);
        } elseif ($credentialsJson) {
            $this->client->setAuthConfig(json_decode($credentialsJson, true));

            $impersonate = env('GOOGLE_DRIVE_IMPERSONATE');
            if ($impersonate) {
                $this->client->setSubject($impersonate);
            }

            $this->client->addScope(Google_Service_Drive::DRIVE_READONLY);
        } elseif ($apiKey) {
            // Public data access using API key (developer key) — good for publicly shared folders/files
            $this->client->setDeveloperKey($apiKey);
            // No impersonation or scopes required for public data via API key
        } else {
            throw new \RuntimeException('Google Drive credentials not configured. Set GOOGLE_DRIVE_CREDENTIALS_PATH, GOOGLE_DRIVE_CREDENTIALS_JSON, or GOOGLE_DRIVE_API_KEY in .env');
        }

        $this->drive = new Google_Service_Drive($this->client);
    }

    /**
     * Import a Drive folder.
     * Returns number of files processed.
     */
    public function importFolder(string $folderId, array $options = []): int
    {
        $output = $options['output'] ?? function () {};
        $dryRun = $options['dry_run'] ?? false;
        $userId = $options['user_id'] ?? 1;
        $albumId = $options['album_id'] ?? null;
        $recursive = $options['recursive'] ?? false;
        $impersonate = $options['impersonate'] ?? null;
        $supportsAllDrives = $options['supports_all_drives'] ?? true;

        // If impersonation provided in options, set subject on client
        if ($impersonate && method_exists($this->client, 'setSubject')) {
            $this->client->setSubject($impersonate);
        }

        // If recursive import requested, walk directory tree and create albums
        if ($recursive) {
            return $this->importFolderRecursive($folderId, $albumId, $userId, $options);
        }

        $processed = 0;

        $q = "'{$folderId}' in parents and trashed = false";

        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, parents)',
                'supportsAllDrives' => $supportsAllDrives,
                'includeItemsFromAllDrives' => $supportsAllDrives,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $results = $this->drive->files->listFiles($params);

            foreach ($results->getFiles() as $file) {
                $mime = $file->getMimeType();
                $name = $file->getName();
                $id = $file->getId();

                // If this is a folder, create an album and import its immediate media children
                if ($mime === 'application/vnd.google-apps.folder') {
                    ($output)("Found folder: {$name} ({$id}) — creating album and importing its files");

                    // find or create album for this folder
                    $album = Album::where('drive_folder_id', $id)->first();
                    if (!$album) {
                        if ($dryRun) {
                            ($output)("[dry-run] Would create album: {$name}");
                        } else {
                            $slugBase = Str::slug($name ?: 'folder');
                            $slug = $slugBase;
                            $i = 1;
                            while (Album::where('slug', $slug)->exists()) {
                                $slug = $slugBase.'-'.$i++;
                            }

                            $album = Album::create([
                                'user_id' => $userId,
                                'parent_id' => $albumId,
                                'drive_folder_id' => $id,
                                'title' => $name,
                                'slug' => $slug,
                                'description' => 'Imported from Google Drive folder ' . $id,
                                'is_public' => true,
                            ]);

                            ($output)("Created album: {$album->title} (id={$album->id})");
                        }
                    } else {
                        ($output)("Using existing album: {$album->title} (id={$album->id})");
                    }

                    if (!$dryRun) {
                        $processed += $this->importChildrenIntoAlbum($id, $album->id ?? $albumId, $userId, $supportsAllDrives, $output);
                    }

                    continue;
                }

                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');

                if (!$isImage && !$isVideo) {
                    ($output)("Skipping non-media file: {$name} ({$mime})");
                    continue;
                }

                ($output)("Processing: {$name} ({$mime})");

                if ($dryRun) {
                    $processed++;
                    continue;
                }

                // Download file content (allow files in Shared Drives)
                $resp = $this->drive->files->get($id, ['alt' => 'media', 'supportsAllDrives' => $supportsAllDrives]);
                $content = $resp->getBody()->getContents();
                [$width, $height] = MediaDimensionExtractor::fromBinary($content, $mime, $name);

                $storagePath = 'media/drive/'.date('Y/m/d');
                $fileName = $name;
                $stored = Storage::disk('public')->put("{$storagePath}/{$fileName}", $content);

                $mediaData = [
                    'album_id' => $albumId,
                    'user_id' => $userId,
                    'file_path' => $stored ? "{$storagePath}/{$fileName}" : null,
                    'file_name' => $fileName,
                    'file_type' => $isImage ? 'image' : 'video',
                    'file_size' => $file->getSize() ?? null,
                    'mime_type' => $mime,
                    'width' => $width,
                    'height' => $height,
                ];

                Media::create($mediaData);

                $processed++;
            }

            $pageToken = $results->getNextPageToken();
        } while ($pageToken);

        return $processed;
    }

    protected function importFolderRecursive(string $folderId, $parentAlbumId = null, int $userId = 1, array $options = []): int
    {
        $output = $options['output'] ?? function () {};
        $dryRun = $options['dry_run'] ?? false;
        $supportsAllDrives = $options['supports_all_drives'] ?? true;

        $processed = 0;

        // Fetch folder metadata
        $folder = $this->drive->files->get($folderId, ['fields' => 'id,name', 'supportsAllDrives' => $supportsAllDrives]);
        $folderName = $folder->getName();

        ($output)("Entering folder: {$folderName} ({$folderId})");

        // Find or create album for this folder
        $album = Album::where('drive_folder_id', $folderId)->first();

        if (!$album) {
            if ($dryRun) {
                ($output)("[dry-run] Would create album: {$folderName}");
            } else {
                $slugBase = Str::slug($folderName ?: 'folder');
                $slug = $slugBase;
                $i = 1;
                while (Album::where('slug', $slug)->exists()) {
                    $slug = $slugBase.'-'.$i++;
                }

                $album = Album::create([
                    'user_id' => $userId,
                    'parent_id' => $parentAlbumId,
                    'drive_folder_id' => $folderId,
                    'title' => $folderName,
                    'slug' => $slug,
                    'description' => 'Imported from Google Drive folder ' . $folderId,
                    'is_public' => false,
                ]);

                ($output)("Created album: {$album->title} (id={$album->id})");
            }
        } else {
            ($output)("Using existing album: {$album->title} (id={$album->id})");
        }

        $albumId = $album->id ?? $parentAlbumId;

        // List children in folder
        $q = "'{$folderId}' in parents and trashed = false";
        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, parents)',
                'supportsAllDrives' => $supportsAllDrives,
                'includeItemsFromAllDrives' => $supportsAllDrives,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $results = $this->drive->files->listFiles($params);

            foreach ($results->getFiles() as $file) {
                $mime = $file->getMimeType();
                $name = $file->getName();
                $id = $file->getId();

                // Folder
                if ($mime === 'application/vnd.google-apps.folder') {
                    $processed += $this->importFolderRecursive($id, $albumId, $userId, $options);
                    continue;
                }

                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');

                if (!$isImage && !$isVideo) {
                    ($output)("Skipping non-media file: {$name} ({$mime})");
                    continue;
                }

                ($output)("Processing: {$name} ({$mime})");

                if ($dryRun) {
                    $processed++;
                    continue;
                }

                $resp = $this->drive->files->get($id, ['alt' => 'media', 'supportsAllDrives' => $supportsAllDrives]);
                $content = $resp->getBody()->getContents();
                [$width, $height] = MediaDimensionExtractor::fromBinary($content, $mime, $name);

                $storagePath = 'media/drive/'.date('Y/m/d');
                $fileName = $name;
                $stored = Storage::disk('public')->put("{$storagePath}/{$fileName}", $content);

                $mediaData = [
                    'album_id' => $albumId,
                    'user_id' => $userId,
                    'file_path' => $stored ? "{$storagePath}/{$fileName}" : null,
                    'file_name' => $fileName,
                    'file_type' => $isImage ? 'image' : 'video',
                    'file_size' => $file->getSize() ?? null,
                    'mime_type' => $mime,
                    'width' => $width,
                    'height' => $height,
                ];

                Media::create($mediaData);

                $processed++;
            }

            $pageToken = $results->getNextPageToken();
        } while ($pageToken);

        return $processed;
    }

    /**
     * Import immediate children (media files) of a Drive folder into the given album.
     * Does not recurse into subfolders.
     */
    protected function importChildrenIntoAlbum(string $folderId, $albumId, int $userId, bool $supportsAllDrives, callable $output): int
    {
        $processed = 0;

        $q = "'{$folderId}' in parents and trashed = false";
        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, parents)',
                'supportsAllDrives' => $supportsAllDrives,
                'includeItemsFromAllDrives' => $supportsAllDrives,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $results = $this->drive->files->listFiles($params);

            foreach ($results->getFiles() as $file) {
                $mime = $file->getMimeType();
                $name = $file->getName();
                $id = $file->getId();

                // Skip subfolders here — only import files one level deep
                if ($mime === 'application/vnd.google-apps.folder') {
                    continue;
                }

                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');

                if (!$isImage && !$isVideo) {
                    ($output)("Skipping non-media file: {$name} ({$mime})");
                    continue;
                }

                ($output)("Importing child file into album: {$name} ({$mime})");

                $resp = $this->drive->files->get($id, ['alt' => 'media', 'supportsAllDrives' => $supportsAllDrives]);
                $content = $resp->getBody()->getContents();
                [$width, $height] = MediaDimensionExtractor::fromBinary($content, $mime, $name);

                $storagePath = 'media/drive/'.date('Y/m/d');
                $fileName = $name;
                $stored = Storage::disk('public')->put("{$storagePath}/{$fileName}", $content);

                $mediaData = [
                    'album_id' => $albumId,
                    'user_id' => $userId,
                    'file_path' => $stored ? "{$storagePath}/{$fileName}" : null,
                    'file_name' => $fileName,
                    'file_type' => $isImage ? 'image' : 'video',
                    'file_size' => $file->getSize() ?? null,
                    'mime_type' => $mime,
                    'width' => $width,
                    'height' => $height,
                ];

                Media::create($mediaData);

                $processed++;
            }

            $pageToken = $results->getNextPageToken();
        } while ($pageToken);

        return $processed;
    }
}
