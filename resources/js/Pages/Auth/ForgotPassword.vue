<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Mail, ArrowLeft } from 'lucide-vue-next';
import ParticleBackground from '@/Components/ParticleBackground.vue';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
const theme = ref('dark');

onMounted(() => {
    // Reinforce dark theme on public pages (no AuthenticatedLayout present)
    const saved = localStorage.getItem('theme');
    theme.value = saved === 'light' ? 'light' : 'dark';
    document.documentElement.classList.remove('dark', 'light');
    document.documentElement.classList.add(theme.value);
});
</script>

<template>
    <Head title="Forgot Password" />

    <div class="min-h-screen flex items-center justify-center relative overflow-hidden bg-background text-foreground font-sans">
        <ParticleBackground />
        <div class="public-perspective-grid" />

        <div class="w-full max-w-md mx-4 animate-fade-in-up">
            <div class="public-panel p-8 relative">
                <Link :href="route('login')" class="absolute top-8 left-8 text-muted-foreground hover:text-foreground transition-colors p-2 -m-2 opacity-80 hover:opacity-100">
                    <ArrowLeft class="w-5 h-5" />
                </Link>

                <div class="text-center mb-8 flex flex-col items-center">
                    <img :src="theme === 'dark' ? '/images/cx-logo-light.svg' : '/images/cx-logo-dark.svg'" alt="Cypherox Technologies" class="h-6 mb-3 mt-2" />
                    <h2 class="font-bold text-xl mb-2">Reset Password</h2>
                    <p class="text-sm text-muted-foreground font-medium px-4">
                        Enter your email address and we'll send you a link to reset your password.
                    </p>
                </div>

                <div v-if="status" class="mb-6 p-3 rounded-lg bg-success/10 border border-success/20 text-sm font-medium text-success text-center">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="relative">
                        <Mail class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <input
                            type="email" 
                            placeholder="Email address" 
                            v-model="form.email"
                            required
                            autofocus
                            class="public-form-control w-full h-11 pl-10 pr-4 rounded-pill text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans"
                        />
                        <p v-if="form.errors.email" class="text-xs text-error mt-1 pl-3">{{ form.errors.email }}</p>
                    </div>

                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="flex justify-center items-center w-full h-11 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 disabled:opacity-50 mt-6"
                    >
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Send Reset Link
                    </button>
                </form>

                <p class="text-center text-sm text-muted-foreground mt-8 font-medium">
                    Remember your password? 
                    <Link :href="route('login')" class="text-primary hover:underline font-bold">Sign In</Link>
                </p>
            </div>
        </div>
    </div>
</template>

<style>
.rounded-pill { border-radius: 9999px; }
.animate-fade-in-up {
    animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
