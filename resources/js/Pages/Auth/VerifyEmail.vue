<script setup>
import { computed, ref, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
import ParticleBackground from '@/Components/ParticleBackground.vue';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);

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
    <Head title="Email Verification" />

    <div class="min-h-screen flex items-center justify-center relative overflow-hidden bg-background text-foreground font-sans">
        <ParticleBackground />
        <div class="public-perspective-grid" />

        <div class="w-full max-w-md mx-4 animate-fade-in-up">
            <div class="public-panel p-8">
                <div class="text-center mb-8 flex flex-col items-center">
                    <img :src="theme === 'dark' ? '/images/cx-logo-light.svg' : '/images/cx-logo-dark.svg'" alt="Cypherox Technologies" class="h-6 mb-3" />
                    <h2 class="font-bold text-xl mb-2">Verify your email</h2>
                    <p class="text-sm text-muted-foreground font-medium px-4">
                        Thanks for signing up! Before getting started, could you verify
                        your email address by clicking on the link we just emailed to
                        you? If you didn't receive the email, we will gladly send you another.
                    </p>
                </div>

                <div v-if="verificationLinkSent" class="mb-6 p-3 rounded-lg bg-success/10 border border-success/20 text-sm font-medium text-success text-center">
                    A new verification link has been sent to the email address you
                    provided during registration.
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="flex justify-center items-center gap-2 w-full h-11 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 disabled:opacity-50 mt-4"
                    >
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <Mail v-if="!form.processing" class="w-4 h-4" /> Resend Verification Email
                    </button>
                    
                    <div class="text-center mt-6">
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="text-sm text-error hover:underline font-bold"
                        >
                            Log Out
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style>
.rounded-pill { border-radius: 9999px; }
.animate-fade-in-up { animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
