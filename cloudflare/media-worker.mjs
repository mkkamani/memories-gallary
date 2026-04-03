const ALLOWED_METHODS = 'GET, HEAD, OPTIONS';
const DEFAULT_CACHE_CONTROL = 'public, max-age=86400';
const THUMB_CACHE_CONTROL = 'public, max-age=31536000, immutable';
const IMAGE_CACHE_CONTROL = 'public, max-age=604800';
const VIDEO_CACHE_CONTROL = 'public, max-age=86400';

function cacheControlFor(key, contentType = '') {
    if (key.startsWith('thumbnails/')) {
        return THUMB_CACHE_CONTROL;
    }

    if (contentType.startsWith('image/')) {
        return IMAGE_CACHE_CONTROL;
    }

    if (contentType.startsWith('video/')) {
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
    const url = new URL(request.url);
    const key = decodeURIComponent(url.pathname.replace(/^\/+/, ''));

    if (!key) {
        return notFoundResponse();
    }

    const getOptions = {};

    const rangeHeader = request.headers.get('range');
    if (rangeHeader) {
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

    if (object.range) {
        const { offset, length, size } = object.range;
        headers.set('Content-Range', `bytes ${offset}-${offset + length - 1}/${size}`);
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

export default {
    async fetch(request, env, ctx) {
        if (request.method === 'OPTIONS') {
            return optionsResponse();
        }

        if (!['GET', 'HEAD'].includes(request.method)) {
            return methodNotAllowedResponse();
        }

        const hasRangeRequest = request.headers.has('range');

        // Skip edge cache for range requests to avoid fragmented cache entries.
        if (hasRangeRequest) {
            return fromR2(request, env);
        }

        const cache = caches.default;
        const cacheKey = new Request(request.url, { method: request.method });
        const cached = await cache.match(cacheKey);

        if (cached) {
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
