<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeR2Folders extends Command
{
    protected $signature = 'r2:purge-folders
        {--disk= : Filesystem disk to clean. Defaults to filesystems.media_disk}
        {--keep=albums,avatars : Comma-separated top-level directories to preserve}
        {--dry-run : Show what would be deleted without deleting anything}
        {--force : Delete without interactive confirmation}';

    protected $description = 'Delete all top-level folders from the configured R2/media disk except protected folders';

    public function handle(): int
    {
        $disk = (string) ($this->option('disk') ?: config('filesystems.media_disk', 'public'));
        $keep = collect(explode(',', (string) $this->option('keep')))
            ->map(fn (string $path) => trim($path, " \t\n\r\0\x0B/"))
            ->filter()
            ->unique()
            ->values();

        if ($keep->isEmpty()) {
            $this->error('At least one folder must be preserved.');
            return self::FAILURE;
        }

        $storage = Storage::disk($disk);
        $keepList = $keep->implode(', ');

        $this->info("Scanning R2/media disk [{$disk}] for top-level directories...");
        $this->line("Protected directories: {$keepList}");

        try {
            $directories = collect($storage->directories(''))
                ->map(fn (string $path) => trim($path, '/'))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        } catch (\Throwable $e) {
            $this->error('Failed to list disk contents: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($directories->isEmpty()) {
            $this->info('No top-level directories found.');
            return self::SUCCESS;
        }

        $toDelete = $directories
            ->reject(fn (string $path) => $keep->contains($path))
            ->values();

        $this->newLine();
        $this->info(sprintf(
            'Found %d top-level director%s. %d will be kept and %d will be deleted.',
            $directories->count(),
            $directories->count() === 1 ? 'y' : 'ies',
            $directories->count() - $toDelete->count(),
            $toDelete->count(),
        ));

        $this->table(
            ['Directory', 'Action'],
            $directories->map(function (string $path) use ($keep) {
                $isKept = $keep->contains($path);

                return [
                    $isKept ? $path : sprintf('<fg=red>%s</>', $path),
                    $isKept ? 'keep' : '<fg=red>delete</>',
                ];
            })->all(),
        );

        if ($toDelete->isEmpty()) {
            $this->info('Nothing to delete. Only protected directories were found.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] No directories were deleted.');
            $this->line('Directories that would be deleted: ' . $toDelete->implode(', '));
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('Safety check: this command was run without --force.');
            $this->line('If this was accidental, press N at the confirmation prompt below.');
        }

        $confirmationMessage = sprintf(
            'You are about to permanently delete %d director%s from disk [%s]. Protected directories [%s] will be preserved. Continue?',
            $toDelete->count(),
            $toDelete->count() === 1 ? 'y' : 'ies',
            $disk,
            $keepList,
        );

        if (! $this->option('force') && ! $this->confirm(
            $confirmationMessage,
            false,
        )) {
            $this->warn('Operation cancelled. No directories were deleted.');
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $typedConfirmation = 'DELETE';
            $enteredConfirmation = strtoupper(trim((string) $this->ask(
                sprintf(
                    'Final safety check: type %s to permanently delete the listed directories',
                    $typedConfirmation,
                ),
            )));

            if ($enteredConfirmation !== $typedConfirmation) {
                $this->warn('Typed confirmation did not match. Operation cancelled. No directories were deleted.');
                return self::SUCCESS;
            }
        }

        if ($this->option('force')) {
            $this->warn(sprintf(
                '[FORCE] Permanently deleting %d director%s from disk [%s].',
                $toDelete->count(),
                $toDelete->count() === 1 ? 'y' : 'ies',
                $disk,
            ));
        }

        $deleted = 0;
        $failed = [];

        foreach ($toDelete as $path) {
            try {
                $result = $storage->deleteDirectory($path);

                if ($result === false) {
                    $failed[] = $path;
                    $this->error("Failed to delete [{$path}].");
                    continue;
                }

                $deleted++;
                $this->info("Deleted [{$path}].");
            } catch (\Throwable $e) {
                $failed[] = $path;
                $this->error("Failed to delete [{$path}]: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Deleted {$deleted} director" . ($deleted === 1 ? 'y' : 'ies') . '.');

        if ($failed !== []) {
            $this->warn('Some directories could not be deleted: ' . implode(', ', $failed));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
