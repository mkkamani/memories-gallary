<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import debounce from 'lodash/debounce';
import { getInitials } from '@/utils/initials';

const props = defineProps({
    users: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');
const showModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const userToDelete = ref(null);

const form = useForm({
    id: null,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'member',
    location: '',
});

watch(search, debounce((value) => {
    router.get(route('users.index'), { search: value }, { preserveState: true, replace: true });
}, 300));

const openCreateModal = () => {
    isEditing.value = false;
    form.reset();
    form.clearErrors();
    form.role = 'member';
    form.location = 'Ahmedabad';
    showModal.value = true;
};

const openEditModal = (user) => {
    isEditing.value = true;
    form.reset();
    form.clearErrors();
    form.id = user.id;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.location = user.location;
    showModal.value = true;
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
                    <h1 class="font-heading font-bold text-3xl text-foreground">Users Management</h1>
                    <p class="text-sm text-muted-foreground mt-1">Manage team members and their roles</p>
                </div>
                <div class="flex items-center gap-3">
                    <input v-model="search" type="text" placeholder="Search users by name or email..." class="h-11 bg-bg-input border-border text-foreground rounded-pill shadow-sm focus:border-primary focus:ring-1 focus:ring-primary w-full md:w-80 px-4 text-sm" />
                    <button @click="openCreateModal" class="flex items-center gap-2 h-11 px-6 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-2px] transition-all whitespace-nowrap">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add User
                    </button>
                </div>
            </div>

            <div class="bg-bg-card border border-border rounded-2xl overflow-hidden shadow-sm animate-fade-in-up">
                <div class="grid grid-cols-[1fr_200px_100px_100px_100px] items-center px-6 py-4 border-b border-border bg-bg-elevated/50">
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Name</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Email</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Location</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Role</span>
                    <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider text-right">Actions</span>
                </div>

                <div class="divide-y divide-border">
                    <div v-for="user in users.data" :key="user.id" class="grid grid-cols-[1fr_200px_100px_100px_100px] items-center px-6 py-4 hover:bg-bg-hover transition-colors">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-sm font-bold text-primary shrink-0">
                                {{ getInitials(user.name) }}
                            </div>
                            <span class="text-sm font-bold text-foreground truncate">{{ user.name }}</span>
                        </div>
                        <span class="text-sm text-muted-foreground truncate pr-4">{{ user.email }}</span>
                        <span class="text-sm font-medium pr-4">{{ user.location || '-' }}</span>
                        <div>
                             <span class="text-[10px] px-2 py-0.5 rounded capitalize font-bold"
                                   :class="user.role === 'admin' ? 'bg-primary/20 text-primary' : (user.role === 'manager' ? 'bg-info/20 text-info' : 'bg-success/20 text-success')">
                                 {{ user.role }}
                             </span>
                        </div>
                        <div class="flex items-center justify-end gap-2 text-right">
                             <button @click="openEditModal(user)" class="p-2 rounded-full hover:bg-bg-elevated text-muted-foreground hover:text-primary transition-all">
                                 <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                             </button>
                             <button @click="confirmDelete(user)" class="p-2 rounded-full hover:bg-bg-elevated text-muted-foreground hover:text-error transition-all">
                                 <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                             </button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="px-6 py-4 border-t border-border flex justify-end" v-if="users.links.length > 3">
                     <div class="flex gap-1">
                         <template v-for="(link, key) in users.links" :key="key">
                             <div v-if="link.url === null" class="h-8 px-3 flex items-center justify-center text-xs text-muted-foreground border border-border rounded-md bg-bg-elevated/50" v-html="link.label"></div>
                             <Link v-else :href="link.url" class="h-8 px-3 flex items-center justify-center text-xs border rounded-md hover:bg-bg-hover transition-colors" :class="link.active ? 'bg-primary border-primary text-primary-foreground' : 'border-border text-foreground'" v-html="link.label" />
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
                            <input v-model="form.email" type="email" class="w-full h-11 px-4 rounded-xl bg-bg-elevated border border-border text-sm text-foreground focus:outline-none focus:border-primary transition-all shadow-inner" required />
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
