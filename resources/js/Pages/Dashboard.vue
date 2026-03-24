<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import MediaPreviewOverlay from '@/Components/MediaPreviewOverlay.vue';
import MediaRenderer from '@/Components/MediaRenderer.vue';
import { getInitials } from '@/utils/initials';
import { useMediaPreview } from '@/composables/useMediaPreview';

const props = defineProps({
    stats: Object,
    recentMedia: Array,
    recentUsers: Array,
    recentAlbums: Array,
    myRecentUploads: Array,
    userRole: String,
});

const formatSize = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const {
    showPreviewModal,
    previewMedia,
    currentIndex,
    items: allMediaContext,
    openPreview,
    closePreview,
    goToNext,
    goToPrevious,
} = useMediaPreview(() => props.recentMedia);

const handleAction = (e, action, id) => {
    e.stopPropagation();
    console.log(`${action} performed on asset ${id}`);
};

const parseStorageToTB = (value) => {
    if (!value) return 0;
    const match = String(value).match(/([\d.]+)\s*(B|KB|MB|GB|TB)?/i);
    if (!match) return 0;

    const amount = parseFloat(match[1]);
    if (Number.isNaN(amount)) return 0;

    const unit = (match[2] || 'B').toUpperCase();
    const divisors = {
        B: 1024 ** 4,
        KB: 1024 ** 3,
        MB: 1024 ** 2,
        GB: 1024,
        TB: 1,
    };

    return amount / (divisors[unit] || 1);
};

const storageUsedLabel = computed(() => {
    if (props.userRole === 'member') return props.stats?.myStorageUsed || '0 TB';
    return props.stats?.storageUsed || '0 TB';
});

const storageUsagePercent = computed(() => {
    const usedTb = parseStorageToTB(storageUsedLabel.value);
    const maxTb = props.userRole === 'member' ? 2 : 5;
    return Math.min(100, Math.max(0, (usedTb / maxTb) * 100));
});

const membersUsed = computed(() => props.stats?.totalUsers || 0);
const membersMax = computed(() => (props.userRole === 'admin' ? 200 : 100));
const memberUsagePercent = computed(() => {
    return Math.min(100, Math.max(0, (membersUsed.value / membersMax.value) * 100));
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-8 py-2">

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
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Users</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalUsers }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Media Assets</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.mediaAssets }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Storage Used</p>
                            <h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.storageUsed }}</h3>
                            <p class="text-[10px] text-muted-foreground mt-1">{{ stats.mediaAssets }} files in R2</p>
                        </div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg></div>
                    </div>
                </template>

                <!-- Manager Stats -->
                <template v-if="userRole === 'manager'">
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Team Members</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalUsers }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">My Albums</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.myAlbums }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">New Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.newUploads }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg></div>
                    </div>
                </template>

                <!-- Member Stats -->
                <template v-if="userRole === 'member'">
                    <div class="dash-card flex items-center justify-between lg:col-span-1">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Total Uploads</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.myUploadsCount }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                    </div>
                    <div class="dash-card flex items-center justify-between lg:col-span-1">
                        <div><p class="text-xs font-bold text-muted-foreground uppercase tracking-widest">Albums Joined</p><h3 class="text-3xl font-bold font-mono text-foreground mt-1">{{ stats.totalAlbums }}</h3></div>
                        <div class="dash-icon-box"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Activity View -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <span>Pinned Albums</span>
                        </h3>
                        <Link href="/albums" class="text-xs font-bold text-primary hover:underline uppercase tracking-widest">View All</Link>
                    </div>

                    <div v-if="recentAlbums.length" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Link
                            v-for="album in recentAlbums.slice(0, 4)"
                            :key="album.id"
                            :href="route('albums.show', album.path || album.slug || album.id)"
                            class="dash-card flex items-center gap-3 p-3 group"
                        >
                            <div class="w-12 h-12 rounded-xl overflow-hidden bg-bg-elevated shrink-0 border border-border">
                                <MediaRenderer
                                    v-if="album.coverMedia"
                                    :media="album.coverMedia"
                                    :alt="album.name"
                                    image-class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                                    video-class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                                    fallback-class="flex h-full w-full items-center justify-center bg-bg-elevated text-[10px] font-bold uppercase tracking-[0.22em] text-muted-foreground"
                                />
                                <div v-else class="w-full h-full flex items-center justify-center text-muted-foreground bg-bg-elevated">
                                    <svg class="w-5 h-5 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-foreground truncate">{{ album.name }}</p>
                                <p class="text-[11px] text-muted-foreground mt-0.5">{{ album.date || 'Recently updated' }}</p>
                                <span class="inline-flex mt-1 text-[10px] font-bold px-2 py-0.5 rounded bg-primary/10 text-primary uppercase tracking-wide">{{ album.photoCount }} assets</span>
                            </div>
                        </Link>
                    </div>
                    <div v-else class="dash-card !p-6 text-sm text-muted-foreground">
                        No pinned albums yet. Pin an album from the Albums or Album details page.
                    </div>

                    <div class="space-y-4 pt-4">
                        <h3 class="font-heading font-bold text-lg text-foreground flex items-center gap-2">
                            <span v-if="userRole === 'member'">My Recent Uploads</span>
                            <span v-else>Recent Media Uploads</span>
                        </h3>

                        <div class="columns-2 sm:columns-3 gap-4">
                            <template v-for="(item, i) in (userRole === 'member' ? myRecentUploads : recentMedia)" :key="item.id">
                                <div @click="openPreview(item, userRole === 'member' ? myRecentUploads : recentMedia)" class="group relative inline-block w-full mb-4 break-inside-avoid rounded-xl overflow-hidden border border-border bg-bg-elevated cursor-pointer hover:border-primary/50 transition-all shadow-sm hover:shadow-lg animate-fade-in-up">
                                    <div class="relative">
                                        <MediaRenderer
                                            :media="item"
                                            :alt="item.file_name"
                                            :use-thumbnail="true"
                                            image-class="w-full h-auto object-cover transition-transform duration-700 group-hover:scale-105 rounded-xl"
                                            video-class="w-full h-auto rounded-xl"
                                            fallback-class="flex min-h-[10rem] w-full items-center justify-center rounded-xl bg-bg-hover text-xs font-bold uppercase tracking-[0.24em] text-muted-foreground"
                                        />

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
                        <div class="dash-card !p-6 space-y-4">
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

                    <div v-if="userRole === 'admin'" class="dash-card !p-6 space-y-4">
                        <h3 class="font-heading font-bold text-sm uppercase tracking-widest text-foreground">Usage Summary</h3>
                        <div class="space-y-4">
                            <div>
                                <div class="flex items-center justify-between text-[11px] text-muted-foreground mb-1.5">
                                    <span>Storage Used</span>
                                    <span class="font-bold text-foreground">{{ storageUsedLabel }} / {{ userRole === 'member' ? '2 TB' : '5 TB' }}</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="orange-progress h-full rounded-full transition-all" :style="{ width: `${storageUsagePercent}%` }" />
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between text-[11px] text-muted-foreground mb-1.5">
                                    <span>Members Active</span>
                                    <span class="font-bold text-foreground">{{ membersUsed }} / {{ membersMax }}</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="orange-progress h-full rounded-full transition-all" :style="{ width: `${memberUsagePercent}%` }" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <MediaPreviewOverlay
                :show="showPreviewModal"
                :media="previewMedia"
                :items="allMediaContext"
                :current-index="currentIndex"
                @close="closePreview"
                @next="goToNext"
                @previous="goToPrevious"
            />

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.dash-card {
    position: relative;
    overflow: hidden;
    background: linear-gradient(132deg, hsl(var(--card)), hsl(var(--card)) 15%, hsl(var(--primary) / 0.08));
    border: 1px solid hsl(var(--border));
    border-radius: 1rem;
    padding: 1.25rem;
    box-shadow: 0 1px 2px hsl(220 15% 20% / 0.04);
}

.dash-icon-box {
    width: 3rem;
    height: 3rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, hsl(var(--primary)), hsl(var(--accent-hover)));
    color: hsl(var(--primary-foreground));
    box-shadow: 0 10px 20px hsl(var(--primary) / 0.22);
}

.orange-progress {
    background: linear-gradient(90deg, hsl(var(--primary)), hsl(var(--accent-hover)));
}
</style>
