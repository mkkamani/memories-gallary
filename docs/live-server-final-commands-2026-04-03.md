# Live Server Final Commands (Low Cost + Better Performance)

This runbook reflects today's final corrected strategy:
- Keep thumbnails (small bytes for grid/list views)
- Store thumbnails on media disk (R2 in production)
- Serve thumbnails and originals through Cloudflare Worker
- Use long cache for thumbnails, moderate cache for originals

## 1. What Was Implemented Today

- `Media::thumbnail_url` now prefers `thumbnail_path` (not original full image) and resolves through CDN when using object storage.
- `ThumbnailService` now writes thumbnails to the configured media disk (`filesystems.media_disk`), which is R2 in production.
- `ThumbnailService::delete()` now safely deletes from both candidate disks (`media_disk` and `public`) to support migration cleanup.
- Worker cache policy updated for lower cost + better behavior:
  - `thumbnails/*` => 1 year immutable
  - images => 7 days
  - videos => 1 day
  - default => 1 day

## 2. Deploy Laravel App (Production)

Run on your live server from project root:

```bash
php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If you build frontend assets on server:

```bash
npm ci
npm run build
```

## 3. Deploy Cloudflare Worker (from deploy machine)

Run from project root where `wrangler.toml` exists:

```bash
wrangler deploy --env=""
```

## 4. Backfill Thumbnails to R2 (Critical)

### 4.1 Smoke test one item first

```bash
php artisan sync:media-records --limit=1 --force
```

### 4.2 Backfill all images

```bash
php artisan sync:media-records --force
```

If you want to batch in chunks during traffic windows, use ID ranges:

```bash
php artisan sync:media-records --ids=1,2,3,4 --force
```

## 5. Verification Commands

### 5.1 Check that thumbnail_url points to Worker thumbnails path

```bash
php artisan tinker --execute="$m = App\Models\Media::where('file_type','image')->whereNotNull('thumbnail_path')->latest('updated_at')->first(); echo 'id=' . $m->id . PHP_EOL; echo 'thumb=' . $m->thumbnail_path . PHP_EOL; echo 'thumb_url=' . $m->thumbnail_url . PHP_EOL;"
```

Expected `thumb_url` format:

```text
https://cx-memories-media.cypherox.workers.dev/thumbnails/{album_id}/{media_id}.jpg
```

### 5.2 Check thumbnail response headers from Worker

```bash
curl -I "https://cx-memories-media.cypherox.workers.dev/thumbnails/18/1.jpg"
```

Expected:
- `200` or `206`
- `Cache-Control: public, max-age=31536000, immutable`

### 5.3 Check original image cache policy

```bash
curl -I "https://cx-memories-media.cypherox.workers.dev/albums/ahmedabad/2019/2nd-oct-pics/IMG_20191002_122453.jpg"
```

Expected:
- `200` or `206`
- `Cache-Control: public, max-age=604800`

## 6. Optional Cleanup (After Verification)

Only run this after you verify thumbnails are loading from Worker/R2 correctly.

```bash
php artisan tinker --execute="Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('thumbnails'); echo 'Local thumbnails removed.' . PHP_EOL;"
```

## 7. Bring App Back Online

```bash
php artisan up
```

## 8. Rollback (If Needed)

If you need to immediately revert traffic behavior:

```bash
php artisan down
# Set CDN_URL empty in .env
# CDN_URL=
php artisan config:clear
php artisan up
```

Notes:
- This falls back away from CDN URL generation.
- If you rollback and still need thumbnails, ensure local `public/thumbnails` exists.

## 9. Recommended Ongoing Commands

Run daily/weekly if new media is ingested heavily:

```bash
php artisan sync:media-records
```

Force regenerate all thumbnails only when quality/thumbnail algorithm changes:

```bash
php artisan sync:media-records --force
```
