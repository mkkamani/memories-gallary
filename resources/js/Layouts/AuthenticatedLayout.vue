<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { getInitials } from '@/utils/initials';
import {
  LayoutDashboard,
  Users,
  Search,
  Activity,
  Bell,
  ChevronDown,
  LogOut,
  User,
  Sun,
  Moon,
  ChevronLeft,
  ChevronRight,
  FolderOpen,
  Trash2,
} from 'lucide-vue-next';

// Shared state
const page = usePage();
const authUser = computed(() => page.props.auth.user);

const collapsed = ref(false);
const theme = ref('light'); // light default
const searchQuery = ref('');
const showUserMenu = ref(false);
const showNotifications = ref(false);
const scrolled = ref(false);

// ── Flash / Toast ─────────────────────────────────────────────────────────────
const toasts = ref([]); // [{ id, type, message }]
const toastHovered = ref(false);
let toastId = 0;
const toastTimers = new Map();
const TOAST_DURATION_MS = 5000;

const toastUi = {
    success: {
        card: 'border-emerald-300/70 bg-emerald-100/90 text-emerald-900 shadow-emerald-300/40',
        iconWrap: 'bg-emerald-700 text-emerald-100',
        title: 'Success',
        icon: 'M5 13l4 4L19 7',
    },
    error: {
        card: 'border-red-300/70 bg-red-100/90 text-red-900 shadow-red-300/40',
        iconWrap: 'bg-red-700 text-red-100',
        title: 'Error',
        icon: 'M6 18L18 6M6 6l12 12',
    },
    warning: {
        card: 'border-amber-300/70 bg-amber-100/90 text-amber-900 shadow-amber-300/40',
        iconWrap: 'bg-amber-700 text-amber-100',
        title: 'Warning',
        icon: 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
    },
    info: {
        card: 'border-blue-300/70 bg-blue-100/90 text-blue-900 shadow-blue-300/40',
        iconWrap: 'bg-blue-700 text-blue-100',
        title: 'Info',
        icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    default: {
        card: 'border-slate-300/70 bg-slate-100/90 text-slate-900 shadow-slate-300/40',
        iconWrap: 'bg-slate-700 text-slate-100',
        title: 'Notice',
        icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
};

const getToastConfig = (type) => toastUi[type] || toastUi.default;

const pushToast = (type, message) => {
    if (!message) return;

    const alreadyQueued = toasts.value.some(
        (item) => item.type === type && item.message === message,
    );

    if (alreadyQueued) {
        return;
    }

    const id = ++toastId;
    toasts.value.unshift({ id, type, message });

    const timer = setTimeout(() => {
        dismissToast(id);
    }, TOAST_DURATION_MS);

    toastTimers.set(id, timer);
};

const dismissToast = (id) => {
    const timer = toastTimers.get(id);
    if (timer) {
        clearTimeout(timer);
        toastTimers.delete(id);
    }

    toasts.value = toasts.value.filter((item) => item.id !== id);
};

const clearAllToasts = () => {
    for (const timer of toastTimers.values()) {
        clearTimeout(timer);
    }
    toastTimers.clear();
    toasts.value = [];
};

const visibleToasts = computed(() => {
    if (toastHovered.value) {
        return toasts.value;
    }

    return toasts.value.slice(0, 1);
});

// Watch for flash messages sent from the server via Inertia shared props
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;

        if (flash.success) pushToast('success', flash.success);
        if (flash.error) pushToast('error', flash.error);
        if (flash.warning) pushToast('warning', flash.warning);
        if (flash.info) pushToast('info', flash.info);
    },
    { deep: true, immediate: true },
);
// ─────────────────────────────────────────────────────────────────────────────

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    if (theme.value === 'dark') {
        document.documentElement.classList.add('dark');
        document.documentElement.classList.remove('light');
    } else {
        document.documentElement.classList.add('light');
        document.documentElement.classList.remove('dark');
    }
    localStorage.setItem('theme', theme.value);
};

const toggleSidebar = () => {
    collapsed.value = !collapsed.value;
    localStorage.setItem('sidebarCollapsed', collapsed.value);
};

const logout = () => {
    router.post(route('logout'));
};

const syncSearchQueryFromPage = () => {
    // Prefer shared page props when available on Albums index.
    const fromProps = page.props?.filters?.search;
    if (typeof fromProps === 'string') {
        searchQuery.value = fromProps;
        return;
    }

    try {
        const url = new URL(page.url, window.location.origin);
        searchQuery.value = url.searchParams.get('search') || '';
    } catch {
        searchQuery.value = '';
    }
};

const submitSearch = () => {
    const keyword = searchQuery.value.trim();

    router.get(route('albums.index'), keyword ? { search: keyword } : {}, {
        preserveState: true,
        replace: true,
    });
};

watch(
    () => page.url,
    () => {
        syncSearchQueryFromPage();
    },
    { immediate: true },
);

onMounted(() => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        theme.value = savedTheme;
    } else {
        theme.value = 'light';
    }

    if (theme.value === 'dark') {
        document.documentElement.classList.add('dark');
        document.documentElement.classList.remove('light');
    } else {
        document.documentElement.classList.add('light');
        document.documentElement.classList.remove('dark');
    }

    const savedSidebar = localStorage.getItem('sidebarCollapsed');
    if (savedSidebar !== null) {
        collapsed.value = savedSidebar === 'true';
    }

    syncSearchQueryFromPage();

    window.addEventListener('scroll', () => {
        scrolled.value = window.scrollY > 10;
    });

    document.addEventListener('click', (e) => {
        const userMenu = document.getElementById('user-menu-dropdown');
        const userBtn = document.getElementById('user-menu-btn');
        if (showUserMenu.value && userMenu && userBtn && !userMenu.contains(e.target) && !userBtn.contains(e.target)) {
            showUserMenu.value = false;
        }

        const notifMenu = document.getElementById('notif-dropdown');
        const notifBtn = document.getElementById('notif-btn');
        if (showNotifications.value && notifMenu && notifBtn && !notifMenu.contains(e.target) && !notifBtn.contains(e.target)) {
            showNotifications.value = false;
        }
    });
});

onBeforeUnmount(() => {
    clearAllToasts();
});

const navItems = computed(() => {
    const role = authUser.value?.role || 'user';
    const items = [
        { label: 'Dashboard', path: route('dashboard'), icon: LayoutDashboard },
        { label: 'Albums', path: route('albums.index'), routeName: 'albums.*', icon: FolderOpen },
    ];

    if (role === 'admin') {
        items.push({ label: 'Recycle Bin', path: route('recycle-bin.index'), routeName: 'recycle-bin.*', icon: Trash2 });
        items.push({ label: 'Users', path: route('users.index'), routeName: 'users.*', icon: Users });
    }

    return items;
});

const isActive = (item) => {
    if (item.routeName) {
        return route().current(item.routeName);
    }
    return page.url === new URL(item.path, window.location.origin).pathname;
};

</script>

<template>
    <div class="min-h-screen bg-background flex flex-col font-sans text-foreground">

        <!-- Sidebar -->
        <aside
            class="hidden md:flex flex-col fixed left-0 top-0 bottom-0 z-[60] border-r border-border bg-card transition-all duration-300"
            :class="collapsed ? 'w-16' : 'w-56'"
        >
            <!-- Logo -->
            <div class="h-16 shrink-0 flex items-center border-b border-border px-4 transition-all" :class="collapsed ? 'justify-center px-2' : ''">
                <Link :href="route('dashboard')" class="flex items-center w-full" :class="collapsed ? 'justify-center' : ''">
                    <img v-if="collapsed" src="/favicon.ico" alt="Icon" class="w-8 h-8 rounded-lg" />
                    <img v-else :src="theme === 'dark' ? '/images/cx-logo-light.svg' : '/images/cx-logo-dark.svg'" alt="Logo" class="h-5" />
                </Link>
            </div>

            <!-- Nav Links -->
            <nav class="flex-1 py-4 px-2 space-y-1 overflow-y-auto">
                <Link
                    v-for="item in navItems"
                    :key="item.path"
                    :href="item.path"
                    :title="collapsed ? item.label : undefined"
                    class="w-full flex items-center gap-3 py-2.5 rounded-pill text-sm font-medium transition-all duration-200"
                    :class="[
                        collapsed ? 'justify-center px-0' : 'px-3',
                        isActive(item) ? 'bg-primary text-primary-foreground shadow-md' : 'text-text-secondary hover:bg-bg-hover hover:text-foreground'
                    ]"
                >
                    <component :is="item.icon" class="w-5 h-5 shrink-0" />
                    <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                </Link>
            </nav>

            <button
                @click="toggleSidebar"
                class="shrink-0 flex items-center justify-center h-12 text-muted-foreground hover:text-foreground transition-colors"
            >
                <ChevronRight v-if="collapsed" class="w-4 h-4" />
                <ChevronLeft v-else class="w-4 h-4" />
            </button>
        </aside>

        <!-- Mobile Bottom Nav -->
        <nav class="md:hidden fixed bottom-0 left-0 right-0 z-[60] h-16 bg-card border-t border-border flex items-center justify-around px-2 pb-safe">
            <Link
                v-for="item in navItems.slice(0, 5)"
                :key="item.path"
                :href="item.path"
                class="flex flex-col items-center gap-0.5 py-1 px-2 rounded-lg text-xs transition-colors"
                :class="isActive(item) ? 'text-primary' : 'text-muted-foreground'"
            >
                <component :is="item.icon" class="w-5 h-5" :class="isActive(item) ? 'drop-shadow-[0_0_6px_hsl(var(--primary))]' : ''" />
                <span class="truncate max-w-[56px]">{{ item.label }}</span>
            </Link>
        </nav>

        <!-- Header -->
        <header
            class="fixed top-0 right-0 z-50 h-16 flex items-center px-4 lg:px-6 transition-all duration-300"
            :class="[
                collapsed ? 'md:left-16' : 'md:left-56',
                scrolled ? 'bg-background/95 backdrop-blur-xl border-b border-border shadow-sm' : 'bg-background/80 backdrop-blur-md',
                'left-0'
            ]"
        >
            <!-- Mobile Logo -->
            <div class="flex md:hidden items-center mr-3 shrink-0 cursor-pointer">
                <Link :href="route('dashboard')">
                    <img src="/favicon.ico" alt="Icon" class="w-8 h-8 rounded-lg" />
                </Link>
            </div>

            <!-- Search -->
            <div class="flex flex-1 max-w-[160px] sm:max-w-xs md:max-w-lg mr-2 md:mr-0 shrink">
                <div class="relative w-full">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 md:w-4 md:h-4 text-muted-foreground" />
                    <input
                        type="text"
                        placeholder="Search albums..."
                        v-model="searchQuery"
                        @keyup.enter="submitSearch"
                        class="w-full h-8 md:h-10 pl-9 md:pl-10 pr-3 rounded-full bg-muted/50 border border-border text-xs md:text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all font-sans"
                    />
                </div>
            </div>

            <!-- Right Controls -->
            <div class="flex items-center gap-1.5 ml-auto">
                <button
                    @click="toggleTheme"
                    class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-muted/60 transition-colors"
                    :title="`Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`"
                >
                    <Sun v-if="theme === 'dark'" class="w-4.5 h-4.5 text-muted-foreground" />
                    <Moon v-else class="w-4.5 h-4.5 text-muted-foreground" />
                </button>

                <!-- Notifications -->
                <!-- <div class="relative">
                    <button
                        id="notif-btn"
                        @click="showNotifications = !showNotifications; showUserMenu = false"
                        class="relative w-9 h-9 rounded-full flex items-center justify-center hover:bg-muted/60 transition-colors"
                    >
                        <Bell class="w-5 h-5 text-muted-foreground" />
                    </button>
                    <div id="notif-dropdown" v-if="showNotifications" class="fixed left-4 right-4 top-16 sm:absolute sm:left-auto sm:right-0 sm:top-12 sm:w-80 rounded-2xl border border-border bg-card shadow-2xl p-4 animate-scale-in z-50">
                        <h3 class="font-bold text-base mb-2">Notifications</h3>
                        <p class="text-sm text-muted-foreground">You have no new notifications.</p>
                    </div>
                </div> -->

                <!-- User Menu -->
                <div class="relative">
                    <button
                        id="user-menu-btn"
                        @click="showUserMenu = !showUserMenu; showNotifications = false"
                        class="flex items-center gap-2 h-9 pl-1 pr-3 rounded-full hover:bg-muted/60 transition-colors"
                    >
                        <img v-if="authUser?.avatar_url" :src="authUser.avatar_url" alt="Avatar" class="w-7 h-7 rounded-full object-cover border border-border shrink-0" />
                        <div v-else class="w-7 h-7 rounded-full bg-gradient-to-br from-primary to-accent-hover flex items-center justify-center text-xs font-bold text-primary-foreground shrink-0">
                            {{ getInitials(authUser?.name) }}
                        </div>
                        <span class="hidden lg:inline text-sm text-muted-foreground font-medium">{{ authUser?.name?.split(' ')[0] }}</span>
                        <ChevronDown class="w-3 h-3 text-muted-foreground" />
                    </button>

                    <div id="user-menu-dropdown" v-if="showUserMenu" class="absolute right-0 top-12 w-56 rounded-2xl border border-border bg-card shadow-2xl p-2 animate-scale-in z-50">
                        <div class="px-3 py-2 border-b border-border mb-2">
                            <p class="text-sm font-bold text-foreground">{{ authUser?.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ authUser?.email }}</p>
                            <span class="inline-block mt-2 text-[10px] px-2 py-0.5 rounded capitalize font-bold"
                                :class="authUser?.role === 'admin' ? 'bg-primary/20 text-primary' : (authUser?.role === 'manager' ? 'bg-info/20 text-info' : 'bg-success/20 text-success')">
                                {{ authUser?.role }}
                            </span>
                        </div>
                        <Link :href="route('profile.edit')" class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-muted/40 rounded-lg transition-colors">
                            <User class="w-4 h-4" /> Profile
                        </Link>
                        <button @click="logout" class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-error hover:bg-muted/40 rounded-lg transition-colors">
                            <LogOut class="w-4 h-4" /> Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Stacked notifications (hover to expand) ─────────────────────── -->
        <div
            v-if="toasts.length"
            class="fixed top-20 right-4 z-[200] w-[88vw] max-w-xs"
            @mouseenter="toastHovered = true"
            @mouseleave="toastHovered = false"
        >
            <div class="relative">
                <!-- Back cards to mimic the layered design when collapsed -->
                <div
                    v-if="!toastHovered && toasts.length > 1"
                    class="pointer-events-none absolute inset-x-2 top-2 h-12 rounded-2xl border border-white/50 bg-white/55 shadow-lg backdrop-blur-md"
                ></div>
                <div
                    v-if="!toastHovered && toasts.length > 2"
                    class="pointer-events-none absolute inset-x-4 top-4 h-12 rounded-2xl border border-white/45 bg-white/45 shadow-md backdrop-blur-md"
                ></div>

                <TransitionGroup
                    name="toast-stack"
                    tag="div"
                    class="relative space-y-2"
                >
                    <div
                        v-for="toast in visibleToasts"
                        :key="toast.id"
                        class="relative flex items-start gap-2 rounded-xl border px-3 py-2.5 shadow-xl backdrop-blur-md"
                        :class="getToastConfig(toast.type).card"
                    >
                        <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="getToastConfig(toast.type).iconWrap">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="getToastConfig(toast.type).icon" />
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1 pr-5">
                            <p class="text-[10px] font-extrabold uppercase tracking-[0.14em] opacity-85">{{ getToastConfig(toast.type).title }}</p>
                            <p class="mt-0.5 text-xs font-semibold leading-4">{{ toast.message }}</p>
                        </div>

                        <button
                            @click="dismissToast(toast.id)"
                            class="absolute right-2.5 top-2.5 rounded-full p-1 text-current/60 transition hover:bg-black/10 hover:text-current"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </TransitionGroup>
            </div>
        </div>
        <!-- ─────────────────────────────────────────────────────────────────── -->

        <!-- Main Content area -->
        <main class="flex-1 flex flex-col pt-16 pb-16 md:pb-0 transition-all duration-300" :class="collapsed ? 'md:pl-16' : 'md:pl-56'">
            <div
                class="relative flex-1 w-full max-w-full overflow-y-auto"
                :style="{ '--layout-content-left': collapsed ? '4rem' : '14rem' }"
            >
                <div class="p-4 lg:p-6">
                    <!-- Page Heading (Optional, can be used for secondary headers) -->
                    <header v-if="$slots.header" class="mb-4 hidden md:block">
                        <slot name="header" />
                    </header>
                    <slot />
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="mt-auto p-3 lg:px-6 border-t border-border text-center text-xs text-muted-foreground transition-all duration-300" :class="collapsed ? 'md:ml-16' : 'md:ml-56'">
            <p>&copy; 2026 <b style="color:#303030;">Cypherox Technologies</b>. All Rights Reserved.</p>
        </footer>
    </div>
</template>

<style>
.rounded-pill {
    border-radius: 9999px;
}
.pb-safe {
    padding-bottom: env(safe-area-inset-bottom);
}

.toast-stack-enter-active,
.toast-stack-leave-active,
.toast-stack-move {
    transition: all 220ms ease;
}

.toast-stack-enter-from,
.toast-stack-leave-to {
    opacity: 0;
    transform: translateY(-8px) scale(0.98);
}
</style>
