<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';

const props = defineProps({
    media:  Array,
    albums: Array,
});

// ── Selection ─────────────────────────────────────────────────────────────────
const selectedMedia  = ref([]);
const selectedAlbums = ref([]);

const toggleMediaSelect = (id) => {
    const idx = selectedMedia.value.indexOf(id);
    if (idx === -1) selectedMedia.value.push(id);
    else            selectedMedia.value.splice(idx, 1);
};

const toggleAlbumSelect = (id) => {
    const idx = selectedAlbums.value.indexOf(id);
    if (idx === -1) selectedAlbums.value.push(id);
    else            selectedAlbums.value.splice(idx, 1);
};

const hasSelection = computed(() => selectedMedia.value.length > 0 || selectedAlbums.value.length > 0);

// ── Helpers ───────────────────────────────────────────────────────────────────
const formatDate = (val) => {
    if (!val) return '—';
    return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(val));
};

const formatSize = (bytes) => {
    if (!bytes) return '—';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

// ── Actions ───────────────────────────────────────────────────────────────────
const restoreMedia = (id) => {
    router.post(route('recycle-bin.restore-media', id), {}, { preserveScroll: true });
};

const forceDeleteMedia = (id) => {
    if (!confirm('Permanently delete this file? This cannot be undone and will remove it from R2 storage.')) return;
    router.delete(route('recycle-bin.force-delete-media', id), { preserveScroll: true });
};

const restoreAlbum = (id) => {
    router.post(route('recycle-bin.restore-album', id), {}, { preserveScroll: true });
};

const forceDeleteAlbum = (id) => {
    if (!confirm('Permanently delete this album and all its contents? This cannot be undone.')) return;
    router.delete(route('recycle-bin.force-delete-album', id), { preserveScroll: true });
};

// Days remaining before auto-purge (7-day retention)
const daysRemaining = (deletedAt) => {
    if (!deletedAt) return null;
    const purgeAt = new Date(deletedAt);
    purgeAt.setDate(purgeAt.getDate() + 7);
    const diff = Math.ceil((purgeAt - Date.now()) / 86400000);
    return diff > 0 ? diff : 0;
};
</script>

<template>
    <Head title="Recycle Bin" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-8">

            <!-- Page header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="font-heading font-bold text-3xl flex items-center gap-3">
                        <span class="w-10 h-10 rounded-2xl bg-error/10 flex items-center justify-center text-error">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </span>
                        Recycle Bin
                    </h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Items are automatically purged after <span class="font-semibold text-foreground">7 days</span>. Restore them before then to keep them.
                    </p>
                </div>

                <!-- Summary badges -->
                <div class="flex items-center gap-3 shrink-0">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-pill bg-bg-elevated border border-border text-xs font-bold text-muted-foreground">
                        <svg class="w-3.5 h-3.5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        {{ media.length }} file{{ media.length !== 1 ? 's' : '' }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-pill bg-bg-elevated border border-border text-xs font-bold text-muted-foreground">
                        <svg class="w-3.5 h-3.5 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                        {{ albums.length }} album{{ albums.length !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>

            <!-- Completely empty state -->
            <div v-if="media.length === 0 && albums.length === 0"
                 class="flex flex-col items-center justify-center py-24 bg-bg-card border border-dashed border-border rounded-3xl animate-fade-in">
                <div class="w-20 h-20 rounded-full bg-bg-elevated flex items-center justify-center text-muted-foreground mb-6">
                    <svg class="w-10 h-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-foreground">Recycle Bin is empty</h3>
                <p class="text-muted-foreground mt-2 text-sm text-center max-w-xs">
                    Deleted albums and media files will appear here before being permanently removed.
                </p>
            </div>

            <template v-else>

                <!-- ── Albums Section ──────────────────────────────────────── -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <svg class="w-5 h-5 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                            </svg>
                            Deleted Albums
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-bg-elevated border border-border text-muted-foreground">{{ albums.length }}</span>
                        </h2>
                    </div>

                    <!-- Albums empty -->
                    <div v-if="albums.length === 0"
                         class="flex items-center gap-4 px-6 py-5 rounded-2xl bg-bg-card border border-dashed border-border text-sm text-muted-foreground">
                        <svg class="w-8 h-8 opacity-30 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                        No deleted albums.
                    </div>

                    <!-- Albums list -->
                    <div v-else class="bg-bg-card border border-border rounded-2xl overflow-hidden shadow-sm">
                        <div class="divide-y divide-border">
                            <div v-for="album in albums" :key="album.id"
                                 class="flex items-center gap-4 px-5 py-4 hover:bg-bg-hover transition-colors group">

                                <!-- Icon -->
                                <div class="w-10 h-10 rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400 shrink-0">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                    </svg>
                                </div>

                                <!-- Details -->
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-foreground truncate">{{ album.title }}</p>
                                    <p class="text-[11px] text-muted-foreground mt-0.5">
                                        Deleted {{ formatDate(album.deleted_at) }}
                                        <span class="mx-1 opacity-40">·</span>
                                        by {{ album.user?.name || 'Unknown' }}
                                    </p>
                                </div>

                                <!-- Days remaining pill -->
                                <div class="hidden sm:block shrink-0">
                                    <span class="text-[10px] font-bold px-2 py-1 rounded-full"
                                          :class="daysRemaining(album.deleted_at) <= 2
                                              ? 'bg-error/10 text-error'
                                              : 'bg-bg-elevated border border-border text-muted-foreground'">
                                        {{ daysRemaining(album.deleted_at) }}d left
                                    </span>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-2 shrink-0">
                                    <button @click="restoreAlbum(album.id)"
                                            class="h-8 px-3 rounded-lg bg-green-500/10 border border-green-500/20 text-green-500 text-xs font-bold hover:bg-green-500/20 transition-colors flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        <span class="hidden sm:inline">Restore</span>
                                    </button>
                                    <button @click="forceDeleteAlbum(album.id)"
                                            class="h-8 px-3 rounded-lg bg-error/10 border border-error/20 text-error text-xs font-bold hover:bg-error/20 transition-colors flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        <span class="hidden sm:inline">Delete Forever</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Media Section ───────────────────────────────────────── -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            Deleted Files
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-bg-elevated border border-border text-muted-foreground">{{ media.length }}</span>
                        </h2>
                    </div>

                    <!-- Media empty -->
                    <div v-if="media.length === 0"
                         class="flex items-center gap-4 px-6 py-5 rounded-2xl bg-bg-card border border-dashed border-border text-sm text-muted-foreground">
                        <svg class="w-8 h-8 opacity-30 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        No deleted files.
                    </div>

                    <!-- Media grid -->
                    <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                        <div v-for="item in media" :key="item.id"
                             class="group relative rounded-2xl overflow-hidden bg-bg-card border border-border hover:border-primary/40 transition-all shadow-sm hover:shadow-lg">

                            <!-- Thumbnail -->
                            <div class="aspect-square relative overflow-hidden">
                                <MediaRenderer
                                    :media="item"
                                    :alt="item.file_name"
                                    image-class="w-full h-full object-cover opacity-60 group-hover:opacity-80 transition-opacity duration-300"
                                    video-class="w-full h-full object-cover opacity-60 group-hover:opacity-80 transition-opacity duration-300"
                                    fallback-class="flex h-full w-full items-center justify-center bg-bg-elevated text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground"
                                />

                                <!-- Overlay on hover -->
                                <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex flex-col items-center justify-end p-2 gap-1.5">
                                    <button @click="restoreMedia(item.id)"
                                            class="w-full h-8 rounded-lg bg-green-500/80 hover:bg-green-500 text-white text-[11px] font-bold transition-colors flex items-center justify-center gap-1.5 backdrop-blur-sm">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        Restore
                                    </button>
                                    <button @click="forceDeleteMedia(item.id)"
                                            class="w-full h-8 rounded-lg bg-error/80 hover:bg-error text-white text-[11px] font-bold transition-colors flex items-center justify-center gap-1.5 backdrop-blur-sm">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Delete Forever
                                    </button>
                                </div>

                                <!-- Days remaining badge -->
                                <div class="absolute top-2 left-2 z-10">
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-sm"
                                          :class="daysRemaining(item.deleted_at) <= 2
                                              ? 'bg-error/80 text-white'
                                              : 'bg-black/50 text-white/80'">
                                        {{ daysRemaining(item.deleted_at) }}d
                                    </span>
                                </div>

                                <!-- Video badge -->
                                <div v-if="item.file_type === 'video'" class="absolute top-2 right-2 z-10">
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-black/60 text-white/90 backdrop-blur-sm">
                                        VIDEO
                                    </span>
                                </div>
                            </div>

                            <!-- File info -->
                            <div class="px-2.5 py-2 border-t border-border bg-bg-card">
                                <p class="text-[11px] font-semibold text-foreground truncate">{{ item.file_name }}</p>
                                <div class="flex items-center justify-between mt-0.5">
                                    <span class="text-[10px] text-muted-foreground">{{ formatSize(item.file_size) }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ formatDate(item.deleted_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </template>

        </div>
    </AuthenticatedLayout>
</template>
