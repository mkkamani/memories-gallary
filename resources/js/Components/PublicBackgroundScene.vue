<script setup>
import ParticleBackground from '@/Components/ParticleBackground.vue';
</script>

<template>
    <div class="public-background-scene" aria-hidden="true">
        <ParticleBackground />
        <div class="public-background-vignette"></div>
        <div class="public-background-amber public-background-amber-left"></div>
        <div class="public-background-amber public-background-amber-right"></div>
        <div class="public-background-core"></div>
        <div class="public-tunnel-grid public-tunnel-grid-top"></div>
        <div class="public-tunnel-grid public-tunnel-grid-bottom"></div>
        <div class="public-background-haze"></div>
    </div>
</template>

<style scoped>
.public-background-scene {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
    background:
        radial-gradient(circle at center, hsla(14, 100%, 56%, 0.08), transparent 28%),
        linear-gradient(180deg, hsl(18 60% 5%) 0%, hsl(16 62% 3%) 48%, hsl(18 68% 4%) 100%);
}

.public-background-vignette {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, transparent 22%, hsla(0, 0%, 0%, 0.52) 100%);
}

.public-background-amber {
    position: absolute;
    width: 42vw;
    height: 42vw;
    border-radius: 9999px;
    filter: blur(90px);
    opacity: 0.16;
    background: radial-gradient(circle, hsla(14, 100%, 56%, 0.4) 0%, hsla(14, 100%, 56%, 0) 70%);
    animation: amberDrift 16s ease-in-out infinite;
}

.public-background-amber-left {
    top: -18vh;
    left: -8vw;
}

.public-background-amber-right {
    right: -12vw;
    bottom: 6vh;
    width: 36vw;
    height: 36vw;
    opacity: 0.12;
    animation-duration: 20s;
    animation-direction: reverse;
}

.public-background-core {
    position: absolute;
    inset: 20% 26%;
    border-radius: 9999px;
    background: radial-gradient(circle, hsla(14, 100%, 56%, 0.14) 0%, hsla(14, 100%, 56%, 0.05) 22%, transparent 68%);
    filter: blur(70px);
    animation: corePulse 8s ease-in-out infinite;
}

.public-background-haze {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, hsla(14, 100%, 56%, 0.05), transparent 18%, transparent 82%, hsla(14, 100%, 56%, 0.05)),
        linear-gradient(90deg, hsla(14, 100%, 56%, 0.04), transparent 22%, transparent 78%, hsla(14, 100%, 56%, 0.04));
}

.public-tunnel-grid {
    position: absolute;
    left: -18%;
    width: 136%;
    height: 42%;
    opacity: 0.58;
    background-image:
        linear-gradient(to right, hsl(var(--grid-line) / 0.25) 1px, transparent 1px),
        linear-gradient(to bottom, hsl(var(--grid-line) / 0.25) 1px, transparent 1px);
    background-size: 56px 56px;
}

.public-tunnel-grid-top {
    top: -10%;
    transform-origin: top center;
    transform: perspective(1100px) rotateX(-72deg) translateY(-8%);
    animation: tunnelTopMove 18s linear infinite;
}

.public-tunnel-grid-bottom {
    bottom: -14%;
    transform-origin: bottom center;
    transform: perspective(1100px) rotateX(72deg) translateY(8%);
    animation: tunnelBottomMove 18s linear infinite;
}

@keyframes tunnelTopMove {
    from {
        background-position: 0 0, 0 0;
    }
    to {
        background-position: 56px 0, 0 56px;
    }
}

@keyframes tunnelBottomMove {
    from {
        background-position: 0 0, 0 0;
    }
    to {
        background-position: -56px 0, 0 -56px;
    }
}

@keyframes amberDrift {
    0%,
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }
    50% {
        transform: translate3d(4vw, 3vh, 0) scale(1.08);
    }
}

@keyframes corePulse {
    0%,
    100% {
        opacity: 0.72;
        transform: scale(0.96);
    }
    50% {
        opacity: 1;
        transform: scale(1.04);
    }
}

@media (max-width: 768px) {
    .public-tunnel-grid {
        height: 34%;
        background-size: 34px 34px;
    }

    .public-background-amber {
        width: 58vw;
        height: 58vw;
        filter: blur(68px);
    }

    .public-background-core {
        inset: 28% 14%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .public-background-amber,
    .public-background-core,
    .public-tunnel-grid-top,
    .public-tunnel-grid-bottom {
        animation: none !important;
    }
}
</style>
