<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import debounce from 'lodash/debounce';
import { getInitials } from '@/utils/initials';

const props = defineProps({
    users: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');
const searchInputKey = ref(0);
const roleFilter = ref(props.filters.role || 'all');
const showModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const userToDelete = ref(null);

const roleTabs = [
    { value: 'all', label: 'All' },
    { value: 'admin', label: 'Admin' },
    { value: 'manager', label: 'Manager' },
    { value: 'member', label: 'Member' },
];

const form = useForm({
    id: null,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'member',
    location: '',
});

watch([search, roleFilter], debounce(() => {
    router.get(route('users.index'), {
        search: search.value,
        role: roleFilter.value,
    }, { preserveState: true, replace: true });
}, 300));

const roleBadgeClass = (role) => {
    if (role === 'admin') return 'bg-primary text-white';
    if (role === 'manager') return 'bg-info text-white';

    return 'bg-success text-white';
};

const locationBadgeClass = (location) => {
    if (location === 'Ahmedabad') return 'bg-primary/10 text-primary';
    if (location === 'Rajkot') return 'bg-info/10 text-info';

    return 'bg-bg-elevated text-muted-foreground';
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(value));
};

const paginationSummary = computed(() => {
    const from = props.users.from || 0;
    const to = props.users.to || 0;
    const total = props.users.total || 0;

    return `Showing ${from} to ${to} of ${total} entries`;
});

const preserveSearchField = () => {
    const currentSearch = search.value;

    window.setTimeout(() => {
        search.value = currentSearch;
        searchInputKey.value += 1;
    }, 0);
};

const openCreateModal = (event) => {
    event?.preventDefault?.();
    event?.stopPropagation?.();

    isEditing.value = false;
    form.reset();
    form.clearErrors();
    form.role = 'member';
    form.location = 'Ahmedabad';
    showModal.value = true;
    preserveSearchField();
};

const openEditModal = (event, user) => {
    event?.preventDefault?.();
    event?.stopPropagation?.();

    isEditing.value = true;
    form.reset();
    form.clearErrors();
    form.id = user.id;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.location = user.location;
    showModal.value = true;
    preserveSearchField();
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
};

const submit = () => {
    if (isEditing.value) {
        form.put(route('users.update', form.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const confirmDelete = (user) => {
    userToDelete.value = user;
    showDeleteModal.value = true;
};

const deleteUser = () => {
    if (userToDelete.value) {
        router.delete(route('users.destroy', userToDelete.value.id), {
            onSuccess: () => {
                showDeleteModal.value = false;
                userToDelete.value = null;
            },
        });
    }
};

</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <div class="animate-fade-in text-foreground space-y-6">

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="font-heading font-bold text-4xl text-foreground tracking-tight">User Management</h1>
                    <p class="text-sm text-muted-foreground mt-1">Manage team members, roles, and account access</p>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="openCreateModal($event)" class="flex items-center gap-2 h-11 px-6 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-2px] transition-all whitespace-nowrap">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add User
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-[1.75rem] border border-border bg-bg-card shadow-sm animate-fade-in-up">
                <div class="flex flex-col gap-4 border-b border-border bg-bg-card px-4 py-4 md:flex-row md:items-center md:justify-between md:px-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="tab in roleTabs"
                            :key="tab.value"
                            type="button"
                            @click="roleFilter = tab.value"
                            class="inline-flex h-9 items-center rounded-full px-4 text-sm font-semibold transition-all"
                            :class="roleFilter === tab.value ? 'bg-primary text-white shadow-sm' : 'bg-bg-elevated text-muted-foreground hover:bg-bg-hover hover:text-foreground'"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div class="relative w-full md:w-72">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input :key="searchInputKey" v-model="search" type="text" name="users-search" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="Search users..." class="h-11 w-full rounded-full border border-border bg-bg-elevated pl-11 pr-4 text-sm text-foreground shadow-sm transition focus:border-primary focus:ring-1 focus:ring-primary" />
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <div class="min-w-[980px]">
                        <div class="grid grid-cols-[1.45fr_1.6fr_0.95fr_1.15fr_1fr_0.95fr] items-center gap-6 border-b border-border bg-bg-elevated/40 px-6 py-4">
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">User</span>
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Email</span>
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Role</span>
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Location</span>
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Joined</span>
                            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider text-right">Actions</span>
                        </div>

                        <div class="divide-y divide-border">
                            <div v-for="user in users.data" :key="user.id" class="grid grid-cols-[1.45fr_1.6fr_0.95fr_1.15fr_1fr_0.95fr] items-center gap-6 px-6 py-4 transition-colors hover:bg-bg-hover/70">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-accent-hover text-sm font-bold text-white shadow-sm">
                                        {{ getInitials(user.name) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-foreground">{{ user.name }}</p>
                                    </div>
                                </div>

                                <span class="truncate text-sm text-muted-foreground">{{ user.email }}</span>

                                <div>
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold capitalize shadow-sm" :class="roleBadgeClass(user.role)">
                                        {{ user.role }}
                                    </span>
                                </div>

                                <div>
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="locationBadgeClass(user.location)">
                                        {{ user.location || '-' }}
                                    </span>
                                </div>

                                <span class="text-sm text-muted-foreground">{{ formatDate(user.created_at) }}</span>

                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" @click="openEditModal($event, user)" class="flex h-9 w-9 items-center justify-center rounded-full border border-border/80 bg-bg-card/90 text-muted-foreground shadow-sm transition hover:border-primary/30 hover:text-primary hover:bg-bg-elevated">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button type="button" @click="confirmDelete(user)" class="flex h-9 w-9 items-center justify-center rounded-full border border-border/80 bg-bg-card/90 text-muted-foreground shadow-sm transition hover:border-error/30 hover:text-error hover:bg-bg-elevated">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!users.data.length" class="px-6 py-16 text-center">
                    <p class="text-lg font-bold text-foreground">No users found</p>
                    <p class="mt-1 text-sm text-muted-foreground">Try a different search or filter.</p>
                </div>

                <div class="flex flex-col gap-4 border-t border-border px-4 py-4 md:flex-row md:items-center md:justify-between md:px-6">
                    <div class="flex items-center gap-4 text-sm text-muted-foreground">
                        <select class="h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <option selected>10</option>
                        </select>
                        <span>{{ paginationSummary }}</span>
                    </div>

                    <div v-if="users.links.length > 3" class="flex items-center gap-1">
                        <template v-for="(link, key) in users.links" :key="key">
                            <div v-if="link.url === null" class="flex h-9 min-w-9 items-center justify-center rounded-full px-3 text-sm text-muted-foreground opacity-50" v-html="link.label"></div>
                            <Link
                                v-else
                                :href="link.url"
                                class="flex h-9 min-w-9 items-center justify-center rounded-full px-3 text-sm transition-colors"
                                :class="link.active ? 'bg-primary/15 text-primary border border-primary/25' : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground'"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>

            <!-- Create/Edit Modal -->
            <Modal :show="showModal" @close="closeModal" max-width="md" contained>
                <div class="p-8 bg-bg-card border border-border rounded-xl">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                        </div>
                        <h2 class="text-xl font-bold text-foreground">
                            {{ isEditing ? 'Edit User' : 'Add New User' }}
                        </h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Name</label>
                            <input v-model="form.name" type="text" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" required autofocus />
                            <p v-if="form.errors.name" class="text-error text-xs mt-1">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Email</label>
                            <input v-model="form.email" type="email" name="user-email" autocomplete="off" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" required />
                            <p v-if="form.errors.email" class="text-error text-xs mt-1">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Role</label>
                            <select v-model="form.role" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner uppercase tracking-wide font-medium text-xs">
                                <option value="member">Member</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                            </select>
                            <p v-if="form.errors.role" class="text-error text-xs mt-1">{{ form.errors.role }}</p>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Location</label>
                            <select v-model="form.location" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner uppercase tracking-wide font-medium text-xs appearance-none">
                                <option value="" disabled>Select Location...</option>
                                <option value="Ahmedabad">Ahmedabad</option>
                                <option value="Rajkot">Rajkot</option>
                            </select>
                            <p v-if="form.errors.location" class="text-error text-xs mt-1">{{ form.errors.location }}</p>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Password</label>
                            <input v-model="form.password" type="password" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" :required="!isEditing" />
                            <p v-if="form.errors.password" class="text-error text-xs mt-1">{{ form.errors.password }}</p>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2 block">Confirm Password</label>
                            <input v-model="form.password_confirmation" type="password" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" :required="!isEditing" />
                        </div>
                    </div>

                    <div class="mt-8 flex justify-end gap-3">
                        <button type="button" @click="closeModal" class="h-11 px-6 rounded-pill text-sm font-bold text-foreground hover:bg-bg-hover transition-all">Cancel</button>
                        <button type="button" @click="submit" :disabled="form.processing" class="flex items-center gap-2 h-11 px-8 rounded-pill bg-primary text-primary-foreground text-sm font-bold shadow-lg shadow-primary/20 hover:scale-105 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            {{ isEditing ? 'Update' : 'Create' }}
                        </button>
                    </div>
                </div>
            </Modal>

            <!-- Delete Confirmation Modal -->
            <Modal :show="showDeleteModal" @close="showDeleteModal = false" max-width="sm" contained>
                <div class="p-6 bg-bg-card border border-border rounded-xl">
                    <h2 class="text-lg font-bold text-foreground">Delete User</h2>

                    <p class="mt-2 text-sm text-muted-foreground">
                        Are you sure you want to delete <span class="font-bold text-foreground">"{{ userToDelete?.name }}"</span>? This action cannot be undone.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button @click="showDeleteModal = false" class="px-4 py-2 rounded-md text-sm font-bold text-foreground hover:bg-bg-hover transition-colors">Cancel</button>
                        <button @click="deleteUser" class="px-4 py-2 rounded-md text-sm font-bold bg-error text-white hover:bg-red-600 transition-colors shadow-sm">Delete</button>
                    </div>
                </div>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
