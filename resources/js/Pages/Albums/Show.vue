<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    album: Object,
    breadcrumbs: Array,
});

const fileInput = ref(null);
const uploadForm = useForm({
    files: [],
    album_id: props.album.id,
});

const selectedMedia = ref([]);
const showPreviewModal = ref(false);
const previewMedia = ref(null);
const currentIndex = ref(0);

// Child album creation
const showCreateAlbumModal = ref(false);
const createAlbumForm = useForm({
    title: '',
    description: '',
    is_public: false,
    parent_id: props.album.id,
});

// Child album deletion
const showDeleteAlbumModal = ref(false);
const albumToDelete = ref(null);

const toggleSelection = (id) => {
    if (selectedMedia.value.includes(id)) {
        selectedMedia.value = selectedMedia.value.filter(item => item !== id);
    } else {
        selectedMedia.value.push(id);
    }
};

const selectAll = () => {
    if (selectedMedia.value.length === props.album.media.length) {
        selectedMedia.value = [];
    } else {
        selectedMedia.value = props.album.media.map(media => media.id);
    }
};

const bulkDelete = () => {
    if (confirm('Are you sure you want to delete selected items?')) {
        router.post(route('media.bulk-delete'), { ids: selectedMedia.value }, {
            onSuccess: () => selectedMedia.value = [],
        });
    }
};

const bulkDownload = () => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('media.bulk-download');
    
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = token;
    form.appendChild(csrfInput);

    selectedMedia.value.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
};

const triggerUpload = () => {
    fileInput.value.click();
};

const handleUpload = (e) => {
    const files = Array.from(e.target.files);
    if (files.length === 0) return;

    uploadForm.files = files;
    uploadForm.post(route('media.store'), {
        onSuccess: () => {
            uploadForm.reset();
            e.target.value = null;
        },
        preserveScroll: true,
    });
};

const openPreview = (media) => {
    const index = props.album.media.findIndex(m => m.id === media.id);
    currentIndex.value = index;
    previewMedia.value = media;
    showPreviewModal.value = true;
};

const closePreview = () => {
    showPreviewModal.value = false;
    previewMedia.value = null;
};

const goToNext = () => {
    if (currentIndex.value < props.album.media.length - 1) {
        currentIndex.value++;
        previewMedia.value = props.album.media[currentIndex.value];
    }
};

const goToPrevious = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
        previewMedia.value = props.album.media[currentIndex.value];
    }
};

const downloadMedia = () => {
    if (previewMedia.value) {
        const link = document.createElement('a');
        link.href = '/storage/' + previewMedia.value.file_path;
        link.download = previewMedia.value.file_name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
};

// Child Album Functions
const openCreateAlbumModal = () => {
    showCreateAlbumModal.value = true;
};

const closeCreateAlbumModal = () => {
    showCreateAlbumModal.value = false;
    createAlbumForm.reset();
};

const createChildAlbum = () => {
    createAlbumForm.post(route('albums.store'), {
        onSuccess: () => {
            closeCreateAlbumModal();
        },
        preserveScroll: true,
    });
};

const confirmDeleteAlbum = (album) => {
    albumToDelete.value = album;
    showDeleteAlbumModal.value = true;
};

const deleteChildAlbum = () => {
    if (albumToDelete.value) {
        router.delete(route('albums.destroy', albumToDelete.value.id), {
            onSuccess: () => {
                showDeleteAlbumModal.value = false;
                albumToDelete.value = null;
            },
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="album.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4">
                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2 text-sm text-gray-400">
                    <Link :href="route('albums.index')" class="hover:text-brand-red transition-colors">
                        Home
                    </Link>
                    <template v-if="breadcrumbs && breadcrumbs.length > 0">
                        <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                            <Link :href="route('albums.show', crumb.id)" class="hover:text-brand-red transition-colors">
                                {{ crumb.title }}
                            </Link>
                        </template>
                    </template>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <span class="text-white">{{ album.title }}</span>
                </div>

                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ album.title }}</h2>
                        <p class="text-sm text-gray-400 mt-1">{{ album.description }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div v-if="selectedMedia.length > 0" class="flex items-center gap-2 mr-4">
                            <span class="text-white text-sm">{{ selectedMedia.length }} selected</span>
                            <button @click="bulkDownload" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded transition">Download</button>
                            <button @click="bulkDelete" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-sm rounded transition">Delete</button>
                        </div>

                        <div v-if="!album.is_system" class="flex items-center gap-2">
                            <input type="file" multiple class="hidden" ref="fileInput" @change="handleUpload" accept="image/*,video/*" />
                            <button @click="openCreateAlbumModal" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-md transition flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                <span>Create Album</span>
                            </button>
                            <button @click="triggerUpload" class="px-4 py-2 bg-brand-red hover:bg-brand-red-hover text-white rounded-md transition flex items-center gap-2" :disabled="uploadForm.processing">
                                <span v-if="uploadForm.processing">Uploading...</span>
                                <span v-else>Upload Media</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="py-12 animate-fade-in">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Progress Bar -->
                <div v-if="uploadForm.progress" class="w-full bg-gray-800 rounded-full h-3 mb-8 overflow-hidden shadow-lg animate-slide-up">
                    <div class="bg-gradient-to-r from-brand-red to-brand-red-hover h-3 rounded-full transition-all duration-300 flex items-center justify-end pr-2" 
                        :style="{ width: uploadForm.progress.percentage + '%' }">
                        <span class="text-xs font-bold text-white">{{ uploadForm.progress.percentage }}%</span>
                    </div>
                </div>
                
                <!-- Child Albums Section -->
                <div v-if="album.children && album.children.length > 0" class="mb-8">
                    <h3 class="text-lg font-semibold text-gray-200 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                        </svg>
                        Sub-Albums ({{ album.children.length }})
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-8">
                        <div v-for="(child, index) in album.children" :key="child.id" 
                            class="gallery-card animate-slide-up"
                            :class="'stagger-' + ((index % 4) + 1)">
                            <div class="block h-full relative group">
                                <Link :href="route('albums.show', child.id)" class="block">
                                    <div class="aspect-video bg-gradient-to-br from-purple-900 to-purple-700 relative overflow-hidden">
                                        <img v-if="child.thumbnail" :src="child.thumbnail" 
                                            class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" 
                                            :alt="child.title" />
                                        <div v-else class="w-full h-full flex items-center justify-center">
                                            <svg class="w-20 h-20 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                            </svg>
                                        </div>
                                        <div class="gallery-card-overlay">
                                            <div class="absolute bottom-0 left-0 right-0 p-4">
                                                <div class="flex items-center justify-between text-white">
                                                    <span class="text-sm font-medium bg-purple-600 px-3 py-1 rounded-full uppercase tracking-wide">
                                                        Album
                                                    </span>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm bg-black/50 px-3 py-1 rounded-full">
                                                            {{ child.media_count || 0 }} items
                                                        </span>
                                                        <span v-if="child.children_count > 0" class="text-sm bg-purple-600 px-3 py-1 rounded-full">
                                                            {{ child.children_count }} sub-albums
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-5 bg-gradient-to-b from-brand-dark to-brand-gray">
                                        <h3 class="text-lg font-bold text-white group-hover:text-purple-400 transition-colors duration-300 line-clamp-1">
                                            {{ child.title }}
                                        </h3>
                                        <p v-if="child.description" class="text-sm text-gray-400 mt-2 line-clamp-2">{{ child.description }}</p>
                                        <div class="flex items-center justify-between mt-3">
                                            <p v-if="child.event_date" class="text-xs text-gray-500 flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                                {{ new Date(child.event_date).toLocaleDateString() }}
                                            </p>
                                            <span v-if="child.is_public" class="text-xs text-green-400 flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Public
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                                
                                <!-- Action Buttons -->
                                <div class="absolute top-2 right-2 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                                    <Link :href="route('albums.edit', child.id)" 
                                        class="p-2 bg-blue-600 hover:bg-blue-700 text-white rounded-full transition-colors duration-200">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </Link>
                                    <button @click.prevent="confirmDeleteAlbum(child)" 
                                        class="p-2 bg-red-600 hover:bg-red-700 text-white rounded-full transition-colors duration-200">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-6 flex items-center justify-between" v-if="album.media.length > 0">
                    <button @click="selectAll" class="text-sm font-medium text-brand-red hover:text-white transition-colors duration-300 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        {{ selectedMedia.length === album.media.length ? 'Deselect All' : 'Select All' }}
                    </button>
                    <p class="text-sm text-gray-400">{{ album.media.length }} items</p>
                </div>

                <!-- Media Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                    <div v-for="(media, index) in album.media" :key="media.id" 
                        class="gallery-card aspect-square cursor-pointer animate-scale-in"
                        :class="[
                            selectedMedia.includes(media.id) ? 'ring-4 ring-brand-red' : '',
                            'stagger-' + ((index % 4) + 1)
                        ]"
                        @click="toggleSelection(media.id)">
                        <div class="relative w-full h-full group">
                            <img v-if="media.file_type === 'image'" 
                                :src="'/storage/' + media.file_path" 
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" 
                                :alt="media.file_name" />
                            <video v-else 
                                :src="'/storage/' + media.file_path" 
                                class="w-full h-full object-cover"
                                muted></video>
                            
                            <div v-if="media.file_type === 'video'" class="absolute top-3 right-3 bg-black/70 rounded-full p-2 shadow-lg">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path>
                                </svg>
                            </div>
                            
                            <div class="absolute top-3 left-3 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                <div class="bg-white/90 rounded-full p-1.5 shadow-lg">
                                    <input type="checkbox" 
                                        :checked="selectedMedia.includes(media.id)" 
                                        class="w-5 h-5 rounded border-2 border-gray-400 text-brand-red focus:ring-brand-red focus:ring-2" 
                                        @click.stop />
                                </div>
                            </div>

                            <div class="gallery-card-overlay pointer-events-none">
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <button @click.stop="openPreview(media)" 
                                        class="pointer-events-auto px-4 py-2 bg-white/90 hover:bg-white text-brand-black font-semibold rounded-lg shadow-xl transition-all duration-300 hover:scale-110 flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View Full
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="album.media.length === 0" class="text-center py-20 animate-fade-in">
                    <svg class="w-32 h-32 mx-auto text-gray-700 mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-gray-400 text-xl font-medium mb-2">No media in this album yet</p>
                    <p class="text-gray-500 mb-6">Upload some photos or videos to get started!</p>
                    <button @click="triggerUpload" class="btn-primary inline-flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        Upload Media
                    </button>
                </div>
            </div>
        </div>

        <!-- Media Preview Modal -->
        <Modal :show="showPreviewModal" @close="closePreview" max-width="6xl">
            <div class="bg-black p-4 relative">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-white">{{ previewMedia?.file_name }}</h3>
                    <div class="flex items-center gap-2">
                        <button @click="downloadMedia" class="p-2 text-gray-400 hover:text-white transition" title="Download">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                        </button>
                        <button @click="closePreview" class="p-2 text-gray-400 hover:text-white transition" title="Close">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="relative flex items-center justify-center bg-gray-900 rounded-lg overflow-hidden" style="max-height: 80vh;">
                    <!-- Previous Button -->
                    <button 
                        v-if="currentIndex > 0"
                        @click="goToPrevious" 
                        class="absolute left-4 z-10 p-3 bg-black/50 hover:bg-black/70 text-white rounded-full transition-all duration-200 hover:scale-110"
                        title="Previous">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>

                    <!-- Media Content -->
                    <img v-if="previewMedia?.file_type === 'image'" 
                        :src="'/storage/' + previewMedia?.file_path" 
                        :alt="previewMedia?.file_name"
                        class="max-w-full max-h-[80vh] object-contain" />
                    
                    <video v-else-if="previewMedia?.file_type === 'video'" 
                        :src="'/storage/' + previewMedia?.file_path" 
                        controls 
                        autoplay
                        class="max-w-full max-h-[80vh]">
                    </video>

                    <!-- Next Button -->
                    <button 
                        v-if="currentIndex < album.media.length - 1"
                        @click="goToNext" 
                        class="absolute right-4 z-10 p-3 bg-black/50 hover:bg-black/70 text-white rounded-full transition-all duration-200 hover:scale-110"
                        title="Next">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>

                <div class="mt-4 flex justify-between items-center text-sm text-gray-400">
                    <span>{{ album.title }}</span>
                    <span>{{ currentIndex + 1 }} / {{ album.media.length }}</span>
                    <span>{{ previewMedia?.created_at ? new Date(previewMedia.created_at).toLocaleDateString() : '' }}</span>
                </div>
            </div>
        </Modal>

        <!-- Create Child Album Modal -->
        <Modal :show="showCreateAlbumModal" @close="closeCreateAlbumModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    Create Sub-Album in "{{ album.title }}"
                </h2>

                <form @submit.prevent="createChildAlbum">
                    <div class="mb-4">
                        <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Album Title
                        </label>
                        <input 
                            type="text" 
                            id="title"
                            v-model="createAlbumForm.title"
                            class="w-full bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red"
                            required
                            autofocus
                        />
                        <div v-if="createAlbumForm.errors.title" class="text-red-500 text-sm mt-1">
                            {{ createAlbumForm.errors.title }}
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Description (Optional)
                        </label>
                        <textarea 
                            id="description"
                            v-model="createAlbumForm.description"
                            rows="3"
                            class="w-full bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red"
                        ></textarea>
                        <div v-if="createAlbumForm.errors.description" class="text-red-500 text-sm mt-1">
                            {{ createAlbumForm.errors.description }}
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                v-model="createAlbumForm.is_public"
                                class="rounded border-gray-300 text-brand-red shadow-sm focus:ring-brand-red"
                            />
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Make this album public</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3">
                        <SecondaryButton @click="closeCreateAlbumModal" type="button">
                            Cancel
                        </SecondaryButton>
                        <button 
                            type="submit"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-md transition"
                            :disabled="createAlbumForm.processing"
                        >
                            <span v-if="createAlbumForm.processing">Creating...</span>
                            <span v-else>Create Album</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Delete Album Confirmation Modal -->
        <Modal :show="showDeleteAlbumModal" @close="showDeleteAlbumModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Delete Album
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Are you sure you want to delete "{{ albumToDelete?.title }}"?
                    <span v-if="albumToDelete?.children_count > 0" class="font-semibold text-brand-red">
                        This will also delete {{ albumToDelete.children_count }} nested album(s) inside it.
                    </span>
                    This will move the album to the recycle bin.
                </p>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="showDeleteAlbumModal = false">Cancel</SecondaryButton>
                    <DangerButton class="ms-3" @click="deleteChildAlbum">Delete Album</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
