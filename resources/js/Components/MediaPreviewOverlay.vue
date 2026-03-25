<script setup>
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Minus,
    Plus,
    RotateCcw,
    Share2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { downloadFile } from '@/utils/media';
import MediaRenderer from '@/Components/MediaRenderer.vue';

const MAX_ZOOM = 6;
const MIN_ZOOM = 0.6;
const VOLUME_STEP = 0.05;
const SEEK_STEP_SECONDS = 5;

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    media: {
        type: Object,
        default: null,
    },
    items: {
        type: Array,
        default: () => [],
    },
    currentIndex: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['close', 'next', 'previous']);

const currentNumber = computed(() => props.currentIndex + 1);
const totalItems = computed(() => props.items.length || 1);
const canGoPrevious = computed(() => props.currentIndex > 0);
const canGoNext = computed(() => props.currentIndex < props.items.length - 1);

const metaDate = computed(() => {
    const value = props.media?.taken_at || props.media?.created_at || props.media?.updated_at;

    if (!value) {
        return 'No date available';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'No date available';
    }

    return new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
});

const uploaderName = computed(() =>
    props.media?.user?.name
    || props.media?.user_name
    || props.media?.uploaded_by
    || (props.media?.user_id ? `User ${props.media.user_id}` : 'Unknown uploader'),
);
const mediaLabel = computed(() => props.media?.file_name || props.media?.title || 'Preview');
const isVideo = computed(() => props.media?.file_type === 'video');
const zoomLevel = ref(1);
const panX = ref(0);
const panY = ref(0);
const isPanning = ref(false);
const panStartX = ref(0);
const panStartY = ref(0);
const panOriginX = ref(0);
const panOriginY = ref(0);
const previewStageRef = ref(null);

const imageTransformStyle = computed(() => {
    if (isVideo.value) {
        return undefined;
    }

    return {
        transform: `translate(${panX.value}px, ${panY.value}px) scale(${zoomLevel.value})`,
    };
});

const canPanImage = computed(() => !isVideo.value && zoomLevel.value > 1);

const resetView = () => {
    zoomLevel.value = 1;
    panX.value = 0;
    panY.value = 0;
    isPanning.value = false;
};

const resetZoom = () => {
    resetView();
};

const zoomIn = () => {
    zoomLevel.value = Math.min(zoomLevel.value + 0.2, MAX_ZOOM);
};

const zoomOut = () => {
    zoomLevel.value = Math.max(zoomLevel.value - 0.2, MIN_ZOOM);

    if (zoomLevel.value <= 1) {
        panX.value = 0;
        panY.value = 0;
        isPanning.value = false;
    }
};

const getVideoElement = () => {
    if (!previewStageRef.value) {
        return null;
    }

    return previewStageRef.value.querySelector('video');
};

const toggleVideoPlayPause = () => {
    const video = getVideoElement();
    if (!video) {
        return;
    }

    if (video.paused) {
        void video.play();
    } else {
        video.pause();
    }
};

const adjustVideoVolume = (delta) => {
    const video = getVideoElement();
    if (!video) {
        return;
    }

    video.muted = false;
    video.volume = Math.min(1, Math.max(0, video.volume + delta));
};

const seekVideo = (deltaSeconds) => {
    const video = getVideoElement();
    if (!video) {
        return;
    }

    const duration = Number.isFinite(video.duration) ? video.duration : null;
    const nextTime = video.currentTime + deltaSeconds;

    if (duration === null) {
        video.currentTime = Math.max(0, nextTime);
        return;
    }

    video.currentTime = Math.min(duration, Math.max(0, nextTime));
};

const stopPanning = () => {
    isPanning.value = false;
};

const onImageMouseDown = (event) => {
    if (!canPanImage.value) {
        return;
    }

    if (event.button !== 2) {
        return;
    }

    event.preventDefault();
    isPanning.value = true;
    panStartX.value = event.clientX;
    panStartY.value = event.clientY;
    panOriginX.value = panX.value;
    panOriginY.value = panY.value;
};

const onMouseMove = (event) => {
    if (!isPanning.value) {
        return;
    }

    panX.value = panOriginX.value + (event.clientX - panStartX.value);
    panY.value = panOriginY.value + (event.clientY - panStartY.value);
};

const onImageContextMenu = (event) => {
    if (canPanImage.value) {
        event.preventDefault();
    }
};

const downloadCurrent = () => {
    if (!props.media?.url) {
        return;
    }

    const downloadUrl = props.media?.id
        ? `${route('media.raw', props.media.id)}?download=1`
        : props.media.url;

    downloadFile(downloadUrl, props.media.file_name || 'media');
};

const shareCurrent = async () => {
    if (!props.media?.url) {
        return;
    }

    const payload = {
        title: mediaLabel.value,
        text: `Shared from ${uploaderName.value}`,
        url: props.media.url,
    };

    try {
        if (navigator.share) {
            await navigator.share(payload);
            return;
        }

        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.media.url);
        }
    } catch (_error) {
        // Ignore user-cancelled share flows.
    }
};

const onKeydown = (event) => {
    if (!props.show) {
        return;
    }

    if (event.key === 'Escape') {
        emit('close');
    }

    if (event.key === '0') {
        event.preventDefault();
        resetView();
        return;
    }

    if (isVideo.value) {
        if (event.key === ' ') {
            event.preventDefault();
            toggleVideoPlayPause();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            adjustVideoVolume(VOLUME_STEP);
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            adjustVideoVolume(-VOLUME_STEP);
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            seekVideo(-SEEK_STEP_SECONDS);
            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            seekVideo(SEEK_STEP_SECONDS);
            return;
        }
    }

    if (event.key === 'ArrowLeft' && canGoPrevious.value) {
        emit('previous');
    }

    if (event.key === 'ArrowRight' && canGoNext.value) {
        emit('next');
    }

    if (event.key === '+' || event.key === '=') {
        zoomIn();
    }

    if (event.key === '-') {
        zoomOut();
    }
};

const onWheelZoom = (event) => {
    if (isVideo.value) {
        return;
    }

    if (!event.ctrlKey) {
        return;
    }

    event.preventDefault();

    if (event.deltaY < 0) {
        zoomIn();
    } else {
        zoomOut();
    }
};

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            resetView();
            window.addEventListener('keydown', onKeydown);
            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', stopPanning);
            document.body.style.overflow = 'hidden';
            return;
        }

        window.removeEventListener('keydown', onKeydown);
        window.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('mouseup', stopPanning);
        stopPanning();
        document.body.style.overflow = '';
    },
    { immediate: true },
);

watch(
    () => props.media?.id,
    () => resetView(),
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', stopPanning);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show && media"
            class="fixed inset-0 z-[120] overflow-hidden bg-[#111318]/95 backdrop-blur-md"
            @click.self="emit('close')"
        >
            <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/55 to-transparent"></div>
            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/45 to-transparent"></div>

            <div class="absolute inset-x-0 top-0 z-20 flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="min-w-0 text-white">
                    <h2 class="truncate text-sm font-bold sm:text-base">{{ mediaLabel }}</h2>
                    <p class="mt-0.5 truncate text-[11px] text-white/70 sm:text-xs">{{ metaDate }} · Uploaded by {{ uploaderName }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white transition hover:border-primary/55 hover:bg-primary/25"
                        aria-label="Download"
                        @click="downloadCurrent"
                    >
                        <Download class="h-4 w-4" />
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white transition hover:border-primary/55 hover:bg-primary/25"
                        aria-label="Share"
                        @click="shareCurrent"
                    >
                        <Share2 class="h-4 w-4" />
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white transition hover:border-primary/55 hover:bg-primary/25"
                        aria-label="Close preview"
                        @click="emit('close')"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <button
                type="button"
                class="absolute left-3 top-1/2 z-20 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/25 bg-black/55 text-white shadow-[0_8px_25px_rgba(0,0,0,0.4)] transition hover:border-primary/70 hover:bg-primary/35 disabled:cursor-not-allowed disabled:opacity-35 sm:left-5 lg:left-6"
                aria-label="Previous media"
                :disabled="!canGoPrevious"
                @click="emit('previous')"
            >
                <ChevronLeft class="h-6 w-6" />
            </button>

            <button
                type="button"
                class="absolute right-3 top-1/2 z-20 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/25 bg-black/55 text-white shadow-[0_8px_25px_rgba(0,0,0,0.4)] transition hover:border-primary/70 hover:bg-primary/35 disabled:cursor-not-allowed disabled:opacity-35 sm:right-5 lg:right-6"
                aria-label="Next media"
                :disabled="!canGoNext"
                @click="emit('next')"
            >
                <ChevronRight class="h-6 w-6" />
            </button>

            <div
                ref="previewStageRef"
                class="absolute inset-0 flex items-center justify-center px-14 pt-20 pb-20 sm:px-20 sm:pt-24 sm:pb-24 lg:px-24"
                :class="{ 'cursor-grab': canPanImage && !isPanning, 'cursor-grabbing': canPanImage && isPanning }"
                @wheel="onWheelZoom"
                @mousedown="onImageMouseDown"
                @contextmenu="onImageContextMenu"
            >
                <div class="flex h-full w-full items-center justify-center">
                        <MediaRenderer
                            :media="media"
                            :alt="mediaLabel"
                            :preview="true"
                            image-class="max-h-full max-w-full w-auto h-auto object-contain select-none"
                            video-class="max-h-full max-w-full rounded-lg bg-black object-contain"
                            fallback-class="flex min-h-[18rem] min-w-[18rem] items-center justify-center rounded-2xl border border-white/10 bg-black/35 px-8 text-base font-bold uppercase tracking-[0.3em] text-white/70"
                            :style="imageTransformStyle"
                            :video-controls="isVideo"
                            :video-autoplay="isVideo"
                            :video-playsinline="isVideo"
                        />
                </div>
            </div>

            <div class="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-2 rounded-full border border-white/20 bg-black/55 px-3 py-2 text-white shadow-xl backdrop-blur-md sm:bottom-5">
                <span class="min-w-12 px-2 text-center text-xs font-semibold text-white/80 sm:text-sm">
                    {{ currentNumber }} / {{ totalItems }}
                </span>

                <button
                    v-if="!isVideo"
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full transition hover:bg-white/15"
                    aria-label="Zoom out"
                    @click="zoomOut"
                >
                    <Minus class="h-4 w-4" />
                </button>

                <button
                    v-if="!isVideo"
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full transition hover:bg-white/15"
                    aria-label="Reset zoom"
                    @click="resetZoom"
                >
                    <RotateCcw class="h-4 w-4" />
                </button>

                <button
                    v-if="!isVideo"
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full transition hover:bg-white/15"
                    aria-label="Zoom in"
                    @click="zoomIn"
                >
                    <Plus class="h-4 w-4" />
                </button>
            </div>
        </div>
    </Teleport>
</template>
