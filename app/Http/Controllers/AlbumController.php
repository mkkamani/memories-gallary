<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Album;
use App\Services\AlbumService;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class AlbumController extends Controller
{
    use AuthorizesRequests;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $query = Album::with([
            "media" => function ($q) {
                $q->orderBy("created_at", "asc")->limit(1);
            },
        ])
            ->withCount(["media", "children"])
            ->where(function ($q) {
                // All albums are public; still scope to the current user's own
                // albums plus any public ones so the query stays consistent.
                $q->where("user_id", auth()->id())->orWhere("is_public", true);
            });

        // Nested album navigation
        if ($request->has("parent_id")) {
            $query->where("parent_id", $request->parent_id);
        } else {
            $query->whereNull("parent_id");
        }

        if ($request->search) {
            $query->where("title", "like", "%" . $request->search . "%");
        }

        // Location filter
        $userLocation = auth()->user()->location;
        $locationFilter = $request->has("location")
            ? $request->location
            : $userLocation;

        if ($locationFilter && $locationFilter !== "all") {
            $query->where("location", $locationFilter);
        }

        $albums = $query
            ->latest()
            ->get()
            ->map(function ($album) {
                return [
                    "id" => $album->id,
                    "title" => $album->title,
                    "description" => $album->description,
                    "type" => $album->type,
                    "event_date" => $album->event_date,
                    "is_public" => $album->is_public,
                    "user_id" => $album->user_id,
                    "parent_id" => $album->parent_id,
                    "media_count" => $album->media_count,
                    "children_count" => $album->children_count,
                    "thumbnail" => $album->media->first()?->url,
                    "is_system" => false,
                    "location" => $album->location,
                    "created_at" => $album->created_at,
                ];
            });

        // ---- Smart / system albums (root level only) ----
        $systemAlbums = collect();

        if (!$request->has("parent_id")) {
            // Recent – last 30 days
            $recentMedia = \App\Models\Media::where("user_id", auth()->id())
                ->where("created_at", ">=", now()->subDays(30))
                ->orderBy("created_at", "desc")
                ->get();

            if ($recentMedia->count() > 0) {
                $systemAlbums->push([
                    "id" => "recent",
                    "title" => "Recent",
                    "description" => "Photos and videos from the last 30 days",
                    "type" => "system",
                    "event_date" => null,
                    "is_public" => true,
                    "user_id" => auth()->id(),
                    "media_count" => $recentMedia->count(),
                    "children_count" => 0,
                    "thumbnail" => $recentMedia->first()->url,
                    "is_system" => true,
                ]);
            }

            // Today's Memories – same day in previous years
            $todayMemories = \App\Models\Media::where("user_id", auth()->id())
                ->whereRaw("MONTH(created_at) = ?", [now()->month])
                ->whereRaw("DAY(created_at) = ?", [now()->day])
                ->whereRaw("YEAR(created_at) < ?", [now()->year])
                ->orderBy("created_at", "desc")
                ->get();

            if ($todayMemories->count() > 0) {
                $systemAlbums->push([
                    "id" => "todays-memories",
                    "title" => "Today's Memories",
                    "description" => "Photos from this day in previous years",
                    "type" => "system",
                    "event_date" => null,
                    "is_public" => true,
                    "user_id" => auth()->id(),
                    "media_count" => $todayMemories->count(),
                    "children_count" => 0,
                    "thumbnail" => $todayMemories->first()->url,
                    "is_system" => true,
                ]);
            }
        }

        $allAlbums = $systemAlbums->concat($albums);

        // Breadcrumbs for nested navigation
        $breadcrumbs = [];
        if ($request->has("parent_id") && $request->parent_id) {
            $parent = Album::find($request->parent_id);
            if ($parent) {
                $breadcrumbs = $parent
                    ->ancestors()
                    ->reverse()
                    ->map(fn($a) => ["id" => $a->id, "title" => $a->title])
                    ->toArray();
                $breadcrumbs[] = [
                    "id" => $parent->id,
                    "title" => $parent->title,
                ];
            }
        }

        return Inertia::render("Albums/Index", [
            "albums" => $allAlbums,
            "filters" => array_merge(
                $request->only(["search", "parent_id", "location"]),
                ["location" => $locationFilter],
            ),
            "breadcrumbs" => $breadcrumbs,
        ]);
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function create()
    {
        return Inertia::render("Albums/Create");
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function store(
        Request $request,
        AlbumService $albumService,
        ActivityLogService $logService,
    ) {
        $data = $request->validate([
            "title" => "required|string|max:255",
            "description" => "nullable|string",
            "location" => "nullable|string|in:Rajkot,Ahmedabad",
            // parent_id is sent programmatically when creating a sub-folder
            // from the album Show page – it is NOT shown in the Create form.
            "parent_id" => "nullable|exists:albums,id",
            "files" => "nullable|array",
            "files.*" => "file|mimes:jpeg,jpg,png,gif,mp4,mov,avi|max:102400",
        ]);

        // is_public is always true; enforced in AlbumService as well
        $data["is_public"] = true;

        $album = $albumService->create($data, auth()->user());

        if ($request->hasFile("files")) {
            $mediaService = app(\App\Services\MediaService::class);
            foreach ($request->file("files") as $file) {
                $mediaService->upload($file, auth()->user(), $album);
            }
        }

        $logService->logAlbumCreated($album);

        // If this was a sub-folder creation redirect back to the parent album,
        // otherwise go to the albums index.
        if (!empty($data["parent_id"])) {
            return redirect()->route("albums.show", $album->parent_id);
        }

        return redirect()->route("albums.index");
    }

    // -------------------------------------------------------------------------
    // Import from ZIP
    // -------------------------------------------------------------------------

    public function import(
        Request $request,
        AlbumService $albumService,
        \App\Services\MediaService $mediaService,
    ) {
        // Give large ZIP imports plenty of time to complete.
        set_time_limit(600);

        // ── PHP-level upload pre-flight check ────────────────────────────────
        // When the uploaded file exceeds post_max_size PHP empties both
        // $_POST and $_FILES entirely before Laravel even boots its request
        // object, so the normal validator never sees the file at all.
        // We detect that here and return a clear, actionable message.
        $contentLength = (int) ($request->server("CONTENT_LENGTH") ?? 0);
        $postMaxBytes = $this->parseIniBytes(ini_get("post_max_size"));
        $uploadMaxBytes = $this->parseIniBytes(ini_get("upload_max_filesize"));

        if (
            $contentLength > 0 &&
            $postMaxBytes > 0 &&
            $contentLength > $postMaxBytes
        ) {
            $maxMb = round($postMaxBytes / 1048576);
            return back()
                ->withErrors([
                    "zip_file" => "The ZIP file is too large. Maximum allowed upload size is {$maxMb} MB. Please split the archive and try again.",
                ])
                ->withInput();
        }

        // Check for a PHP-level upload error on the file itself.
        $rawFile = $request->files->get("zip_file");
        if ($rawFile !== null) {
            $phpError = method_exists($rawFile, "getError")
                ? $rawFile->getError()
                : UPLOAD_ERR_OK;
            if ($phpError !== UPLOAD_ERR_OK) {
                $uploadMaxMb = round($uploadMaxBytes / 1048576);
                $phpUploadMessages = [
                    UPLOAD_ERR_INI_SIZE => "The ZIP file exceeds the server upload limit ({$uploadMaxMb} MB).",
                    UPLOAD_ERR_FORM_SIZE => "The ZIP file exceeds the form upload limit.",
                    UPLOAD_ERR_PARTIAL => "The ZIP file was only partially uploaded. Please try again.",
                    UPLOAD_ERR_NO_FILE => "No file was uploaded.",
                    UPLOAD_ERR_NO_TMP_DIR => "Server misconfiguration: missing temporary folder.",
                    UPLOAD_ERR_CANT_WRITE => "Server error: failed to write file to disk.",
                    UPLOAD_ERR_EXTENSION => "A PHP extension blocked the upload.",
                ];
                $message =
                    $phpUploadMessages[$phpError] ??
                    "The ZIP file failed to upload (PHP error code {$phpError}).";
                return back()
                    ->withErrors(["zip_file" => $message])
                    ->withInput();
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $request->validate([
            // Accept both "zip" and the generic octet-stream MIME type that
            // some browsers/OS combinations send for .zip files.
            "zip_file" => [
                "required",
                "file",
                function ($attribute, $value, $fail) {
                    $allowed = [
                        "application/zip",
                        "application/x-zip-compressed",
                        "application/octet-stream",
                        "multipart/x-zip",
                    ];
                    $mime = $value->getMimeType();
                    $ext = strtolower($value->getClientOriginalExtension());
                    if ($ext !== "zip" && !in_array($mime, $allowed)) {
                        $fail("The {$attribute} must be a valid ZIP file.");
                    }
                },
            ],
            "parent_id" => "nullable|exists:albums,id",
            "location" => "nullable|string|in:Ahmedabad,Rajkot",
        ]);

        $uploadedFile = $request->file("zip_file");
        $zipPath = $uploadedFile->getRealPath();

        if (!$zipPath || !file_exists($zipPath)) {
            return back()
                ->withErrors([
                    "zip_file" =>
                        "The zip file failed to upload. Please try again.",
                ])
                ->withInput();
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);

        if ($openResult !== true) {
            $reason = match ($openResult) {
                \ZipArchive::ER_NOZIP => "Not a valid ZIP file.",
                \ZipArchive::ER_INCONS => "ZIP file is inconsistent/corrupt.",
                \ZipArchive::ER_MEMORY => "Not enough memory to open the ZIP.",
                default => "Error code: {$openResult}.",
            };
            return back()
                ->withErrors([
                    "zip_file" => "Unable to open the ZIP file. {$reason}",
                ])
                ->withInput();
        }

        $baseAlbumTitle = pathinfo(
            $uploadedFile->getClientOriginalName(),
            PATHINFO_FILENAME,
        );

        $extractPath = storage_path("app/temp/zip_" . uniqid());

        try {
            \Illuminate\Support\Facades\File::makeDirectory(
                $extractPath,
                0755,
                true,
            );

            $zip->extractTo($extractPath);
            $zip->close();

            // Remove macOS artefacts
            if (
                \Illuminate\Support\Facades\File::exists(
                    $extractPath . "/__MACOSX",
                )
            ) {
                \Illuminate\Support\Facades\File::deleteDirectory(
                    $extractPath . "/__MACOSX",
                );
            }

            $baseAlbum = $albumService->create(
                [
                    "title" => $baseAlbumTitle,
                    "parent_id" => $request->parent_id,
                    "location" =>
                        $request->location ?? auth()->user()->location,
                ],
                auth()->user(),
            );

            $this->processExtractedFolder(
                $extractPath,
                $baseAlbum,
                $albumService,
                $mediaService,
            );
        } catch (\Throwable $e) {
            \Log::error("AlbumController: ZIP import failed.", [
                "file" => $uploadedFile->getClientOriginalName(),
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);

            return back()
                ->withErrors([
                    "zip_file" => "Import failed: " . $e->getMessage(),
                ])
                ->withInput();
        } finally {
            // Always clean up the temp directory, even on failure.
            if (\Illuminate\Support\Facades\File::exists($extractPath)) {
                \Illuminate\Support\Facades\File::deleteDirectory($extractPath);
            }
        }

        return redirect()
            ->route("albums.show", $baseAlbum)
            ->with("success", "Album imported successfully.");
    }

    protected function processExtractedFolder(
        string $folderPath,
        Album $parentAlbum,
        AlbumService $albumService,
        \App\Services\MediaService $mediaService,
    ) {
        // Recursively create sub-albums for every sub-directory
        foreach (
            \Illuminate\Support\Facades\File::directories($folderPath)
            as $dir
        ) {
            $subAlbum = $albumService->create(
                [
                    "title" => basename($dir),
                    "parent_id" => $parentAlbum->id,
                    "location" => $parentAlbum->location,
                ],
                auth()->user(),
            );

            $this->processExtractedFolder(
                $dir,
                $subAlbum,
                $albumService,
                $mediaService,
            );
        }

        // Upload media files in this directory to the parent album
        foreach (
            \Illuminate\Support\Facades\File::files($folderPath)
            as $file
        ) {
            if ($file->getFilename() === ".DS_Store") {
                continue;
            }

            $mime = mime_content_type($file->getRealPath());
            if (
                $mime &&
                (str_starts_with($mime, "image") ||
                    str_starts_with($mime, "video"))
            ) {
                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $file->getRealPath(),
                    $file->getFilename(),
                    $mime,
                    null,
                    true,
                );
                $mediaService->upload(
                    $uploadedFile,
                    auth()->user(),
                    $parentAlbum,
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function show(Album $album)
    {
        $this->authorize("view", $album);

        $album->load([
            "media",
            "children" => function ($query) {
                $query
                    ->with([
                        "media" => fn($q) => $q
                            ->orderBy("created_at", "asc")
                            ->limit(1),
                    ])
                    ->withCount(["media", "children"]);
            },
        ]);

        // Attach thumbnail to each child album
        if ($album->children) {
            $album->children->transform(function ($child) {
                $child->thumbnail = $child->media->first()?->url;
                return $child;
            });
        }

        // Breadcrumbs
        $breadcrumbs = $album
            ->ancestors()
            ->reverse()
            ->map(fn($a) => ["id" => $a->id, "title" => $a->title])
            ->toArray();

        return Inertia::render("Albums/Show", [
            "album" => $album,
            "breadcrumbs" => $breadcrumbs,
        ]);
    }

    // -------------------------------------------------------------------------
    // Edit / Update
    // -------------------------------------------------------------------------

    public function edit(Album $album)
    {
        $this->authorize("update", $album);

        return Inertia::render("Albums/Edit", [
            "album" => $album,
        ]);
    }

    public function update(
        Request $request,
        Album $album,
        AlbumService $albumService,
        ActivityLogService $logService,
    ) {
        $this->authorize("update", $album);

        $data = $request->validate([
            "title" => "required|string|max:255",
            "description" => "nullable|string",
            "location" => "nullable|string|in:Rajkot,Ahmedabad",
        ]);

        $album = $albumService->update($album, $data);
        $logService->logAlbumUpdated($album);

        return redirect()
            ->route("albums.show", $album)
            ->with("success", "Album updated successfully.");
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function destroy(Album $album, ActivityLogService $logService)
    {
        $this->authorize("delete", $album);

        $childrenCount = $album->children()->count();
        $message =
            $childrenCount > 0
                ? "Album and {$childrenCount} nested album(s) deleted successfully."
                : "Album deleted successfully.";

        $logService->logAlbumDeleted($album);
        $album->delete();

        return redirect()->route("albums.index")->with("success", $message);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a PHP ini shorthand value (e.g. "512M", "2G", "128K") to bytes.
     */
    private function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === "" || $value === "-1") {
            return 0; // 0 means "no limit" in this context
        }
        $last = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;
        return match ($last) {
            "g" => $number * 1073741824,
            "m" => $number * 1048576,
            "k" => $number * 1024,
            default => $number,
        };
    }

    // -------------------------------------------------------------------------
    // System Albums (smart albums)
    // -------------------------------------------------------------------------

    public function showSystemAlbum(string $type)
    {
        $album = null;
        $media = collect();

        if ($type === "recent") {
            $media = \App\Models\Media::where("user_id", auth()->id())
                ->where("created_at", ">=", now()->subDays(30))
                ->orderBy("created_at", "desc")
                ->get();

            $album = [
                "id" => "recent",
                "title" => "Recent",
                "description" => "Photos and videos from the last 30 days",
                "type" => "system",
                "is_system" => true,
                "media" => $media,
            ];
        } elseif ($type === "todays-memories") {
            $media = \App\Models\Media::where("user_id", auth()->id())
                ->whereRaw("MONTH(created_at) = ?", [now()->month])
                ->whereRaw("DAY(created_at) = ?", [now()->day])
                ->whereRaw("YEAR(created_at) < ?", [now()->year])
                ->orderBy("created_at", "desc")
                ->get();

            $album = [
                "id" => "todays-memories",
                "title" => "Today's Memories",
                "description" => "Photos from this day in previous years",
                "type" => "system",
                "is_system" => true,
                "media" => $media,
            ];
        }

        if (!$album) {
            abort(404);
        }

        return Inertia::render("Albums/Show", ["album" => $album]);
    }
}
