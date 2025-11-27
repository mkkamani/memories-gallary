<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogService
{
    public function log(string $action, string $description, $subject = null, array $properties = [])
    {
        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'properties' => $properties,
        ]);
    }

    public function logAlbumCreated($album)
    {
        $this->log('album.created', "Created album '{$album->title}'", $album);
    }

    public function logAlbumUpdated($album)
    {
        $this->log('album.updated', "Updated album '{$album->title}'", $album);
    }

    public function logAlbumDeleted($album)
    {
        $this->log('album.deleted', "Deleted album '{$album->title}'", $album);
    }

    public function logMediaUploaded($media)
    {
        $this->log('media.uploaded', "Uploaded {$media->file_type} '{$media->file_name}'", $media);
    }

    public function logMediaDeleted($media)
    {
        $this->log('media.deleted', "Deleted {$media->file_type} '{$media->file_name}'", $media);
    }

    public function logBulkAction(string $action, int $count, string $type = 'media')
    {
        $this->log("bulk.{$action}", "Performed bulk {$action} on {$count} {$type} items", null, ['count' => $count, 'type' => $type]);
    }
}
