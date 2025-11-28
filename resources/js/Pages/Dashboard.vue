<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    media: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');
const showPreviewModal = ref(false);
const previewMedia = ref(null);
const currentIndex = ref(0);

watch(search, debounce((value) => {
    router.get(route('dashboard'), { search: value }, { preserveState: true, replace: true });
}, 300));

const openPreview = (media) => {
    const index = props.media.data.findIndex(m => m.id === media.id);
    currentIndex.value = index;
    previewMedia.value = media;
    showPreviewModal.value = true;
};

const closePreview = () => {
    showPreviewModal.value = false;
    previewMedia.value = null;
};

const goToNext = () => {
    if (currentIndex.value < props.media.data.length - 1) {
        currentIndex.value++;
        previewMedia.value = props.media.data[currentIndex.value];
    }
};

const goToPrevious = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
        previewMedia.value = props.media.data[currentIndex.value];
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
    <Head title="Timeline" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Timeline</h2>
                <input v-model="search" type="text" placeholder="Search memories..." class="bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red w-64" />
            </div>
        </template>

        <div class="py-12 animate-fade-in">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                    <div v-for="(item, index) in media.data" :key="item.id" 
                        class="gallery-card aspect-square animate-scale-in"
                        :class="'stagger-' + ((index % 4) + 1)">
                        <div class="relative w-full h-full group">
                            <img v-if="item.file_type === 'image'" 
                                :src="'/storage/' + item.file_path" 
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" 
                                :alt="item.file_name" />
                            <video v-else 
                                :src="'/storage/' + item.file_path" 
                                class="w-full h-full object-cover"
                                muted></video>
                            
                            <div v-if="item.file_type === 'video'" class="absolute top-2 right-2 bg-black/70 rounded-full p-2">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path>
                                </svg>
                            </div>
                            
                            <div class="gallery-card-overlay">
                                <div class="absolute inset-0 flex flex-col justify-end p-4">
                                    <p class="text-xs font-medium text-white mb-1 truncate">{{ item.user.name }}</p>
                                    <p class="text-[10px] text-gray-300 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        {{ new Date(item.created_at).toLocaleDateString() }}
                                    </p>
                                    <button @click.stop="openPreview(item)" 
                                        class="mt-2 inline-flex items-center justify-center gap-1 px-3 py-1.5 bg-brand-red hover:bg-brand-red-hover rounded-lg text-xs font-semibold transition-all duration-300 hover:scale-105">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div v-if="media.data.length === 0" class="text-center py-20 animate-fade-in">
                    <svg class="w-24 h-24 mx-auto text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-gray-400 text-lg mb-2">No memories yet</p>
                    <p class="text-gray-500 text-sm">Start by creating an album and uploading photos!</p>
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
                        class="max-w-full max-h-[80vh]">
                    </video>

                    <!-- Next Button -->
                    <button 
                        v-if="currentIndex < media.data.length - 1"
                        @click="goToNext" 
                        class="absolute right-4 z-10 p-3 bg-black/50 hover:bg-black/70 text-white rounded-full transition-all duration-200 hover:scale-110"
                        title="Next">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>

                <div class="mt-4 flex justify-between items-center text-sm text-gray-400">
                    <span>Uploaded by {{ previewMedia?.user?.name }}</span>
                    <span>{{ currentIndex + 1 }} / {{ media.data.length }}</span>
                    <span>{{ previewMedia?.created_at ? new Date(previewMedia.created_at).toLocaleDateString() : '' }}</span>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
