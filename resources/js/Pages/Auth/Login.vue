<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock } from 'lucide-vue-next';
import PublicBackgroundScene from '@/Components/PublicBackgroundScene.vue';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const theme = ref('light');

onMounted(() => {
    // Apply saved theme and default to light across public pages.
    const saved = localStorage.getItem('theme');
    theme.value = saved === 'dark' ? 'dark' : 'light';
    document.documentElement.classList.remove('dark', 'light');
    document.documentElement.classList.add(theme.value);
});
</script>

<template>
    <Head title="Log in" />

    <div class="min-h-screen flex items-center justify-center relative overflow-hidden bg-background text-foreground font-sans isolate">
        <PublicBackgroundScene />

        <!-- Card -->
        <div class="w-full max-w-md mx-4 animate-fade-in-up">
            <div class="public-panel p-8 sm:p-10">

                <!-- Logo + tagline -->
                <div class="text-center mb-8 flex flex-col items-center">
                    <img
                        :src="theme === 'dark' ? '/images/cx-logo-light.svg' : '/images/cx-logo-dark.svg'"
                        alt="Cypherox Technologies"
                        class="h-7 mb-4"
                        onerror="this.onerror=null;this.src='/images/cx-logo-dark.svg'"
                    />
                    <p class="text-sm text-muted-foreground font-medium">
                        Your Company Memories, Safely Organized
                    </p>
                </div>

                <!-- Status message -->
                <div v-if="status" class="mb-5 flex items-center gap-2 px-4 py-3 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm font-medium">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ status }}
                </div>

                <!-- Form -->
                <form @submit.prevent="submit" class="space-y-4">

                    <!-- Email -->
                    <div class="space-y-1.5">
                        <div class="relative">
                            <Mail class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
                            <input
                                type="email"
                                placeholder="Email address"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="email"
                                class="public-form-control w-full h-11 pl-10 pr-4 rounded-xl text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_0_3px_hsla(var(--primary),0.12)] transition-all"
                            />
                        </div>
                        <p v-if="form.errors.email" class="text-xs text-red-400 pl-1 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Password -->
                    <div class="space-y-1.5">
                        <div class="relative">
                            <Lock class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                placeholder="Password"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                                class="public-form-control w-full h-11 pl-10 pr-11 rounded-xl text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_0_3px_hsla(var(--primary),0.12)] transition-all"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                                tabindex="-1"
                            >
                                <EyeOff v-if="showPassword" class="w-4 h-4" />
                                <Eye v-else class="w-4 h-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="text-xs text-red-400 pl-1 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Remember + Forgot -->
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2 text-sm text-muted-foreground cursor-pointer select-none hover:text-foreground transition-colors">
                            <input
                                type="checkbox"
                                v-model="form.remember"
                                class="w-4 h-4 rounded accent-primary cursor-pointer"
                            />
                            Remember me
                        </label>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-sm text-primary hover:text-orange-400 font-semibold transition-colors"
                        >
                            Forgot password?
                        </Link>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="relative w-full h-11 mt-2 rounded-xl bg-gradient-to-r from-primary to-orange-400 text-primary-foreground font-bold text-sm shadow-lg shadow-primary/25 hover:shadow-primary/40 hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100 transition-all duration-200 overflow-hidden"
                    >
                        <span v-if="!form.processing" class="flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            Sign In
                        </span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            Signing in…
                        </span>
                    </button>
                </form>

            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Entrance animation ─────────────────────────────────── */
.animate-fade-in-up {
    animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(24px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0)    scale(1);    }
}
</style>
