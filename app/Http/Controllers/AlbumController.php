<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Services\ActivityLogService;
use App\Services\AlbumService;
use App\Services\MediaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AlbumController extends Controller
{
    use AuthorizesRequests;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $pinnedAlbumIds = auth()
            ->user()
            ->pinnedAlbums()
            ->pluck("albums.id")
            ->all();

        $query = Album::withCount(["media", "children"])->where(function ($q) {
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
        $userLocation = auth()->user()->location ?: "Rajkot";
        $locationFilter = $request->has("location")
            ? $request->location
            : $userLocation;

        if ($locationFilter && $locationFilter !== "all") {
            $query->where("location", $locationFilter);
        }

        $albums = $query
            ->latest()
            ->get()
            ->map(function ($album) use ($pinnedAlbumIds) {
                $nestedAlbumIds = $album->descendants()->pluck("id")->prepend($album->id)->all();
                $nestedMediaQuery = \App\Models\Media::whereIn("album_id", $nestedAlbumIds)
                    ->orderBy("created_at", "desc");

                $nestedMedia = $nestedMediaQuery->limit(5)->get();
                $nestedMediaCount = \App\Models\Media::whereIn("album_id", $nestedAlbumIds)->count();

                // Use cover_image if available, otherwise first media
                $thumbnailUrl = $this->resolveCoverImageUrl($album->cover_image) ?: ($nestedMedia->first()?->url);
                $thumbnailMedia = $album->cover_image ? null : $nestedMedia->first();

                return [
                    "id" => $album->id,
                    "slug" => $album->slug,
                    "path" => $album->path,
                    "title" => $album->title,
                    "description" => $album->description,
                    "type" => $album->type,
                    "event_date" => $album->event_date,
                    "is_public" => $album->is_public,
                    "user_id" => $album->user_id,
                    "parent_id" => $album->parent_id,
                    "media_count" => $nestedMediaCount,
                    "children_count" => $album->children_count,
                    "thumbnail" => $thumbnailUrl,
                    "thumbnail_media" => $thumbnailMedia
                        ? [
                            "url" => $thumbnailMedia->url,
                            "file_type" => $thumbnailMedia->file_type,
                            "file_name" => $thumbnailMedia->file_name,
                            "mime_type" => $thumbnailMedia->mime_type,
                        ]
                        : null,
                    "is_pinned" => in_array($album->id, $pinnedAlbumIds, true),
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
                $thumbnailMedia = $recentMedia->first();
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
                    "thumbnail" => $thumbnailMedia->url,
                    "thumbnail_media" => [
                        "url" => $thumbnailMedia->url,
                        "file_type" => $thumbnailMedia->file_type,
                        "file_name" => $thumbnailMedia->file_name,
                        "mime_type" => $thumbnailMedia->mime_type,
                    ],
                    "is_system" => true,
                ]);
            }

            // Today's Memories – same day in previous years
            $todayMemories = \App\Models\Media::where("user_id", auth()->id())
                ->whereMonth('created_at', now()->month)
                ->whereDay('created_at', now()->day)
                ->whereYear('created_at', '<', now()->year)
                ->orderBy("created_at", "desc")
                ->get();

            if ($todayMemories->count() > 0) {
                $thumbnailMedia = $todayMemories->first();
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
                    "thumbnail" => $thumbnailMedia->url,
                    "thumbnail_media" => [
                        "url" => $thumbnailMedia->url,
                        "file_type" => $thumbnailMedia->file_type,
                        "file_name" => $thumbnailMedia->file_name,
                        "mime_type" => $thumbnailMedia->mime_type,
                    ],
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
                    ->values()
                    ->map(
                        fn($a) => [
                            "id" => $a->id,
                            "slug" => $a->slug,
                            "path" => $a->path,
                            "title" => $a->title,
                        ],
                    )
                    ->toArray();
                $breadcrumbs[] = [
                    "id" => $parent->id,
                    "slug" => $parent->slug,
                    "path" => $parent->path,
                    "title" => $parent->title,
                ];
            }
        }

        return Inertia::render("Albums/Index", [
            "albums" => $allAlbums,
            "pinnedAlbumIds" => $pinnedAlbumIds,
            "filters" => array_merge(
                $request->only(["search", "parent_id", "location"]),
                ["location" => $locationFilter],
            ),
            "breadcrumbs" => $breadcrumbs,
        ]);
    }

    private function resolveCoverImageUrl(?string $coverImage): ?string
    {
        if (empty($coverImage)) {
            return null;
        }

        if (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://')) {
            return $coverImage;
        }

        // Local public storage path.
        if (Storage::disk('public')->exists($coverImage)) {
            return Storage::disk('public')->url($coverImage);
        }

        // Fallback to interface-based behavior for other disks.
        $storageService = app(\App\Interfaces\StorageServiceInterface::class);
        return $storageService->getFileUrl($coverImage);
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
            "cover_image" => "nullable|file|mimes:jpeg,jpg,png,gif|max:10240",
            // parent_id is sent programmatically when creating a sub-folder
            // from the album Show page – it is NOT shown in the Create form.
            "parent_id" => "nullable|exists:albums,id",
            "files" => "nullable|array",
            "files.*" => "file|mimes:jpeg,jpg,png,gif,mp4,mov,avi|max:102400",
        ]);

        // is_public is always true; enforced in AlbumService as well
        $data["is_public"] = true;

        $album = $albumService->create($data, auth()->user());

        // Handle cover image upload (store in local public disk and save as local path).
        if ($request->hasFile("cover_image")) {
            $filePath = $request->file('cover_image')->store('album-cover-images', 'public');
            $album->update(['cover_image' => $filePath]);
        }

        if ($request->hasFile("files")) {
            $mediaService = app(\App\Services\MediaService::class);
            foreach ($request->file("files") as $file) {
                $mediaService->upload($file, auth()->user(), $album);
            }
        }

        $logService->logAlbumCreated($album);

        // JSON response for async (axios) requests — e.g. inline folder creation on Show page
        if ($request->wantsJson()) {
            $album->load(['media' => fn($q) => $q->latest()->limit(1)]);
            $thumbnailMedia = $album->media->first();

            return response()->json([
                'album' => [
                    'id'             => $album->id,
                    'slug'           => $album->slug,
                    'path'           => $album->path,
                    'title'          => $album->title,
                    'description'    => $album->description,
                    'location'       => $album->location,
                    'media_count'    => 0,
                    'children_count' => 0,
                    'thumbnail'      => $thumbnailMedia?->url,
                    'thumbnail_media'=> null,
                    'user_id'        => $album->user_id,
                    'parent_id'      => $album->parent_id,
                    'created_at'     => $album->created_at,
                ],
            ]);
        }

        // If this was a sub-folder creation redirect back to the parent album,
        // otherwise go to the albums index.
        if (!empty($data["parent_id"])) {
            $parent = Album::find($data["parent_id"]);
            return $parent
                ? redirect()->route("albums.show", $parent->path)
                : redirect()->route("albums.index");
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
            Log::error("AlbumController: ZIP import failed.", [
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
                ->route("albums.show", $baseAlbum->path)
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
                try {
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

                    // Free memory after each file to prevent exhaustion
                    unset($uploadedFile);
                    gc_collect_cycles();
                } catch (\Throwable $e) {
                    Log::warning('Failed to upload extracted media file', [
                        'file' => $file->getFilename(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Upload Page / Upload Store
    // -------------------------------------------------------------------------

    public function uploadPage(string $path, Request $request)
    {
        $album = $this->resolveAlbumFromPath($path);

        $requestedPath = trim((string) $path, '/');
        $canonicalPath = (string) $album->path;
        if ($requestedPath !== $canonicalPath) {
            $queryString = $request->getQueryString();
            $canonicalUrl = route('albums.upload', $canonicalPath);

            return redirect()->to(
                $queryString ? ($canonicalUrl . '?' . $queryString) : $canonicalUrl,
            );
        }

        $this->authorize("view", $album);

        $album->load([
            "user:id,name,role",
            "media" => fn($q) => $q->latest()->limit(1),
        ]);

        $breadcrumbs = $album
            ->ancestors()
            ->reverse()
            ->values()
            ->map(
                fn($a) => [
                    "id" => $a->id,
                    "slug" => $a->slug,
                    "path" => $a->path,
                    "title" => $a->title,
                ],
            )
            ->toArray();

        return Inertia::render("Albums/Upload", [
            "album" => [
                "id" => $album->id,
                "slug" => $album->slug,
                "path" => $album->path,
                "title" => $album->title,
                "description" => $album->description,
                "location" => $album->location,
                "created_at" => $album->created_at,
                "thumbnail" =>
                    $this->resolveCoverImageUrl($album->cover_image) ?: $album->media->first()?->url,
                "user" => $album->user
                    ? [
                        "id" => $album->user->id,
                        "name" => $album->user->name,
                        "role" => $album->user->role,
                    ]
                    : null,
            ],
            "breadcrumbs" => $breadcrumbs,
        ]);
    }

    public function uploadStore(
        Request $request,
        string $path,
        MediaService $mediaService,
        AlbumService $albumService,
        ActivityLogService $logService,
    ) {
        // Extend time limits for large file uploads
        set_time_limit(0);  // No time limit for upload processing
        ini_set('max_execution_time', '0');
        ini_set('max_input_time', '0');

        $album = $this->resolveAlbumFromPath($path);

        $this->authorize("view", $album);

        // ── PHP-level upload pre-flight ───────────────────────────────────────
        // When the request body exceeds post_max_size PHP silently empties
        // $_FILES before Laravel boots, so the validator gets nothing.
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
                    "files" => "The total upload size is too large. Maximum allowed: {$maxMb} MB. Try uploading fewer files at once.",
                ])
                ->withInput();
        }

        // Check each individual file for PHP-level upload errors (e.g. UPLOAD_ERR_INI_SIZE).
        $rawFileList = $request->files->get("files", []);
        if (is_array($rawFileList)) {
            $uploadMaxMb = round($uploadMaxBytes / 1048576, 1);
            foreach ($rawFileList as $rawFile) {
                if (
                    $rawFile &&
                    method_exists($rawFile, "getError") &&
                    $rawFile->getError() !== UPLOAD_ERR_OK
                ) {
                    $errMsg = match ($rawFile->getError()) {
                        UPLOAD_ERR_INI_SIZE,
                        UPLOAD_ERR_FORM_SIZE
                            => "'{$rawFile->getClientOriginalName()}' is too large. The server allows a maximum of {$uploadMaxMb} MB per file. Please reduce the file size or contact your administrator.",
                        UPLOAD_ERR_PARTIAL
                            => "'{$rawFile->getClientOriginalName()}' was only partially uploaded. Please try again.",
                        default
                            => "'{$rawFile->getClientOriginalName()}' failed to upload (PHP error code {$rawFile->getError()}).",
                    };
                    return back()
                        ->withErrors(["files" => $errMsg])
                        ->withInput();
                }
            }
        }

        $request->validate([
            "files" => "required|array|min:1|max:100",
            // 512 MB hard cap so ZIP uploads can pass this layer. Media files
            // are further validated below with stricter per-type limits.
            "files.*" => "required|file|max:524288",
        ]);

        $uploadedFiles = $request->file("files", []);

        if (empty($uploadedFiles)) {
            throw ValidationException::withMessages([
                "files" => "Please choose at least one file to upload.",
            ]);
        }

        $zipMimeTypes = [
            "application/zip",
            "application/x-zip-compressed",
            "multipart/x-zip",
        ];

        $zipFiles = [];
        $mediaFiles = [];
        $validationMessages = [];
        $imageExtensions = ["heic", "heif"];

        foreach ($uploadedFiles as $file) {
            $mime = (string) $file->getMimeType();
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $size = (int) $file->getSize();
            // Determine type: ZIP is extension-first to avoid mis-classifying
            // video/octet-stream files as archives.
            $isZip =
                $ext === "zip" ||
                ($ext !== "" && in_array($mime, $zipMimeTypes, true));
            $isMedia =
                str_starts_with($mime, "image/") ||
                str_starts_with($mime, "video/") ||
                in_array($ext, $imageExtensions, true) ||
                in_array(
                    $ext,
                    ["mp4", "mov", "avi", "mkv", "webm", "m4v", "3gp"],
                    true,
                );

            if ($isZip) {
                if ($size > 536870912) {
                    $validationMessages[] = "ZIP file '{$file->getClientOriginalName()}' exceeds 512 MB.";
                }
                $zipFiles[] = $file;
                continue;
            }

            if ($isMedia) {
                if ($size > 104857600) {
                    $validationMessages[] = "Media file '{$file->getClientOriginalName()}' exceeds 100 MB.";
                }
                $mediaFiles[] = $file;
                continue;
            }

            $validationMessages[] = "Unsupported file '{$file->getClientOriginalName()}'. Only images, videos, or ZIP files are allowed.";
        }

        if (count($zipFiles) > 1) {
            $validationMessages[] =
                "Please upload only one ZIP file at a time.";
        }

        if (count($zipFiles) > 0 && count($mediaFiles) > 0) {
            $validationMessages[] =
                "Upload either a ZIP file or media files in one request, not both together.";
        }

        if (!empty($validationMessages)) {
            throw ValidationException::withMessages([
                "files" => implode(" ", $validationMessages),
            ]);
        }

        try {
            if (count($zipFiles) === 1) {
                $this->importZipIntoAlbum(
                    $zipFiles[0],
                    $album,
                    $albumService,
                    $mediaService,
                );

                if ($request->wantsJson()) {
                    return response()->json(["success" => true, "message" => "ZIP imported successfully."]);
                }

                return redirect()
                        ->route("albums.show", $album->path)
                    ->with(
                        "success",
                        "ZIP imported successfully into '{$album->title}'.",
                    );
            }

            foreach ($mediaFiles as $file) {
                $media = $mediaService->upload($file, auth()->user(), $album);
                $logService->logMediaUploaded($media);
            }

            $count = count($mediaFiles);

            if ($request->wantsJson()) {
                return response()->json(["success" => true, "message" => "{$count} files uploaded."]);
            }

            return redirect()
                ->route("albums.show", $album->path)
                ->with(
                    "success",
                    $count === 1
                        ? "1 file uploaded successfully."
                        : "{$count} files uploaded successfully.",
                );
        } catch (\Throwable $e) {
            \Log::error("AlbumController: album upload failed.", [
                "album_id" => $album->id,
                "user_id" => auth()->id(),
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);

            if ($request->wantsJson()) {
                return response()->json(["success" => false, "message" => "Upload failed: " . $e->getMessage()], 422);
            }

            return back()
                ->withErrors([
                    "files" => "Upload failed: " . $e->getMessage(),
                ])
                ->with(
                    "error",
                    "Upload failed. Please review the error and try again.",
                )
                ->withInput();
        }
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function show($path, Request $request)
    {
        $album = $this->resolveAlbumFromPath((string) $path);

        $requestedPath = trim((string) $path, '/');
        $canonicalPath = (string) $album->path;
        if ($requestedPath !== $canonicalPath) {
            $queryString = $request->getQueryString();
            $canonicalUrl = route('albums.show', $canonicalPath);

            return redirect()->to(
                $queryString ? ($canonicalUrl . '?' . $queryString) : $canonicalUrl,
            );
        }

        $this->authorize("view", $album);

        $album->setAttribute(
            "is_pinned",
            auth()->user()->pinnedAlbums()->where("albums.id", $album->id)->exists(),
        );
        $album->setAttribute('path', $album->path);

        $paginatedMedia = $album->media()
            ->with("user:id,name,role")
            ->orderBy("created_at", "desc")
            ->paginate(20)
            ->withQueryString();

        if ($request->wantsJson() && !$request->header('X-Inertia')) {
            return response()->json($paginatedMedia);
        }

        $album->load([
            "children" => function ($query) {
                $query
                    ->with([
                        "media" => fn($q) => $q
                            ->orderBy("created_at", "desc")
                            ->limit(1),
                    ])
                    ->withCount(["media", "children"]);
            },
        ]);

        // Attach thumbnail to each child album
        if ($album->children) {
            $album->children->transform(function ($child) {
                $thumbnailMedia = $child->media->first();
                $child->setAttribute('path', $child->path);
                $child->thumbnail = $this->resolveCoverImageUrl($child->cover_image) ?: $thumbnailMedia?->url;
                $child->thumbnail_media = $thumbnailMedia
                    ? [
                        "url" => $thumbnailMedia->url,
                        "file_type" => $thumbnailMedia->file_type,
                        "file_name" => $thumbnailMedia->file_name,
                        "mime_type" => $thumbnailMedia->mime_type,
                    ]
                    : null;
                return $child;
            });
        }

        // Breadcrumbs
        $breadcrumbs = $album
            ->ancestors()
            ->reverse()
            ->values()
            ->map(
                fn($a) => [
                    "id" => $a->id,
                    "slug" => $a->slug,
                    "path" => $a->path,
                    "title" => $a->title,
                ],
            )
            ->toArray();

        return Inertia::render("Albums/Show", [
            "album" => $album,
            "mediaData" => $paginatedMedia,
            "breadcrumbs" => $breadcrumbs,
        ]);
    }

    public function togglePin(Request $request, Album $album)
    {
        $this->authorize("view", $album);

        $user = $request->user();

        $existingPin = $user
            ->pinnedAlbumRecords()
            ->where("album_id", $album->id)
            ->first();

        if ($existingPin) {
            $existingPin->delete();
            $pinned = false;
        } else {
            $user->pinnedAlbumRecords()->create([
                "album_id" => $album->id,
            ]);
            $pinned = true;
        }

        if ($request->wantsJson() && !$request->header("X-Inertia")) {
            return response()->json([
                "album_id" => $album->id,
                "pinned" => $pinned,
            ]);
        }

        return back();
    }

    // -------------------------------------------------------------------------
    // Edit / Update
    // -------------------------------------------------------------------------

    public function edit(Album $album)
    {
        $this->authorize("update", $album);

        return Inertia::render("Albums/Edit", [
            "album" => [
                "id" => $album->id,
                "slug" => $album->slug,
                "path" => $album->path,
                "title" => $album->title,
                "description" => $album->description,
                "location" => $album->location,
                "cover_image" => $this->resolveCoverImageUrl($album->cover_image),
            ],
        ]);
    }

    public function update(
        Request $request,
        Album $album,
        AlbumService $albumService,
        ActivityLogService $logService,
    ) {
        // Comprehensive logging
        Log::info('========== ALBUM UPDATE REQUEST START ==========');
        Log::info('Request method: ' . $request->method());
        Log::info('Request header Content-Type: ' . $request->header('Content-Type'));
        Log::info('Full request data:', $request->all());
        Log::info('Has title field: ' . ($request->has('title') ? 'YES' : 'NO'));
        Log::info('Title input value: "' . ($request->input('title') ?? 'NULL') . '"');
        Log::info('Title value type: ' . gettype($request->input('title')));
        Log::info('========== REQUEST END ==========');

        $this->authorize("update", $album);

        $data = $request->validate([
            "title" => "required|string|max:255",
            "description" => "nullable|string",
            "location" => "nullable|string|in:Rajkot,Ahmedabad",
            "cover_image" => "nullable|file|mimes:jpeg,jpg,png,gif|max:10240",
            "return_to_path" => "nullable|string|max:2048",
        ]);

        $returnToPath = isset($data["return_to_path"])
            ? trim((string) $data["return_to_path"], "/")
            : null;
        unset($data["return_to_path"]);

        // Handle cover image upload (store in local public disk and save as local path).
        if ($request->hasFile("cover_image")) {
            $filePath = $request->file('cover_image')->store('album-cover-images', 'public');
            $data['cover_image'] = $filePath;
        }

        $album = $albumService->update($album, $data);
        $logService->logAlbumUpdated($album);

        if (!empty($returnToPath)) {
            return redirect()
                ->route("albums.show", $returnToPath)
                ->with("success", "Album updated successfully.");
        }

        return redirect()
            ->route("albums.show", $album->path)
            ->with("success", "Album updated successfully.");
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function destroy(Request $request, Album $album, ActivityLogService $logService)
    {
        $this->authorize("delete", $album);

        $childrenCount = $album->children()->count();
        $successMessage =
            $childrenCount > 0
                ? "\"{$album->title}\" and {$childrenCount} nested album(s) moved to Recycle Bin."
                : "\"{$album->title}\" moved to Recycle Bin.";

        try {
            $logService->logAlbumDeleted($album);
            $album->delete();
        } catch (\Throwable $e) {
            Log::error("AlbumController::destroy – failed to delete album.", [
                "album_id" => $album->id,
                "error" => $e->getMessage(),
            ]);

            return back()
                ->with(
                    "error",
                    "Failed to delete \"{$album->title}\". Please try again.",
                );
        }

        return back()->with("success", $successMessage);
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

    private function resolveAlbumFromPath(string $path): Album
    {
        $segments = array_values(
            array_filter(explode('/', trim((string) $path, '/'))),
        );

        if (empty($segments)) {
            abort(404);
        }

        $allowedLocations = [
            'rajkot' => 'Rajkot',
            'ahmedabad' => 'Ahmedabad',
        ];

        $location = null;
        $firstSegment = strtolower($segments[0]);
        if (array_key_exists($firstSegment, $allowedLocations)) {
            $location = $allowedLocations[$firstSegment];
            array_shift($segments);
        }

        if ($location === null) {
            $location = auth()->user()->location ?: 'Rajkot';
        }

        if (empty($segments)) {
            abort(404);
        }

        $parentId = null;
        $album = null;

        foreach ($segments as $slug) {
            $query = Album::query()
                ->where('slug', $slug)
                ->where('parent_id', $parentId);

            if ($location !== null) {
                $query->where('location', $location);
            }

            $album = $query->first();

            if (!$album) {
                abort(404);
            }

            $parentId = $album->id;
        }

        return $album;
    }

    private function importZipIntoAlbum(
        UploadedFile $uploadedFile,
        Album $targetAlbum,
        AlbumService $albumService,
        MediaService $mediaService,
    ): void {
        $zipPath = $uploadedFile->getRealPath();

        if (!$zipPath || !file_exists($zipPath)) {
            throw new \RuntimeException(
                "The ZIP file failed to upload. Please try again.",
            );
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

            throw new \RuntimeException(
                "Unable to open the ZIP file. {$reason}",
            );
        }

        $extractPath = storage_path("app/temp/zip_" . uniqid());

        try {
            \Illuminate\Support\Facades\File::makeDirectory(
                $extractPath,
                0755,
                true,
            );

            // Log extraction start
            Log::info('ZIP extraction starting', [
                'size_mb' => round(filesize($zipPath) / 1048576),
                'memory_mb' => round(memory_get_usage(true) / 1048576),
            ]);

            // Extract with memory management
            $zip->extractTo($extractPath);
            $zip->close();

            // Free memory after ZIP operations
            unset($zip);
            gc_collect_cycles();

            // Log extraction complete
            Log::info('ZIP extraction complete', [
                'memory_mb' => round(memory_get_usage(true) / 1048576),
            ]);

            if (
                \Illuminate\Support\Facades\File::exists(
                    $extractPath . "/__MACOSX",
                )
            ) {
                \Illuminate\Support\Facades\File::deleteDirectory(
                    $extractPath . "/__MACOSX",
                );
            }

            $this->processExtractedFolder(
                $extractPath,
                $targetAlbum,
                $albumService,
                $mediaService,
            );
        } finally {
            if (\Illuminate\Support\Facades\File::exists($extractPath)) {
                \Illuminate\Support\Facades\File::deleteDirectory($extractPath);
            }
        }
    }

    // -------------------------------------------------------------------------
    // System Albums (smart albums)
    // -------------------------------------------------------------------------

    public function showSystemAlbum(string $type, Request $request)
    {
        $album = null;
        $paginatedMedia = null;

        if ($type === "recent") {
            $paginatedMedia = \App\Models\Media::with("user:id,name,role")
                ->where("user_id", auth()->id())
                ->where("created_at", ">=", now()->subDays(30))
                ->orderBy("created_at", "desc")
                ->paginate(20)
                ->withQueryString();

            $album = [
                "id" => "recent",
                "title" => "Recent",
                "description" => "Photos and videos from the last 30 days",
                "type" => "system",
                "is_system" => true,
                "children" => [],
            ];
        } elseif ($type === "todays-memories") {
            $paginatedMedia = \App\Models\Media::with("user:id,name,role")
                ->where("user_id", auth()->id())
                ->whereRaw("MONTH(created_at) = ?", [now()->month])
                ->whereRaw("DAY(created_at) = ?", [now()->day])
                ->whereRaw("YEAR(created_at) < ?", [now()->year])
                ->orderBy("created_at", "desc")
                ->paginate(20)
                ->withQueryString();

            $album = [
                "id" => "todays-memories",
                "title" => "Today's Memories",
                "description" => "Photos from this day in previous years",
                "type" => "system",
                "is_system" => true,
                "children" => [],
            ];
        }

        if (!$album) {
            abort(404);
        }

        // Handle JSON pagination requests (for infinite scroll)
        if ($request->wantsJson() && !$request->header('X-Inertia')) {
            return response()->json($paginatedMedia);
        }

        // No breadcrumbs for system albums
        $breadcrumbs = [];

        return Inertia::render("Albums/Show", [
            "album" => (object)$album,
            "mediaData" => $paginatedMedia,
            "breadcrumbs" => $breadcrumbs,
        ]);
    }
}
