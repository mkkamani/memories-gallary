<script setup>
import heic2any from 'heic2any';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { decodeHeicBlobWithLibheif } from '@/utils/heicDecode';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    media: {
        type: Object,
        default: null,
    },
    alt: {
        type: String,
        default: '',
    },
    imageClass: {
        type: String,
        default: '',
    },
    videoClass: {
        type: String,
        default: '',
    },
    fallbackClass: {
        type: String,
        default: 'flex h-full w-full items-center justify-center bg-bg-hover text-xs font-bold uppercase tracking-[0.2em] text-muted-foreground',
    },
    fallbackLabel: {
        type: String,
        default: 'HEIC',
    },
    videoControls: {
        type: Boolean,
        default: false,
    },
    videoAutoplay: {
        type: Boolean,
        default: false,
    },
    videoPlaysinline: {
        type: Boolean,
        default: true,
    },
    // When true the component renders a plain <img> with no wrapper div so that
    // the preview overlay's zoom/pan (applied via v-bind="$attrs" → transform)
    // is not clipped by an overflow:hidden container.
    preview: {
        type: Boolean,
        default: false,
    },
    // When true the container positions absolutely (inset:0) to fill whatever
    // positioned ancestor wraps it — used for fixed-height thumbnail strips.
    fill: {
        type: Boolean,
        default: false,
    },
    // When true and media.thumbnail_url is available the component prefers
    // the fast listing URL. That can be either a local thumbnail path or a
    // Worker/CDN-backed R2 URL depending on backend configuration.
    useThumbnail: {
        type: Boolean,
        default: false,
    },
    // When true, never run client-side HEIC conversion; render direct URL only.
    disableHeicConversion: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['load']);

const resolvedUrl = ref('');
const conversionFailed = ref(false);
const isLoading = ref(false);
const hasImageLoaded = ref(false);
const attemptedHeicConversion = ref(false);
const attemptedThumbnailFallback = ref(false);
const lastEmittedDbDimsKey = ref('');
const loadedNaturalWidth = ref(null);
const loadedNaturalHeight = ref(null);

let objectUrl = null;
let runId = 0;

const extension = computed(() => {
    const name = props.media?.file_name || '';
    const parts = name.split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
});

const mimeType = computed(() => String(props.media?.mime_type || '').toLowerCase());
const isVideo = computed(() => props.media?.file_type === 'video');
const isBrowserNativeImageMime = computed(() => {
    const mime = mimeType.value;
    if (!mime.startsWith('image/')) {
        return false;
    }

    // Keep HEIC/HEIF out of native set: they still require conversion.
    if (mime.includes('heic') || mime.includes('heif')) {
        return false;
    }

    return true;
});
const intrinsicWidth = computed(() => {
    const value = Number(props.media?.width);
    return Number.isFinite(value) && value > 0 ? value : null;
});
const intrinsicHeight = computed(() => {
    const value = Number(props.media?.height);
    return Number.isFinite(value) && value > 0 ? value : null;
});
const hasSuspiciousHeicDbDimensions = computed(() => {
    if (!isHeic.value) {
        return false;
    }

    const w = intrinsicWidth.value;
    const h = intrinsicHeight.value;

    if (!w || !h) {
        return false;
    }

    // Some HEIC dimension extractors return embedded preview dimensions
    // (commonly 512x512) instead of true photo dimensions.
    return w === h && [256, 512, 1024].includes(w);
});
const reliableDbWidth = computed(() => {
    if (hasSuspiciousHeicDbDimensions.value) {
        return null;
    }

    return intrinsicWidth.value;
});
const reliableDbHeight = computed(() => {
    if (hasSuspiciousHeicDbDimensions.value) {
        return null;
    }

    return intrinsicHeight.value;
});
const shouldLockSquareHeicTile = computed(() => {
    // In grid mode, keep known-suspicious HEIC metadata tiles square so
    // masonry row spans don't jump and overlap during lazy-loading.
    return hasSuspiciousHeicDbDimensions.value && !props.preview && !props.fill;
});
const displayWidth = computed(() => {
    if (shouldLockSquareHeicTile.value) {
        return intrinsicWidth.value || 1;
    }

    return loadedNaturalWidth.value || reliableDbWidth.value;
});
const displayHeight = computed(() => {
    if (shouldLockSquareHeicTile.value) {
        return intrinsicHeight.value || 1;
    }

    return loadedNaturalHeight.value || reliableDbHeight.value;
});
const containerStyle = computed(() => {
    if (props.fill) {
        // Fill mode: stretch to cover the positioned ancestor (e.g. fixed-height strip tiles).
        return { position: 'absolute', inset: '0', width: '100%', height: '100%' };
    }
    // In grid mode, do NOT set aspect-ratio. Always let the image fill the container.
    return { width: '100%', height: '100%', minHeight: '160px' };
});
const isHeic = computed(() => {
    if (isVideo.value) {
        return false;
    }

    // If backend says this is already a browser-native image format (jpeg/png/webp...),
    // trust MIME over extension and skip HEIC decode path.
    if (isBrowserNativeImageMime.value) {
        return false;
    }

    return ['heic', 'heif'].includes(extension.value)
        || mimeType.value.includes('image/heic')
        || mimeType.value.includes('image/heif');
});

const heicProxyUrl = computed(() => {
    if (!isHeic.value) {
        return null;
    }

    // Always prefer the same-origin Laravel proxy route so the JS fetch() in
    // convertHeicToBrowserImage() never touches a cross-origin URL.
    // Cross-origin <img> loads use "no-cors" mode, which the browser may cache
    // as an opaque response. A subsequent cors-mode fetch() to the same URL
    // receives that opaque response — which JS cannot read — causing conversion
    // to silently fail. The local proxy is same-origin, so this never happens.
    if (props.media?.id) {
        return `/media/${props.media.id}/raw`;
    }

    if (props.media?.url) {
        return props.media.url;
    }

    if (derivedWorkerMediaUrl.value) {
        return derivedWorkerMediaUrl.value;
    }

    return null;
});

const mediaOriginalUrl = computed(() => {
    if (props.media?.url) {
        return props.media.url;
    }

    if (derivedWorkerMediaUrl.value) {
        return derivedWorkerMediaUrl.value;
    }

    if (props.media?.id) {
        return `/media/${props.media.id}/raw`;
    }

    return '';
});

const mediaProxyUrl = computed(() => {
    if (props.useThumbnail && resolvedThumbnailUrl.value) {
        return resolvedThumbnailUrl.value;
    }

    if (props.useThumbnail && localThumbnailUrl.value) {
        return localThumbnailUrl.value;
    }

    return mediaOriginalUrl.value;
});

/**
 * Fast listing URL for media thumbnails.
 * This may be a local thumbnail path or a Worker/CDN URL.
 */
const localThumbnailUrl = computed(() => props.media?.thumbnail_url || null);
const localPreviewUrl = computed(() => props.media?.preview_url || null);
const localPreviewFallbackUrl = computed(() => props.media?.preview_fallback_url || null);

const workerOrigin = computed(() => {
    for (const candidate of [props.media?.url, localThumbnailUrl.value]) {
        if (typeof candidate !== 'string' || candidate === '') {
            continue;
        }

        if (!/^https?:\/\//.test(candidate)) {
            continue;
        }

        try {
            return new URL(candidate).origin;
        } catch (_e) {
            // Ignore malformed URL candidates and continue.
        }
    }

    return null;
});

const derivedWorkerMediaUrl = computed(() => {
    const origin = workerOrigin.value;
    const path = typeof props.media?.file_path === 'string' ? props.media.file_path.trim() : '';

    if (!origin || path === '') {
        return null;
    }

    return `${origin}/${path.replace(/^\/+/, '')}`;
});

const derivedThumbnailUrl = computed(() => {
    const mediaId = props.media?.id;
    const albumId = props.media?.album_id ?? 0;

    if (!mediaId) {
        return null;
    }

    const originCandidate = mediaOriginalUrl.value;
    if (!originCandidate || !/^https?:\/\//.test(originCandidate)) {
        return null;
    }

    try {
        const origin = new URL(originCandidate).origin;
        return `${origin}/thumbnails/${albumId}/${mediaId}.jpg`;
    } catch (_e) {
        return null;
    }
});

const resolvedThumbnailUrl = computed(() => {
    if (!props.useThumbnail) {
        return null;
    }

    // Preferred explicit thumbnail from backend.
    if (localThumbnailUrl.value && !/\/albums\//.test(localThumbnailUrl.value)) {
        return localThumbnailUrl.value;
    }

    // If backend fell back to original URL, derive deterministic thumbnail key.
    if (derivedThumbnailUrl.value) {
        return derivedThumbnailUrl.value;
    }

    return localThumbnailUrl.value;
});

const heicDisplayFallbackUrl = computed(() => {
    if (!isHeic.value) {
        return null;
    }

    if (localThumbnailUrl.value && !/\/albums\//.test(localThumbnailUrl.value)) {
        return localThumbnailUrl.value;
    }

    return derivedThumbnailUrl.value || localThumbnailUrl.value || null;
});

const shouldRenderVideoElement = computed(() => {
    // Requirement: grid/list should show thumbnails, preview should play video.
    return isVideo.value && props.preview;
});
const shouldUseHeicCoverMode = computed(() => {
    // Only force cover behavior in grid cards for HEIC/HEIF.
    return isHeic.value && !props.preview && !props.fill;
});

const cleanupObjectUrl = () => {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
};

const preloadImageUrl = (url) => new Promise((resolve, reject) => {
    if (!url) {
        reject(new Error('Missing image URL to preload.'));
        return;
    }

    const img = new Image();
    img.decoding = 'async';
    img.onload = () => resolve(true);
    img.onerror = () => reject(new Error('Image preload failed.'));
    img.src = url;

    if (typeof img.decode === 'function') {
        img.decode().then(() => resolve(true)).catch(() => {
            // Keep onload/onerror as fallback path for older browsers.
        });
    }
});

const getHeicSourceMimeType = () => {
    if (mimeType.value.includes('heif')) {
        return 'image/heif';
    }

    return 'image/heic';
};

const parseIsoBmffHeader = (arrayBuffer) => {
    if (!(arrayBuffer instanceof ArrayBuffer) || arrayBuffer.byteLength < 12) {
        return { hasFtyp: false, majorBrand: '', compatibleBrands: [] };
    }

    const bytes = new Uint8Array(arrayBuffer);
    const toAscii = (start, len) => String.fromCharCode(...bytes.slice(start, start + len));

    const boxType = toAscii(4, 4);
    if (boxType !== 'ftyp') {
        return { hasFtyp: false, majorBrand: '', compatibleBrands: [] };
    }

    const majorBrand = toAscii(8, 4).trim().toLowerCase();
    const compatibleBrands = [];
    for (let i = 16; i + 4 <= bytes.length && i < 64; i += 4) {
        const brand = toAscii(i, 4).trim().toLowerCase();
        if (brand) {
            compatibleBrands.push(brand);
        }
    }

    return { hasFtyp: true, majorBrand, compatibleBrands };
};

const isLikelyHeifContainer = (header) => {
    if (!header?.hasFtyp) {
        return false;
    }

    const supported = new Set(['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1']);
    if (supported.has(header.majorBrand)) {
        return true;
    }

    return (header.compatibleBrands || []).some((brand) => supported.has(brand));
};

const convertHeicToBrowserImage = async ({ silent = false, preserveOnFail = false } = {}) => {
    if (!isHeic.value || attemptedHeicConversion.value) {
        return false;
    }

    attemptedHeicConversion.value = true;
    if (!silent) {
        isLoading.value = true;
        conversionFailed.value = false;
        hasImageLoaded.value = false;
    }

    try {
        const fetchUrl = heicProxyUrl.value || mediaProxyUrl.value;

        if (!fetchUrl) {
            throw new Error('Missing HEIC source URL.');
        }

        const response = await fetch(fetchUrl, {
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Proxy fetch failed: ${response.status}`);
        }

        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        const rawBlob = await response.blob();
        const rawBuffer = await rawBlob.arrayBuffer();
        const header = parseIsoBmffHeader(rawBuffer);

        if (contentType.includes('text/html') || contentType.includes('application/json')) {
            throw new Error(`HEIC proxy returned unexpected content-type: ${contentType || 'unknown'}`);
        }

        if (!header.hasFtyp) {
            throw new Error("Invalid HEIF bytes: missing 'ftyp' box.");
        }

        if (!isLikelyHeifContainer(header)) {
            throw new Error(
                `Source is ISO BMFF but not HEIC/HEIF (major brand: ${header.majorBrand || 'unknown'}).`,
            );
        }

        const heicBlob = new Blob([rawBuffer], {
            type: getHeicSourceMimeType(),
        });

        let outputBlob = null;

        try {
            // Prefer libheif-js: it decodes top-level HEIC image items and avoids
            // accidental low-res embedded preview selection.
            outputBlob = await decodeHeicBlobWithLibheif(heicBlob, {
                mimeType: 'image/png',
                quality: 0.98,
            });
        } catch (_libheifError) {
            // Keep heic2any as a compatibility fallback for browsers/devices where
            // wasm decoding fails, so preview still remains functional.
            const converted = await heic2any({
                blob: heicBlob,
                toType: 'image/jpeg',
                quality: 0.95,
            });

            outputBlob = Array.isArray(converted) ? converted[0] : converted;
        }

        const nextObjectUrl = URL.createObjectURL(outputBlob);

        // Prevent preview flicker: preload the decoded HEIC object URL fully,
        // then swap src in one step so the old image stays visible until ready.
        if (props.preview && silent) {
            await preloadImageUrl(nextObjectUrl);
        }

        objectUrl = nextObjectUrl;
        resolvedUrl.value = nextObjectUrl;
        conversionFailed.value = false;

        return true;
    } catch (error) {
        // Keep a targeted console trail for records that claim HEIC but stream
        // non-HEIC bytes from /media/{id}/raw (e.g. auth HTML, MOV bytes, bad path).
        if (isHeic.value) {
            console.warn('HEIC preview background decode failed', {
                mediaId: props.media?.id,
                fileName: props.media?.file_name,
                mimeType: props.media?.mime_type,
                error: error instanceof Error ? error.message : String(error),
            });
        }

        if (silent && preserveOnFail) {
            return false;
        }

        // heic2any can't parse every HEIC variant (codec profiles, HDR, etc.).
        // The server generates JPG thumbnails server-side, so fall back to the
        // thumbnail URL before showing the "HEIC" placeholder — gives a degraded
        // but visible preview.  Only skip this if the thumbnail path was already
        // exhausted (e.g. in grid mode, thumbnail 404 triggered onImageError
        // which set attemptedThumbnailFallback before calling us).
        if (heicDisplayFallbackUrl.value && !attemptedThumbnailFallback.value) {
            attemptedThumbnailFallback.value = true;
            resolvedUrl.value = heicDisplayFallbackUrl.value;
            conversionFailed.value = false;
        } else {
            conversionFailed.value = true;
        }
        return false;
    } finally {
        if (!silent) {
            isLoading.value = false;
        }
    }
};

const syncUrl = async () => {
    runId += 1;

    // Emit stored DB dimensions immediately so parent layouts can reserve
    // the correct card height before the media file finishes loading.
    const dbW = Number(shouldLockSquareHeicTile.value ? intrinsicWidth.value : reliableDbWidth.value);
    const dbH = Number(shouldLockSquareHeicTile.value ? intrinsicHeight.value : reliableDbHeight.value);
    if (Number.isFinite(dbW) && Number.isFinite(dbH) && dbW > 0 && dbH > 0) {
        const mediaId = props.media?.id ?? 'unknown';
        const dimsKey = `${mediaId}:${dbW}x${dbH}`;
        if (lastEmittedDbDimsKey.value !== dimsKey) {
            lastEmittedDbDimsKey.value = dimsKey;
            emit('load', { naturalWidth: dbW, naturalHeight: dbH });
        }
    }

    cleanupObjectUrl();
    conversionFailed.value = false;
    isLoading.value = false;
    hasImageLoaded.value = false;
    attemptedHeicConversion.value = false;
    attemptedThumbnailFallback.value = false;
    loadedNaturalWidth.value = null;
    loadedNaturalHeight.value = null;
    resolvedUrl.value = '';

    if (!mediaProxyUrl.value) {
        // No URL at all — show fallback immediately so no blank tile is rendered
        conversionFailed.value = true;
        return;
    }

    // Preview mode must always use original media for quality/zoom behavior.
    if (props.preview) {
        let hasFastPreview = false;

        if (!isVideo.value) {
            if (localPreviewUrl.value) {
                resolvedUrl.value = localPreviewUrl.value;
                hasFastPreview = true;
            }

            if (!hasFastPreview && localPreviewFallbackUrl.value) {
                resolvedUrl.value = localPreviewFallbackUrl.value;
                hasFastPreview = true;
            }
        }

        if (isHeic.value) {
            if (!hasFastPreview && heicDisplayFallbackUrl.value) {
                resolvedUrl.value = heicDisplayFallbackUrl.value;
                hasFastPreview = true;
            }

            if (hasFastPreview) {
                // Keep fast derivative visible, then silently upgrade to
                // full-quality conversion from original HEIC bytes.
                await convertHeicToBrowserImage({ silent: true, preserveOnFail: true });
            } else {
                // Only attempt conversion when no server-side JPG thumbnail exists.
                await convertHeicToBrowserImage();
            }
        } else {
            resolvedUrl.value = mediaOriginalUrl.value;
        }
        return;
    }

    // Use the fast listing URL for grid/list contexts.
    // Falls back to the regular media URL when unavailable.
    if (props.useThumbnail && resolvedThumbnailUrl.value) {
        resolvedUrl.value = resolvedThumbnailUrl.value;
        return;
    }

    resolvedUrl.value = isHeic.value
        ? (heicProxyUrl.value || mediaOriginalUrl.value)
        : mediaOriginalUrl.value;
};

const onImgLoad = (e) => {
    const img = e.target;
    loadedNaturalWidth.value = Number(img.naturalWidth) > 0 ? img.naturalWidth : null;
    loadedNaturalHeight.value = Number(img.naturalHeight) > 0 ? img.naturalHeight : null;
    hasImageLoaded.value = true;

    if (shouldLockSquareHeicTile.value && intrinsicWidth.value && intrinsicHeight.value) {
        emit('load', { naturalWidth: intrinsicWidth.value, naturalHeight: intrinsicHeight.value });
        return;
    }

    emit('load', { naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight });
};

const onVideoMeta = (e) => {
    const v = e.target;
    loadedNaturalWidth.value = Number(v.videoWidth) > 0 ? v.videoWidth : null;
    loadedNaturalHeight.value = Number(v.videoHeight) > 0 ? v.videoHeight : null;
    emit('load', { naturalWidth: v.videoWidth || 16, naturalHeight: v.videoHeight || 9 });
};

const onVideoError = () => {
    conversionFailed.value = true;
    hasImageLoaded.value = true;
};

const onImageError = async () => {
    if (props.preview && resolvedUrl.value === localPreviewUrl.value && localPreviewFallbackUrl.value) {
        resolvedUrl.value = localPreviewFallbackUrl.value;
        return;
    }

    if (props.preview && resolvedUrl.value === localPreviewFallbackUrl.value && mediaOriginalUrl.value) {
        resolvedUrl.value = mediaOriginalUrl.value;
        return;
    }

    if (
        props.useThumbnail
        && resolvedThumbnailUrl.value
        && resolvedUrl.value === resolvedThumbnailUrl.value
        && !attemptedThumbnailFallback.value
        && mediaOriginalUrl.value
    ) {
        attemptedThumbnailFallback.value = true;
        hasImageLoaded.value = false;
        conversionFailed.value = false;

        if (isHeic.value) {
            // For HEIC, skip setting the cross-origin HEIC URL as <img src>.
            // That no-cors request would be cached as an opaque response, which
            // the subsequent cors fetch() in convertHeicToBrowserImage cannot read.
            // Jump straight to conversion using the same-origin proxy instead.
            await convertHeicToBrowserImage();
        } else {
            resolvedUrl.value = mediaOriginalUrl.value;
        }
        return;
    }

    if (isHeic.value && !attemptedHeicConversion.value) {
        if (props.disableHeicConversion) {
            hasImageLoaded.value = true;
            conversionFailed.value = true;
            return;
        }

        const converted = await convertHeicToBrowserImage();

        if (converted) {
            return;
        }
    }

    hasImageLoaded.value = true;
    conversionFailed.value = true;
};

watch(
    () => [props.media?.id, props.media?.url, props.media?.thumbnail_url, props.media?.preview_url, props.media?.preview_fallback_url, props.media?.file_name, props.media?.mime_type, props.media?.file_type, props.media?.width, props.media?.height],
    () => {
        syncUrl();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    cleanupObjectUrl();
    runId += 1;
});
</script>

<template>
    <!-- ─── VIDEO ─────────────────────────────────────────────────────────── -->
    <video
        v-if="shouldRenderVideoElement"
        v-bind="$attrs"
        :src="resolvedUrl"
        :width="displayWidth || undefined"
        :height="displayHeight || undefined"
        :class="videoClass"
        :controls="videoControls"
        :autoplay="videoAutoplay"
        :playsinline="videoPlaysinline"
        preload="metadata"
        @loadedmetadata="onVideoMeta"
        @error="onVideoError"
    ></video>

    <!-- ─── HEIC conversion in-progress ──────────────────────────────────── -->
    <div v-else-if="isLoading" :class="fallbackClass" :style="containerStyle" class="relative overflow-hidden">
        <div
            v-if="resolvedUrl"
            class="absolute inset-0 scale-110 blur-xl bg-cover bg-center"
            :style="{ backgroundImage: `url('${resolvedUrl}')` }"
        ></div>
        <div class="absolute inset-0 cover-wave-placeholder"></div>
    </div>

    <!-- ─── PREVIEW MODE: bare <img>, no wrapper so pan/zoom is not clipped ─ -->
    <img
        v-else-if="!conversionFailed && preview"
        v-bind="$attrs"
        :src="resolvedUrl"
        :alt="alt || media?.file_name || 'Media'"
        :width="displayWidth || undefined"
        :height="displayHeight || undefined"
        :class="[imageClass, hasImageLoaded ? 'media-image-ready' : 'media-image-loading']"
        decoding="async"
        loading="lazy"
        @load="onImgLoad"
        @error="onImageError"
    />

    <!-- ─── GRID MODE: wrapper with aspect-ratio + blurred actual-image bg ── -->
    <div v-else-if="!conversionFailed" class="media-container" :style="containerStyle">
        <div
            v-if="!hasImageLoaded && resolvedUrl"
            class="media-blur-bg"
            :style="{ backgroundImage: `url('${resolvedUrl}')` }"
        />

        <!-- Shared wave shimmer used project-wide while lazy image is loading -->
        <div v-if="!hasImageLoaded" class="cover-wave-placeholder absolute inset-0" />

        <img
            v-bind="$attrs"
            :src="resolvedUrl"
            :alt="alt || media?.file_name || 'Media'"
            :class="'media-img-fill ' + (hasImageLoaded ? 'media-img-fill--ready' : 'media-img-fill--loading')"
            decoding="async"
            loading="lazy"
            @load="onImgLoad"
            @error="onImageError"
        />
    </div>

    <!-- ─── FALLBACK (broken URL / load error / HEIC conversion failed) ───── -->
    <div v-else :class="fallbackClass" :style="containerStyle">
        <template v-if="isHeic">{{ fallbackLabel }}</template>
        <h3 v-else-if="alt" class="px-3 text-center text-[1.5rem] font-bold leading-snug opacity-60 break-words line-clamp-3">{{ alt }}</h3>
        <svg v-else class="w-8 h-8 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
    </div>
</template>

<style scoped>
/* ── Grid container ─────────────────────────────────────────────────────── */
.media-container {
    position: relative;
    z-index: 0;
    isolation: isolate;
    width: 100%;
    overflow: hidden;
    display: block;
}

.media-container {
    /* Use the project's bg-elevated HSL token; fallback to a neutral gray */
    background-color: hsl(var(--bg-elevated, 220 14% 94%));
}

/* ── Blurred actual-image backdrop (grid only) ──────────────────────────── */
.media-blur-bg {
    position: absolute;
    inset: -12%;           /* overshoot so blur edge artifacts are hidden */
    background-size: cover;
    background-position: center;
    filter: blur(22px);
    z-index: 0;
}

/* ── Grid image states ──────────────────────────────────────────────────── */
/* While loading: invisible but above wave so it covers when loaded */
.media-image-loading {
    position: relative;
    z-index: 3;
    width: 100%;
    height: auto;
    display: block;
    opacity: 0;
    transform: scale(1.015);
    transition: opacity 380ms ease, transform 700ms ease-out;
}

/* Once loaded: fade in over the wave + blur bg */
.media-image-ready {
    position: relative;
    z-index: 3;
    width: 100%;
    height: auto;
    display: block;
    opacity: 1;
    transform: scale(1);
    transition: opacity 380ms ease, transform 700ms ease-out;
}

/* ── Fill-mode image (absolutely fills the .media-container) ────────────── */
.media-img-fill {
    position: absolute;
    inset: 0;
    z-index: 3;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: opacity 320ms ease, transform 700ms ease-out;
}

.media-img-fill--loading {
    opacity: 0;
}

.media-img-fill--ready {
    opacity: 1;
}

</style>
