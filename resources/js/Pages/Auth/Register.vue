<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock, User } from 'lucide-vue-next';
import ParticleBackground from '@/Components/ParticleBackground.vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    location: '',
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
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
    <Head title="Register" />

    <div class="min-h-screen flex items-center justify-center relative overflow-hidden bg-background text-foreground font-sans">
        <ParticleBackground />
        <div class="public-perspective-grid" />

        <div class="w-full max-w-md mx-4 animate-fade-in-up">
            <div class="public-panel p-8">
                <div class="text-center mb-8 flex flex-col items-center">
                    <img :src="theme === 'dark' ? '/images/cx-logo-light.svg' : '/images/cx-logo-dark.svg'" alt="Cypherox Technologies" class="h-6 mb-3" />
                    <p class="text-sm text-muted-foreground font-medium">Join our secure memory organization</p>
                </div>

                <form @submit.prevent="submit" class="space-y-4 text-sm">
                    <div class="relative">
                        <User class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <input
                            type="text" 
                            placeholder="Full Name" 
                            v-model="form.name"
                            required
                            autofocus
                            class="public-form-control w-full h-11 pl-10 pr-4 rounded-pill text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans"
                        />
                        <p v-if="form.errors.name" class="text-xs text-error mt-1 pl-3">{{ form.errors.name }}</p>
                    </div>

                    <div class="relative">
                        <Mail class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <input
                            type="email" 
                            placeholder="Email address" 
                            v-model="form.email"
                            required
                            class="public-form-control w-full h-11 pl-10 pr-4 rounded-pill text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans"
                        />
                        <p v-if="form.errors.email" class="text-xs text-error mt-1 pl-3">{{ form.errors.email }}</p>
                    </div>

                    <div class="relative">
                        <select 
                            v-model="form.location" 
                            required
                            class="public-form-control w-full h-11 px-4 rounded-pill text-sm text-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans appearance-none"
                            :class="!form.location ? 'text-muted-foreground' : 'text-foreground'"
                        >
                            <option value="" disabled>Select Location</option>
                            <option value="Ahmedabad" class="text-foreground">Ahmedabad</option>
                            <option value="Rajkot" class="text-foreground">Rajkot</option>
                        </select>
                        <p v-if="form.errors.location" class="text-xs text-error mt-1 pl-3">{{ form.errors.location }}</p>
                    </div>

                    <div class="relative">
                        <Lock class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <input
                            :type="showPassword ? 'text' : 'password'" 
                            placeholder="Password" 
                            v-model="form.password"
                            required
                            class="public-form-control w-full h-11 pl-10 pr-10 rounded-pill text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans"
                        />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <EyeOff v-if="showPassword" class="w-4 h-4" />
                            <Eye v-else class="w-4 h-4" />
                        </button>
                        <p v-if="form.errors.password" class="text-xs text-error mt-1 pl-3">{{ form.errors.password }}</p>
                    </div>

                    <div class="relative">
                        <Lock class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <input
                            :type="showPassword ? 'text' : 'password'" 
                            placeholder="Confirm Password" 
                            v-model="form.password_confirmation"
                            required
                            class="public-form-control w-full h-11 pl-10 pr-10 rounded-pill text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:shadow-[0_0_15px_hsla(var(--primary),0.15)] transition-all font-sans"
                        />
                        <p v-if="form.errors.password_confirmation" class="text-xs text-error mt-1 pl-3">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="flex justify-center items-center w-full h-11 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 disabled:opacity-50 mt-6"
                    >
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Create Account
                    </button>
                </form>

                <p class="text-center text-sm text-muted-foreground mt-6 font-medium">
                    Already have an account? 
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
