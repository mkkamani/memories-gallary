<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    album: {
        type: Object,
        required: true,
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const fileInput = ref(null);
const selectedFiles = ref([]);
const processing = ref(false);
const dragActive = ref(false);
const clientErrors = ref([]);
const uploadProgress = ref(0);
const currentUploadIndex = ref(0);
const successfulUploads = ref(0);
const failedUploads = ref(0);

const acceptedZipMimes = [
    'application/zip',
    'application/x-zip-compressed',
    'application/octet-stream',
    'multipart/x-zip',
];

const imageExtensions = ['heic', 'heif'];
const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp'];

const serverErrors = computed(() => {
    const errors = page.props.errors || {};
    return Object.values(errors).filter(Boolean);
});

const flashAlerts = computed(() => {
    const flash = page.props.flash || {};
    const items = [];

    if (flash.error) items.push({ type: 'error', message: flash.error });
    if (flash.warning) items.push({ type: 'warning', message: flash.warning });
    if (flash.info) items.push({ type: 'info', message: flash.info });
    if (flash.success) items.push({ type: 'success', message: flash.success });

    return items;
});

const totalSelected = computed(() => selectedFiles.value.length);
const locationTitle = computed(() => props.album?.location || 'Rajkot');
const breadcrumbTrail = computed(() => [...props.breadcrumbs, props.album]);

const openFileDialog = () => {
    fileInput.value?.click();
};

const fileTypeMeta = (file) => {
    const mime = String(file.type || '');
    const ext = String(file.name.split('.').pop() || '').toLowerCase();
    const isVideo = mime.startsWith('video/') || videoExtensions.includes(ext);
    // ZIP: extension takes priority; only use MIME as fallback for non-video files
    const isZip = ext === 'zip' || (!isVideo && acceptedZipMimes.includes(mime));
    const isImage = mime.startsWith('image/') || imageExtensions.includes(ext);

    if (isZip) return { kind: 'zip', label: 'ZIP' };
    if (isImage) return { kind: 'image', label: 'Image' };
    if (isVideo) return { kind: 'video', label: 'Video' };

    return { kind: 'other', label: 'Other' };
};

const validateSelection = (files) => {
    const messages = [];

    if (!files || files.length === 0) {
        messages.push('Please choose at least one file.');
        return messages;
    }

    const zipFiles = [];
    const mediaFiles = [];

    for (const file of files) {
        const meta = fileTypeMeta(file);

        if (meta.kind === 'zip') {
            if (file.size > 512 * 1024 * 1024) {
                messages.push(`ZIP file '${file.name}' exceeds 512 MB.`);
            }
            zipFiles.push(file);
            continue;
        }

        if (meta.kind === 'image' || meta.kind === 'video') {
            if (file.size > 100 * 1024 * 1024) {
                messages.push(`Media file '${file.name}' exceeds 100 MB.`);
            }
            mediaFiles.push(file);
            continue;
        }

        messages.push(`Unsupported file '${file.name}'. Only images, videos, or ZIP files are allowed.`);
    }

    if (zipFiles.length > 1) {
        messages.push('Please upload only one ZIP file at a time.');
    }

    if (zipFiles.length > 0 && mediaFiles.length > 0) {
        messages.push('Upload either a ZIP file or media files in one request, not both together.');
    }

    return messages;
};

const setFiles = (rawList) => {
    const files = Array.from(rawList || []);
    clientErrors.value = validateSelection(files);
    selectedFiles.value = files;
};

const handleInputChange = (event) => {
    setFiles(event.target.files);
};

const onDrop = (event) => {
    dragActive.value = false;
    setFiles(event.dataTransfer?.files || []);
};

const submitUpload = async () => {
    clientErrors.value = validateSelection(selectedFiles.value);

    if (clientErrors.value.length > 0 || selectedFiles.value.length === 0) {
        return;
    }

    processing.value = true;
    uploadProgress.value = 0;
    successfulUploads.value = 0;
    failedUploads.value = 0;

    const totalFiles = selectedFiles.value.length;

    for (let i = 0; i < totalFiles; i++) {
        currentUploadIndex.value = i;
        const file = selectedFiles.value[i];
        const formData = new FormData();
        formData.append('files[]', file);

        try {
            await axios.post(route('albums.upload.store', props.album.path || props.album.slug || props.album.id), formData, {
                headers: {
                    'Accept': 'application/json'
                },
                onUploadProgress: (progressEvent) => {
                    const percentCompleted = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    // Overall progress relative to all files
                    uploadProgress.value = Math.floor(((i + (percentCompleted / 100)) / totalFiles) * 100);
                }
            });
            successfulUploads.value++;
        } catch (error) {
            console.error('Upload failed for file ', file.name, error);
            failedUploads.value++;
            const errMsg = error.response?.data?.message || `Failed to upload ${file.name}`;
            clientErrors.value.push(errMsg);
        }
    }

    uploadProgress.value = 100;

    // After uploading all
    if (failedUploads.value === 0) {
        // Redirect if everything was successful
        router.get(route('albums.show', props.album.path || props.album.slug || props.album.id));
    } else {
        processing.value = false;
        if (successfulUploads.value > 0) {
            clientErrors.value.push(`Successfully uploaded ${successfulUploads.value} files, but ${failedUploads.value} failed.`);
        }
    }
};

const removeSelectedFile = (index) => {
    selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index);
    clientErrors.value = validateSelection(selectedFiles.value);
};
</script>

<template>
    <Head :title="`Upload to ${album.title}`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-6">
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <Link :href="route('albums.show', album.path || album.slug || album.id)" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border bg-bg-card text-muted-foreground transition hover:text-foreground hover:border-primary/30">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <div>
                        <h1 class="text-4xl font-heading font-bold text-foreground">Upload to Album</h1>
                        <nav class="flex items-center gap-1 overflow-x-auto whitespace-nowrap text-sm text-muted-foreground">
                            <Link :href="route('albums.index')" class="hover:text-foreground transition-colors">Albums</Link>
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>

                            <Link :href="route('albums.index', { location: album.location || 'Rajkot' })" class="hover:text-foreground transition-colors">
                                {{ locationTitle }}
                            </Link>

                            <template v-for="(crumb, index) in breadcrumbTrail" :key="crumb.id || crumb.path || crumb.slug">
                                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>

                                <span
                                    v-if="index === breadcrumbTrail.length - 1"
                                    class="font-semibold text-foreground"
                                >
                                    {{ crumb.title }}
                                </span>

                                <Link
                                    v-else
                                    :href="route('albums.show', crumb.path || crumb.slug || crumb.id)"
                                    class="hover:text-foreground transition-colors"
                                >
                                    {{ crumb.title }}
                                </Link>
                            </template>
                        </nav>
                    </div>
                </div>
            </div>

            <section class="overflow-hidden rounded-2xl border border-border bg-bg-card shadow-sm">
                <div class="relative h-40 w-full overflow-hidden bg-bg-elevated">
                    <img v-if="album.thumbnail" :src="album.thumbnail" :alt="album.title" class="h-full w-full object-cover" />
                    <div v-else class="flex h-full w-full items-center justify-center bg-gradient-to-br from-bg-elevated to-bg-card text-muted-foreground">
                        <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                    </div>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/35 to-transparent"></div>
                    <div class="absolute inset-x-5 bottom-4 text-white">
                        <h2 class="text-3xl font-heading font-bold">{{ album.title }}</h2>
                        <p class="mt-1 text-sm text-white/80">By {{ album.user?.name || 'Unknown' }} • {{ album.user?.role || 'Member' }}</p>
                    </div>
                </div>

                <div class="space-y-4 p-6 sm:p-8">
                    <div v-for="alert in flashAlerts" :key="`${alert.type}-${alert.message}`" class="rounded-xl border px-4 py-3 text-sm"
                        :class="{
                            'border-error/30 bg-error/10 text-error': alert.type === 'error',
                            'border-amber-400/35 bg-amber-100/60 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300': alert.type === 'warning',
                            'border-info/30 bg-info/10 text-info': alert.type === 'info',
                            'border-success/30 bg-success/10 text-success': alert.type === 'success'
                        }"
                    >
                        {{ alert.message }}
                    </div>

                    <div v-for="(message, index) in [...serverErrors, ...clientErrors]" :key="`err-${index}-${message}`" class="rounded-xl border border-error/30 bg-error/10 px-4 py-3 text-sm text-error">
                        {{ message }}
                    </div>

                    <div
                        class="relative rounded-2xl border-2 border-dashed p-10 text-center transition"
                        :class="dragActive ? 'border-primary bg-primary/5' : 'border-border bg-bg-elevated/40 hover:border-primary/40 hover:bg-bg-elevated'"
                        @dragover.prevent="dragActive = true"
                        @dragleave.prevent="dragActive = false"
                        @drop.prevent="onDrop"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            class="hidden"
                            multiple
                            accept="image/*,video/*,.zip,.heic,.heif"
                            @change="handleInputChange"
                        />

                        <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>

                        <p class="text-2xl font-heading font-bold text-foreground">Drop files here or click to browse</p>
                        <p class="mt-2 text-sm text-muted-foreground">Upload images, videos, or a ZIP archive to this album</p>

                        <button
                            type="button"
                            class="mt-6 h-11 rounded-pill bg-gradient-to-r from-primary to-accent-hover px-7 text-sm font-bold text-primary-foreground shadow-md transition hover:shadow-primary/20"
                            @click="openFileDialog"
                        >
                            Choose Files
                        </button>
                    </div>

                    <div v-if="totalSelected > 0" class="space-y-2 rounded-xl border border-border bg-bg-elevated/40 p-4">
                        <p class="text-sm font-bold text-foreground">{{ totalSelected }} file(s) selected</p>
                        <div class="max-h-44 space-y-2 overflow-y-auto pr-1">
                            <div v-for="(file, index) in selectedFiles" :key="`${file.name}-${file.size}`" class="flex items-center justify-between rounded-lg border border-border bg-bg-card px-3 py-2 text-sm">
                                <span class="truncate pr-3 text-foreground">{{ file.name }}</span>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-pill bg-bg-elevated px-3 py-1 text-xs font-bold text-muted-foreground">{{ fileTypeMeta(file).label }}</span>
                                    <button
                                        type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-full text-muted-foreground transition hover:bg-error/10 hover:text-error"
                                        aria-label="Remove file"
                                        @click="removeSelectedFile(index)"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="processing" class="mt-4 mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-semibold text-foreground">
                                Uploading {{ currentUploadIndex + 1 }} of {{ totalSelected }}...
                            </span>
                            <span class="text-sm font-bold text-primary">{{ uploadProgress }}%</span>
                        </div>
                        <div class="w-full h-3 rounded-full bg-bg-elevated overflow-hidden border border-border">
                            <div class="h-full bg-gradient-to-r from-primary to-accent-hover transition-all duration-300 ease-out" :style="`width: ${uploadProgress}%`"></div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Link :href="route('albums.show', album.path || album.slug || album.id)" class="inline-flex h-11 items-center rounded-pill px-6 text-sm font-bold text-foreground transition hover:bg-bg-hover" :class="{ 'pointer-events-none opacity-50': processing }">Cancel</Link>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center rounded-pill bg-primary px-7 text-sm font-bold text-primary-foreground shadow-md transition hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="processing || totalSelected === 0"
                            @click="submitUpload"
                        >
                            {{ processing ? 'Uploading...' : 'Upload Files' }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
