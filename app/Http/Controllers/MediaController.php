<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Album;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MediaController extends Controller
{
    use AuthorizesRequests;
    public function store(Request $request, MediaService $service, ActivityLogService $logService)
    {
        $request->validate([
            'files.*' => 'required|file|mimes:jpg,jpeg,png,mp4|max:102400',
            'album_id' => 'nullable|exists:albums,id',
        ]);

        $album = $request->album_id ? Album::find($request->album_id) : null;

        foreach ($request->file('files') as $file) {
            $media = $service->upload($file, auth()->user(), $album);
            $logService->logMediaUploaded($media);
        }

        return back();
    }

    public function destroy(Media $media, MediaService $service, ActivityLogService $logService)
    {
        $this->authorize('delete', $media);
        $logService->logMediaDeleted($media);
        $service->delete($media);
        return back();
    }

    public function bulkDelete(Request $request, MediaService $service, ActivityLogService $logService)
    {
        $request->validate(['ids' => 'required|array']);
        
        $mediaItems = Media::whereIn('id', $request->ids)->get();
        $count = 0;
        
        foreach ($mediaItems as $media) {
            if (auth()->user()->can('delete', $media)) {
                $service->delete($media);
                $count++;
            }
        }
        
        $logService->logBulkAction('delete', $count);
        return back();
    }

    public function bulkDownload(Request $request, ActivityLogService $logService)
    {
        $request->validate(['ids' => 'required|array']);
        
        $mediaItems = Media::whereIn('id', $request->ids)->get();
        
        if ($mediaItems->isEmpty()) {
            return back();
        }

        $zip = new \ZipArchive;
        $fileName = 'download-' . time() . '.zip';
        $filePath = storage_path('app/public/' . $fileName);

        if ($zip->open($filePath, \ZipArchive::CREATE) === TRUE) {
            foreach ($mediaItems as $media) {
                if (auth()->user()->can('view', $media)) {
                    $zip->addFile(storage_path('app/public/' . $media->file_path), $media->file_name);
                }
            }
            $zip->close();
        }

        $logService->logBulkAction('download', $mediaItems->count());
        return response()->download($filePath)->deleteFileAfterSend(true);
    }
}
