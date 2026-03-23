<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicBackgroundScene from '@/Components/PublicBackgroundScene.vue';

defineProps({
    canLogin: { type: Boolean },
});

onMounted(() => {
    // Landing page is always forced dark for the cinematic look
    document.documentElement.classList.remove('dark', 'light');
    document.documentElement.classList.add('dark');
});

const scrollDown = () => {
    window.scrollTo({ top: window.innerHeight, behavior: 'smooth' });
};

const features = [
    {
        label: 'Smart Albums',
        desc: 'Auto-organized by date & event',
        svg: 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
    },
    {
        label: 'Role-based Access',
        desc: 'Admin, manager & member roles',
        svg: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
        label: 'Bulk ZIP Import',
        desc: 'Upload entire archives at once',
        svg: 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12',
    },
    {
        label: 'Secure Storage',
        desc: 'End-to-end protected media',
        svg: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    },
];
</script>

<template>
    <Head title="Welcome to Cypherox Technologies Memories" />

    <div class="min-h-screen bg-[#0a0a0a] text-white overflow-hidden font-sans relative flex flex-col isolate">
        <PublicBackgroundScene />

        <!-- Extra warm radial glow behind hero text (reduced brightness) -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden z-0">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-[50%] w-[800px] h-[600px] rounded-full bg-primary/[0.03] blur-[150px]"></div>
        </div>



        <!-- ── Navbar ─────────────────────────────────── -->
        <nav class="relative z-20 w-full px-8 py-6 flex justify-between items-center max-w-7xl mx-auto">
            <div class="flex items-center">
                <img
                    src='/images/cx-logo-light.svg'
                    alt="Cypherox Technologies Logo"
                    class="h-6"
                    onerror="this.onerror=null;this.src='/images/cx-logo-dark.svg'"
                />
            </div>

            <div class="flex items-center gap-2">
                <template v-if="$page.props.auth.user">
                    <Link :href="route('dashboard')"
                        class="text-sm font-medium text-white/60 hover:text-white transition-colors px-4 py-2 rounded-full hover:bg-white/5">
                        Dashboard
                    </Link>
                </template>
                <template v-else>
                    <Link :href="route('login')"
                        class="text-sm font-medium text-white/60 hover:text-white transition-colors px-5 py-2 rounded-full hover:bg-white/8">
                        Log in
                    </Link>
                </template>
            </div>
        </nav>

        <!-- ── Hero ──────────────────────────────────── -->
        <main class="relative z-10 flex-1 flex flex-col items-center justify-center text-center px-6 pt-20 pb-16 mt-8">

            <!-- Eyebrow badge -->
            <div class="hero-eyebrow inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-white/10 bg-white/[0.02] text-white/50 text-[11px] font-semibold mb-8 tracking-[0.18em] uppercase backdrop-blur-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>
                Digital Asset Management
            </div>

            <!-- Main heading -->
            <h1 class="hero-heading font-extrabold tracking-tight leading-[1.06] font-heading mb-6 max-w-4xl">
                <span class="block text-white text-5xl sm:text-6xl md:text-[74px] lg:text-[86px]">Relive Your</span>
                <span class="block text-5xl sm:text-6xl md:text-[74px] lg:text-[86px] bg-gradient-to-r from-primary via-orange-400 to-red-500 bg-clip-text text-transparent mt-1">
                    Timeless Moments
                </span>
            </h1>

            <!-- Sub text -->
            <p class="hero-sub text-[15px] md:text-lg text-white/45 max-w-lg mx-auto mb-12 leading-relaxed">
                Securely store, beautifully organize, and forever cherish the memories that
                define your journey. Experience the future of digital nostalgia.
            </p>

            <!-- CTAs -->
            <div class="hero-cta flex flex-col sm:flex-row gap-4 justify-center items-center mb-20">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('albums.index')"
                    class="cta-primary h-[52px] px-9 flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-primary to-primary-dark outline outline-1 outline-primary/30 text-white font-bold text-[15px] shadow-[0_4px_20px_hsla(14,100%,56%,0.2)] hover:shadow-[0_6px_28px_hsla(14,100%,56%,0.3)] hover:scale-[1.03] active:scale-[0.97] transition-all duration-200"
                >
                    Open My Gallery
                </Link>
                <Link
                    v-else
                    :href="route('login')"
                    class="cta-primary h-[52px] px-9 flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-primary to-primary-dark outline outline-1 outline-primary/30 text-white font-bold text-[15px] shadow-[0_4px_20px_hsla(14,100%,56%,0.2)] hover:shadow-[0_6px_28px_hsla(14,100%,56%,0.3)] hover:scale-[1.03] active:scale-[0.97] transition-all duration-200"
                >
                    Start Your Gallery
                </Link>

                <a href="#features"
                    class="cta-secondary h-[52px] px-9 flex items-center justify-center gap-2 rounded-full border border-white/15 text-white/75 font-semibold text-[15px] bg-white/[0.04] backdrop-blur-sm hover:bg-white/[0.09] hover:border-white/25 hover:scale-[1.04] active:scale-[0.97] transition-all duration-200">
                    Explore Features
                </a>
            </div>

            <!-- Scroll indicator -->
            <button @click="scrollDown" class="hero-scroll flex flex-col items-center gap-1.5 text-white/25 hover:text-white/50 transition-colors">
                <svg class="w-5 h-5 animate-bounce-slow" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </main>

        <!-- ── Features strip ─────────────────────────────────── -->
        <section id="features" class="relative z-10 border-t border-white/[0.06] bg-white/[0.015]">
            <div class="max-w-4xl mx-auto px-6 py-12 grid grid-cols-2 md:grid-cols-4 gap-8">
                <div v-for="feat in features" :key="feat.label" class="features-item flex flex-col items-center text-center gap-3 group">
                    <div class="w-11 h-11 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary group-hover:bg-primary/20 group-hover:scale-110 transition-all duration-200">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="feat.svg"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-white/80">{{ feat.label }}</p>
                        <p class="text-xs text-white/35 mt-0.5 leading-relaxed">{{ feat.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Footer ─────────────────────────────────── -->
        <footer class="relative z-10 w-full text-center py-5 border-t border-white/[0.05]">
            <p class="text-xs text-white/20">
                &copy; 2026 <span class="text-white/35 font-semibold">Cypherox Technologies</span>. All Rights Reserved.
            </p>
        </footer>
    </div>
</template>

<style scoped>
/* ── Staggered entrance animations ─────────────────────────────── */
.hero-eyebrow { animation: heroUp 0.7s 0.05s cubic-bezier(0.16,1,0.3,1) both; }
.hero-heading { animation: heroUp 0.85s 0.18s cubic-bezier(0.16,1,0.3,1) both; }
.hero-sub     { animation: heroUp 0.85s 0.32s cubic-bezier(0.16,1,0.3,1) both; }
.hero-cta     { animation: heroUp 0.85s 0.46s cubic-bezier(0.16,1,0.3,1) both; }
.hero-scroll  { animation: heroUp 0.85s 0.62s cubic-bezier(0.16,1,0.3,1) both; }
.features-item { animation: heroUp 0.7s 0.1s cubic-bezier(0.16,1,0.3,1) both; }

@keyframes heroUp {
    from { opacity: 0; transform: translateY(30px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes bounceSlow {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(6px); }
}

.animate-bounce-slow { animation: bounceSlow 2s ease-in-out infinite; }

.cta-primary { letter-spacing: -0.01em; }
.cta-secondary { letter-spacing: -0.01em; }

@media (prefers-reduced-motion: reduce) {
    .hero-eyebrow, .hero-heading, .hero-sub,
    .hero-cta, .hero-scroll, .features-item,
    .animate-bounce-slow { animation: none; }
}
</style>
