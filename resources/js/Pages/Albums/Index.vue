<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    albums: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || '');
const showDeleteModal = ref(false);
const albumToDelete = ref(null);

watch([search, type], debounce(() => {
    router.get(route('albums.index'), { search: search.value, type: type.value }, { preserveState: true, replace: true });
}, 300));

const confirmDelete = (album) => {
    albumToDelete.value = album;
    showDeleteModal.value = true;
};

const deleteAlbum = () => {
    if (albumToDelete.value) {
        router.delete(route('albums.destroy', albumToDelete.value.id), {
            onSuccess: () => {
                showDeleteModal.value = false;
                albumToDelete.value = null;
            },
        });
    }
};

const systemAlbums = computed(() => {
    return props.albums.filter(album => album.is_system);
});

const userAlbums = computed(() => {
    return props.albums.filter(album => !album.is_system);
});
</script>

<template>
    <Head title="Albums" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Albums</h2>
                
                <div class="flex items-center gap-4 w-full sm:w-auto">
                    <input v-model="search" type="text" placeholder="Search albums..." class="bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red w-full sm:w-64" />
                    
                    <select v-model="type" class="bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red">
                        <option value="">All Types</option>
                        <option value="festival">Festival</option>
                        <option value="event">Event</option>
                    </select>

                    <Link :href="route('albums.create')" class="px-4 py-2 bg-brand-red hover:bg-brand-red-hover text-white rounded-md transition whitespace-nowrap">
                        Create Album
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 animate-fade-in">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- System Albums Section -->
                <div v-if="systemAlbums.length > 0">
                    <h3 class="text-lg font-semibold text-gray-200 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                        </svg>
                        Smart Albums
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-8">
                        <div v-for="(album, index) in systemAlbums" :key="album.id" 
                            class="gallery-card animate-slide-up"
                            :class="'stagger-' + ((index % 4) + 1)">
                            <div class="block h-full relative group">
                                <Link :href="route('albums.system', album.id)" class="block">
                                    <div class="aspect-video bg-gradient-to-br from-gray-800 to-gray-900 relative overflow-hidden">
                                        <!-- System Album Badge -->
                                        <div class="absolute top-2 left-2 z-10 bg-gradient-to-r from-purple-600 to-pink-600 px-3 py-1 rounded-full text-xs font-bold text-white shadow-lg">
                                            <svg class="w-3 h-3 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                            </svg>
                                            Auto
                                        </div>

                                        <img v-if="album.thumbnail" :src="album.thumbnail" 
                                            class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" 
                                            :alt="album.title" />
                                        <div v-else class="w-full h-full flex items-center justify-center">
                                            <svg class="w-20 h-20 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <div class="gallery-card-overlay">
                                            <div class="absolute bottom-0 left-0 right-0 p-4">
                                                <div class="flex items-center justify-between text-white">
                                                    <span class="text-sm font-medium bg-purple-600 px-3 py-1 rounded-full uppercase tracking-wide">
                                                        {{ album.type }}
                                                    </span>
                                                    <span class="text-sm bg-black/50 px-3 py-1 rounded-full">
                                                        {{ album.media_count || 0 }} items
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-5 bg-gradient-to-b from-brand-dark to-brand-gray">
                                        <h3 class="text-lg font-bold text-white group-hover:text-purple-400 transition-colors duration-300 line-clamp-1">
                                            {{ album.title }}
                                        </h3>
                                        <p v-if="album.description" class="text-sm text-gray-400 mt-2 line-clamp-2">{{ album.description }}</p>
                                    </div>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="relative my-8">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-700"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-4 bg-gray-900 text-gray-400 font-medium">Your Albums</span>
                        </div>
                    </div>
                </div>

                <!-- User Albums Section -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    <div v-for="(album, index) in userAlbums" :key="album.id" 
                        class="gallery-card animate-slide-up"
                        :class="'stagger-' + ((index % 4) + 1)">
                        <div class="block h-full relative group">
                            <Link :href="route('albums.show', album.id)" class="block">
                                <div class="aspect-video bg-gradient-to-br from-gray-800 to-gray-900 relative overflow-hidden">
                                    <img v-if="album.thumbnail" :src="album.thumbnail" 
                                        class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" 
                                        :alt="album.title" />
                                    <div v-else class="w-full h-full flex items-center justify-center">
                                        <svg class="w-20 h-20 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <div class="gallery-card-overlay">
                                        <div class="absolute bottom-0 left-0 right-0 p-4">
                                            <div class="flex items-center justify-between text-white">
                                                <span class="text-sm font-medium bg-brand-red px-3 py-1 rounded-full uppercase tracking-wide">
                                                    {{ album.type }}
                                                </span>
                                                <span class="text-sm bg-black/50 px-3 py-1 rounded-full">
                                                    {{ album.media_count || 0 }} items
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-5 bg-gradient-to-b from-brand-dark to-brand-gray">
                                    <h3 class="text-lg font-bold text-white group-hover:text-brand-red transition-colors duration-300 line-clamp-1">
                                        {{ album.title }}
                                    </h3>
                                    <p v-if="album.description" class="text-sm text-gray-400 mt-2 line-clamp-2">{{ album.description }}</p>
                                    <div class="flex items-center justify-between mt-3">
                                        <p v-if="album.event_date" class="text-xs text-gray-500 flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            {{ new Date(album.event_date).toLocaleDateString() }}
                                        </p>
                                        <span v-if="album.is_public" class="text-xs text-green-400 flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Public
                                        </span>
                                    </div>
                                </div>
                            </Link>
                            
                            <!-- Action Buttons -->
                            <div v-if="$page.props.auth.user.id === album.user_id || $page.props.auth.user.role === 'admin'" 
                                class="absolute top-2 right-2 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                                <Link :href="route('albums.edit', album.id)" 
                                    class="p-2 bg-blue-600 hover:bg-blue-700 text-white rounded-full transition-colors duration-200">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </Link>
                                <button @click.prevent="confirmDelete(album)" 
                                    class="p-2 bg-red-600 hover:bg-red-700 text-white rounded-full transition-colors duration-200">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div v-if="albums.length === 0" class="text-center py-20 animate-fade-in">
                    <svg class="w-24 h-24 mx-auto text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <p class="text-gray-400 text-lg">No albums found. Create one to get started!</p>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Delete Album
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Are you sure you want to delete "{{ albumToDelete?.title }}"? This will move the album to the recycle bin.
                </p>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="showDeleteModal = false">Cancel</SecondaryButton>
                    <DangerButton class="ms-3" @click="deleteAlbum">Delete Album</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
