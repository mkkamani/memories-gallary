<script setup>
import InputError from '@/Components/InputError.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    role: String(user.role || 'member').toLowerCase(),
    location: user.location || 'Rajkot',
});

const roleLabel = computed(() => {
    const role = String(user.role || 'member').toLowerCase();

    if (role === 'admin') return 'Admin';
    if (role === 'manager') return 'Manager';

    return 'Member';
});

const canEditRole = computed(() => ['admin', 'manager'].includes(String(user.role || '').toLowerCase()));
</script>

<template>
    <section>
        <header class="pb-4 border-b border-border/80">
            <h2 class="text-2xl font-heading font-bold text-foreground flex items-center gap-2">
                <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Personal Details
            </h2>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-5"
        >
            <div>
                <label for="name" class="block mb-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground">Full Name</label>
                <input
                    id="name"
                    type="text"
                    class="w-full h-12 rounded-xl border border-border bg-bg-secondary px-4 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <label for="email" class="block mb-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground">Email Address</label>
                <input
                    id="email"
                    type="email"
                    class="w-full h-12 rounded-xl border border-border bg-bg-secondary px-4 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <label class="block mb-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground">Location</label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition"
                        :class="form.location === 'Ahmedabad' ? 'border-primary/40 bg-primary/5' : 'border-border bg-bg-secondary hover:border-primary/25'"
                    >
                        <input
                            v-model="form.location"
                            type="radio"
                            value="Ahmedabad"
                            class="h-4 w-4 border-border text-primary focus:ring-primary"
                        />
                        <span class="text-sm font-semibold text-foreground">Ahmedabad</span>
                    </label>

                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition"
                        :class="form.location === 'Rajkot' ? 'border-primary/40 bg-primary/5' : 'border-border bg-bg-secondary hover:border-primary/25'"
                    >
                        <input
                            v-model="form.location"
                            type="radio"
                            value="Rajkot"
                            class="h-4 w-4 border-border text-primary focus:ring-primary"
                        />
                        <span class="text-sm font-semibold text-foreground">Rajkot</span>
                    </label>
                </div>

                <InputError class="mt-2" :message="form.errors.location" />
            </div>

            <div v-if="canEditRole">
                <label for="role" class="block mb-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground">Role</label>
                <select
                    id="role"
                    v-model="form.role"
                    class="w-full h-12 rounded-xl border border-border bg-bg-secondary px-4 text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary"
                >
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="member">Member</option>
                </select>

                <InputError class="mt-2" :message="form.errors.role" />
            </div>

            <div v-else>
                <label class="block mb-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground">Role</label>
                <input
                    type="text"
                    :value="roleLabel"
                    readonly
                    class="w-full h-12 rounded-xl border border-border bg-bg-secondary px-4 text-foreground/80"
                />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-primary underline hover:text-accent-hover focus:outline-none"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-success"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="pt-6 mt-4 border-t border-border/80 flex items-center justify-end gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="h-11 px-8 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground text-sm font-bold shadow-md hover:shadow-primary/25 transition-all disabled:opacity-60"
                >
                    Save Changes
                </button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-success"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
