<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    album: Object,
    breadcrumbs: Array,
});

const page = usePage();
const user = page.props.auth.user;

const viewMode = ref('grid');
const filter = ref('All');
const showNewMenu = ref(false);
const showActionMenu = ref(null);
const showNewFolderModal = ref(false);
const showUploadModal = ref(false);
const showDeleteModal = ref(false);
const itemToDelete = ref(null);
const deleteType = ref('album');

const newFolder = ref({ title: '', description: '' });
const uploadFiles = ref([]);
const isPinned = ref(false); // Mock

const canManage = computed(() => ['admin', 'manager'].includes(user.role));
const canModify = computed(() => canManage.value || props.album.user_id === user.id);

const folders = computed(() => props.album.children || []);
const files = computed(() => props.album.media || []);

const filteredFolders = computed(() => {
    if (filter.value === 'Photos' || filter.value === 'Videos') return [];
    return folders.value;
});

const filteredFiles = computed(() => {
    if (filter.value === 'Folders') return [];
    if (filter.value === 'Photos') return files.value.filter(f => f.file_type === 'image');
    if (filter.value === 'Videos') return files.value.filter(f => f.file_type === 'video');
    return files.value;
});

const toggleActionMenu = (e, id) => {
    e.stopPropagation();
    showActionMenu.value = showActionMenu.value === id ? null : id;
};

const closeActionMenu = () => {
    showActionMenu.value = null;
    showNewMenu.value = false;
};

const togglePin = (e) => {
    e.stopPropagation();
    isPinned.value = !isPinned.value;
};

const createFolder = () => {
    if (!newFolder.value.title.trim()) return;
    router.post(route('albums.store'), {
        title: newFolder.value.title,
        description: newFolder.value.description,
        parent_id: props.album.id,
        is_public: props.album.is_public,
    }, {
        onSuccess: () => {
            showNewFolderModal.value = false;
            newFolder.value = { title: '', description: '' };
        }
    });
};

const handleUpload = () => {
    if (uploadFiles.value.length === 0) return;
    const formData = new FormData();
    formData.append('album_id', props.album.id);
    for (let i = 0; i < uploadFiles.value.length; i++) {
        formData.append('files[]', uploadFiles.value[i]);
    }
    router.post(route('media.store'), formData, {
        onSuccess: () => {
            showUploadModal.value = false;
            uploadFiles.value = [];
        }
    });
};

const confirmDelete = (item, type) => {
    itemToDelete.value = item;
    deleteType.value = type;
    showActionMenu.value = null;
    showDeleteModal.value = true;
};

const deleteItem = () => {
    if (!itemToDelete.value) return;
    
    if (deleteType.value === 'album') {
        router.delete(route('albums.destroy', itemToDelete.value.id), {
            onSuccess: () => {
                showDeleteModal.value = false;
                itemToDelete.value = null;
            }
        });
    } else {
        router.delete(route('media.destroy', itemToDelete.value.id), {
            onSuccess: () => {
                showDeleteModal.value = false;
                itemToDelete.value = null;
            }
        });
    }
};

const handleFileSelect = (e) => {
    uploadFiles.value = e.target.files;
};

const canDeleteMedia = (media) => {
    if (canManage.value) return true;
    return media.user_id === user.id;
};

const handleAction = (action, type, item) => {
    showActionMenu.value = null;
    if (action === 'Delete') {
        confirmDelete(item, type);
    } else if (action === 'Download') {
        if (type === 'media') {
            const link = document.createElement('a');
            link.href = '/storage/' + item.file_path;
            link.download = item.file_name;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
};

const showPreviewModal = ref(false);
const previewMedia = ref(null);
const currentIndex = ref(0);

const openPreview = (media) => {
    const index = filteredFiles.value.findIndex(m => m.id === media.id);
    currentIndex.value = index !== -1 ? index : 0;
    previewMedia.value = media;
    showPreviewModal.value = true;
};

const closePreview = () => {
    showPreviewModal.value = false;
    previewMedia.value = null;
};

const goToNext = () => {
    if (currentIndex.value < filteredFiles.value.length - 1) {
        currentIndex.value++;
        previewMedia.value = filteredFiles.value[currentIndex.value];
    }
};

const goToPrevious = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
        previewMedia.value = filteredFiles.value[currentIndex.value];
    }
};
</script>

<template>
    <Head :title="album.title" />

    <AuthenticatedLayout>
        <div class="py-12 animate-fade-in max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" @click="closeActionMenu">
            
            <!-- Header with Breadcrumbs and New Button -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-2 overflow-x-auto pb-2 md:pb-0 scrollbar-hide flex-1">
                    <Link :href="album.parent_id ? route('albums.show', album.parent_id) : route('albums.index')" class="p-2 rounded-full hover:bg-bg-hover text-muted-foreground transition-all shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    </Link>
                    
                    <div class="flex items-center gap-1 text-sm text-muted-foreground whitespace-nowrap min-w-0 flex-1">
                        <Link :href="route('albums.index')" class="hover:text-foreground cursor-pointer transition-colors">Albums</Link>
                        
                        <template v-for="crumb in breadcrumbs" :key="crumb.id">
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <Link :href="route('albums.show', crumb.id)" class="hover:text-foreground cursor-pointer transition-colors truncate">
                                {{ crumb.title }}
                            </Link>
                        </template>
                        
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-foreground font-bold truncate">{{ album.title }}</span>

                        <!-- Pin Button -->
                        <button v-if="!album.is_system" @click="togglePin" class="ml-3 p-1.5 rounded-full transition-all shrink-0 shadow-sm border" 
                                :class="isPinned ? 'bg-primary border-primary text-primary-foreground' : 'hover:bg-bg-hover text-muted-foreground border-border'">
                            <svg class="w-4 h-4" :class="isPinned ? 'fill-current' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <div class="relative" v-if="!album.is_system && canModify">
                        <button @click.stop="showNewMenu = !showNewMenu" class="flex items-center gap-2 h-10 px-5 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-1px] transition-all">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            New
                        </button>
                        
                        <div v-if="showNewMenu" class="absolute right-0 mt-2 w-56 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-50 animate-scale-in" @click.stop>
                            <button @click="showNewFolderModal = true; showNewMenu = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                New folder
                            </button>
                            <div class="h-px bg-border my-1"></div>
                            <button @click="showUploadModal = true; showNewMenu = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                File upload
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center bg-bg-elevated rounded-pill p-1 border border-border" v-if="!album.is_system">
                        <button @click="viewMode = 'list'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'list' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <button @click="viewMode = 'grid'" class="p-1.5 rounded-full transition-all" :class="viewMode === 'grid' ? 'bg-bg-card text-primary shadow-sm' : 'text-muted-foreground'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex items-center gap-2 border-b border-border pb-2">
                <template v-for="f in ['All', 'Photos', 'Videos', 'Folders']" :key="f">
                    <button @click="filter = f" class="px-4 py-1.5 rounded-pill text-xs font-bold transition-all border"
                            :class="filter === f ? 'bg-primary/10 border-primary text-primary' : 'bg-transparent border-transparent text-muted-foreground hover:bg-bg-hover'">
                        {{ f }}
                    </button>
                </template>
            </div>

            <!-- Folders Section -->
            <div v-if="filteredFolders.length > 0" class="space-y-4">
                <h3 class="text-sm font-bold text-foreground">Folders</h3>
                <div class="grid gap-4" :class="viewMode === 'grid' ? 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' : 'grid-cols-1'">
                    <Link v-for="folder in filteredFolders" :key="folder.id" :href="route('albums.show', folder.id)"
                          class="group cursor-pointer transition-all animate-fade-in-up flex items-center gap-4 bg-bg-card border border-border rounded-2xl hover:bg-bg-hover hover:border-primary/30"
                          :class="viewMode === 'grid' ? 'p-4 pr-2' : 'p-3 px-6'">
                        <div class="w-14 h-14 rounded-xl bg-primary/5 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110 overflow-hidden">
                            <img v-if="folder.thumbnail" :src="folder.thumbnail" class="w-full h-full object-cover rounded-xl" :alt="folder.title" />
                            <div v-else class="w-full h-full rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                                <svg class="w-6 h-6 fill-current" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-foreground truncate">{{ folder.title }}</p>
                            <p class="text-[10px] text-muted-foreground mt-0.5">{{ folder.media_count }} files</p>
                        </div>
                        
                        <div class="relative" @click.stop>
                            <button @click="toggleActionMenu($event, 'folder-'+folder.id)" class="opacity-0 group-hover:opacity-100 p-2 rounded-full hover:bg-bg-elevated text-muted-foreground transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
                            </button>
                            <div v-if="showActionMenu === 'folder-'+folder.id" class="absolute right-0 top-full mt-1 w-40 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-50 animate-scale-in">
                                <Link v-if="canManage || folder.user_id === user.id" :href="route('albums.edit', folder.id)" class="w-full flex items-center gap-3 px-4 py-2 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Rename
                                </Link>
                                <button v-if="canManage || folder.user_id === user.id" @click="handleAction('Delete', 'album', folder)" class="w-full flex items-center gap-3 px-4 py-2 text-sm text-error hover:bg-error/10 transition-colors">
                                    <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Files Section -->
            <div v-if="filteredFiles.length > 0" class="space-y-4">
                <h3 class="text-sm font-bold text-foreground">Files</h3>
                
                <div v-if="viewMode === 'grid'" class="columns-2 sm:columns-3 lg:columns-4 xl:columns-5 gap-4 space-y-4">
                    <div v-for="(file, index) in filteredFiles" :key="file.id" @click="openPreview(file)" 
                         class="group relative inline-block w-full break-inside-avoid rounded-2xl overflow-hidden border border-border bg-bg-elevated cursor-pointer hover:border-primary/50 transition-all shadow-sm hover:shadow-xl animate-fade-in-up">
                        <div class="relative">
                            <img v-if="file.file_type === 'image'" :src="'/storage/' + file.file_path" class="w-full h-auto object-cover transition-transform duration-700 group-hover:scale-105" :alt="file.file_name" />
                            <video v-else :src="'/storage/' + file.file_path" class="w-full h-auto object-cover transition-transform duration-700 group-hover:scale-105"></video>
                            
                            <div v-if="file.file_type === 'video'" class="absolute inset-0 flex items-center justify-center bg-black/10">
                                <div class="w-12 h-12 rounded-full bg-black/40 backdrop-blur-md flex items-center justify-center text-white scale-90 group-hover:scale-100 transition-transform">
                                    <svg class="w-6 h-6 fill-current ml-0.5" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                                </div>
                            </div>
                            
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-all duration-300">
                                <div class="absolute top-3 right-3 flex flex-col gap-2">
                                    <button @click="toggleActionMenu($event, 'file-'+file.id)" class="w-10 h-10 rounded-full bg-white/10 backdrop-blur-md text-white flex items-center justify-center hover:bg-white/20 transition-all">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                    </button>
                                </div>
                                <div class="absolute bottom-4 inset-x-4 flex items-center justify-between">
                                    <div class="flex flex-col min-w-0 pr-2">
                                        <span class="text-white text-xs font-bold truncate">{{ file.file_name }}</span>
                                        <span class="text-white/60 text-[10px] truncate">By {{ file.user?.name || 'Unknown' }}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <button @click="handleAction('Download', 'media', file)" class="w-8 h-8 rounded-full bg-white/10 backdrop-blur-md text-white flex items-center justify-center hover:bg-primary transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Menu -->
                        <div v-if="showActionMenu === 'file-'+file.id" class="absolute top-14 right-4 w-48 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-20 animate-scale-in" @click.stop>
                            <button @click="handleAction('Download', 'media', file)" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-bg-hover transition-colors">
                                <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
                            </button>
                            <button v-if="canDeleteMedia(file)" @click="handleAction('Delete', 'media', file)" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-error hover:bg-error/10 transition-colors">
                                <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Remove
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="bg-bg-card border border-border rounded-2xl overflow-hidden shadow-sm">
                    <div class="grid grid-cols-[1fr_200px_100px] items-center px-6 py-3 border-b border-border bg-bg-elevated/50">
                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Name</span>
                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Owner</span>
                        <span />
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="file in filteredFiles" :key="file.id" @click="openPreview(file)" class="grid grid-cols-[1fr_200px_100px] items-center px-6 py-4 hover:bg-bg-hover transition-colors cursor-pointer group">
                            <div class="flex items-center gap-4 min-w-0">
                                <div class="w-12 h-12 rounded-lg overflow-hidden border border-border shrink-0">
                                    <img v-if="file.file_type === 'image'" :src="'/storage/' + file.file_path" class="w-full h-full object-cover" />
                                    <video v-else :src="'/storage/' + file.file_path" class="w-full h-full object-cover"></video>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-foreground truncate">{{ file.file_name }}</p>
                                    <p class="text-[11px] text-muted-foreground">{{ new Date(file.created_at).toLocaleDateString() }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-foreground truncate">{{ file.user?.name || 'Unknown' }}</span>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <button @click.stop="handleAction('Download', 'media', file)" class="p-2 rounded-full hover:bg-bg-elevated text-muted-foreground hover:text-foreground transition-all">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </button>
                                <button @click.stop="toggleActionMenu($event, 'file-'+file.id)" class="p-2 rounded-full hover:bg-bg-elevated text-muted-foreground hover:text-foreground transition-all">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                                <div v-if="showActionMenu === 'file-'+file.id" class="absolute right-6 mt-1 w-40 bg-bg-card border border-border rounded-xl shadow-2xl py-2 z-50 animate-scale-in" @click.stop>
                                    <button v-if="canDeleteMedia(file)" @click="handleAction('Delete', 'media', file)" class="w-full flex items-center gap-3 px-4 py-2 text-sm text-error hover:bg-error/10 transition-colors">
                                        <svg class="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="filteredFiles.length === 0 && filteredFolders.length === 0" class="flex flex-col items-center justify-center py-20 bg-bg-card border border-dashed border-border rounded-3xl animate-fade-in">
                <div class="w-20 h-20 rounded-full bg-bg-elevated flex items-center justify-center text-muted-foreground mb-6">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </div>
                <h3 class="text-xl font-bold text-foreground">This album is empty</h3>
                <p class="text-muted-foreground mt-1 text-center">Click New to add folders and upload media<br/>All members can contribute</p>
            </div>
            
        </div>

        <!-- Lightbox -->
        <div v-if="showPreviewModal" class="fixed inset-0 z-[100] bg-black/95 backdrop-blur-sm flex items-center justify-center" @click.self="closePreview">
            <!-- Close Header -->
            <div class="absolute top-4 right-4 flex items-center gap-4">
                <button class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </button>
                <button @click="closePreview" class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <button v-if="currentIndex > 0" @click="goToPrevious" class="absolute left-4 w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors backdrop-blur-md">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            
            <button v-if="currentIndex < filteredFiles.length - 1" @click="goToNext" class="absolute right-4 w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors backdrop-blur-md">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
            
            <div class="flex items-center justify-center w-full max-w-5xl max-h-[85vh] px-16">
                <img v-if="previewMedia?.file_type === 'image'" :src="'/storage/' + previewMedia.file_path" class="max-w-full max-h-[85vh] object-contain" />
                <video v-else-if="previewMedia?.file_type === 'video'" :src="'/storage/' + previewMedia.file_path" controls autoplay class="max-w-full max-h-[85vh]"></video>
            </div>
        </div>

        <!-- New Folder Modal -->
        <Modal :show="showNewFolderModal" @close="showNewFolderModal = false" max-width="md">
            <div class="p-8 bg-bg-card border border-border rounded-xl">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h2 class="text-xl font-bold text-foreground">Create New Folder</h2>
                </div>
                
                <form @submit.prevent="createFolder" class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Folder Name</label>
                        <input type="text" v-model="newFolder.title" placeholder="e.g. Stage Performances" class="w-full h-12 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" required />
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" @click="showNewFolderModal = false" class="h-11 px-6 rounded-pill text-sm font-bold text-foreground hover:bg-bg-hover transition-all">Cancel</button>
                        <button type="submit" class="h-11 px-8 rounded-pill bg-primary text-primary-foreground text-sm font-bold shadow-lg shadow-primary/20 hover:scale-105 active:scale-95 transition-all">Create Folder</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Upload Files Modal -->
        <Modal :show="showUploadModal" @close="showUploadModal = false" max-width="md">
            <div class="p-8 bg-bg-card border border-border rounded-xl">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    </div>
                    <h2 class="text-xl font-bold text-foreground">Upload Files</h2>
                </div>
                
                <div class="space-y-6">
                    <div class="border-2 border-dashed border-border rounded-xl p-8 text-center bg-bg-elevated/50 hover:bg-bg-elevated transition-colors cursor-pointer relative">
                        <input type="file" multiple accept="image/*,video/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" @change="handleFileSelect" />
                        <svg class="w-10 h-10 mx-auto text-muted-foreground mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <p class="text-sm font-bold text-foreground">Click or drag files here to upload</p>
                        <p class="text-[10px] text-muted-foreground mt-1">Maximum file size: 100MB per file</p>
                    </div>
                    <div v-if="uploadFiles.length > 0" class="text-sm">
                         {{ uploadFiles.length }} file(s) selected
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" @click="showUploadModal = false" class="h-11 px-6 rounded-pill text-sm font-bold text-foreground hover:bg-bg-hover transition-all">Cancel</button>
                        <button type="button" @click="handleUpload" class="h-11 px-8 rounded-pill bg-primary text-primary-foreground text-sm font-bold shadow-lg shadow-primary/20 hover:scale-105 active:scale-95 transition-all">Upload</button>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false" max-width="sm">
            <div class="p-6 bg-bg-card border border-border rounded-xl">
                <h2 class="text-lg font-bold text-foreground">Confirm Delete</h2>

                <p class="mt-2 text-sm text-muted-foreground">
                    Are you sure you want to delete <span class="font-bold text-foreground">"{{ itemToDelete?.title || itemToDelete?.file_name }}"</span>?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="px-4 py-2 rounded-md text-sm font-bold text-foreground hover:bg-bg-hover transition-colors">Cancel</button>
                    <button @click="deleteItem" class="px-4 py-2 rounded-md text-sm font-bold bg-error text-white hover:bg-red-600 transition-colors shadow-sm">Delete</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
