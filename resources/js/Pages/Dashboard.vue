<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import MediaPreviewOverlay from '@/Components/MediaPreviewOverlay.vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';
import { useMediaPreview } from '@/composables/useMediaPreview';
import { formatNumber } from '@/utils/number';

const props = defineProps({
    stats: Object,
    recentMedia: Array,
    recentAlbums: Array,
    allRecentAlbums: Array,
    myRecentUploads: Array,
    userRole: String,
});

// ── Dynamic storage stats (fetched via Ajax from DB) ─────────────────────────
const storageLoading    = ref(true);
const dynamicStorage    = ref(null);  // { storageUsed, storageTotalBytes, mediaAssets, myStorageUsed, myStorageBytes }

onMounted(async () => {
    try {
        const res  = await fetch('/dashboard/storage-stats', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (res.ok) {
            dynamicStorage.value = await res.json();
        }
    } catch (e) {
        // silently fall back to the server-rendered props
    } finally {
        storageLoading.value = false;
    }
});

// Resolved stats — use Ajax result when ready, fall back to SSR props.
const resolvedStorageUsed = computed(() => {
    if (storageLoading.value) return null; // null → show spinner
    if (dynamicStorage.value) {
        return props.userRole === 'member'
            ? dynamicStorage.value.myStorageUsed
            : dynamicStorage.value.storageUsed;
    }
    return props.userRole === 'member'
        ? props.stats?.myStorageUsed
        : props.stats?.storageUsed;
});

const resolvedMediaAssets = computed(() => {
    if (dynamicStorage.value) return dynamicStorage.value.mediaAssets;
    return props.stats?.mediaAssets;
});

const isAdmin = computed(() => props.userRole === 'admin');
const isManager = computed(() => props.userRole === 'manager');
const isMember = computed(() => props.userRole === 'member');

const recentVisualMedia = computed(() => {
    const media = props.recentMedia || [];
    return media.filter((item) => {
        const type = String(item?.file_type || '').toLowerCase();
        return type === 'image' || type === 'video';
    });
});

const statsGridClass = computed(() => (
    isMember.value
        ? 'grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-3 gap-6'
        : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6'
));
// ─────────────────────────────────────────────────────────────────────────────

const MASONRY_ROW_UNIT = 10;
const MASONRY_ROW_GAP = 12;

const recentMediaMasonryRef = ref(null);
const recentMediaNaturalDims = ref({});
const recentMediaSpanMap = ref({});

const resolveRecentMediaColumnWidth = (grid) => {
    const containerW = grid?.clientWidth || grid?.offsetWidth || 0;
    let colWidth = 220;

    if (containerW > 0) {
        const styles = window.getComputedStyle(grid);
        const template = styles.gridTemplateColumns || '';
        const colGap = parseFloat(styles.columnGap) || 16;

        let colCount = 0;

        const repeatMatch = template.match(/repeat\((\d+),/);
        if (repeatMatch) {
            colCount = Number(repeatMatch[1]) || 0;
        }

        if (!colCount) {
            colCount = (template.match(/\S+/g) || []).length;
        }

        if (!colCount) {
            const firstItem = grid.querySelector('[data-media-id]');
            const firstWidth = Number(firstItem?.getBoundingClientRect?.().width || 0);
            if (firstWidth > 0) {
                colCount = Math.max(1, Math.round((containerW + colGap) / (firstWidth + colGap)));
            }
        }

        colCount = Math.max(1, colCount);
        colWidth = (containerW - colGap * (colCount - 1)) / colCount;
    }

    return colWidth;
};

const spanFromRecentMediaHeight = (height) => {
    const safeHeight = Number(height);
    if (!Number.isFinite(safeHeight) || safeHeight <= 0) {
        return 1;
    }

    return Math.max(1, Math.ceil((safeHeight + MASONRY_ROW_GAP) / (MASONRY_ROW_UNIT + MASONRY_ROW_GAP)));
};

const estimateRecentMediaSpan = (width, height, grid) => {
    const w = Number(width);
    const h = Number(height);
    if (w <= 0 || h <= 0) {
        return null;
    }

    const colWidth = resolveRecentMediaColumnWidth(grid);
    const imageHeight = (h / w) * colWidth;

    return spanFromRecentMediaHeight(imageHeight);
};

const getRecentMediaSpan = (item) => {
    const explicit = recentMediaSpanMap.value[item.id];
    if (explicit) {
        return explicit;
    }

    const estimated = estimateRecentMediaSpan(item.width, item.height, recentMediaMasonryRef.value);
    if (estimated) {
        return estimated;
    }

    return 22;
};

const formatSize = (bytes) => {
    if (!bytes) return '0.00 B';
    const k = 1000;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
};

const {
    showPreviewModal,
    previewMedia,
    currentIndex,
    items: allMediaContext,
    openPreview,
    closePreview,
    goToNext,
    goToPrevious,
} = useMediaPreview(() => props.recentMedia);

const handleAction = (e, action, id) => {
    e.stopPropagation();
    console.log(`${action} performed on asset ${id}`);
};

const parseStorageToTB = (value) => {
    if (!value) return 0;
    const match = String(value).match(/([\d.]+)\s*(B|KB|MB|GB|TB)?/i);
    if (!match) return 0;

    const amount = parseFloat(match[1]);
    if (Number.isNaN(amount)) return 0;

    const unit = (match[2] || 'B').toUpperCase();
    const divisors = {
        B: 1000 ** 4,
        KB: 1000 ** 3,
        MB: 1000 ** 2,
        GB: 1000,
        TB: 1,
    };

    return amount / (divisors[unit] || 1);
};

const formatStorageLabel = (value) => {
    if (!value) return '0.00 TB';

    const match = String(value).match(/([\d.]+)\s*(B|KB|MB|GB|TB)?/i);
    if (!match) return '0.00 TB';

    const amount = parseFloat(match[1]);
    if (Number.isNaN(amount)) return '0.00 TB';

    const unit = (match[2] || 'B').toUpperCase();
    return `${amount.toFixed(2)} ${unit}`;
};

const storageUsedLabel = computed(() => {
    if (resolvedStorageUsed.value === null) return null; // loading
    return formatStorageLabel(resolvedStorageUsed.value);
});

const storageUsagePercent = computed(() => {
    if (!storageUsedLabel.value) return 0;
    const usedTb = parseStorageToTB(storageUsedLabel.value);
    const maxTb = props.userRole === 'member' ? 2 : 1;
    return Math.min(100, Math.max(0, (usedTb / maxTb) * 100));
});

const membersUsed = computed(() => props.stats?.totalUsers || 0);
const membersMax = computed(() => (props.userRole === 'admin' ? 200 : 100));
const memberUsagePercent = computed(() => {
    return Math.min(100, Math.max(0, (membersUsed.value / membersMax.value) * 100));
});

const calcRecentMediaSpan = (mediaId) => {
    const dims = recentMediaNaturalDims.value[mediaId];
    if (!dims || !recentMediaMasonryRef.value) {
        return;
    }

    const span = estimateRecentMediaSpan(dims.w, dims.h, recentMediaMasonryRef.value);
    if (!span) {
        return;
    }

    recentMediaSpanMap.value = {
        ...recentMediaSpanMap.value,
        [mediaId]: span,
    };
};

const recalcRecentMediaSpans = () => {
    Object.keys(recentMediaNaturalDims.value).forEach((id) => calcRecentMediaSpan(Number(id)));
};

const seedRecentMediaDims = (items) => {
    let hasChanges = false;

    for (const item of items || []) {
        const width = Number(item?.width);
        const height = Number(item?.height);

        if (width > 0 && height > 0 && !recentMediaNaturalDims.value[item.id]) {
            recentMediaNaturalDims.value[item.id] = { w: width, h: height };
            hasChanges = true;
        }
    }

    if (hasChanges) {
        nextTick(() => requestAnimationFrame(() => recalcRecentMediaSpans()));
    }
};

const rememberRecentMediaDims = (mediaId, { naturalWidth, naturalHeight }) => {
    if (!naturalWidth || !naturalHeight) {
        return;
    }

    recentMediaNaturalDims.value = {
        ...recentMediaNaturalDims.value,
        [mediaId]: {
            w: naturalWidth,
            h: naturalHeight,
        },
    };

    nextTick(() => calcRecentMediaSpan(mediaId));
};

onMounted(() => {
    window.addEventListener('resize', recalcRecentMediaSpans);
    seedRecentMediaDims(recentVisualMedia.value);
    nextTick(() => requestAnimationFrame(() => recalcRecentMediaSpans()));
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', recalcRecentMediaSpans);
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-8 py-2">

            <div class="flex items-start sm:items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 v-if="userRole === 'admin'" class="font-heading font-bold text-3xl">Overview</h1>
                    <h1 v-else-if="userRole === 'manager'" class="font-heading font-bold text-3xl">Manager Hub</h1>
                    <h1 v-else class="font-heading font-bold text-3xl">My Workspace</h1>

                    <p class="text-muted-foreground mt-1 text-sm max-w-[24ch] sm:max-w-none">
                        {{ userRole === 'admin' ? 'Monitor platform assets and user contributions' :
                           userRole === 'manager' ? 'Coordinate albums and team contributions' :
                           'Access albums and contribute your media' }}
                    </p>
                </div>

                <Link v-if="userRole !== 'member'" href="/albums/create" class="shrink-0 whitespace-nowrap inline-flex items-center gap-2 h-9 px-3 sm:h-10 sm:px-5 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-xs sm:text-sm shadow-lg hover:shadow-primary/20 transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Album
                </Link>
                <Link v-else href="/albums" class="shrink-0 whitespace-nowrap inline-flex items-center gap-2 h-9 px-3 sm:h-10 sm:px-5 rounded-pill bg-primary text-primary-foreground font-bold text-xs sm:text-sm shadow-lg hover:shadow-primary/20 transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    Upload Files
                </Link>
            </div>

            <!-- Stat Cards common -->
            <div :class="statsGridClass">
                <!-- Admin Stats -->
                <template v-if="userRole === 'admin'">
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Users</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.totalUsers) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.totalAlbums) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Media Assets</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(resolvedMediaAssets) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Storage Used</p>
                            <h3 v-if="!storageLoading" class="text-3xl font-bold font-mono text-foreground mt-1">{{ storageUsedLabel }}</h3>
                            <div v-else class="flex items-center gap-2 mt-1">
                                <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                <span class="text-sm font-semibold text-muted-foreground tracking-wide">Calculating...</span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-1">{{ formatNumber(resolvedMediaAssets) }} files in library</p>
                        </div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg></div>
                    </div>
                </template>

                <!-- Manager Stats -->
                <template v-if="userRole === 'manager'">
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Team Members</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.totalUsers) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.totalAlbums) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">My Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.myAlbums) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">New Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.newUploads) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg></div>
                    </div>
                </template>

                <!-- Member Stats -->
                <template v-if="userRole === 'member'">
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.myUploadsCount) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Album</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(stats.totalAlbums) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Media Assets</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ formatNumber(resolvedMediaAssets) }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 gap-8">
                <!-- Main Activity View -->
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <span>Pinned Albums</span>
                        </h3>
                        <Link href="/albums" class="text-xs font-bold text-primary hover:underline uppercase tracking-widest">View All</Link>
                    </div>

                    <div v-if="recentAlbums.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                        <Link
                            v-for="album in recentAlbums.slice(0, 10)"
                            :key="album.id"
                            :href="route('albums.show', album.path || album.slug || album.id)"
                            class="group relative flex flex-col gap-2 cursor-pointer transition-all duration-300"
                        >
                            <div class="relative rounded-2xl bg-bg-elevated border border-border overflow-hidden transition-all shadow-sm group-hover:border-primary/50 group-hover:shadow-md">
                                <div class="aspect-video relative overflow-hidden bg-bg-elevated rounded-2xl">
                                    <MediaRenderer
                                        v-if="album.coverMedia"
                                        :media="album.coverMedia"
                                        :alt="album.name"
                                        :use-thumbnail="true"
                                        image-class="w-full h-full object-cover will-change-transform group-hover:scale-[1.035] transition-transform duration-700 ease-out"
                                        video-class="w-full h-full object-cover will-change-transform group-hover:scale-[1.035] transition-transform duration-700 ease-out"
                                        fallback-class="flex h-full w-full items-center justify-center bg-primary/5 text-xs font-bold uppercase tracking-[0.24em] text-primary/60"
                                    />
                                    <div v-else class="w-full h-full flex items-center justify-center text-primary/40 bg-primary/5 rounded-2xl">
                                        <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    </div>
                                    <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                                    <div v-if="album.location" class="absolute bottom-2 right-2 flex items-center gap-1 text-[10px] bg-black/50 text-white px-2 py-0.5 rounded-md backdrop-blur-md">
                                        <svg class="w-3 h-3 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ album.location }}
                                    </div>
                                </div>
                            </div>
                            <div class="px-1">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-orange-500 fill-orange-500/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    <p class="text-sm font-bold text-foreground truncate">{{ album.name }}</p>
                                </div>
                                <p class="text-[11px] text-muted-foreground mt-0.5 flex items-center gap-2.5">
                                    <template v-if="album.photo_count > 0">
                                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
                                        <span class="font-medium">{{ formatNumber(album.photo_count) }}</span>
                                    </template>
                                    <template v-if="album.video_count > 0">
                                        <svg class="w-3.5 h-3.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                                        <span class="font-medium">{{ formatNumber(album.video_count) }}</span>
                                    </template>
                                    <template v-if="album.file_count > 0">
                                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                                        <span class="font-medium">{{ formatNumber(album.file_count) }}</span>
                                    </template>
                                    <template v-if="album.children_count > 0">
                                        <svg class="w-3.5 h-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                        <span class="font-medium">{{ formatNumber(album.children_count) }}</span>
                                    </template>
                                </p>
                            </div>
                        </Link>
                    </div>
                    <div v-else class="dash-card !p-6 text-sm text-muted-foreground">
                        No pinned albums yet. Pin an album from the Albums or Album details page.
                    </div>

                    <!-- Recent Albums + Recent Photos/Videos -->
                    <div class="space-y-4 pt-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-heading font-bold text-lg text-foreground">Recent Albums</h3>
                            <Link href="/albums" class="text-xs font-bold text-primary hover:underline uppercase tracking-widest">View All</Link>
                        </div>
                        <div v-if="allRecentAlbums?.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                            <Link
                                v-for="album in allRecentAlbums.slice(0, 10)"
                                :key="album.id"
                                :href="route('albums.show', album.path || album.slug || album.id)"
                                class="group relative flex flex-col gap-2 cursor-pointer transition-all duration-300"
                            >
                                <div class="relative rounded-2xl bg-bg-elevated border border-border overflow-hidden transition-all shadow-sm group-hover:border-primary/50 group-hover:shadow-md">
                                    <div class="aspect-video relative overflow-hidden bg-bg-elevated rounded-2xl">
                                        <MediaRenderer
                                            v-if="album.coverMedia"
                                            :media="album.coverMedia"
                                            :alt="album.name"
                                            :use-thumbnail="true"
                                            image-class="w-full h-full object-cover will-change-transform group-hover:scale-[1.035] transition-transform duration-700 ease-out"
                                            video-class="w-full h-full object-cover will-change-transform group-hover:scale-[1.035] transition-transform duration-700 ease-out"
                                            fallback-class="flex h-full w-full items-center justify-center bg-primary/5 text-xs font-bold uppercase tracking-[0.24em] text-primary/60"
                                        />
                                        <div v-else class="w-full h-full flex items-center justify-center text-primary/40 bg-primary/5 rounded-2xl">
                                            <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        </div>
                                        <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                                        <div v-if="album.location" class="absolute bottom-2 right-2 flex items-center gap-1 text-[10px] bg-black/50 text-white px-2 py-0.5 rounded-md backdrop-blur-md">
                                            <svg class="w-3 h-3 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            {{ album.location }}
                                        </div>
                                    </div>
                                </div>
                                <div class="px-1">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-orange-500 fill-orange-500/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <p class="text-sm font-bold text-foreground truncate">{{ album.name }}</p>
                                    </div>
                                    <p class="text-[11px] text-muted-foreground mt-0.5 flex items-center gap-2.5">
                                        <template v-if="album.photo_count > 0">
                                            <svg class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
                                            <span class="font-medium">{{ formatNumber(album.photo_count) }}</span>
                                        </template>
                                        <template v-if="album.video_count > 0">
                                            <svg class="w-3.5 h-3.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                                            <span class="font-medium">{{ formatNumber(album.video_count) }}</span>
                                        </template>
                                        <template v-if="album.file_count > 0">
                                            <svg class="w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                                            <span class="font-medium">{{ formatNumber(album.file_count) }}</span>
                                        </template>
                                        <template v-if="album.children_count > 0">
                                            <svg class="w-3.5 h-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                            <span class="font-medium">{{ formatNumber(album.children_count) }}</span>
                                        </template>
                                    </p>
                                </div>
                            </Link>
                        </div>
                        <div v-else class="dash-card !p-6 text-sm text-muted-foreground">No albums yet.</div>
                    </div>

                    <div class="space-y-4 pt-4">
                        <h3 class="font-heading font-bold text-lg text-foreground">Recent Photos <span class="font-sans">&amp;</span> Videos</h3>
                        <div
                            v-if="recentVisualMedia.length"
                            ref="recentMediaMasonryRef"
                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
                            style="grid-auto-rows: 10px; row-gap: 12px; column-gap: 0.5rem; grid-auto-flow: dense;"
                        >
                            <template v-for="item in recentVisualMedia" :key="item.id">
                                <div
                                    :data-media-id="item.id"
                                    :style="{ gridRowEnd: 'span ' + getRecentMediaSpan(item) }"
                                    @click="openPreview(item, recentVisualMedia)"
                                    class="group relative w-full rounded-2xl overflow-hidden border border-border bg-bg-elevated cursor-pointer hover:border-primary/50 transition-all shadow-sm hover:shadow-lg animate-fade-in-up"
                                >
                                    <MediaRenderer
                                        :media="item"
                                        :alt="item.file_name"
                                        :use-thumbnail="true"
                                        :fill="true"
                                        image-class="transition-transform duration-700 group-hover:scale-105"
                                        video-class="transition-transform duration-700 group-hover:scale-105"
                                        fallback-class="flex w-full h-full items-center justify-center bg-bg-hover text-sm font-bold uppercase tracking-[0.24em] text-muted-foreground"
                                        @load="rememberRecentMediaDims(item.id, $event)"
                                    />
                                    <div v-if="item.file_type === 'video'" class="absolute inset-0 z-10 flex items-center justify-center">
                                        <div class="w-10 h-10 rounded-full bg-black/60 flex items-center justify-center">
                                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                                        </div>
                                    </div>
                                    <div class="absolute inset-0 z-10 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex items-end p-3 rounded-xl">
                                        <div class="flex items-center justify-between w-full">
                                            <span class="text-white text-[10px] font-bold truncate tracking-wide">By {{ item.user?.name || 'Unknown' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div
                            v-else
                            class="dash-card !p-6 text-sm text-muted-foreground"
                        >
                            No recent photos or videos available right now.
                        </div>
                    </div>


            </div>
            </div>

            <MediaPreviewOverlay
                :show="showPreviewModal"
                :media="previewMedia"
                :items="allMediaContext"
                :current-index="currentIndex"
                @close="closePreview"
                @next="goToNext"
                @previous="goToPrevious"
            />

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.dash-card {
    position: relative;
    overflow: hidden;
    background: linear-gradient(132deg, hsl(var(--card)), hsl(var(--card)) 15%, hsl(var(--primary) / 0.08));
    border: 1px solid hsl(var(--border));
    border-radius: 1rem;
    padding: 1.25rem;
    box-shadow: 0 1px 2px hsl(220 15% 20% / 0.04);
}

/* Stat label text is too dim on dark card backgrounds — brighten it */
:global(.dark) .dash-card p {
    color: hsl(0 0% 72%);
}

.dash-icon-box {
    width: 3rem;
    height: 3rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, hsl(var(--primary)), hsl(var(--accent-hover)));
    color: hsl(var(--primary-foreground));
    box-shadow: 0 10px 20px hsl(var(--primary) / 0.22);
}

.orange-progress {
    background: linear-gradient(90deg, hsl(var(--primary)), hsl(var(--accent-hover)));
}

.progress-shimmer {
    width: 100%;
    background: linear-gradient(
        90deg,
        hsl(var(--muted)) 25%,
        hsl(var(--primary) / 0.45) 50%,
        hsl(var(--muted)) 75%
    );
    background-size: 200% 100%;
    animation: shimmer 1.4s ease-in-out infinite;
}

@keyframes shimmer {
    0%   { background-position: 200% center; }
    100% { background-position: -200% center; }
}
</style>
