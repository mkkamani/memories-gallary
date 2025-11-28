<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Welcome to CX Memories" />
    
    <div class="min-h-screen bg-black text-white selection:bg-brand-red selection:text-white overflow-hidden font-sans">
        <!-- Navbar -->
        <nav class="absolute top-0 w-full z-50 px-6 py-6 flex justify-between items-center max-w-7xl mx-auto left-0 right-0">
            <div class="text-2xl font-bold tracking-tighter">
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-red-500 via-rose-500 to-red-600">CX</span> Memories
            </div>
            <div v-if="canLogin" class="flex gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="px-4 py-2 rounded-full text-sm font-medium transition-all duration-300 hover:bg-white/10"
                >
                    Dashboard
                </Link>

                <template v-else>
                    <Link
                        :href="route('login')"
                        class="px-4 py-2 rounded-full text-sm font-medium transition-all duration-300 hover:bg-white/10"
                    >
                        Log in
                    </Link>

                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="px-4 py-2 rounded-full bg-white text-black text-sm font-medium transition-all duration-300 hover:bg-gray-200 hover:scale-105"
                    >
                        Register
                    </Link>
                </template>
            </div>
        </nav>

        <!-- Hero / Banner Section -->
        <main class="relative flex items-center justify-center min-h-screen px-6 overflow-hidden">
            <!-- Background Elements -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute top-[-20%] left-[-10%] w-[60%] h-[60%] rounded-full bg-gradient-to-br from-red-600/20 to-transparent blur-[120px] animate-float"></div>
                <div class="absolute bottom-[-20%] right-[-10%] w-[60%] h-[60%] rounded-full bg-gradient-to-tl from-rose-900/30 to-transparent blur-[120px] animate-float delay-2000"></div>
                <div class="absolute top-[30%] left-[20%] w-[40%] h-[40%] rounded-full bg-orange-600/10 blur-[100px] animate-pulse-slow"></div>
                <!-- Moving Shadows -->
                <div class="absolute top-[10%] right-[20%] w-[30%] h-[30%] rounded-full bg-black/80 blur-[80px] animate-float delay-1000"></div>
                <div class="absolute bottom-[10%] left-[20%] w-[35%] h-[35%] rounded-full bg-red-950/40 blur-[90px] animate-float delay-3000"></div>
            </div>

            <div class="relative z-10 max-w-5xl mx-auto text-center">
                <h1 class="text-5xl md:text-7xl lg:text-8xl font-extrabold tracking-tight mb-8 leading-tight drop-shadow-2xl">
                    <span class="block animate-fade-in-up opacity-0" style="animation-delay: 0.1s;">Relive Your</span>
                    <span class="block text-transparent bg-clip-text bg-gradient-to-r from-orange-400 via-red-500 to-rose-600 animate-fade-in-up opacity-0 filter drop-shadow-lg" style="animation-delay: 0.3s;">
                        Timeless Moments
                    </span>
                </h1>
                
                <p class="mt-6 text-lg md:text-xl text-gray-400 max-w-2xl mx-auto mb-10 animate-fade-in-up opacity-0" style="animation-delay: 0.5s;">
                    Securely store, beautifully organize, and forever cherish the memories that define your journey. Experience the future of digital nostalgia.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center animate-fade-in-up opacity-0" style="animation-delay: 0.7s;">
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="px-8 py-4 rounded-full bg-gradient-to-r from-red-600 via-rose-600 to-red-800 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(225,29,72,0.6)] hover:scale-105 ring-1 ring-white/10"
                    >
                        Start Your Gallery
                    </Link>
                    <button class="px-8 py-4 rounded-full border border-white/20 text-white font-medium text-lg transition-all duration-300 hover:bg-white/10 backdrop-blur-sm hover:shadow-lg">
                        Explore Features
                    </button>
                </div>
            </div>
            
            <!-- Scroll Indicator -->
            <div class="absolute bottom-10 left-1/2 transform -translate-x-1/2 animate-bounce opacity-50">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                </svg>
            </div>
        </main>
    </div>
</template>

<style>
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in-up {
    animation: fadeInUp 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
}

@keyframes pulseSlow {
    0%, 100% {
        opacity: 0.4;
        transform: scale(1);
    }
    50% {
        opacity: 0.7;
        transform: scale(1.1);
    }
}

.animate-pulse-slow {
    animation: pulseSlow 6s ease-in-out infinite;
}

@keyframes float {
    0% {
        transform: translate(0px, 0px) scale(1);
    }
    33% {
        transform: translate(30px, -50px) scale(1.1);
    }
    66% {
        transform: translate(-20px, 20px) scale(0.9);
    }
    100% {
        transform: translate(0px, 0px) scale(1);
    }
}

.animate-float {
    animation: float 15s ease-in-out infinite;
}

.delay-1000 {
    animation-delay: 1s;
}

.delay-2000 {
    animation-delay: 2s;
}

.delay-3000 {
    animation-delay: 3s;
}
</style>
