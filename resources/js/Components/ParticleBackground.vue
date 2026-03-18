<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const canvasRef = ref(null);
let animationId = null;
let cleanup = null;

onMounted(() => {
    const canvas = canvasRef.value;
    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const pointer = { x: 0, y: 0 };
    const state = {
        width: window.innerWidth,
        height: window.innerHeight,
        dpr: Math.min(window.devicePixelRatio || 1, 2),
        time: 0,
    };

    const primaryToken = getComputedStyle(document.documentElement)
        .getPropertyValue('--primary')
        .trim() || '14 100% 56%';
    const particleColor = `hsl(${primaryToken})`;

    const particleCount = Math.max(36, Math.min(88, Math.floor((state.width * state.height) / 26000)));
    const particles = [];

    for (let i = 0; i < particleCount; i++) {
        const depth = Math.random() * 0.8 + 0.2;
        particles.push({
            x: Math.random() * state.width,
            y: Math.random() * state.height,
            radius: Math.random() * 2.2 + 0.6,
            velocityX: (Math.random() - 0.5) * (0.22 + depth * 0.38),
            velocityY: (Math.random() - 0.5) * (0.22 + depth * 0.38),
            pulse: Math.random() * Math.PI * 2,
            opacity: Math.random() * 0.42 + 0.16,
            depth,
        });
    }

    const resize = () => {
        state.width = window.innerWidth;
        state.height = window.innerHeight;
        state.dpr = Math.min(window.devicePixelRatio || 1, 2);

        canvas.width = Math.floor(state.width * state.dpr);
        canvas.height = Math.floor(state.height * state.dpr);
        canvas.style.width = `${state.width}px`;
        canvas.style.height = `${state.height}px`;

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.scale(state.dpr, state.dpr);
    };

    const onPointerMove = (event) => {
        pointer.x = event.clientX;
        pointer.y = event.clientY;
    };

    resize();
    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', onPointerMove, { passive: true });

    const drawSweep = (time) => {
        const sweepAngle = time * 0.15;
        const centerX = state.width * 0.5;
        const centerY = state.height * 0.25;
        const sweepX = centerX + Math.cos(sweepAngle) * state.width * 0.4;
        const sweepY = centerY + Math.sin(sweepAngle) * state.height * 0.3;
        const gradient = ctx.createRadialGradient(
            sweepX,
            sweepY,
            0,
            centerX,
            centerY,
            Math.max(state.width, state.height) * 0.65,
        );

        gradient.addColorStop(0, 'hsla(14, 100%, 56%, 0.06)');
        gradient.addColorStop(0.4, 'hsla(14, 100%, 56%, 0.025)');
        gradient.addColorStop(1, 'hsla(14, 100%, 56%, 0)');

        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, state.width, state.height);
    };

    const drawOrbitalRings = (time) => {
        const orbitX = state.width * 0.5 + Math.sin(time * 0.45) * 26;
        const orbitY = state.height * 0.42 + Math.cos(time * 0.32) * 16;
        const baseRadius = Math.min(state.width, state.height) * 0.2;

        for (let ring = 0; ring < 3; ring++) {
            ctx.beginPath();
            ctx.ellipse(
                orbitX,
                orbitY,
                baseRadius + ring * 42,
                baseRadius * 0.34 + ring * 10,
                time * 0.06 + ring * 0.28,
                0,
                Math.PI * 2,
            );
            ctx.strokeStyle = `hsla(14, 100%, 56%, ${0.08 - ring * 0.018})`;
            ctx.lineWidth = 1;
            ctx.stroke();
        }
    };

    const animate = () => {
        state.time += reducedMotion ? 0.003 : 0.011;
        ctx.clearRect(0, 0, state.width, state.height);

        drawSweep(state.time);
        drawOrbitalRings(state.time);

        const linkDistance = Math.min(190, Math.max(110, state.width * 0.12));

        for (let i = 0; i < particles.length; i++) {
            const particle = particles[i];
            particle.pulse += reducedMotion ? 0.01 : 0.026;

            const pointerDx = pointer.x - particle.x;
            const pointerDy = pointer.y - particle.y;
            const pointerDistance = Math.hypot(pointerDx, pointerDy);

            if (pointerDistance > 0 && pointerDistance < 180) {
                const influence = (180 - pointerDistance) / 180;
                particle.x -= (pointerDx / pointerDistance) * influence * 0.28;
                particle.y -= (pointerDy / pointerDistance) * influence * 0.28;
            }

            particle.x += particle.velocityX * particle.depth;
            particle.y += particle.velocityY * particle.depth;

            if (particle.x < -10) particle.x = state.width + 10;
            if (particle.x > state.width + 10) particle.x = -10;
            if (particle.y < -10) particle.y = state.height + 10;
            if (particle.y > state.height + 10) particle.y = -10;

            const flicker = 0.45 + Math.sin(particle.pulse) * 0.55;
            const alpha = Math.min(0.85, particle.opacity * flicker * 1.35);

            ctx.beginPath();
            ctx.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
            ctx.fillStyle = particleColor.replace('hsl(', 'hsla(').replace(')', `, ${Math.max(0.22, alpha)})`);
            ctx.fill();

            ctx.beginPath();
            ctx.arc(particle.x, particle.y, particle.radius * 4.5, 0, Math.PI * 2);
            ctx.fillStyle = particleColor.replace('hsl(', 'hsla(').replace(')', `, ${alpha * 0.16})`);
            ctx.fill();

            for (let j = i + 1; j < particles.length; j++) {
                const other = particles[j];
                const dx = particle.x - other.x;
                const dy = particle.y - other.y;
                const distance = Math.hypot(dx, dy);

                if (distance < linkDistance) {
                    const lineAlpha = ((linkDistance - distance) / linkDistance) * 0.26;
                    ctx.beginPath();
                    ctx.moveTo(particle.x, particle.y);
                    ctx.lineTo(other.x, other.y);
                    ctx.strokeStyle = `hsla(14, 100%, 56%, ${lineAlpha})`;
                    ctx.lineWidth = 1;
                    ctx.stroke();
                }
            }
        }

        animationId = requestAnimationFrame(animate);
    };

    animate();

    cleanup = () => {
        if (animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
        }
        window.removeEventListener('resize', resize);
        window.removeEventListener('mousemove', onPointerMove);
    };
});

onUnmounted(() => {
    if (cleanup) {
        cleanup();
        cleanup = null;
    }
});
</script>

<template>
    <canvas
        ref="canvasRef"
        class="fixed inset-0 pointer-events-none particle-canvas"
    ></canvas>
</template>

<style scoped>
.particle-canvas {
    opacity: 1;
    filter: saturate(1.16) contrast(1.1);
    animation: particle-canvas-breathe 8s ease-in-out infinite;
}

@keyframes particle-canvas-breathe {
    0%,
    100% {
        opacity: 0.82;
    }
    50% {
        opacity: 0.94;
    }
}
</style>
