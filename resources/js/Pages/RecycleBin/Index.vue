<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatFileSize } from '@/utils/media';
import { formatNumber } from '@/utils/number';

const props = defineProps({
    media: Array,
    albums: Array,
});

const mediaItems = ref([...(props.media || [])]);
const albumItems = ref([...(props.albums || [])]);
const showCardMenu = ref(null);
const pendingAction = ref({
    show: false,
    item: null,
    itemType: 'media',
    actionType: 'restore',
});

const formatDate = (value) => {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
};

const daysRemaining = (deletedAt) => {
    if (!deletedAt) return 0;

    const purgeAt = new Date(deletedAt);
    purgeAt.setDate(purgeAt.getDate() + 7);

    return Math.max(0, Math.ceil((purgeAt.getTime() - Date.now()) / 86400000));
};

const retentionLabel = (deletedAt) => {
    const days = daysRemaining(deletedAt);

    if (days <= 0) return 'Purges today';
    if (days === 1) return '1 day left';
    return `${days} days left`;
};

const totalBytes = computed(() => mediaItems.value.reduce((sum, item) => sum + (Number(item.file_size) || 0), 0));
const urgentItems = computed(() => {
    return mediaItems.value.filter(item => daysRemaining(item.deleted_at) <= 2).length
        + albumItems.value.filter(item => daysRemaining(item.deleted_at) <= 2).length;
});
const nextPurgeIn = computed(() => {
    const values = [...mediaItems.value, ...albumItems.value]
        .map(item => daysRemaining(item.deleted_at))
        .filter(value => value !== null && value !== undefined);

    if (!values.length) return null;

    return Math.min(...values);
});

const stats = computed(() => [
    {
        label: 'Deleted Files',
        value: formatNumber(mediaItems.value.length),
        accent: 'from-primary/20 via-primary/10 to-transparent',
        text: 'text-primary',
        icon: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
        meta: 'Media waiting to be restored or purged',
    },
    {
        label: 'Deleted Albums',
        value: formatNumber(albumItems.value.length),
        accent: 'from-orange-500/20 via-orange-500/10 to-transparent',
        text: 'text-orange-500',
        icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
        meta: 'Album shells retained for 7 days',
    },
    {
        label: 'Recoverable Storage',
        value: formatFileSize(totalBytes.value),
        accent: 'from-emerald-500/20 via-emerald-500/10 to-transparent',
        text: 'text-emerald-500',
        icon: 'M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2',
        meta: 'Restoring keeps these assets available instantly',
    },
    {
        label: 'Urgent Review',
        value: formatNumber(urgentItems.value),
        accent: 'from-error/20 via-error/10 to-transparent',
        text: 'text-error',
        icon: 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        meta: nextPurgeIn.value === null ? 'Nothing scheduled' : `Next purge in ${nextPurgeIn.value} day${nextPurgeIn.value === 1 ? '' : 's'}`,
    },
]);

const hasContent = computed(() => mediaItems.value.length > 0 || albumItems.value.length > 0);

const toggleCardMenu = (e, id) => {
    e.preventDefault();
    e.stopPropagation();
    showCardMenu.value = showCardMenu.value === id ? null : id;
};

const closeCardMenu = () => {
    showCardMenu.value = null;
};

const openActionModal = (item, itemType, actionType) => {
    showCardMenu.value = null;
    pendingAction.value = {
        show: true,
        item,
        itemType,
        actionType,
    };
};

const closeActionModal = () => {
    pendingAction.value = {
        show: false,
        item: null,
        itemType: 'media',
        actionType: 'restore',
    };
};

const removeLocalItem = (itemType, id) => {
    if (itemType === 'media') {
        mediaItems.value = mediaItems.value.filter(item => item.id !== id);
        return;
    }

    albumItems.value = albumItems.value.filter(item => item.id !== id);
};

const executeAction = () => {
    const { item, itemType, actionType } = pendingAction.value;

    if (!item) return;

    const isMedia = itemType === 'media';
    const routeName = actionType === 'restore'
        ? (isMedia ? 'recycle-bin.restore-media' : 'recycle-bin.restore-album')
        : (isMedia ? 'recycle-bin.force-delete-media' : 'recycle-bin.force-delete-album');

    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            removeLocalItem(itemType, item.id);
            showCardMenu.value = null;
            closeActionModal();
        },
        onError: () => {
            showCardMenu.value = null;
            closeActionModal();
        },
    };

    if (actionType === 'restore') {
        router.post(route(routeName, item.id), {}, requestOptions);
        return;
    }

    router.delete(route(routeName, item.id), requestOptions);
};

const modalTitle = computed(() => {
    return pendingAction.value.actionType === 'restore' ? 'Restore Item' : 'Delete Forever';
});

const modalDescription = computed(() => {
    const item = pendingAction.value.item;

    if (!item) return '';

    const name = item.title || item.file_name || 'this item';

    if (pendingAction.value.actionType === 'restore') {
        return `Restore "${name}" back into the main gallery.`;
    }

    return `Permanently remove "${name}" from the recycle bin and storage. This cannot be undone.`;
});
</script>

<template>
    <Head title="Recycle Bin" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-8 pb-6" @click="closeCardMenu">
            <section class="relative overflow-hidden rounded-[2rem] border border-border bg-bg-card shadow-sm">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_right,rgba(255,96,43,0.08),transparent_28%),linear-gradient(135deg,rgba(255,255,255,0.88),rgba(255,248,244,0.96))] dark:bg-[radial-gradient(circle_at_right,rgba(255,96,43,0.18),transparent_32%),linear-gradient(135deg,rgba(17,24,39,0.9),rgba(10,12,24,0.96))]"></div>
                <div class="absolute -right-50 -top-16 h-52 w-52 rounded-full bg-primary/10 blur-3xl"></div>
                <div class="absolute left-10 bottom-0 h-24 w-24 rounded-full bg-orange-500/10 blur-2xl"></div>

                <div class="relative space-y-6 px-6 py-7 lg:px-8">
                    <div class="space-y-5">
                        <div class="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-bg-card/70 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.24em] text-primary shadow-sm backdrop-blur-sm">
                            Recovery Workspace
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center gap-4">
                                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-error/15 via-primary/10 to-orange-500/15 text-error shadow-inner">
                                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </div>
                                <div>
                                    <h1 class="font-heading text-3xl font-bold tracking-tight text-foreground sm:text-4xl">Recycle Bin</h1>
                                    <p class="mt-1 text-sm text-muted-foreground">Recover assets before the retention window expires, or purge them permanently.</p>
                                </div>
                            </div>

                            <p class="max-w-2xl text-sm leading-6 text-muted-foreground">
                                This screen follows the main gallery system: image-first cards, compact metadata, and quick actions. Everything here is kept for <b>7 days</b> before automatic purge.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article
                            v-for="card in stats"
                            :key="card.label"
                            class="relative overflow-hidden rounded-3xl border border-border/80 bg-bg-card/80 p-4 shadow-sm backdrop-blur-sm"
                        >
                            <div class="absolute inset-0 bg-gradient-to-br" :class="card.accent"></div>
                            <div class="relative flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-muted-foreground">{{ card.label }}</p>
                                    <p class="mt-3 text-3xl font-bold text-foreground">{{ card.value }}</p>
                                    <p class="mt-2 text-xs leading-5 text-muted-foreground">{{ card.meta }}</p>
                                </div>
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl border border-border/70 bg-bg-card/80 shadow-sm" :class="card.text">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="card.icon" />
                                    </svg>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section
                v-if="!hasContent"
                class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-border bg-bg-card px-6 py-24 text-center shadow-sm"
            >
                <div class="flex h-20 w-20 items-center justify-center rounded-full bg-bg-elevated text-muted-foreground shadow-inner">
                    <svg class="h-10 w-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h2 class="mt-6 text-2xl font-bold text-foreground">Nothing is waiting here</h2>
                <p class="mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                    Deleted albums and files will appear here in the same branded gallery format, ready for restore or permanent removal.
                </p>
            </section>

            <template v-else>
                <section class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-foreground">Deleted Albums</h2>
                            <p class="mt-1 text-sm text-muted-foreground">Folder structures and collections currently inside the retention window.</p>
                        </div>
                        <span class="inline-flex items-center gap-2 rounded-full border border-border bg-bg-elevated px-3 py-1.5 text-xs font-bold text-muted-foreground">
                            <span class="h-2 w-2 rounded-full bg-orange-500"></span>
                            {{ formatNumber(albumItems.length) }} album{{ albumItems.length === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div v-if="albumItems.length === 0" class="flex items-center gap-3 rounded-[1.75rem] border border-dashed border-border bg-bg-card px-6 py-8 text-sm text-muted-foreground shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-bg-elevated text-muted-foreground">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                            </svg>
                        </div>
                        <span>No deleted albums or folders.</span>
                    </div>

                    <div v-else class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">
                        <article
                            v-for="album in albumItems"
                            :key="album.id"
                            class="group relative overflow-hidden rounded-2xl border border-border bg-bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-lg"
                        >
                            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-orange-400 via-primary to-orange-300 opacity-80"></div>

                            <div class="space-y-3 pt-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-500/10 text-orange-500 shadow-inner">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                                            </svg>
                                        </div>

                                        <div class="min-w-0">
                                            <h3 class="truncate text-sm font-bold text-foreground">{{ album.title }}</h3>
                                            <p class="mt-0.5 text-[10px] uppercase tracking-[0.18em] text-muted-foreground">Deleted album</p>
                                        </div>
                                    </div>

                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold"
                                        :class="daysRemaining(album.deleted_at) <= 2 ? 'bg-error/10 text-error' : 'border border-border bg-bg-elevated text-muted-foreground'"
                                    >
                                        {{ daysRemaining(album.deleted_at) }}d
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-2xl border border-border/80 bg-bg-elevated/60 px-3 py-2">
                                        <p class="font-bold uppercase tracking-[0.18em] text-muted-foreground">Deleted</p>
                                        <p class="mt-1 font-medium text-foreground">{{ formatDate(album.deleted_at) }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-border/80 bg-bg-elevated/60 px-3 py-2">
                                        <p class="font-bold uppercase tracking-[0.18em] text-muted-foreground">Owner</p>
                                        <p class="mt-1 font-medium text-foreground">{{ album.user?.name || 'Unknown' }}</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-1.5 pt-0.5">
                                    <button
                                        @click="openActionModal(album, 'album', 'restore')"
                                        class="inline-flex h-8 items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400 px-2 text-[11px] font-bold text-white shadow-md shadow-emerald-500/20 transition-all hover:-translate-y-0.5"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                        </svg>
                                        Restore
                                    </button>
                                    <button
                                        @click="openActionModal(album, 'album', 'delete')"
                                        class="inline-flex h-8 items-center justify-center gap-1.5 rounded-full border border-error/20 bg-error/10 px-2 text-[11px] font-bold text-error transition-all hover:bg-error/15"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-foreground">Deleted Files</h2>
                            <p class="mt-1 text-sm text-muted-foreground">Image-first recovery grid using the same gallery language as the main album surfaces.</p>
                        </div>
                        <span class="inline-flex items-center gap-2 rounded-full border border-border bg-bg-elevated px-3 py-1.5 text-xs font-bold text-muted-foreground">
                            <span class="h-2 w-2 rounded-full bg-primary"></span>
                            {{ formatNumber(mediaItems.length) }} file{{ mediaItems.length === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div v-if="mediaItems.length === 0" class="flex items-center gap-3 rounded-[1.75rem] border border-dashed border-border bg-bg-card px-6 py-8 text-sm text-muted-foreground shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-bg-elevated text-muted-foreground">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <span>No deleted files.</span>
                    </div>

                    <div v-else class="columns-2 sm:columns-3 lg:columns-4 xl:columns-5 [column-gap:1rem]">
                        <article
                            v-for="item in mediaItems"
                            :key="item.id"
                            class="group relative mb-4 inline-block w-full break-inside-avoid overflow-hidden rounded-2xl border border-border bg-bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-xl"
                        >
                            <div class="absolute left-3 top-3 z-10 flex items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-bold backdrop-blur-sm"
                                    :class="daysRemaining(item.deleted_at) <= 2 ? 'bg-error text-white' : 'bg-black/60 text-white/90'"
                                >
                                    {{ retentionLabel(item.deleted_at) }}
                                </span>
                                <span v-if="item.file_type === 'video'" class="rounded-full border border-border/60 bg-bg-card/85 px-2.5 py-1 text-[10px] font-bold text-foreground shadow-sm">
                                    Video
                                </span>
                            </div>

                            <div class="absolute right-3 top-3 z-20" @click.stop>
                                <button
                                    @click="toggleCardMenu($event, item.id)"
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-white shadow-sm backdrop-blur-sm transition-colors hover:bg-black/70"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5h.01M12 12h.01M12 19h.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                    </svg>
                                </button>

                                <div
                                    v-if="showCardMenu === item.id"
                                    class="absolute right-0 top-11 w-44 overflow-hidden rounded-2xl border border-border/80 bg-bg-card/95 p-1.5 shadow-2xl backdrop-blur-xl"
                                >
                                    <button
                                        @click="openActionModal(item, 'media', 'restore')"
                                        class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold text-emerald-600 transition-colors hover:bg-emerald-500/10"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                        </svg>
                                        Restore
                                    </button>
                                    <button
                                        @click="openActionModal(item, 'media', 'delete')"
                                        class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold text-error transition-colors hover:bg-error/10"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <div class="relative overflow-hidden bg-bg-elevated">
                                <MediaRenderer
                                    :media="item"
                                    :alt="item.file_name"
                                    :use-thumbnail="true"
                                    image-class="w-full h-auto block object-cover grayscale saturate-0 opacity-75 transition-all duration-500 group-hover:grayscale-0 group-hover:saturate-100 group-hover:opacity-100"
                                    video-class="w-full h-auto block object-cover grayscale saturate-0 opacity-75 transition-all duration-500 group-hover:grayscale-0 group-hover:saturate-100 group-hover:opacity-100"
                                    fallback-class="flex min-h-[180px] w-full items-center justify-center bg-bg-elevated text-[10px] font-bold uppercase tracking-[0.22em] text-muted-foreground"
                                />

                                <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-transparent transition-opacity duration-300 group-hover:opacity-60"></div>

                                <div class="absolute bottom-0 inset-x-0 px-3 py-2">
                                    <p class="truncate text-[11px] font-bold text-white/95">{{ item.file_name }}</p>
                                    <p class="truncate text-[10px] text-white/70">{{ item.user?.name || 'Unknown owner' }}</p>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </template>
        </div>

        <Modal :show="pendingAction.show" @close="closeActionModal" max-width="sm" contained>
            <div class="rounded-3xl border border-border bg-bg-card p-6 shadow-xl">
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-2xl"
                        :class="pendingAction.actionType === 'restore' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-error/10 text-error'"
                    >
                        <svg v-if="pendingAction.actionType === 'restore'" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                        </svg>
                        <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <h3 class="text-lg font-bold text-foreground">{{ modalTitle }}</h3>
                        <p class="mt-2 text-sm leading-6 text-muted-foreground">{{ modalDescription }}</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        @click="closeActionModal"
                        class="inline-flex h-11 items-center rounded-full border border-border px-5 text-sm font-bold text-foreground transition-colors hover:bg-bg-hover"
                    >
                        Cancel
                    </button>
                    <button
                        @click="executeAction"
                        class="inline-flex h-11 items-center rounded-full px-5 text-sm font-bold text-white shadow-lg transition-all"
                        :class="pendingAction.actionType === 'restore' ? 'bg-gradient-to-r from-emerald-500 to-emerald-400 shadow-emerald-500/20' : 'bg-gradient-to-r from-error to-red-500 shadow-error/20'"
                    >
                        {{ pendingAction.actionType === 'restore' ? 'Restore Now' : 'Delete Permanently' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
