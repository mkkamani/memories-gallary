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
});

const emit = defineEmits(['load']);

const resolvedUrl = ref('');
const conversionFailed = ref(false);
const isLoading = ref(false);
const hasImageLoaded = ref(false);

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

const cleanupObjectUrl = () => {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
};

const syncUrl = async () => {
    runId += 1;
    const currentRun = runId;

    cleanupObjectUrl();
    conversionFailed.value = false;
    isLoading.value = false;
    hasImageLoaded.value = false;
    resolvedUrl.value = props.media?.url || '';

    if (!props.media?.url || isVideo.value || !isHeic.value) {
        return;
    }

    isLoading.value = true;

    try {
        const fetchUrl = heicProxyUrl.value || props.media.url;
        const response = await fetch(fetchUrl);
        if (!response.ok) {
            throw new Error(`Proxy fetch failed: ${response.status}`);
        }

        const rawBlob = await response.blob();

        if (currentRun !== runId) {
            return;
        }

        const heicBlob = new Blob([await rawBlob.arrayBuffer()], { type: 'image/heic' });

        const converted = await heic2any({
            blob: heicBlob,
            toType: 'image/jpeg',
            quality: 0.9,
        });

        if (currentRun !== runId) {
            return;
        }

        const outputBlob = Array.isArray(converted) ? converted[0] : converted;
        objectUrl = URL.createObjectURL(outputBlob);
        resolvedUrl.value = objectUrl;
    } catch (_error) {
        if (currentRun !== runId) {
            return;
        }
        conversionFailed.value = true;
    } finally {
        if (currentRun === runId) {
            isLoading.value = false;
        }
    }
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

const onImageError = () => {
    hasImageLoaded.value = true;
    if (isHeic.value) {
        conversionFailed.value = true;
    }
};

watch(
    () => [props.media?.id, props.media?.url, props.media?.file_name, props.media?.mime_type, props.media?.file_type],
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
    ></video>

    <!-- ─── HEIC conversion in-progress ──────────────────────────────────── -->
    <div v-else-if="isLoading" :class="fallbackClass" class="flex flex-col items-center justify-center gap-3">
        <div class="orange-loader"></div>
    </div>

    <!-- ─── PREVIEW MODE: bare <img>, no wrapper so pan/zoom is not clipped ─ -->
    <img
        v-else-if="!conversionFailed && preview"
        v-bind="$attrs"
        :src="resolvedUrl"
        :alt="alt || media?.file_name || 'Media'"
        :width="intrinsicWidth || undefined"
        :height="intrinsicHeight || undefined"
        :class="[imageClass, hasImageLoaded ? 'preview-img-ready' : 'preview-img-loading']"
        decoding="async"
        @load="onImgLoad"
        @error="onImageError"
    />

    <!-- ─── GRID MODE: wrapper with aspect-ratio + blurred actual-image bg ── -->
    <div v-else-if="!conversionFailed" class="media-container" :style="containerStyle">
        <!--
            Blurred version of the ACTUAL image shown while the img element is
            still downloading.  Both point to the same URL so the browser
            satisfies both from one HTTP request / cache entry.
            inset: -12% overflows the container boundaries to hide blur edges.
        -->
        <div
            v-if="!hasImageLoaded && resolvedUrl"
            class="media-blur-bg"
            :style="{ backgroundImage: `url('${resolvedUrl}')` }"
        />

        <!-- Left-to-right wave shimmer overlay — always visible while loading -->
        <div v-if="!hasImageLoaded" class="media-wave" />

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

    <!-- ─── FALLBACK (e.g. HEIC conversion failed) ────────────────────────── -->
    <div v-else :class="fallbackClass">
        {{ fallbackLabel }}
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

/* ── Gray base color when no URL yet ─────────────────────────────────── */
.media-container {
    background-color: var(--color-bg-elevated, #ebebeb);
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

/* ── Left-to-right wave shimmer overlay ─────────────────────────────────── */
/*    Rendered on top of both the gray base AND the blurred-bg while loading. */
.media-wave {
    position: absolute;
    inset: -30%;
    z-index: 2;
    background: linear-gradient(
        120deg,
        rgba(255, 255, 255, 0)    0%,
        rgba(255, 255, 255, 0)    40%,
        rgba(255, 255, 255, 0.34) 50%,
        rgba(255, 255, 255, 0)    60%,
        rgba(255, 255, 255, 0)    100%
    );
    background-repeat: no-repeat;
    background-size: 100% 100%;
    transform: translate3d(-35%, -35%, 0);
    animation: wave-ltr 3.2s ease-in-out infinite;
    pointer-events: none;
}

@keyframes wave-ltr {
    0% {
        transform: translate3d(-35%, -35%, 0);
    }
    100% {
        transform: translate3d(35%, 35%, 0);
    }
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
}

/* Once loaded: fade in over the wave + blur bg */
.media-image-ready {
    position: relative;
    z-index: 3;
    width: 100%;
    height: auto;
    display: block;
    opacity: 1;
    transition: opacity 380ms ease;
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
    transition: opacity 250ms ease;
}

.media-img-fill--loading {
    opacity: 0;
}

.media-img-fill--ready {
    opacity: 1;
}

/* ── Preview image states (no container, just the <img>) ────────────────── */
.preview-img-loading {
    opacity: 0;
    transition: none;
}

.preview-img-ready {
    opacity: 1;
    transition: opacity 250ms ease;
}
</style>
