<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    albums: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || '');

watch([search, type], debounce(() => {
    router.get(route('albums.index'), { search: search.value, type: type.value }, { preserveState: true, replace: true });
}, 300));
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
                        <option value="personal">Personal</option>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    <div v-for="(album, index) in albums" :key="album.id" 
                        class="gallery-card animate-slide-up"
                        :class="'stagger-' + ((index % 4) + 1)">
                        <Link :href="route('albums.show', album.id)" class="block h-full">
                            <div class="aspect-video bg-gradient-to-br from-gray-800 to-gray-900 relative overflow-hidden">
                                <img v-if="album.cover_image" :src="album.cover_image" 
                                    class="w-full h-full object-cover transition-transform duration-500 hover:scale-110" 
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
    </AuthenticatedLayout>
</template>
