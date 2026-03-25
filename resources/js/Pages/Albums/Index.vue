<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';
import { downloadFile } from '@/utils/media';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    albums: Array,
    pinnedAlbumIds: Array,
    filters: Object,
    breadcrumbs: Array,
});

const search = ref(props.filters.search || '');
const locationFilter = ref(props.filters.location || 'all');
const viewMode = ref('grid');
const showDeleteModal = ref(false);
const showActionMenu = ref(null);
const albumToDelete = ref(null);
const pinnedAlbums = ref([...(props.pinnedAlbumIds || [])]);
const pinLoadingAlbums = ref([]);
const showNewMenu = ref(false);
const showImportModal = ref(false);
const importForm = useForm({
    zip_file: null,
    location: props.filters.location && props.filters.location !== 'all' ? props.filters.location : '',
    parent_id: props.filters.parent_id || null,
});

watch([search, locationFilter], debounce(() => {
    router.get(route('albums.index'), {
        search: search.value,
        location: locationFilter.value,
        type: props.filters.type || '',
        parent_id: props.filters.parent_id || ''
    }, { preserveState: true, replace: true });
}, 300));

const page = usePage();
const canManage = computed(() => ['admin', 'manager'].includes(page.props.auth.user.role));
const canCreateActions = computed(() => ['admin', 'manager', 'member'].includes(page.props.auth.user.role));

const confirmDelete = (album) => {
    albumToDelete.value = album;
    showActionMenu.value = null;
    showDeleteModal.value = true;
};

const deleteAlbum = () => {
    if (albumToDelete.value) {
        router.delete(route('albums.destroy', albumToDelete.value.slug || albumToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showDeleteModal.value = false;
                albumToDelete.value = null;
            },
            onError: () => {
                showDeleteModal.value = false;
                albumToDelete.value = null;
            },
        });
    }
};

const toggleActionMenu = (e, albumId) => {
    e.stopPropagation();
    e.preventDefault();
    if (showActionMenu.value === albumId) {
        showActionMenu.value = null;
    } else {
        showActionMenu.value = albumId;
    }
};

const closeActionMenu = () => {
    showActionMenu.value = null;
    showNewMenu.value = false;
};

const togglePin = (e, album) => {
    e.stopPropagation();
    e.preventDefault();

    const albumId = album.id;
    if (pinLoadingAlbums.value.includes(albumId)) return;

    pinLoadingAlbums.value.push(albumId);

    router.post(route('albums.pin-toggle', album.slug || albumId), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const index = pinnedAlbums.value.indexOf(albumId);
            if (index > -1) {
                pinnedAlbums.value.splice(index, 1);
            } else {
                pinnedAlbums.value.push(albumId);
            }
        },
        onFinish: () => {
            pinLoadingAlbums.value = pinLoadingAlbums.value.filter((id) => id !== albumId);
        },
    });
};

const systemAlbums = computed(() => props.albums.filter(a => a.is_system));
const userAlbums = computed(() => props.albums.filter(a => !a.is_system));

const handleAction = (action, album) => {
    showActionMenu.value = null;

    if (action === 'Delete') {
        confirmDelete(album);
        return;
    }

    if (action === 'Download') {
        if (album.thumbnail) {
            downloadFile(
                album.thumbnail,
                `${(album.title || 'album').replace(/\s+/g, '-').toLowerCase()}-cover`,
            );
        } else {
            router.visit(route('albums.show', album.path || album.slug || album.id));
        }
        return;
    }

    if (action === 'Share') {
        const url = `${window.location.origin}${route('albums.show', album.path || album.slug || album.id)}`;

        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(url);
            return;
        }

        window.prompt('Copy album link:', url);
    }
};

const handleImportFiles = (e) => {
    importForm.zip_file = e.target.files[0];
};

const getAlbumTotalItems = (album) => {
    const total = Number(album?.media_count);
    if (Number.isFinite(total) && total > 0) {
        return total;
    }

    return Array.isArray(album?.preview_media) ? album.preview_media.length : 0;
};

const getBottomPreviewItems = (album) => {
    const previews = Array.isArray(album?.preview_media) ? album.preview_media.slice(1) : [];
    return previews.slice(0, 4);
};

const getHiddenPreviewCount = (album) => {
    return Math.max(0, getAlbumTotalItems(album) - 5);
};

const isOverflowPreviewTile = (album, idx) => {
    const bottomItems = getBottomPreviewItems(album);
    if (!bottomItems.length || getHiddenPreviewCount(album) <= 0) {
        return false;
    }

    return idx === Math.min(3, bottomItems.length - 1);
};

const submitImport = () => {
    importForm.post(route('albums.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false;
            importForm.reset();
        },
        // Keep the modal open on error so the user can see the message
        onError: () => {},
    });
};
</script>

<template>
    <Head title="Albums" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-6" @click="closeActionMenu">

            <!-- Header with Search and New Button -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="font-heading font-bold text-3xl">Albums</h1>
                    <p class="text-sm text-muted-foreground mt-1">Manage your event collections and assets</p>
                </div>

                <div class="flex items-center gap-3">
                    <select v-model="locationFilter" class="h-11 bg-bg-input border-border text-foreground rounded-pill shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 pr-10 text-sm appearance-none outline-none">
                        <option value="all">All Locations</option>
                        <option value="Ahmedabad">Ahmedabad</option>
                        <option value="Rajkot">Rajkot</option>
                    </select>

                    <input v-model="search" type="text" placeholder="Search albums..." class="h-11 bg-bg-input border-border text-foreground rounded-pill shadow-sm focus:border-primary focus:ring-1 focus:ring-primary w-full md:w-64 px-4 text-sm" />

                    <div class="relative" v-if="canCreateActions">
                        <button @click.stop="showNewMenu = !showNewMenu" class="flex items-center gap-2 h-11 px-6 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-2px] transition-all whitespace-nowrap">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            New
                        </button>

                        <div v-if="showNewMenu" class="absolute right-0 mt-2 w-56 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-50 animate-scale-in">
                            <Link :href="route('albums.create')" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg> New Album
                            </Link>
                            <!--
                            <div class="h-px bg-border my-1"></div>
                             <button @click="showImportModal = true; showNewMenu = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg> Import from ZIP
                            </button> -->
                        </div>
                    </div>

                    <div class="flex items-center bg-bg-elevated rounded-pill p-1 border border-border">
                        <button @click="viewMode = 'list'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'list' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <button @click="viewMode = 'grid'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'grid' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filters & Breadcrumbs -->
            <div class="flex items-center justify-between border-b border-border pb-4">
                <div class="flex items-center gap-2 text-sm">
                    <Link :href="route('albums.index')" class="text-muted-foreground hover:text-foreground font-medium">Albums</Link>
                    <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id">
                        <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <Link :href="route('albums.index', { parent_id: crumb.id })" class="text-foreground hover:text-primary font-medium">{{ crumb.title }}</Link>
                    </template>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-xs text-muted-foreground hidden sm:inline">Name</span>
                    <span class="text-xs text-muted-foreground hidden sm:inline">Last modified</span>
                </div>
            </div>

            <div v-if="systemAlbums.length > 0" class="space-y-4">
                <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                    <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                    Smart Albums
                </h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    <template v-for="album in systemAlbums" :key="album.id">
                        <Link :href="route('albums.all', album.id)" class="group relative flex flex-col gap-2 cursor-pointer transition-all active:scale-95">
                            <div class="aspect-[4/3] rounded-2xl bg-bg-elevated border border-border overflow-hidden relative group-hover:border-purple-500/50 transition-all shadow-sm group-hover:shadow-md">
                                <MediaRenderer
                                    v-if="album.thumbnail_media"
                                    :media="album.thumbnail_media"
                                    :alt="album.title"
                                    image-class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                    video-class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                    fallback-class="flex h-full w-full items-center justify-center bg-purple-500/5 text-xs font-bold uppercase tracking-[0.24em] text-purple-500"
                                />
                                <div v-else class="w-full h-full flex items-center justify-center bg-purple-500/5 text-purple-500">
                                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </div>
                                <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                                <div class="absolute top-2 left-2 z-10 bg-gradient-to-r from-purple-600 to-pink-600 px-2 py-0.5 rounded-md text-[10px] font-bold text-white shadow-lg tracking-wider uppercase">Auto</div>
                            </div>
                            <div class="px-1">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-purple-500 fill-purple-500/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    <h3 class="text-sm font-bold text-foreground truncate">{{ album.title }}</h3>
                                </div>
                                <p class="text-[11px] text-muted-foreground mt-0.5">{{ album.media_count }} items</p>
                            </div>
                        </Link>
                    </template>
                </div>
                <div class="border-b border-border my-6"></div>
            </div>

            <!-- Content View -->
            <div v-if="viewMode === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <template v-for="album in userAlbums" :key="album.id">
                    <div
                        class="group relative flex flex-col gap-2 cursor-pointer transition-all active:scale-95"
                        :class="showActionMenu === album.id ? 'z-50' : 'z-0'"
                        @click="router.visit(route('albums.show', album.path || album.slug || album.id))"
                    >
                        <!-- Unified card: cover + strip in one bordered container -->
                        <div class="relative rounded-2xl bg-bg-elevated border border-border overflow-visible transition-all shadow-sm group-hover:border-primary/50 group-hover:shadow-md">
                        <div class="aspect-video relative overflow-hidden bg-bg-elevated" :class="getBottomPreviewItems(album).length > 0 ? 'rounded-t-2xl' : 'rounded-2xl'">
                            <MediaRenderer
                                v-if="album.thumbnail_media"
                                :media="album.thumbnail_media"
                                :alt="album.title"
                                image-class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                video-class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                fallback-class="flex h-full w-full items-center justify-center bg-primary/5 text-xs font-bold uppercase tracking-[0.24em] text-primary/60"
                            />
                            <div v-else class="w-full h-full flex items-center justify-center text-primary/40 bg-primary/5">
                                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            </div>
                            <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>

                            <!-- Pin Button -->
                            <div class="absolute top-2 left-2">
                                <button @click="togglePin($event, album)" class="w-8 h-8 rounded-full flex items-center justify-center transition-all shadow-lg backdrop-blur-md"
                                        :class="pinnedAlbums.includes(album.id) ? 'bg-primary text-primary-foreground opacity-100' : 'bg-black/40 text-white opacity-0 group-hover:opacity-100 hover:bg-black/60'">
                                    <svg data-v-c3700575="" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pin w-4 h-4 fill-current"><path data-v-c3700575="" d="M12 17v5"></path><path data-v-c3700575="" d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"></path></svg>
                                </button>
                            </div>

                            <div v-if="album.location" class="absolute bottom-2 right-2 flex items-center gap-1 text-[10px] bg-black/50 text-white px-2 py-0.5 rounded-md backdrop-blur-md">
                                <svg class="w-3 h-3 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                {{ album.location }}
                            </div>
                        </div>

                        <!-- Action Menu Button -->
                        <div class="absolute top-2 right-2 z-30" @click.stop>
                            <button @click="toggleActionMenu($event, album.id)" class="w-8 h-8 rounded-full border border-white/20 bg-black/55 backdrop-blur-md text-white flex items-center justify-center opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/70 hover:border-white/40 shadow-lg">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                            </button>

                            <!-- Grid Item Context Menu -->
                            <div v-if="showActionMenu === album.id" class="absolute right-0 top-full mt-2 w-44 rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl z-[9999] animate-scale-in" @click.stop>
                                <button @click="handleAction('Download', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
                                </button>
                                <Link v-if="canManage || $page.props.auth.user.id === album.user_id" :href="route('albums.edit', album.slug || album.id)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> Rename
                                </Link>
                                <button @click="handleAction('Share', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg> Share
                                </button>
                                <button v-if="canManage || $page.props.auth.user.id === album.user_id" @click="handleAction('Delete', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-error transition-colors hover:bg-error/10">
                                    <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Remove
                                </button>
                            </div>
                        </div>

                        <!-- Preview strip: fixed-height row so all cards keep consistent size -->
                        <div v-if="getBottomPreviewItems(album).length > 0" class="flex h-24 gap-px bg-border overflow-hidden rounded-b-2xl">
                            <div
                                v-for="(pm, idx) in getBottomPreviewItems(album)"
                                :key="idx"
                                class="relative h-full min-w-0 flex-1 overflow-hidden bg-bg-elevated"
                            >
                                <MediaRenderer
                                    :media="pm"
                                    :alt="album.title"
                                    :fill="true"
                                    image-class="object-cover"
                                    video-class="object-cover"
                                    fallback-class="absolute inset-0 bg-primary/5"
                                />
                                <div
                                    v-if="isOverflowPreviewTile(album, idx)"
                                    class="absolute inset-0 z-10 bg-black/55 backdrop-blur-[1px] flex items-center justify-center"
                                >
                                    <span class="text-white text-sm font-bold tracking-wide">+{{ getHiddenPreviewCount(album) }}</span>
                                </div>
                            </div>
                        </div>
                        </div><!-- end unified card -->

                        <div class="px-1 relative">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-orange-500 fill-orange-500/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                <h3 class="text-sm font-bold text-foreground truncate">{{ album.title }}</h3>
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-0.5">{{ album.media_count }} items<template v-if="album.children_count">, {{ album.children_count }} folders</template></p>

                        </div>
                    </div>
                </template>
            </div>

            <div v-else class="bg-bg-card border border-border rounded-2xl overflow-visible">
                <div class="grid grid-cols-[1fr_120px_120px_150px_40px] items-center px-6 py-3 border-b border-border bg-bg-elevated/50">
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Name</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider text-center">Items</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Location</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Last Modified</span>
                    <span />
                </div>
                <div class="divide-y divide-border">
                    <template v-for="album in userAlbums" :key="album.id">
                        <div class="grid grid-cols-[1fr_120px_120px_150px_40px] items-center px-6 py-4 hover:bg-bg-hover transition-colors cursor-pointer group" @click="router.visit(route('albums.show', album.path || album.slug || album.id))">
                            <div class="flex items-center gap-4">
                                <button @click="togglePin($event, album)" class="p-1.5 rounded-full transition-all" :class="pinnedAlbums.includes(album.id) ? 'text-primary' : 'text-muted-foreground opacity-0 group-hover:opacity-100'">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pin w-4 h-4 fill-current"><path d="M12 17v5"></path><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"></path></svg>
                                </button>
                                <div class="w-10 h-10 rounded-lg bg-orange-500/10 flex items-center justify-center text-orange-500">
                                    <svg class="w-5 h-5 fill-orange-500/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                </div>
                                <span class="text-sm font-bold text-foreground">{{ album.title }}</span>
                            </div>
                            <span class="text-sm text-muted-foreground text-center">{{ album.media_count }}</span>
                            <span class="text-[11px] font-medium text-blue-500">{{ album.location || '-' }}</span>
                            <span class="text-sm text-muted-foreground">{{ new Date(album.created_at || Date.now()).toLocaleDateString() }}</span>
                            <div class="relative">
                                <button @click="toggleActionMenu($event, album.id)" class="p-2 rounded-full border border-border/80 bg-bg-card/90 text-foreground shadow-sm transition-all hover:bg-bg-elevated hover:border-primary/30 hover:text-foreground">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>

                                <div v-if="showActionMenu === album.id" class="absolute right-0 top-full mt-2 w-44 rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl z-50 animate-scale-in" @click.stop>
                                    <button @click="handleAction('Download', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                        <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
                                    </button>
                                    <Link v-if="canManage || $page.props.auth.user.id === album.user_id" :href="route('albums.edit', album.slug || album.id)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                        <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> Rename
                                    </Link>
                                    <button @click="handleAction('Share', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-bg-hover">
                                        <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg> Share
                                    </button>
                                    <button v-if="canManage || $page.props.auth.user.id === album.user_id" @click="handleAction('Delete', album)" class="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-error transition-colors hover:bg-error/10">
                                        <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div v-if="albums.length === 0" class="text-center py-20 animate-fade-in border border-dashed border-border rounded-xl bg-bg-card">
                <svg class="w-16 h-16 mx-auto text-muted-foreground mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                <p class="text-foreground font-bold text-lg">No albums found.</p>
                <p class="text-muted-foreground text-sm mt-1 mb-4">Create your first album to start managing assets.</p>
                <Link v-if="canCreateActions" :href="route('albums.create')" class="inline-flex items-center justify-center h-10 px-5 rounded-pill bg-primary text-primary-foreground font-bold text-sm shadow-sm transition-all hover:bg-accent-hover">
                    Create Album
                </Link>
            </div>

        </div>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false" max-width="sm" contained>
            <div class="p-6 bg-bg-card border border-border rounded-xl">
                <h2 class="text-lg font-bold text-foreground">Delete Album</h2>

                <p class="mt-2 text-sm text-muted-foreground">
                    Are you sure you want to delete <span class="font-bold text-foreground">"{{ albumToDelete?.title }}"</span>?
                    <span v-if="albumToDelete?.children_count > 0" class="text-error font-medium"> This will also delete {{ albumToDelete.children_count }} nested album(s) inside it.</span>
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="px-4 py-2 rounded-md text-sm font-bold text-foreground hover:bg-bg-hover transition-colors">Cancel</button>
                    <button @click="deleteAlbum" class="px-4 py-2 rounded-md text-sm font-bold bg-error text-white hover:bg-red-600 transition-colors shadow-sm">Delete</button>
                </div>
            </div>
        </Modal>

        <!-- Import ZIP Modal -->
        <Modal :show="showImportModal" @close="!importForm.processing && (showImportModal = false)" max-width="md" contained>
            <form @submit.prevent="submitImport" class="relative p-6 bg-bg-card border border-border rounded-xl overflow-hidden">

                <!-- Processing overlay -->
                <div v-if="importForm.processing" class="absolute inset-0 z-10 bg-bg-card/80 backdrop-blur-sm flex flex-col items-center justify-center gap-4 rounded-xl">
                    <svg class="animate-spin h-10 w-10 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <div class="text-center">
                        <p class="text-sm font-bold text-foreground">Importing ZIP…</p>
                        <p class="text-xs text-muted-foreground mt-1">Uploading and processing files. Please wait.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 mb-5">
                    <div class="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center text-primary shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    </div>
                    <h2 class="text-lg font-bold text-foreground">Import Albums from ZIP</h2>
                </div>

                <!-- Global error banner (server-side flash or catch-all) -->
                <div v-if="importForm.errors.zip_file" class="mb-4 flex items-start gap-2 px-4 py-3 rounded-xl bg-error/10 border border-error/20 text-error text-sm">
                    <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <span>{{ importForm.errors.zip_file }}</span>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">ZIP File</label>
                        <input
                            type="file"
                            accept=".zip"
                            @change="handleImportFiles"
                            required
                            :disabled="importForm.processing"
                            class="block w-full text-sm text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-colors disabled:opacity-50"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1">Accepted: <span class="font-semibold">.zip</span> — Max size: <span class="font-semibold">512 MB</span></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Default Location</label>
                        <select v-model="importForm.location" :disabled="importForm.processing" class="w-full h-10 rounded-xl border border-border bg-bg-input text-foreground text-sm px-3 focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary transition-all disabled:opacity-50">
                            <option value="">User's Default Location</option>
                            <option value="Ahmedabad">Ahmedabad</option>
                            <option value="Rajkot">Rajkot</option>
                        </select>
                        <div v-if="importForm.errors.location" class="text-error text-xs mt-1">{{ importForm.errors.location }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        @click="showImportModal = false"
                        :disabled="importForm.processing"
                        class="px-4 py-2 rounded-pill text-sm font-bold text-foreground hover:bg-bg-hover transition-colors disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="importForm.processing || !importForm.zip_file"
                        class="px-5 py-2 rounded-pill text-sm font-bold bg-primary text-primary-foreground hover:bg-primary/90 transition-all shadow-sm flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <svg v-if="importForm.processing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        {{ importForm.processing ? 'Importing…' : 'Import' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
