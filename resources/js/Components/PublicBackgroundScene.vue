<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const canvasRef = ref(null);
let resizeHandler;
let mouseHandler;
let rafCanvas;

onMounted(() => {
    const cv = canvasRef.value;
    if (!cv) return;
    const ct = cv.getContext('2d');
    if (!ct) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let W = 0;
    let H = 0;
    let mx = window.innerWidth / 2;
    let my = window.innerHeight / 2;

    const resize = () => {
        W = cv.width = window.innerWidth;
        H = cv.height = window.innerHeight;
    };

    const hexes = [];
    const buildHex = () => {
        hexes.length = 0;
        const sz = 72;
        for (let r = 0; r < Math.ceil(H / sz) + 2; r++) {
            for (let c = 0; c < Math.ceil(W / sz) + 2; c++) {
                hexes.push({ x: (c + (r % 2) * 0.5) * sz, y: r * sz * 0.866 });
            }
        }
    };

    const hexP = (x, y, s) => {
        ct.beginPath();
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 3) * i - Math.PI / 6;
            const px = x + s * Math.cos(a);
            const py = y + s * Math.sin(a);
            if (i === 0) ct.moveTo(px, py);
            else ct.lineTo(px, py);
        }
        ct.closePath();
    };

    const EM = 75;
    const em = [];
    for (let i = 0; i < EM; i++) {
        em.push({
            x: Math.random(),
            y: 0.2 + Math.random() * 0.9,
            vx: (Math.random() - 0.5) * 0.0005,
            vy: -(0.0002 + Math.random() * 0.0005),
            r: 0.4 + Math.random() * 2,
            a: 0,
            maxA: 0.25 + Math.random() * 0.5,
            life: Math.random(),
            sp: 0.003 + Math.random() * 0.007,
        });
    }

    const stars = Array.from({ length: 160 }, () => ({
        x: Math.random(),
        y: Math.random(),
        r: Math.random() * 1.1,
        a: 0.05 + Math.random() * 0.25,
        fl: Math.random() * Math.PI * 2,
        fs: 0.004 + Math.random() * 0.009,
    }));

    let t = 0;
    const draw = () => {
        t += reducedMotion ? 0 : 0.008;
        ct.clearRect(0, 0, W, H);

        ct.fillStyle = '#0a0404';
        ct.fillRect(0, 0, W, H);

        ct.strokeStyle = 'rgba(200,30,15,.045)';
        ct.lineWidth = 0.7;
        hexes.forEach((h) => {
            hexP(h.x, h.y, 34);
            ct.stroke();
        });

        const mg = ct.createRadialGradient(mx, my, 0, mx, my, 320);
        mg.addColorStop(0, 'rgba(200,30,15,.06)');
        mg.addColorStop(1, 'rgba(0,0,0,0)');
        ct.fillStyle = mg;
        ct.fillRect(0, 0, W, H);

        if (!reducedMotion) {
            stars.forEach((s) => {
                s.fl += s.fs;
                const fa = s.a * (0.4 + 0.6 * Math.sin(s.fl));
                ct.beginPath();
                ct.arc(s.x * W, s.y * H, s.r, 0, Math.PI * 2);
                ct.fillStyle = `rgba(245,235,235,${fa})`;
                ct.fill();
            });

            em.forEach((e) => {
                e.life += e.sp;
                if (e.life > 1) {
                    e.life = 0;
                    e.x = 0.2 + Math.random() * 0.6;
                    e.y = 1;
                    e.a = 0;
                }
                e.x += e.vx;
                e.y += e.vy;
                e.a =
                    e.life < 0.12
                        ? (e.life / 0.12) * e.maxA
                        : e.life > 0.75
                            ? ((1 - e.life) / 0.25) * e.maxA
                            : e.maxA;

                const g = ct.createRadialGradient(e.x * W, e.y * H, 0, e.x * W, e.y * H, e.r * 5);
                g.addColorStop(0, `rgba(224,60,20,${e.a})`);
                g.addColorStop(0.5, `rgba(200,30,10,${e.a * 0.4})`);
                g.addColorStop(1, 'rgba(0,0,0,0)');
                ct.fillStyle = g;
                ct.beginPath();
                ct.arc(e.x * W, e.y * H, e.r * 5, 0, Math.PI * 2);
                ct.fill();
            });
        }

        rafCanvas = requestAnimationFrame(draw);
    };

    mouseHandler = (e) => {
        mx = e.clientX;
        my = e.clientY;
    };

    resizeHandler = () => {
        resize();
        buildHex();
    };

    resize();
    buildHex();
    draw();

    window.addEventListener('resize', resizeHandler);
    window.addEventListener('mousemove', mouseHandler, { passive: true });
});

onBeforeUnmount(() => {
    if (resizeHandler) window.removeEventListener('resize', resizeHandler);
    if (mouseHandler) window.removeEventListener('mousemove', mouseHandler);
    if (rafCanvas) cancelAnimationFrame(rafCanvas);
});
</script>

<template>
    <div class="auth-bg-scene" aria-hidden="true">
        <canvas ref="canvasRef" class="auth-bg-canvas"></canvas>
        <div class="auth-bg-noise"></div>
        <div class="auth-bg-vig"></div>
    </div>
</template>

<style scoped>
.auth-bg-scene {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
}

.auth-bg-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    display: block;
    z-index: 0;
}

.auth-bg-noise {
    position: absolute;
    inset: -80px;
    opacity: 0.03;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 400 400' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='400' height='400' filter='url(%23n)'/%3E%3C/svg%3E");
    animation: auth-noise-shift 0.4s steps(2) infinite;
    pointer-events: none;
    z-index: 1;
}

.auth-bg-vig {
    position: absolute;
    inset: 0;
    z-index: 2;
    background: radial-gradient(ellipse 75% 75% at 50% 50%, transparent 20%, rgba(10, 4, 4, 0.85) 100%);
    pointer-events: none;
}

@keyframes auth-noise-shift {
    0%   { transform: translate(0, 0); }
    50%  { transform: translate(-2%, 2%); }
    100% { transform: translate(1%, -1%); }
}

@media (prefers-reduced-motion: reduce) {
    .auth-bg-noise {
        animation: none;
    }
}
</style>
