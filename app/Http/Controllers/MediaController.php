<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use App\Models\Album;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MediaController extends Controller
{
    use AuthorizesRequests;
    public function store(
        Request $request,
        MediaService $service,
        ActivityLogService $logService,
    ) {
        $request->validate([
            "files" => "required|array",
            "files.*" =>
                "file|mimes:jpg,jpeg,png,gif,heic,heif,mp4,mov,avi,webm,mkv|max:204800",
            "album_id" => "nullable|exists:albums,id",
        ]);

        $album = $request->album_id ? Album::find($request->album_id) : null;

        foreach ($request->file("files") as $file) {
            $media = $service->upload($file, auth()->user(), $album);
            $logService->logMediaUploaded($media);
        }

        return back();
    }

    /**
     * Stream a media file's raw bytes through the app server.
     * This proxy avoids CORS issues when the media is stored on an external
     * origin (e.g. Cloudflare R2 presigned URLs) and the browser cannot
     * fetch them directly via JavaScript.
     */
    public function raw(Media $media)
    {
        $this->authorize("view", $media);

        $disk = (string) config("filesystems.media_disk", "public");
        $stream = Storage::disk($disk)->readStream($media->file_path);

        if (!$stream) {
            abort(404, "Media file not found.");
        }

        $mimeType = $media->mime_type ?: "application/octet-stream";
        $fileName = basename((string) $media->file_name);

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                "Content-Type" => $mimeType,
                "Content-Disposition" => 'inline; filename="' . $fileName . '"',
                "Cache-Control" => "private, max-age=3600",
                "X-Accel-Buffering" => "no",
            ],
        );
    }

    public function destroy(
        Media $media,
        MediaService $service,
        ActivityLogService $logService,
    ) {
        $this->authorize("delete", $media);

        $fileName = $media->file_name;

        try {
            $logService->logMediaDeleted($media);
            $result = $service->delete($media);

            if (!$result) {
                throw new \RuntimeException("Failed to soft-delete media record.");
            }
        } catch (\Throwable $e) {
            \Log::error("MediaController::destroy – failed to delete media.", [
                "media_id" => $media->id,
                "file_name" => $fileName,
                "error" => $e->getMessage(),
            ]);

            return back()->with(
                "error",
                'Failed to delete "' . $fileName . '". Please try again.',
            );
        }

        return back()->with(
            "success",
            '"' . $fileName . '" has been moved to the Recycle Bin.',
        );
    }

    public function bulkDelete(
        Request $request,
        MediaService $service,
        ActivityLogService $logService,
    ) {
        $request->validate(["ids" => "required|array", "ids.*" => "integer"]);

        $mediaItems = Media::whereIn("id", $request->ids)->get();
        $count = 0;
        $skipped = 0;

        foreach ($mediaItems as $media) {
            // Individually authorize every item — admins/managers can delete
            // any media, members can only delete their own.
            if (auth()->user()->cannot("delete", $media)) {
                $skipped++;
                continue;
            }

            $service->delete($media);
            $count++;
        }

        $logService->logBulkAction("delete", $count);

        if ($count === 0 && $skipped > 0) {
            return back()->with(
                "error",
                "No items deleted. You do not have permission to delete the selected {$skipped} item(s).",
            );
        }

        if ($skipped > 0) {
            return back()->with(
                "warning",
                "Moved {$count} item(s) to the Recycle Bin. {$skipped} item(s) were skipped (no permission).",
            );
        }

        return back()->with(
            "success",
            "Moved {$count} item(s) to the Recycle Bin.",
        );
    }

    public function bulkDownload(
        Request $request,
        ActivityLogService $logService,
    ) {
        $request->validate(["ids" => "required|array"]);

        $mediaItems = Media::whereIn("id", $request->ids)->get();

        if ($mediaItems->isEmpty()) {
            return back();
        }

        $zip = new \ZipArchive();
        $fileName = "download-" . time() . ".zip";
        $filePath = storage_path("app/public/" . $fileName);

        if ($zip->open($filePath, \ZipArchive::CREATE) === true) {
            foreach ($mediaItems as $media) {
                if (auth()->user()->can("view", $media)) {
                    $zip->addFile(
                        storage_path("app/public/" . $media->file_path),
                        $media->file_name,
                    );
                }
            }
            $zip->close();
        }

        $logService->logBulkAction("download", $mediaItems->count());
        return response()->download($filePath)->deleteFileAfterSend(true);
    }
}
