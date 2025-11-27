<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    media: Array,
    albums: Array,
});

const restoreMedia = (id) => {
    if (confirm('Are you sure you want to restore this item?')) {
        router.post(route('recycle-bin.restore-media', id));
    }
};

const forceDeleteMedia = (id) => {
    if (confirm('Are you sure you want to PERMANENTLY delete this item? This action cannot be undone.')) {
        router.delete(route('recycle-bin.force-delete-media', id));
    }
};

const restoreAlbum = (id) => {
    if (confirm('Are you sure you want to restore this album?')) {
        router.post(route('recycle-bin.restore-album', id));
    }
};

const forceDeleteAlbum = (id) => {
    if (confirm('Are you sure you want to PERMANENTLY delete this album? This action cannot be undone.')) {
        router.delete(route('recycle-bin.force-delete-album', id));
    }
};
</script>

<template>
    <Head title="Recycle Bin" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Recycle Bin</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-12">
                
                <!-- Albums Section -->
                <div v-if="albums.length > 0">
                    <h3 class="text-lg font-medium text-gray-200 mb-4">Deleted Albums</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <div v-for="album in albums" :key="album.id" class="bg-brand-dark overflow-hidden shadow-sm sm:rounded-lg ring-1 ring-gray-700">
                            <div class="p-4">
                                <h4 class="text-lg font-bold text-white">{{ album.title }}</h4>
                                <p class="text-xs text-gray-500 mt-1">Deleted: {{ new Date(album.deleted_at).toLocaleDateString() }}</p>
                                
                                <div class="mt-4 flex gap-2">
                                    <button @click="restoreAlbum(album.id)" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-sm rounded transition">Restore</button>
                                    <button @click="forceDeleteAlbum(album.id)" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-sm rounded transition">Delete Forever</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Media Section -->
                <div>
                    <h3 class="text-lg font-medium text-gray-200 mb-4">Deleted Media</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                        <div v-for="item in media" :key="item.id" class="relative group aspect-square bg-gray-800 rounded-lg overflow-hidden ring-1 ring-gray-700">
                            <img v-if="item.file_type === 'image'" :src="'/storage/' + item.file_path" class="w-full h-full object-cover opacity-50" />
                            <video v-else :src="'/storage/' + item.file_path" class="w-full h-full object-cover opacity-50"></video>
                            
                            <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition bg-black/60">
                                <button @click="restoreMedia(item.id)" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-xs rounded transition w-24">Restore</button>
                                <button @click="forceDeleteMedia(item.id)" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded transition w-24">Delete Forever</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="media.length === 0 && albums.length === 0" class="text-center text-gray-400 py-12">
                        Recycle bin is empty.
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
