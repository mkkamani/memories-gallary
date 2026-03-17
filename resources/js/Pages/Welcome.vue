<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ParticleBackground from '@/Components/ParticleBackground.vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});

onMounted(() => {
    // Reinforce dark theme on public pages (no AuthenticatedLayout present)
    const saved = localStorage.getItem('theme');
    const theme = saved === 'light' ? 'light' : 'dark';
    document.documentElement.classList.remove('dark', 'light');
    document.documentElement.classList.add(theme);
});
</script>

<template>
    <Head title="Welcome to CypherFrame" />

    <div class="min-h-screen bg-background text-foreground selection:bg-primary/20 selection:text-primary overflow-hidden font-sans relative flex flex-col items-center justify-center">

        <!-- Particle animation layer -->
        <ParticleBackground />

        <!-- Subtle perspective grid -->
        <div class="perspective-grid" />

        <!-- Radial glow behind the card -->
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center z-0">
            <div class="w-[600px] h-[600px] rounded-full bg-primary/5 blur-[120px]"></div>
        </div>

        <!-- ── Navbar ─────────────────────────────────── -->
        <nav class="absolute top-0 w-full z-50 px-6 py-5 flex justify-between items-center max-w-7xl mx-auto left-0 right-0">
            <div class="flex items-center">
                <img
                    src="/images/cx-logo-dark.svg"
                    alt="CypherFrame Logo"
                    class="h-6"
                    onerror="this.onerror=null;this.src='/images/cx-logo-light.svg'"
                />
            </div>

            <div class="flex items-center gap-3">
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
                        class="text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors px-4 py-2 rounded-full hover:bg-white/5"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="h-9 px-5 rounded-full bg-primary text-primary-foreground font-semibold text-sm flex items-center justify-center shadow-lg shadow-primary/20 hover:scale-105 hover:shadow-primary/30 transition-all"
                    >
                        Register
                    </Link>
                </template>
            </div>
        </nav>

        <!-- ── Hero ──────────────────────────────────── -->
        <main class="relative z-10 w-full max-w-2xl mx-4 text-center">
            <div class="hero-card p-10 sm:p-14 animate-fade-in-up">

                <!-- Pill badge -->
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-primary text-xs font-bold mb-8 tracking-widest uppercase">
                    <span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>
                    Digital Asset Management
                </div>

                <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight leading-tight font-heading mb-6">
                    <span class="block text-foreground">Your Company Memories,</span>
                    <span class="block mt-2 bg-gradient-to-r from-primary via-orange-400 to-primary bg-clip-text text-transparent drop-shadow-[0_0_20px_hsla(14,100%,56%,0.35)]">
                        Safely Organized
                    </span>
                </h1>

                <p class="text-base md:text-lg text-muted-foreground max-w-lg mx-auto mb-10 leading-relaxed">
                    Securely store, beautifully organize, and forever cherish the memories
                    that define your journey. Experience the future of digital asset management today.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="w-full sm:w-auto px-8 h-12 flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-primary to-orange-400 text-primary-foreground font-bold text-[15px] shadow-[0_4px_24px_hsla(14,100%,56%,0.3)] hover:translate-y-[-2px] hover:shadow-[0_8px_40px_hsla(14,100%,56%,0.45)] transition-all duration-300"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        Go to Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="w-full sm:w-auto px-8 h-12 flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-primary to-orange-400 text-primary-foreground font-bold text-[15px] shadow-[0_4px_24px_hsla(14,100%,56%,0.3)] hover:translate-y-[-2px] hover:shadow-[0_8px_40px_hsla(14,100%,56%,0.45)] transition-all duration-300"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                        Sign In to Your Gallery
                    </Link>

                    <Link
                        v-if="!$page.props.auth.user && canRegister"
                        :href="route('register')"
                        class="w-full sm:w-auto px-8 h-12 flex items-center justify-center gap-2 rounded-full border border-border text-foreground font-bold text-[15px] hover:bg-white/5 hover:border-primary/40 hover:translate-y-[-2px] transition-all duration-300"
                    >
                        Create an Account
                    </Link>
                </div>

                <!-- Feature pills -->
                <div class="flex flex-wrap items-center justify-center gap-3 mt-10 pt-8 border-t border-white/5">
                    <span v-for="feat in ['Cloudflare R2 Storage', 'Role-based Access', 'ZIP Import', 'Smart Albums']" :key="feat"
                          class="flex items-center gap-1.5 text-xs text-muted-foreground px-3 py-1.5 rounded-full bg-white/[0.04] border border-white/[0.08]">
                        <svg class="w-3 h-3 text-primary" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        {{ feat }}
                    </span>
                </div>
            </div>
        </main>

        <!-- ── Footer ─────────────────────────────────── -->
        <footer class="absolute bottom-5 w-full text-center z-10">
            <p class="text-xs text-muted-foreground/50">
                © 2026 CypherFrame &nbsp;·&nbsp; Laravel v{{ laravelVersion }} &nbsp;·&nbsp; PHP v{{ phpVersion }}
            </p>
        </footer>
    </div>
</template>

<style scoped>
/* ── Hero card ──────────────────────────────────────────── */
.hero-card {
    background: hsla(240, 20%, 8%, 0.6);
    backdrop-filter: blur(32px);
    -webkit-backdrop-filter: blur(32px);
    border: 1px solid hsla(240, 20%, 30%, 0.2);
    border-radius: 1.75rem;
    box-shadow:
        inset 0 1px 0 hsla(0, 0%, 100%, 0.06),
        0 24px 64px -12px rgba(0, 0, 0, 0.7),
        0 0 0 1px hsla(0, 0%, 0%, 0.3);
}

/* Light-mode override (when user explicitly switched to light) */
:global(.light) .hero-card {
    background: hsla(0, 0%, 100%, 0.75);
    border: 1px solid hsla(220, 20%, 80%, 0.5);
    box-shadow:
        inset 0 1px 0 hsla(0, 0%, 100%, 0.8),
        0 24px 64px -12px rgba(0, 0, 0, 0.12);
}

/* ── Perspective grid ───────────────────────────────────── */
.perspective-grid {
    position: absolute;
    inset: -50%;
    background-image:
        linear-gradient(to right, hsla(220, 20%, 50%, 0.045) 1px, transparent 1px),
        linear-gradient(to bottom, hsla(220, 20%, 50%, 0.045) 1px, transparent 1px);
    background-size: 50px 50px;
    transform: perspective(1000px) rotateX(60deg) translateY(-100px) translateZ(-200px);
    animation: gridMove 24s linear infinite;
    pointer-events: none;
    z-index: 1;
}

:global(.light) .perspective-grid {
    background-image:
        linear-gradient(to right, hsla(220, 20%, 40%, 0.12) 1px, transparent 1px),
        linear-gradient(to bottom, hsla(220, 20%, 40%, 0.12) 1px, transparent 1px);
}

@keyframes gridMove {
    0%   { transform: perspective(1000px) rotateX(60deg) translateY(0)     translateZ(-200px); }
    100% { transform: perspective(1000px) rotateX(60deg) translateY(50px)  translateZ(-200px); }
}

/* ── Entrance animation ─────────────────────────────────── */
.animate-fade-in-up {
    animation: fadeInUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(32px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0)    scale(1);    }
}
</style>
