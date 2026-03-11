<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    stats: Object,
    recentMedia: Array,
    recentUsers: Array,
    recentAlbums: Array,
    myRecentUploads: Array,
    userRole: String,
});

const getInitials = (name) => {
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
};

const formatSize = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const showPreviewModal = ref(false);
const previewMedia = ref(null);
const currentIndex = ref(0);
const allMediaContext = ref([]);

const openPreview = (media, contextList = null) => {
    allMediaContext.value = contextList || props.recentMedia;
    const index = allMediaContext.value.findIndex(m => m.id === media.id);
    currentIndex.value = index !== -1 ? index : 0;
    previewMedia.value = media;
    showPreviewModal.value = true;
};

const closePreview = () => {
    showPreviewModal.value = false;
    previewMedia.value = null;
};

const goToNext = () => {
    if (currentIndex.value < allMediaContext.value.length - 1) {
        currentIndex.value++;
        previewMedia.value = allMediaContext.value[currentIndex.value];
    }
};

const goToPrevious = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
        previewMedia.value = allMediaContext.value[currentIndex.value];
    }
};

const handleAction = (e, action, id) => {
    e.stopPropagation();
    console.log(`${action} performed on asset ${id}`);
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-12 animate-fade-in text-foreground max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            <div class="flex items-center justify-between">
                <div>
                    <h1 v-if="userRole === 'admin'" class="font-heading font-bold text-3xl">Overview</h1>
                    <h1 v-else-if="userRole === 'manager'" class="font-heading font-bold text-3xl">Manager Hub</h1>
                    <h1 v-else class="font-heading font-bold text-3xl">My Workspace</h1>
                    
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ userRole === 'admin' ? 'Monitor platform assets and user contributions' : 
                           userRole === 'manager' ? 'Coordinate albums and team contributions' : 
                           'Access albums and contribute your media' }}
                    </p>
                </div>
                
                <Link v-if="userRole !== 'member'" href="/albums/create" class="flex items-center gap-2 h-10 px-5 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:shadow-primary/20 transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Album
                </Link>
                <Link v-else href="/albums" class="flex items-center gap-2 h-10 px-5 rounded-pill bg-primary text-primary-foreground font-bold text-sm shadow-lg hover:shadow-primary/20 transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    Upload Files
                </Link>
            </div>

            <!-- Stat Cards common -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Admin Stats -->
                <template v-if="userRole === 'admin'">
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Users</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalUsers }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Media Assets</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.mediaAssets }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Storage Used</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.storageUsed }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg></div>
                    </div>
                </template>

                <!-- Manager Stats -->
                <template v-if="userRole === 'manager'">
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Team Members</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalUsers }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">My Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.myAlbums }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">New Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.newUploads }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg></div>
                    </div>
                </template>

                <!-- Member Stats -->
                <template v-if="userRole === 'member'">
                    <div class="glass-card flex items-center justify-between lg:col-span-1">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.myUploadsCount }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="glass-card flex items-center justify-between lg:col-span-1">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Albums Joined</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-primary/10 text-primary"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Activity View -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <span v-if="userRole === 'member'">Active Albums</span>
                            <span v-else>Recently Updated Albums</span>
                        </h3>
                        <Link href="/albums" class="text-xs font-bold text-primary hover:underline uppercase tracking-widest">Browse All</Link>
                    </div>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <Link v-for="album in recentAlbums.slice(0, 4)" :key="album.id" :href="`/albums/${album.id}`" class="group cursor-pointer flex flex-col items-center gap-2">
                            <div class="w-full aspect-[4/3] rounded-2xl overflow-hidden bg-bg-card border border-border group-hover:border-primary/50 transition-all shadow-sm">
                                <img :src="album.coverUrl" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500" alt="" />
                            </div>
                            <span class="text-xs font-bold text-foreground truncate w-full px-1">{{ album.name }}</span>
                            <span class="text-[10px] text-muted-foreground">{{ album.photoCount }} assets</span>
                        </Link>
                    </div>

                    <div class="space-y-4 pt-4">
                        <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <span v-if="userRole === 'member'">My Recent Uploads</span>
                            <span v-else>Recent Media Uploads</span>
                        </h3>
                        
                        <div class="columns-2 sm:columns-3 gap-4 space-y-4">
                            <template v-for="(item, i) in (userRole === 'member' ? myRecentUploads : recentMedia)" :key="item.id">
                                <div @click="openPreview(item, userRole === 'member' ? myRecentUploads : recentMedia)" class="group relative inline-block w-full break-inside-avoid rounded-xl overflow-hidden border border-border bg-bg-elevated cursor-pointer hover:border-primary/50 transition-all shadow-sm hover:shadow-lg animate-fade-in-up">
                                    <div class="relative">
                                        <img v-if="item.file_type === 'image'" :src="'/storage/' + item.file_path" class="w-full h-auto object-contain transition-transform duration-700 group-hover:scale-105 rounded-xl" :alt="item.file_name" />
                                        <video v-else :src="'/storage/' + item.file_path" class="w-full h-auto rounded-xl"></video>
                                        
                                        <div v-if="item.file_type === 'video'" class="absolute inset-0 flex items-center justify-center">
                                            <div class="w-10 h-10 rounded-full bg-black/60 flex items-center justify-center">
                                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                                            </div>
                                        </div>
                                        
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex items-end p-3 rounded-xl">
                                            <div class="flex items-center justify-between w-full">
                                                <span class="text-white text-[10px] font-bold truncate tracking-wide">By {{ item.user?.name || 'Unknown' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Sidewidget view -->
                <div class="space-y-6">
                    <!-- Admin & Manager Sidebar widgets -->
                    <template v-if="userRole !== 'member'">
                        <div class="glass-card-static !p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="font-heading font-bold text-sm text-foreground flex items-center gap-2">Team Updates</h3>
                                <Link v-if="userRole === 'admin'" href="/users" class="text-[10px] font-bold text-primary hover:underline uppercase tracking-widest">Manage</Link>
                            </div>
                            <div class="space-y-3 mt-4">
                                <div v-for="u in recentUsers" :key="u.id" class="flex items-center gap-3 p-2.5 rounded-xl hover:bg-bg-hover transition-colors">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-xs font-bold text-primary">{{ getInitials(u.name) }}</div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-foreground truncate">{{ u.name }}</p>
                                        <p class="text-[10px] text-muted-foreground uppercase">{{ u.role }}</p>
                                    </div>
                                    <span class="text-[9px] px-1.5 py-0.5 rounded capitalize" :class="u.role === 'admin' ? 'bg-primary/20 text-primary' : (u.role === 'manager' ? 'bg-info/20 text-info' : 'bg-success/20 text-success')">{{ u.role }}</span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Manager/Member specific upload CTA -->
                    <template v-if="userRole === 'member' || userRole === 'manager'">
                        <div class="glass-card-static !p-6 space-y-4 bg-gradient-to-br from-primary/5 to-accent-hover/5 border-primary/20 shadow-xl">
                            <div class="flex flex-col items-center text-center py-4">
                                <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center text-primary mb-4">
                                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                </div>
                                <h3 class="font-heading font-bold text-lg text-foreground">Ready to contribute?</h3>
                                <p class="text-xs text-muted-foreground mt-2 max-w-[200px]">Upload photos from your latest event directly to the shared albums.</p>
                                <Link href="/albums" class="mt-6 w-full h-11 flex items-center justify-center rounded-pill bg-primary text-primary-foreground font-bold text-sm shadow-lg hover:shadow-primary/20 transition-all">Start Uploading</Link>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Lightbox Modal -->
            <div v-if="showPreviewModal" class="fixed inset-0 z-50 bg-black/95 backdrop-blur-sm flex items-center justify-center" @click.self="closePreview">
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
                
                <button v-if="currentIndex < allMediaContext.length - 1" @click="goToNext" class="absolute right-4 w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors backdrop-blur-md">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                
                <div class="flex items-center justify-center w-full max-w-5xl max-h-[85vh] px-16">
                    <img v-if="previewMedia?.file_type === 'image'" :src="'/storage/' + previewMedia.file_path" class="max-w-full max-h-[85vh] object-contain" />
                    <video v-else-if="previewMedia?.file_type === 'video'" :src="'/storage/' + previewMedia.file_path" controls autoplay class="max-w-full max-h-[85vh]"></video>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
