<script setup>
import heic2any from 'heic2any';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

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
});

const emit = defineEmits(['load']);

const resolvedUrl = ref('');
const conversionFailed = ref(false);
const isLoading = ref(false);
const hasImageLoaded = ref(false);
const attemptedHeicConversion = ref(false);
const attemptedThumbnailFallback = ref(false);

let objectUrl = null;
let runId = 0;

const extension = computed(() => {
    const name = props.media?.file_name || '';
    const parts = name.split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
});

const mimeType = computed(() => String(props.media?.mime_type || '').toLowerCase());
const isVideo = computed(() => props.media?.file_type === 'video');
const intrinsicWidth = computed(() => {
    const value = Number(props.media?.width);
    return Number.isFinite(value) && value > 0 ? value : null;
});
const intrinsicHeight = computed(() => {
    const value = Number(props.media?.height);
    return Number.isFinite(value) && value > 0 ? value : null;
});
const containerStyle = computed(() => {
    if (props.fill) {
        // Fill mode: stretch to cover the positioned ancestor (e.g. fixed-height strip tiles).
        return { position: 'absolute', inset: '0', width: '100%', height: '100%' };
    }
    if (intrinsicWidth.value && intrinsicHeight.value) {
        // Use CSS aspect-ratio only — paddingBottom conflicts and causes double height.
        return { aspectRatio: `${intrinsicWidth.value} / ${intrinsicHeight.value}` };
    }
    // No DB dimensions yet: reserve a minimum height so the grid cell isn't zero-height.
    return { minHeight: '160px' };
});
const isHeic = computed(() => {
    if (isVideo.value) {
        return false;
    }
    return ['heic', 'heif'].includes(extension.value)
        || mimeType.value.includes('image/heic')
        || mimeType.value.includes('image/heif');
});

const heicProxyUrl = computed(() => {
    if (props.media?.id && isHeic.value) {
        return `/media/${props.media.id}/raw`;
    }
    return null;
});

const mediaOriginalUrl = computed(() => {
    if (props.media?.url) {
        return props.media.url;
    }

    if (props.media?.id) {
        return `/media/${props.media.id}/raw`;
    }

    return '';
});

const mediaProxyUrl = computed(() => {
    if (props.useThumbnail && localThumbnailUrl.value && !isHeic.value) {
        return localThumbnailUrl.value;
    }

    return mediaOriginalUrl.value;
});

/**
 * Fast listing URL for media thumbnails.
 * This may be a local thumbnail path or a Worker/CDN URL.
 */
const localThumbnailUrl = computed(() => props.media?.thumbnail_url || null);

const resolvedThumbnailUrl = computed(() => {
    if (!props.useThumbnail || isHeic.value) {
        return null;
    }

    // Preferred explicit thumbnail from backend.
    if (localThumbnailUrl.value && !/\/albums\//.test(localThumbnailUrl.value)) {
        return localThumbnailUrl.value;
    }

    // If backend fell back to original URL, derive deterministic thumbnail key.
    const mediaId = props.media?.id;
    const albumId = props.media?.album_id ?? 0;
    const original = mediaOriginalUrl.value;

    if (mediaId && original && /^https?:\/\//.test(original)) {
        try {
            const origin = new URL(original).origin;
            return `${origin}/thumbnails/${albumId}/${mediaId}.jpg`;
        } catch (_e) {
            return localThumbnailUrl.value;
        }
    }

    return localThumbnailUrl.value;
});

const cleanupObjectUrl = () => {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
};

const getHeicSourceMimeType = () => {
    if (mimeType.value.includes('heif')) {
        return 'image/heif';
    }

    return 'image/heic';
};

const convertHeicToBrowserImage = async () => {
    if (!isHeic.value || attemptedHeicConversion.value) {
        return false;
    }

    attemptedHeicConversion.value = true;
    isLoading.value = true;
    conversionFailed.value = false;
    hasImageLoaded.value = false;

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

        const rawBlob = await response.blob();
        const heicBlob = new Blob([await rawBlob.arrayBuffer()], {
            type: getHeicSourceMimeType(),
        });

        const converted = await heic2any({
            blob: heicBlob,
            toType: 'image/jpeg',
            quality: 0.9,
        });

        const outputBlob = Array.isArray(converted) ? converted[0] : converted;
        objectUrl = URL.createObjectURL(outputBlob);
        resolvedUrl.value = objectUrl;

        return true;
    } catch (_error) {
        conversionFailed.value = true;
        return false;
    } finally {
        isLoading.value = false;
    }
};

const syncUrl = async () => {
    runId += 1;

    cleanupObjectUrl();
    conversionFailed.value = false;
    isLoading.value = false;
    hasImageLoaded.value = false;
    attemptedHeicConversion.value = false;
    attemptedThumbnailFallback.value = false;
    resolvedUrl.value = '';

    if (!mediaProxyUrl.value) {
        // No URL at all — show fallback immediately so no blank tile is rendered
        conversionFailed.value = true;
        return;
    }

    // Preview mode must always use original media for quality/zoom behavior.
    if (props.preview) {
        resolvedUrl.value = isHeic.value
            ? (heicProxyUrl.value || mediaOriginalUrl.value)
            : mediaOriginalUrl.value;
        return;
    }

    // Use the fast listing URL for grid/list contexts.
    // Falls back to the regular media URL when unavailable.
    if (props.useThumbnail && resolvedThumbnailUrl.value && !isHeic.value) {
        resolvedUrl.value = resolvedThumbnailUrl.value;
        return;
    }

    resolvedUrl.value = isHeic.value
        ? (heicProxyUrl.value || mediaOriginalUrl.value)
        : mediaOriginalUrl.value;
};

const onImgLoad = (e) => {
    const img = e.target;
    hasImageLoaded.value = true;
    emit('load', { naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight });
};

const onVideoMeta = (e) => {
    const v = e.target;
    emit('load', { naturalWidth: v.videoWidth || 16, naturalHeight: v.videoHeight || 9 });
};

const onVideoError = () => {
    conversionFailed.value = true;
    hasImageLoaded.value = true;
};

const onImageError = async () => {
    if (
        props.useThumbnail
        && resolvedThumbnailUrl.value
        && resolvedUrl.value === resolvedThumbnailUrl.value
        && !attemptedThumbnailFallback.value
        && mediaOriginalUrl.value
    ) {
        // Thumbnail key may not exist in R2 yet; fall back to original media URL.
        attemptedThumbnailFallback.value = true;
        hasImageLoaded.value = false;
        conversionFailed.value = false;
        resolvedUrl.value = mediaOriginalUrl.value;
        return;
    }

    if (isHeic.value && !attemptedHeicConversion.value) {
        const converted = await convertHeicToBrowserImage();

        if (converted) {
            return;
        }
    }

    hasImageLoaded.value = true;
    conversionFailed.value = true;
};

watch(
    () => [props.media?.id, props.media?.url, props.media?.thumbnail_url, props.media?.file_name, props.media?.mime_type, props.media?.file_type],
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
        v-if="isVideo"
        v-bind="$attrs"
        :src="resolvedUrl"
        :class="videoClass"
        :controls="videoControls"
        :autoplay="videoAutoplay"
        :playsinline="videoPlaysinline"
        preload="metadata"
        @loadedmetadata="onVideoMeta"
        @error="onVideoError"
    ></video>

    <!-- ─── HEIC conversion in-progress ──────────────────────────────────── -->
    <div v-else-if="isLoading" :class="fallbackClass" class="relative overflow-hidden">
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
        :width="intrinsicWidth || undefined"
        :height="intrinsicHeight || undefined"
        :class="[imageClass, hasImageLoaded ? 'media-image-ready' : 'media-image-loading']"
        decoding="async"
        loading="lazy"
        @load="onImgLoad"
        @error="onImageError"
    />

    <!-- ─── GRID MODE: wrapper with aspect-ratio + blurred actual-image bg ── -->
    <div v-else-if="!conversionFailed" class="media-container">
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
            :width="intrinsicWidth || undefined"
            :height="intrinsicHeight || undefined"
            :class="fill
                ? [imageClass, hasImageLoaded ? 'media-img-fill media-img-fill--ready' : 'media-img-fill media-img-fill--loading']
                : [imageClass, hasImageLoaded ? 'media-image-ready' : 'media-image-loading']"
            decoding="async"
            loading="lazy"
            @load="onImgLoad"
            @error="onImageError"
        />
    </div>

    <!-- ─── FALLBACK (broken URL / load error / HEIC conversion failed) ───── -->
    <div v-else :class="fallbackClass">
        <template v-if="isHeic">{{ fallbackLabel }}</template>
        <svg v-else class="w-8 h-8 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
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
