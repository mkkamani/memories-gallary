<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    album: Object,
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
</script>

<template>
    <Head :title="album.title" />

    <AuthenticatedLayout>
        <template #header>
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

                    <div v-if="!album.is_system">
                        <input type="file" multiple class="hidden" ref="fileInput" @change="handleUpload" accept="image/*,video/*" />
                        <button @click="triggerUpload" class="px-4 py-2 bg-brand-red hover:bg-brand-red-hover text-white rounded-md transition flex items-center gap-2" :disabled="uploadForm.processing">
                            <span v-if="uploadForm.processing">Uploading...</span>
                            <span v-else>Upload Media</span>
                        </button>
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
    </AuthenticatedLayout>
</template>
