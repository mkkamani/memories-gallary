<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleDriveImportService;

class DriveImport extends Command
{
    protected $signature = 'drive:import
        {folder : Google Drive folder ID to import from}
        {--album_id= : Optional album id to attach media to}
        {--user_id=1 : User id to assign as owner}
        {--dry-run : Do not persist records or files}
        {--recursive : Recurse into subfolders}
        {--impersonate= : Email to impersonate (service account subject)}
        {--supports-all-drives=1 : Set to 0 to disable supportsAllDrives}
    ';

    protected $description = 'Bulk import images and videos from Google Drive';

    public function handle(GoogleDriveImportService $service)
    {
        $folder = $this->argument('folder');
        $albumId = $this->option('album_id');
        $userId = $this->option('user_id') ?: 1;
        $dryRun = $this->option('dry-run');
        $recursive = $this->option('recursive');
        $impersonate = $this->option('impersonate');
        $supportsAllDrives = (bool) $this->option('supports-all-drives');

        $this->info("Starting import from Drive folder: {$folder}");

        try {
            $count = $service->importFolder($folder, [
                'album_id' => $albumId,
                'user_id' => $userId,
                'dry_run' => $dryRun,
                'recursive' => $recursive,
                'impersonate' => $impersonate,
                'supports_all_drives' => $supportsAllDrives,
                'output' => function ($message) {
                    $this->line($message);
                }
            ]);

            $this->info("Import finished. Processed: {$count} files.");
            return 0;
        } catch (\Exception $e) {
            $this->error('Import failed: '.$e->getMessage());
            return 1;
        }
    }
}
