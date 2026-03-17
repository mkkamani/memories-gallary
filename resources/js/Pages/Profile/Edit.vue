<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const initials = computed(() => {
    return (user?.name || 'User')
        .split(' ')
        .map((n) => n[0])
        .join('')
        .substring(0, 2)
        .toUpperCase();
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <div class="space-y-8">
            <div>
                <h1 class="text-4xl font-heading font-bold text-foreground">Account Settings</h1>
                <p class="mt-1 text-sm text-muted-foreground">Manage your profile information and security preferences</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                <section class="profile-shell xl:col-span-3 p-6">
                    <div class="flex flex-col items-center text-center">
                        <div class="relative mb-5">
                            <div class="w-32 h-32 rounded-full bg-primary/15 border-2 border-white shadow-md flex items-center justify-center">
                                <span class="text-5xl font-extrabold text-primary">{{ initials }}</span>
                            </div>
                            <button class="absolute -right-1 bottom-2 w-9 h-9 rounded-full bg-gradient-to-r from-primary to-accent-hover text-white shadow-md border-2 border-white flex items-center justify-center" type="button" aria-label="Change avatar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h2l1-1h8l1 1h2a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16a3 3 0 100-6 3 3 0 000 6z"/></svg>
                            </button>
                        </div>

                        <h2 class="text-3xl font-bold text-foreground">{{ user.name }}</h2>
                        <p class="text-sm text-muted-foreground mt-1">{{ user.email }}</p>

                        <span class="mt-4 inline-flex rounded-pill px-3 py-1 text-xs font-bold uppercase tracking-wide bg-primary/15 text-primary border border-primary/30">
                            {{ user.role || 'Member' }}
                        </span>
                    </div>
                </section>

                <section class="profile-shell xl:col-span-5 p-6">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                    />
                </section>

                <section class="profile-shell xl:col-span-4 p-6">
                    <UpdatePasswordForm />
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.profile-shell {
    background: hsl(var(--card));
    border: 1px solid hsl(var(--border));
    border-radius: 1.1rem;
    box-shadow: 0 1px 2px hsl(220 15% 20% / 0.04);
}
</style>
