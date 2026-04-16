# Cloudflare R2 Storage Complete Guide (Project-Specific)

Last updated: 2026-04-03

This document is based on your current project code and current architecture.

## 1) Current architecture in this project

Upload flow:
1. User uploads media from Laravel app.
2. Media is stored on filesystems.media_disk.
3. In production, media_disk is expected to be r2.
4. Media DB stores file_path and optional thumbnail_path.

Delivery flow:
1. Frontend requests media URL from API/props.
2. Media model generates URL:
   - url for original
   - thumbnail_url for grid/list
3. If CDN_URL is configured and disk is object storage, URLs point to Worker domain.
4. Worker fetches object from R2 bucket binding MEDIA_BUCKET.
5. Worker returns response with cache headers.

Thumbnail flow:
1. sync:media-records command generates missing dimensions and thumbnails.
2. ThumbnailService downloads source image from media disk.
3. ThumbnailService creates JPEG thumbnail (max 480px, quality 82).
4. ThumbnailService stores thumbnail on media disk (R2 in production).
5. thumbnail_path is stored in DB as key, for example: thumbnails/18/1.jpg

## 2) Packages used for R2 and thumbnails

Composer packages currently used:
- league/flysystem-aws-s3-v3 (S3-compatible adapter used for Cloudflare R2)
- intervention/image (thumbnail generation)
- james-heinrich/getid3 (media metadata support)

No extra package is required beyond current composer.json.

## 3) Laravel configuration (required)

File: config/filesystems.php

Important keys:
- media_disk
- cdn_url
- disks.r2

Expected behavior:
- MEDIA_DISK=r2 in production
- CDN_URL set to your worker URL
- R2 credentials and endpoint set in env

## 4) Environment variables

Minimum required in production env:

APP_ENV=production
FILESYSTEM_DISK=local
MEDIA_DISK=r2

CLOUDFLARE_R2_ACCESS_KEY_ID=YOUR_KEY
CLOUDFLARE_R2_SECRET_ACCESS_KEY=YOUR_SECRET
CLOUDFLARE_R2_REGION=auto
CLOUDFLARE_R2_BUCKET=cx-memories
CLOUDFLARE_R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
CLOUDFLARE_R2_URL=https://<accountid>.r2.cloudflarestorage.com
CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT=true

CDN_URL=https://cx-memories-media.cypherox.workers.dev

Notes:
- Keep R2 bucket private.
- Worker binding reads private objects.
- CDN_URL enables fast Worker URLs instead of presigned URLs for listing/preview flows.

## 5) Worker configuration

Files:
- cloudflare/media-worker.mjs
- wrangler.toml

Current cache policy in worker:
- thumbnails/* => Cache-Control: public, max-age=31536000, immutable
- image/* originals => Cache-Control: public, max-age=604800
- video/* => Cache-Control: public, max-age=86400
- default => Cache-Control: public, max-age=86400

How cache is populated:
1. First request at an edge location: MISS
2. Worker fetches from R2
3. Worker stores response in caches.default
4. Next requests at same edge location: HIT

Range requests:
- Worker supports range requests for media streaming.
- Range requests are served from R2 path and not put in edge object cache path logic.

## 6) Model and service behavior in your code

Media model:
- url points to Worker URL for originals when CDN_URL is present on object storage disk.
- thumbnail_url now prefers thumbnail_path.
- If thumbnail_path exists and CDN is active, thumbnail_url points to Worker thumbnail key.
- If thumbnail_path is missing, thumbnail_url falls back to url.

R2StorageService:
- Uploads files to configured media disk.
- Returns file keys (paths) in bucket.
- Generates Worker URL when CDN_URL is configured.

ThumbnailService:
- Generates thumbnails only for image file_type.
- Skips HEIC/HEIF.
- Stores generated thumbnail key under thumbnails/{album_id_or_0}/{media_id}.jpg
- Writes thumbnail to media disk (R2 in production).
- delete() removes thumbnail from both media disk and public disk (migration safety).

Command:
- sync:media-records supports:
  - --force
  - --ids=1,2,3
  - --limit=100

## 7) Recommended low-cost + high-performance strategy

Use this strategy:
1. Keep thumbnails enabled.
2. Store thumbnails in R2.
3. Serve thumbnails and originals via Worker.
4. Use long cache for thumbnails and moderate cache for originals.
5. Keep originals in R2 as source of truth.

Why this is best:
- Grid/list views use small thumbnails, reducing bandwidth.
- Detail views still show full-quality original.
- Edge cache reduces repeated origin reads.
- Costs stay lower than caching only originals with large transfer sizes.

## 8) Live server execution commands

A) Deploy Laravel app

php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

If frontend build on server:

npm ci
npm run build

B) Deploy Worker

wrangler deploy --env=""

C) Backfill thumbnails to R2

php artisan sync:media-records --limit=1 --force
php artisan sync:media-records --force

Optional batch mode:

php artisan sync:media-records --ids=1,2,3,4 --force

D) Verify thumbnail URL points to Worker thumbnail key

php artisan tinker --execute="$m = App\Models\Media::where('file_type','image')->whereNotNull('thumbnail_path')->latest('updated_at')->first(); echo 'id=' . $m->id . PHP_EOL; echo 'thumb=' . $m->thumbnail_path . PHP_EOL; echo 'thumb_url=' . $m->thumbnail_url . PHP_EOL;"

E) Verify cache headers

curl -I "https://cx-memories-media.cypherox.workers.dev/thumbnails/18/1.jpg"
curl -I "https://cx-memories-media.cypherox.workers.dev/albums/ahmedabad/2019/2nd-oct-pics/IMG_20191002_122453.jpg"

Expected:
- thumbnail response includes max-age=31536000
- original image response includes max-age=604800

F) Optional local thumbnail cleanup after verification

php artisan tinker --execute="Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('thumbnails'); echo 'Local thumbnails removed.' . PHP_EOL;"

G) Bring app online

php artisan up

## 9) Rollback steps

Immediate rollback for URL routing:
1. Set CDN_URL empty in env.
2. Run php artisan config:clear
3. URLs fall back away from Worker path generation.

Commands:

php artisan down
# Edit .env and set CDN_URL=
php artisan config:clear
php artisan up

## 10) Troubleshooting

Issue: 404 from Worker
- Check path does not include bucket name prefix in URL.
- Correct style: /albums/... or /thumbnails/...
- Wrong style: /cx-memories/albums/...

Issue: thumbnails not generated
- Run sync:media-records --force
- Check image file_type values
- Check HEIC/HEIF are skipped by design

Issue: thumbnail_url returns original URL
- Means thumbnail_path is missing for that row.
- Run backfill command.

Issue: cache not HIT
- First request is expected MISS.
- Repeated request on same edge location should become HIT.

## 11) Quick daily operations

For regular ingestion:

php artisan sync:media-records

For full regeneration after quality/algorithm change:

php artisan sync:media-records --force

For specific IDs:

php artisan sync:media-records --ids=101,102,103 --force

## 12) Source files in this project

- app/Models/Media.php
- app/Services/R2StorageService.php
- app/Services/ThumbnailService.php
- app/Console/Commands/SyncMediaDerivatives.php
- config/filesystems.php
- cloudflare/media-worker.mjs
- wrangler.toml
- docs/live-server-final-commands-2026-04-03.md
