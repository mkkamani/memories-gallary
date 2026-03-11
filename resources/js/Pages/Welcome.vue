<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ParticleBackground from '@/Components/ParticleBackground.vue';

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
    <Head title="Welcome to CypherFrame" />
    
    <div class="min-h-screen bg-background text-foreground selection:bg-primary/20 selection:text-primary overflow-hidden font-sans relative flex flex-col items-center justify-center">
        <!-- Backgrounds -->
        <ParticleBackground />
        <div class="perspective-grid" />
        
        <!-- Navbar -->
        <nav class="absolute top-0 w-full z-50 px-6 py-6 flex justify-between items-center max-w-7xl mx-auto left-0 right-0">
            <div class="flex items-center">
                <img src="/images/cx-logo-light.svg" alt="CypherFrame Logo" class="h-6 logo-themed filter invert dark:filter-none" />
            </div>
            
            <div class="flex items-center gap-4">
                <template v-if="$page.props.auth.user">
                    <Link
                        :href="route('dashboard')"
                        class="text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Dashboard
                    </Link>
                </template>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="h-9 px-4 rounded-full bg-primary text-primary-foreground font-semibold text-sm flex items-center justify-center shadow-md hover:scale-105 transition-all"
                    >
                        Register
                    </Link>
                </template>
            </div>
        </nav>

        <!-- Hero / Banner Section -->
        <main class="relative z-10 w-full max-w-3xl mx-4 text-center">
            <div class="glass-card-static p-10 sm:p-14 animate-fade-in-up">
                <h1 class="text-4xl md:text-6xl font-extrabold tracking-tight mb-6 leading-tight font-heading">
                    <span class="block">Your Company Memories,</span>
                    <span class="block text-primary drop-shadow-[0_0_15px_hsla(var(--primary),0.3)] mt-2">
                        Safely Organized
                    </span>
                </h1>
                
                <p class="mt-4 text-base md:text-lg text-muted-foreground max-w-xl mx-auto mb-10 font-medium z-10 relative">
                    Securely store, beautifully organize, and forever cherish the memories that define your journey. Experience the future of digital asset management today.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center relative z-20">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="w-full sm:w-auto px-8 h-12 flex items-center justify-center rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-[15px] shadow-[0_4px_24px_hsla(var(--primary),0.2)] hover:translate-y-[-2px] hover:shadow-[0_0_50px_hsla(var(--primary),0.4)] transition-all duration-300"
                    >
                        Go to your Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="w-full sm:w-auto px-8 h-12 flex items-center justify-center rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-[15px] shadow-[0_4px_24px_hsla(var(--primary),0.2)] hover:translate-y-[-2px] hover:shadow-[0_0_50px_hsla(var(--primary),0.4)] transition-all duration-300"
                    >
                        Sign In to Your Gallery
                    </Link>
                </div>
            </div>
        </main>
        
        <footer class="absolute bottom-6 w-full text-center z-10">
            <p class="text-xs text-muted-foreground">© 2026 CypherFrame. Laravel v{{ laravelVersion }} (PHP v{{ phpVersion }})</p>
        </footer>
    </div>
</template>

<style>
.rounded-pill { border-radius: 9999px; }
.glass-card-static {
    background: hsla(220, 40%, 15%, 0.4);
    backdrop-filter: blur(24px);
    border: 1px solid hsla(220, 20%, 30%, 0.3);
    border-radius: 1.5rem;
    box-shadow: inset 0 0 0 1px hsla(0, 0%, 100%, 0.05),
                0 10px 40px -10px rgba(0, 0, 0, 0.5);
}
.perspective-grid {
    position: absolute;
    inset: -50%;
    background-image: 
        linear-gradient(to right, hsla(220, 20%, 50%, 0.05) 1px, transparent 1px),
        linear-gradient(to bottom, hsla(220, 20%, 50%, 0.05) 1px, transparent 1px);
    background-size: 50px 50px;
    transform: perspective(1000px) rotateX(60deg) translateY(-100px) translateZ(-200px);
    animation: gridMove 20s linear infinite;
    pointer-events: none;
    z-index: 1;
}
@keyframes gridMove {
    0% { transform: perspective(1000px) rotateX(60deg) translateY(0) translateZ(-200px); }
    100% { transform: perspective(1000px) rotateX(60deg) translateY(50px) translateZ(-200px); }
}
.animate-fade-in-up {
    animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.light .glass-card-static {
    background: hsla(0, 0%, 100%, 0.6);
    border: 1px solid hsla(220, 20%, 80%, 0.6);
    box-shadow: inset 0 0 0 1px hsla(0, 0%, 100%, 0.5), 0 10px 40px -10px rgba(0, 0, 0, 0.1);
}
.light .perspective-grid {
    background-image: linear-gradient(to right, hsla(220, 20%, 50%, 0.15) 1px, transparent 1px), linear-gradient(to bottom, hsla(220, 20%, 50%, 0.15) 1px, transparent 1px);
}
</style>
