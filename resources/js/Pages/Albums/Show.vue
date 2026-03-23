<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import MediaPreviewOverlay from '@/Components/MediaPreviewOverlay.vue';
import Modal from '@/Components/Modal.vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { useMediaPreview } from '@/composables/useMediaPreview';
import { downloadFile, formatFileSize } from '@/utils/media';
import { useInfiniteScroll } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    album: Object,
    mediaData: Object,
    breadcrumbs: Array,
});

const page = usePage();
const user = page.props.auth.user;

const viewMode = ref('grid');
const filter = ref('All');
const showNewMenu = ref(false);
const showActionMenu = ref(null);
const showNewFolderModal = ref(false);
const showDeleteModal = ref(false);
const itemToDelete = ref(null);
const deleteType = ref('album');

const newFolder = ref({ title: '', description: '' });
const isPinned = ref(!!props.album?.is_pinned);
const isPinProcessing = ref(false);

// ── Pinterest-style LTR masonry ──────────────────────────────────────────────
// CSS Grid masonry using 10 px row tracks with no row-gap.
// Items use align-self:start (via grid's items-start) so the visible card
// height equals the image's natural rendered height — no bg strip below.
// Each item's span = ceil((imageH + 16) / 10)  →  ~16 px gap between rows.
const MASONRY_ROW_UNIT = 10; // px — must match grid-auto-rows below
const MASONRY_GAP      = 16; // px — visual row gap between cards

const masonryRef = ref(null);
const naturalDims = ref({}); // { [fileId]: { w, h } }
const spanMap    = ref({}); // { [fileId]: row span count }

function calcSpan(fileId) {
    const dims = naturalDims.value[fileId];
    if (!dims || !masonryRef.value) return;
    const item = masonryRef.value.querySelector(`[data-file-id="${fileId}"]`);
    // clientWidth excludes the 2 px border so the aspect-ratio math is exact.
    const colWidth = item ? (item.clientWidth || item.offsetWidth) : 220;
    const imageH = dims.h / dims.w * colWidth;
    const span = Math.ceil((imageH + MASONRY_GAP) / MASONRY_ROW_UNIT);
    spanMap.value = { ...spanMap.value, [fileId]: span };
}

function onFileLoad(fileId, { naturalWidth, naturalHeight }) {
    naturalDims.value[fileId] = { w: naturalWidth, h: naturalHeight };
    nextTick(() => calcSpan(fileId));
}

function recalcAllSpans() {
    Object.keys(naturalDims.value).forEach(id => calcSpan(Number(id)));
}

function removeDeletedMediaFromList(mediaId) {
    files.value = files.value.filter(file => file.id !== mediaId);

    const { [mediaId]: removedDims, ...remainingDims } = naturalDims.value;
    const { [mediaId]: removedSpan, ...remainingSpans } = spanMap.value;

    naturalDims.value = remainingDims;
    spanMap.value = remainingSpans;

    if (previewMedia.value?.id === mediaId) {
        closePreview();
    }

    nextTick(() => recalcAllSpans());
}

// The previous original onMounted listener was here but was removed during a refactor.
onBeforeUnmount(() => window.removeEventListener('resize', recalcAllSpans));
// ─────────────────────────────────────────────────────────────────────────────

const canManage = computed(() => ['admin', 'manager'].includes(user.role));
const canContribute = computed(() => ['admin', 'manager', 'member'].includes(user.role));
const canModify = computed(() => canManage.value || props.album.user_id === user.id);
const canUpload = computed(() => !props.album.is_system && canContribute.value);
const canCreateFolder = computed(() => !props.album.is_system && canContribute.value);
const canShowToolbar = computed(() => canUpload.value || canCreateFolder.value);
const parentAlbumSlug = computed(() => {
    if (!props.breadcrumbs?.length) {
        return null;
    }

    return props.breadcrumbs[props.breadcrumbs.length - 1]?.path || props.breadcrumbs[props.breadcrumbs.length - 1]?.slug || null;
});

const folderItems = ref([...(props.album.children || [])]);
const folders = computed(() => folderItems.value);
const files = ref([...(props.mediaData?.data || [])]);
const nextPageUrl = ref(props.mediaData?.next_page_url || null);
const isLoadingMore = ref(false);

useInfiniteScroll(
    window,
    async () => {
        if (!nextPageUrl.value || isLoadingMore.value) return;

        isLoadingMore.value = true;
        try {
            const response = await axios.get(nextPageUrl.value, {
                headers: { 'Accept': 'application/json' }
            });
            files.value.push(...response.data.data);
            nextPageUrl.value = response.data.next_page_url;
        } catch (e) {
            console.error(e);
        } finally {
            isLoadingMore.value = false;
        }
    },
    { distance: 10 }
);

const filteredFolders = computed(() => {
    if (filter.value === 'Photos' || filter.value === 'Videos') return [];
    return folders.value;
});


const allFilteredFiles = computed(() => {
    let result = files.value;
    if (filter.value === 'Folders') return [];
    if (filter.value === 'Photos') result = files.value.filter(f => f.file_type === 'image');
    if (filter.value === 'Videos') result = files.value.filter(f => f.file_type === 'video');
    return result;
});

const filteredFiles = computed(() => {
    return allFilteredFiles.value;
});

const totalFolderCount = computed(() => folders.value.length);
const totalFileCount = computed(() => {
    const serverTotal = Number(props.mediaData?.total);
    if (Number.isFinite(serverTotal) && serverTotal > 0) {
        return serverTotal;
    }

    return files.value.length;
});

onMounted(() => {
    window.addEventListener('resize', recalcAllSpans);
    nextTick(() => recalcAllSpans());
});


const toggleActionMenu = (e, id) => {
    e.stopPropagation();
    showActionMenu.value = showActionMenu.value === id ? null : id;
};

const closeActionMenu = () => {
    showActionMenu.value = null;
    showNewMenu.value = false;
};

const togglePin = (e) => {
    e.stopPropagation();

    if (isPinProcessing.value) return;

    isPinProcessing.value = true;
    router.post(route('albums.pin-toggle', props.album.slug || props.album.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            isPinned.value = !isPinned.value;
        },
        onFinish: () => {
            isPinProcessing.value = false;
        },
    });
};

const isCreatingFolder = ref(false);
const createFolderError = ref('');

const createFolder = async () => {
    if (!newFolder.value.title.trim()) return;
    isCreatingFolder.value = true;
    createFolderError.value = '';
    try {
        const response = await axios.post(route('albums.store'), {
            title: newFolder.value.title,
            description: newFolder.value.description,
            parent_id: props.album.id,
            location: props.album.location,
        }, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        // Push the new folder into folderItems so it shows instantly
        const newAlbum = response.data?.album;
        if (newAlbum) {
            folderItems.value.push(newAlbum);
        }
        showNewFolderModal.value = false;
        newFolder.value = { title: '', description: '' };
    } catch (err) {
        createFolderError.value = err.response?.data?.message || 'Failed to create folder.';
    } finally {
        isCreatingFolder.value = false;
    }
};

const confirmDelete = (item, type) => {
    itemToDelete.value = item;
    deleteType.value = type;
    showActionMenu.value = null;
    showDeleteModal.value = true;
};

const deleteItem = () => {
    if (!itemToDelete.value) return;

    if (deleteType.value === 'album') {
        const albumId = itemToDelete.value.id;

        router.delete(route('albums.destroy', itemToDelete.value.slug || itemToDelete.value.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                folderItems.value = folderItems.value.filter(folder => folder.id !== albumId);
                showDeleteModal.value = false;
                itemToDelete.value = null;
            },
            onError: () => {
                showDeleteModal.value = false;
                itemToDelete.value = null;
            },
        });
    } else {
        const mediaId = itemToDelete.value.id;

        router.delete(route('media.destroy', itemToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                removeDeletedMediaFromList(mediaId);
                showDeleteModal.value = false;
                itemToDelete.value = null;
            },
            onError: () => {
                showDeleteModal.value = false;
                itemToDelete.value = null;
            },
        });
    }
};

const canDeleteMedia = (media) => {
    if (canManage.value) return true;
    return media.user_id === user.id;
};

const handleAction = (action, type, item) => {
    showActionMenu.value = null;
    if (action === 'Delete') {
        confirmDelete(item, type);
    } else if (action === 'Download') {
        if (type === 'media') {
            downloadFile(item.url, item.file_name);
        }
    }
};

const {
    showPreviewModal,
    previewMedia,
    currentIndex,
    openPreview,
    closePreview,
    goToNext,
    goToPrevious,
} = useMediaPreview(allFilteredFiles);
</script>

<template>
    <Head :title="album.title" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-6" @click="closeActionMenu">

            <!-- Header with Breadcrumbs and New Button -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-2 overflow-x-auto pb-2 md:pb-0 scrollbar-hide flex-1">
                    <Link :href="parentAlbumSlug ? route('albums.show', parentAlbumSlug) : route('albums.index')" class="p-2 rounded-full hover:bg-bg-hover text-muted-foreground transition-all shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    </Link>

                    <div class="flex items-center gap-1 text-sm text-muted-foreground whitespace-nowrap min-w-0 flex-1">
                        <Link :href="route('albums.index')" class="hover:text-foreground cursor-pointer transition-colors">Albums</Link>

                        <template v-for="crumb in breadcrumbs" :key="crumb.id">
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <Link :href="route('albums.show', crumb.path || crumb.slug || crumb.id)" class="hover:text-foreground cursor-pointer transition-colors truncate">
                                {{ crumb.title }}
                            </Link>
                        </template>

                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-foreground font-bold truncate">{{ album.title }}</span>

                        <!-- Pin Button -->
                        <button v-if="!album.is_system" @click="togglePin" :disabled="isPinProcessing" class="ml-3 p-1.5 rounded-full transition-all shrink-0 shadow-sm border disabled:opacity-60 disabled:cursor-not-allowed"
                                :class="isPinned ? 'bg-primary border-primary text-primary-foreground' : 'hover:bg-bg-hover text-muted-foreground border-border'">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pin w-4 h-4 fill-current"><path d="M12 17v5"></path><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <div class="relative" v-if="canShowToolbar">
                        <button @click.stop="showNewMenu = !showNewMenu" class="flex items-center gap-2 h-10 px-5 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-1px] transition-all">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            New
                        </button>

                        <div v-if="showNewMenu" class="absolute right-0 mt-2 w-56 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-50 animate-scale-in" @click.stop>
                            <button v-if="canCreateFolder" @click="showNewFolderModal = true; showNewMenu = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                New folder
                            </button>
                            <div v-if="canCreateFolder && canUpload" class="h-px bg-border my-1"></div>
                            <Link v-if="canUpload" :href="route('albums.upload', album.slug || album.id)" @click="showNewMenu = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                File upload
                            </Link>
                        </div>
                    </div>

                    <div class="flex items-center bg-bg-elevated rounded-pill p-1 border border-border">
                        <button @click="viewMode = 'list'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'list' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <button @click="viewMode = 'grid'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'grid' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex items-center gap-2 border-b border-border pb-2">
                <template v-for="f in ['All', 'Photos', 'Videos', 'Folders']" :key="f">
                    <button @click="filter = f" class="px-4 py-1.5 rounded-pill text-xs font-bold transition-all border"
                            :class="filter === f ? 'bg-primary/10 border-primary text-primary' : 'bg-transparent border-transparent text-muted-foreground hover:bg-bg-hover'">
                        {{ f }}
                    </button>
                </template>
            </div>

            <!-- Folders Section -->
            <div v-if="filteredFolders.length > 0" class="space-y-4 overflow-visible isolate folders-layer">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-bold text-foreground">Folders</h3>
                    <span class="inline-flex items-center rounded-pill bg-bg-elevated px-2.5 py-1 text-[11px] font-bold text-muted-foreground">
                        {{ totalFolderCount }}
                    </span>
                </div>
                <div class="grid gap-4 overflow-visible" :class="viewMode === 'grid' ? 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' : 'grid-cols-1'">
                    <Link v-for="folder in filteredFolders" :key="folder.id" :href="route('albums.show', folder.path || folder.slug || folder.id)"
                        class="group relative z-0 overflow-visible cursor-pointer transition-all animate-fade-in-up flex items-center gap-4 bg-bg-card border border-border rounded-2xl hover:bg-bg-hover hover:border-primary/30 folder-card-layer"
                        :class="[
                            viewMode === 'grid' ? 'p-4 pr-2' : 'p-3 px-6',
                            showActionMenu === 'folder-'+folder.id ? 'z-50 menu-open' : '',
                        ]">
                        <div class="w-14 h-14 rounded-xl bg-primary/5 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110 overflow-hidden border border-primary/10">
                            <MediaRenderer
                                v-if="folder.thumbnail_media"
                                :media="folder.thumbnail_media"
                                :alt="folder.title"
                                :use-thumbnail="true"
                                image-class="w-full h-full object-cover rounded-xl"
                                video-class="w-full h-full object-cover rounded-xl"
                                fallback-class="flex h-full w-full items-center justify-center rounded-xl bg-primary/10 text-[10px] font-bold uppercase tracking-[0.22em] text-primary"
                            />
                            <div v-else class="w-full h-full rounded-xl bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center text-primary">
                                <svg class="w-6 h-6 fill-current" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-foreground truncate">{{ folder.title }}</p>
                            <p class="text-[10px] text-muted-foreground mt-0.5">{{ folder.media_count }} files</p>
                        </div>

                        <div class="relative overflow-visible" @click.stop>
                            <button @click.prevent.stop="toggleActionMenu($event, 'folder-'+folder.id)" class="p-2 rounded-full border border-border/80 bg-bg-card/90 text-foreground shadow-sm backdrop-blur-sm transition-all opacity-100 md:opacity-0 md:group-hover:opacity-100 hover:bg-bg-elevated hover:border-primary/30 hover:text-foreground">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
                            </button>
                            <div v-if="showActionMenu === 'folder-'+folder.id" class="absolute right-0 top-full mt-2 w-44 rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl z-[9999] animate-scale-in folder-menu-popover">
                                <button v-if="canManage || folder.user_id === user.id" type="button" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover" @click.prevent.stop="router.visit(route('albums.edit', folder.slug || folder.id)); closeActionMenu();">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Rename
                                </button>
                                <button v-if="canManage || folder.user_id === user.id" @click.prevent.stop="confirmDelete(folder, 'album')" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-error transition-colors hover:bg-error/10">
                                    <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Files Section -->
            <div v-if="filteredFiles.length > 0" class="space-y-4 files-layer">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-bold text-foreground">Files</h3>
                    <span class="inline-flex items-center rounded-pill bg-bg-elevated px-2.5 py-1 text-[11px] font-bold text-muted-foreground">
                        {{ totalFileCount }}
                    </span>
                </div>

                <div
                    v-if="viewMode === 'grid'"
                    ref="masonryRef"
                    class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 items-start"
                    style="grid-auto-rows: 10px; row-gap: 0; column-gap: 1rem;"
                >
                    <div
                        v-for="file in filteredFiles"
                        :key="file.id"
                        :data-file-id="file.id"
                        :style="{ gridRowEnd: 'span ' + (spanMap[file.id] || 22) }"
                        @click="openPreview(file)"
                        class="group relative w-full rounded-2xl overflow-visible border border-border bg-bg-elevated cursor-pointer hover:border-primary/50 transition-all shadow-sm hover:shadow-xl animate-fade-in-up"
                    >
                        <div class="relative overflow-hidden rounded-2xl">
                            <MediaRenderer
                                :media="file"
                                :alt="file.file_name"
                                :use-thumbnail="true"
                                image-class="w-full h-auto block object-cover transition-transform duration-700 group-hover:scale-105"
                                video-class="w-full h-auto block object-cover transition-transform duration-700 group-hover:scale-105"
                                fallback-class="flex h-[180px] w-full items-center justify-center bg-bg-hover text-sm font-bold uppercase tracking-[0.24em] text-muted-foreground"
                                @load="dims => onFileLoad(file.id, dims)"
                            />

                            <div v-if="file.file_type === 'video'" class="absolute inset-0 flex items-center justify-center bg-black/10">
                                <div class="w-12 h-12 rounded-full bg-black/40 backdrop-blur-md flex items-center justify-center text-white scale-90 group-hover:scale-100 transition-transform">
                                    <svg class="w-6 h-6 fill-current ml-0.5" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                                </div>
                            </div>

                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-all duration-300">
                                <div class="absolute top-3 right-3 flex flex-col gap-2">
                                    <button @click="toggleActionMenu($event, 'file-'+file.id)" class="w-10 h-10 rounded-full border border-white/20 bg-black/55 text-white backdrop-blur-md flex items-center justify-center shadow-lg transition-all hover:bg-black/70 hover:border-white/40">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                    </button>
                                </div>
                                <div class="absolute bottom-4 inset-x-4 flex items-center justify-between">
                                    <div class="flex flex-col min-w-0 pr-2">
                                        <span class="text-white text-xs font-bold truncate">{{ file.file_name }}</span>
                                        <span class="text-white/60 text-[10px] truncate">By {{ file.user?.name || 'Unknown' }}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <button @click="handleAction('Download', 'media', file)" class="w-8 h-8 rounded-full bg-white/10 backdrop-blur-md text-white flex items-center justify-center hover:bg-primary transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Menu -->
                        <div v-if="showActionMenu === 'file-'+file.id" class="absolute right-3 top-14 z-30 w-44 rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl animate-scale-in" @click.stop>
                            <button @click="handleAction('Download', 'media', file)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
                            </button>
                            <button v-if="canDeleteMedia(file)" @click="handleAction('Delete', 'media', file)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-error transition-colors hover:bg-error/10">
                                <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Remove
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="bg-bg-card border border-border rounded-2xl overflow-hidden shadow-sm">
                    <div class="grid grid-cols-[minmax(0,1fr)_220px_120px_88px] items-center gap-4 px-6 py-3 border-b border-border bg-bg-elevated/50">
                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Name</span>
                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Owner</span>
                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider text-right">Size</span>
                        <span />
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="file in filteredFiles" :key="file.id" @click="openPreview(file)" class="grid grid-cols-[minmax(0,1fr)_220px_120px_88px] items-center gap-4 px-6 py-4 hover:bg-bg-hover transition-colors cursor-pointer group">
                            <div class="flex items-center gap-4 min-w-0">
                                <div class="w-12 h-12 rounded-lg overflow-hidden border border-border shrink-0">
                                    <MediaRenderer
                                        :media="file"
                                        :alt="file.file_name"
                                        :use-thumbnail="true"
                                        image-class="w-full h-full object-cover"
                                        video-class="w-full h-full object-cover"
                                        fallback-class="flex h-full w-full items-center justify-center bg-bg-hover text-[10px] font-bold uppercase tracking-[0.22em] text-muted-foreground"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-foreground truncate">{{ file.file_name }}</p>
                                    <p class="text-[11px] text-muted-foreground">{{ new Date(file.created_at).toLocaleDateString() }}</p>
                                </div>
                            </div>
                            <div class="min-w-0 flex items-center gap-2">
                                <span class="text-sm text-foreground truncate">{{ file.user?.name || 'Unknown' }}</span>
                            </div>
                            <span class="text-sm font-medium text-foreground text-right tabular-nums">{{ formatFileSize(file.file_size) }}</span>
                            <div class="relative flex items-center justify-end gap-1">
                                <button @click.stop="handleAction('Download', 'media', file)" class="p-2 rounded-full hover:bg-bg-elevated text-muted-foreground hover:text-foreground transition-all">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </button>
                                <button @click.stop="toggleActionMenu($event, 'file-'+file.id)" class="p-2 rounded-full border border-border/80 bg-bg-card/90 text-foreground shadow-sm transition-all hover:bg-bg-elevated hover:border-primary/30 hover:text-foreground">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                                <div v-if="showActionMenu === 'file-'+file.id" class="absolute right-0 top-full mt-2 w-44 rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl z-50 animate-scale-in" @click.stop>
                                    <button @click="handleAction('Download', 'media', file)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                        <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
                                    </button>
                                    <button v-if="canDeleteMedia(file)" @click="handleAction('Delete', 'media', file)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-error transition-colors hover:bg-error/10">
                                        <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div v-if="isLoadingMore" class="py-6 flex justify-center text-muted-foreground animate-pulse">
                <div class="orange-loader"></div>
            </div>

            <!-- Empty State -->
            <div v-if="filteredFiles.length === 0 && filteredFolders.length === 0" class="flex flex-col items-center justify-center py-20 bg-bg-card border border-dashed border-border rounded-3xl animate-fade-in">
                <div class="w-20 h-20 rounded-full bg-bg-elevated flex items-center justify-center text-muted-foreground mb-6">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </div>
                <h3 class="text-xl font-bold text-foreground">This album is empty</h3>
                <p class="text-muted-foreground mt-1 text-center">Click New to add folders and upload media<br/>All members can contribute</p>
            </div>

        </div>

        <MediaPreviewOverlay
            :show="showPreviewModal"
            :media="previewMedia"
            :items="allFilteredFiles"
            :current-index="currentIndex"
            @close="closePreview"
            @next="goToNext"
            @previous="goToPrevious"
        />

        <!-- New Folder Modal -->
        <Modal :show="showNewFolderModal" @close="showNewFolderModal = false" max-width="md" contained>
            <div class="p-8 bg-bg-card border border-border rounded-xl">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h2 class="text-xl font-bold text-foreground">Create New Folder</h2>
                </div>

                <form @submit.prevent="createFolder" class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Folder Name</label>
                        <input type="text" v-model="newFolder.title" placeholder="e.g. Stage Performances" class="w-full h-12 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" required />
                    </div>

                    <p v-if="createFolderError" class="text-sm text-error">{{ createFolderError }}</p>

                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" @click="showNewFolderModal = false" :disabled="isCreatingFolder" class="h-11 px-6 rounded-pill text-sm font-bold text-foreground hover:bg-bg-hover transition-all disabled:opacity-50">Cancel</button>
                        <button type="submit" :disabled="isCreatingFolder" class="h-11 px-8 rounded-pill bg-primary text-primary-foreground text-sm font-bold shadow-lg shadow-primary/20 hover:scale-105 active:scale-95 transition-all disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2">
                            <svg v-if="isCreatingFolder" class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            {{ isCreatingFolder ? 'Creating...' : 'Create Folder' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false" max-width="sm" contained>
            <div class="p-6 bg-bg-card border border-border rounded-xl">
                <h2 class="text-lg font-bold text-foreground">Confirm Delete</h2>

                <p class="mt-2 text-sm text-muted-foreground">
                    Are you sure you want to delete <span class="font-bold text-foreground">"{{ itemToDelete?.title || itemToDelete?.file_name }}"</span>?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="px-4 py-2 rounded-md text-sm font-bold text-foreground hover:bg-bg-hover transition-colors">Cancel</button>
                    <button @click="deleteItem" class="px-4 py-2 rounded-md text-sm font-bold bg-error text-white hover:bg-red-600 transition-colors shadow-sm">Delete</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.folders-layer {
    position: relative !important;
    z-index: 120 !important;
    overflow: visible !important;
    isolation: isolate !important;
}

.folder-card-layer {
    position: relative !important;
    overflow: visible !important;
}

.folder-card-layer.menu-open {
    z-index: 130 !important;
}

.folder-menu-popover {
    position: absolute !important;
    z-index: 9999 !important;
    overflow: visible !important;
}

.files-layer {
    position: relative !important;
    z-index: 1 !important;
}
</style>
