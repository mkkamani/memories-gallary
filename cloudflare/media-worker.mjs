const ALLOWED_METHODS = 'GET, HEAD, OPTIONS';
const DEFAULT_CACHE_CONTROL = 'public, max-age=86400';
const THUMB_CACHE_CONTROL = 'public, max-age=31536000, immutable';
const HEIC_CACHE_CONTROL = 'public, max-age=31536000, immutable';
const IMAGE_CACHE_CONTROL = 'public, max-age=604800';
const VIDEO_CACHE_CONTROL = 'public, max-age=86400';
// Bump this value to invalidate all existing Worker edge-cache entries.
const CACHE_VERSION = '2026-04-08-1';

function extractKeyFromRequest(request) {
    const url = new URL(request.url);
    return decodeURIComponent(url.pathname.replace(/^\/+/, ''));
}

function normalizeEtag(etag) {
    if (!etag) {
        return '';
    }

    return String(etag)
        .replace(/^W\//, '')
        .replace(/"/g, '')
        .trim();
}

function buildCacheKey(request) {
    const url = new URL(request.url);
    url.searchParams.set('__cv', CACHE_VERSION);

    return new Request(url.toString(), { method: 'GET' });
}

function cacheControlFor(key, contentType = '') {
    if (key.startsWith('thumbnails/')) {
        return THUMB_CACHE_CONTROL;
    }

    const loweredKey = key.toLowerCase();
    const loweredType = contentType.toLowerCase();

    if (
        loweredType.includes('image/heic')
        || loweredType.includes('image/heif')
        || loweredKey.endsWith('.heic')
        || loweredKey.endsWith('.heif')
    ) {
        return HEIC_CACHE_CONTROL;
    }

    if (loweredType.startsWith('image/')) {
        return IMAGE_CACHE_CONTROL;
    }

    if (loweredType.startsWith('video/')) {
        return VIDEO_CACHE_CONTROL;
    }

    return DEFAULT_CACHE_CONTROL;
}

function withCors(headers, cacheControl = DEFAULT_CACHE_CONTROL) {
    headers.set('Access-Control-Allow-Origin', '*');
    headers.set('Access-Control-Allow-Methods', ALLOWED_METHODS);
    headers.set('Access-Control-Allow-Headers', 'Range, Content-Type, If-None-Match, If-Modified-Since');
    headers.set('Cache-Control', cacheControl);
    headers.set('CDN-Cache-Control', cacheControl);

    return headers;
}

function optionsResponse() {
    return new Response(null, {
        status: 204,
        headers: withCors(new Headers(), DEFAULT_CACHE_CONTROL),
    });
}

function notFoundResponse() {
    return new Response('Not found', {
        status: 404,
        headers: withCors(new Headers({ 'Content-Type': 'text/plain; charset=utf-8' }), DEFAULT_CACHE_CONTROL),
    });
}

function methodNotAllowedResponse() {
    return new Response('Method not allowed', {
        status: 405,
        headers: withCors(new Headers({
            'Content-Type': 'text/plain; charset=utf-8',
            Allow: ALLOWED_METHODS,
        }), DEFAULT_CACHE_CONTROL),
    });
}

async function fromR2(request, env) {
    const key = extractKeyFromRequest(request);

    if (!key) {
        return notFoundResponse();
    }

    const getOptions = {};
    const rangeHeader = request.headers.get('range');
    const hasRangeRequest = !!rangeHeader;

    if (hasRangeRequest) {
        getOptions.range = request.headers;
    }

    const object = await env.MEDIA_BUCKET.get(key, getOptions);

    if (!object) {
        return notFoundResponse();
    }

    const headers = new Headers();
    object.writeHttpMetadata(headers);
    headers.set('etag', object.httpEtag);
    headers.set('Accept-Ranges', 'bytes');

    const contentType = headers.get('Content-Type') || 'application/octet-stream';
    if (!headers.has('Content-Type')) {
        headers.set('Content-Type', contentType);
    }

    const cacheControl = cacheControlFor(key, contentType);
    withCors(headers, cacheControl);

    if (hasRangeRequest && object.range) {
        const { offset, length, size } = object.range;
        const totalSize = object.size ?? size;

        if (totalSize != null) {
            headers.set('Content-Range', `bytes ${offset}-${offset + length - 1}/${totalSize}`);
        }

        headers.set('Content-Length', String(length));

        return new Response(request.method === 'HEAD' ? null : object.body, {
            status: 206,
            headers,
        });
    }

    if (object.size != null) {
        headers.set('Content-Length', String(object.size));
    }

    return new Response(request.method === 'HEAD' ? null : object.body, {
        status: 200,
        headers,
    });
}

async function warmFullObjectCache(request, env, cache, cacheKey) {
    const warmRequest = new Request(request.url, {
        method: 'GET',
        headers: new Headers({
            Accept: request.headers.get('Accept') || '*/*',
        }),
    });

    const warmResponse = await fromR2(warmRequest, env);

    if (warmResponse.ok && warmResponse.status === 200) {
        await cache.put(cacheKey, warmResponse.clone());
    }
}

export default {
    async fetch(request, env, ctx) {
        if (request.method === 'OPTIONS') {
            return optionsResponse();
        }

        if (!['GET', 'HEAD'].includes(request.method)) {
            return methodNotAllowedResponse();
        }

        const cache = caches.default;
        const cacheKey = buildCacheKey(request);
        const key = extractKeyFromRequest(request);
        const hasRangeRequest = request.headers.has('range');

        // For range/video playback requests, return the requested bytes
        // immediately and warm the full-object GET cache asynchronously.
        if (hasRangeRequest) {
            const cached = await cache.match(cacheKey);

            if (!cached) {
                ctx.waitUntil(warmFullObjectCache(request, env, cache, cacheKey));
            }

            return fromR2(request, env);
        }

        const cached = await cache.match(cacheKey);

        if (cached) {
            // Verify cached object still exists and is still the latest version in R2.
            const head = await env.MEDIA_BUCKET.head(key);

            if (!head) {
                await cache.delete(cacheKey);
                return notFoundResponse();
            }

            const cachedEtag = normalizeEtag(cached.headers.get('etag'));
            const sourceEtag = normalizeEtag(head.httpEtag);

            if (cachedEtag !== '' && sourceEtag !== '' && cachedEtag !== sourceEtag) {
                const freshResponse = await fromR2(request, env);

                if (freshResponse.ok) {
                    ctx.waitUntil(cache.put(cacheKey, freshResponse.clone()));
                } else {
                    await cache.delete(cacheKey);
                }

                return freshResponse;
            }

            return new Response(request.method === 'HEAD' ? null : cached.body, {
                status: cached.status,
                headers: new Headers(cached.headers),
            });
        }

        const response = await fromR2(request, env);

        if (response.ok) {
            ctx.waitUntil(cache.put(cacheKey, response.clone()));
        }

        return response;
    },
};
