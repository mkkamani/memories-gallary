# Google Drive bulk import

Requirements:

- Install Google API client:

```bash
composer require google/apiclient
```

- Set credentials in your `.env`:

```
GOOGLE_DRIVE_CREDENTIALS_PATH=/full/path/to/credentials.json
# or
GOOGLE_DRIVE_CREDENTIALS_JSON={...json content...}
```

Usage:

```bash
php artisan drive:import <FOLDER_ID> --album_id=12 --user_id=1
```

If you need to access folders shared to you or items in a Shared Drive using a service account, either share the folder/drive with the service account email, or enable impersonation and set `GOOGLE_DRIVE_IMPERSONATE` to the user email the service account should act as.

Examples in `.env`:

```
GOOGLE_DRIVE_CREDENTIALS_PATH=/full/path/to/service-account.json
GOOGLE_DRIVE_IMPERSONATE=user@example.com

Public folders (no credentials)

If the Drive folder is shared as "Anyone with the link" (Viewer) you can access it without service-account credentials by using an API key.

1. Create an API key in Google Cloud Console: https://console.cloud.google.com/apis/credentials → Create credentials → API key
2. Restrict the key (recommended) to the Drive API and to your server IPs or referrers.
3. Add to `.env`:

```
GOOGLE_DRIVE_API_KEY=YOUR_API_KEY_HERE
```

The import service will use `GOOGLE_DRIVE_API_KEY` automatically if no service-account credentials are configured. This is suitable for publicly shared folders only.

SSL certificate errors (recommended fix)

If you see errors like "cURL error 60: SSL certificate problem: unable to get local issuer certificate", do NOT permanently disable certificate verification. Instead:

- Download the CA bundle from curl's CA extract: https://curl.se/docs/caextract.html (file `cacert.pem`).
- Place it somewhere on the server, e.g. `C:\wamp64\bin\php\extras\ssl\cacert.pem` (Windows) or `/etc/ssl/certs/cacert.pem` (Linux).
- Edit your `php.ini` (the CLI one used by `php`): set these values to the absolute path:

```
curl.cainfo = "C:\\wamp64\\bin\\php\\php8.x\\extras\\ssl\\cacert.pem"
openssl.cafile = "C:\\wamp64\\bin\\php\\php8.x\\extras\\ssl\\cacert.pem"
```

- Restart Apache / PHP-FPM or ensure the CLI `php` picks up the change, then run:

```bash
php -i | grep -i "curl.cainfo\|openssl.cafile"
php artisan config:clear
```

Temporary workaround (not recommended)

If you must bypass verification for a quick test, set this env var (temporary only):

```
GOOGLE_DRIVE_VERIFY_SSL=false
```

This service uses that variable to configure the underlying HTTP client. Be sure to remove/disable this when done.
```

Flags:

- `--dry-run` : do not persist files or records
- `--recursive` : recurse into subfolders (not implemented in detail yet)

- `--impersonate` : optionally impersonate a user for this run (overrides `GOOGLE_DRIVE_IMPERSONATE`)
- `--supports-all-drives` : set to `0` to disable Shared Drive support (default: `1`)

Notes:

- The command stores files to the `public` disk under `media/drive/YYYY/MM/DD`.
- The service expects `google/apiclient` to be installed and configured.
