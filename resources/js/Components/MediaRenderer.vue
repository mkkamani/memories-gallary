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
});

const emit = defineEmits(['load']);

const resolvedUrl = ref('');
const conversionFailed = ref(false);
const isLoading = ref(false);

let objectUrl = null;
let runId = 0;

const extension = computed(() => {
    const name = props.media?.file_name || '';
    const parts = name.split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
});

const mimeType = computed(() => String(props.media?.mime_type || '').toLowerCase());
const isVideo = computed(() => props.media?.file_type === 'video');
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
    emit('load', { naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight });
};

const onVideoMeta = (e) => {
    const v = e.target;
    emit('load', { naturalWidth: v.videoWidth || 16, naturalHeight: v.videoHeight || 9 });
};

const onImageError = () => {
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

    <div v-else-if="isLoading" :class="fallbackClass" class="flex flex-col items-center justify-center gap-3">
        <div class="orange-loader"></div>
    </div>

    <img
        v-else-if="!conversionFailed"
        v-bind="$attrs"
        :src="resolvedUrl"
        :alt="alt || media?.file_name || 'Media'"
        :class="imageClass"
        loading="lazy"
        @load="onImgLoad"
        @error="onImageError"
    />

    <div v-else :class="fallbackClass">
        {{ fallbackLabel }}
    </div>
</template>
