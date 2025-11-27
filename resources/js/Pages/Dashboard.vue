<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    media: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');

watch(search, debounce((value) => {
    router.get(route('dashboard'), { search: value }, { preserveState: true, replace: true });
}, 300));
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
                                    <a :href="'/storage/' + item.file_path" 
                                        target="_blank" 
                                        class="mt-2 inline-flex items-center justify-center gap-1 px-3 py-1.5 bg-brand-red hover:bg-brand-red-hover rounded-lg text-xs font-semibold transition-all duration-300 hover:scale-105">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View
                                    </a>
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
    </AuthenticatedLayout>
</template>
