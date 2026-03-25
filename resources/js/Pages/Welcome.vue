<script setup>
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const canvasRef = ref(null);
const cursorRef = ref(null);
const cursorRingRef = ref(null);
const reelTrackRef = ref(null);
const holesTopRef = ref(null);
const holesBottomRef = ref(null);
const pathRef = ref(null);
const flashRef = ref(null);

let resizeHandler;
let mouseHandler;
let rafCursor;
let rafCanvas;

onMounted(() => {
    const cv = canvasRef.value;
    const ct = cv?.getContext('2d');
    const cursor = cursorRef.value;
    const cursorRing = cursorRingRef.value;
    const reelTrack = reelTrackRef.value;
    const holesTop = holesTopRef.value;
    const holesBottom = holesBottomRef.value;
    const path = pathRef.value;
    const flash = flashRef.value;

    if (!cv || !ct || !cursor || !cursorRing || !reelTrack || !holesTop || !holesBottom || !path || !flash) {
        return;
    }

    const spawnSparks = () => {
        const charSvg = document.getElementById('char-svg');
        if (!charSvg) return;
        const rect = charSvg.getBoundingClientRect();
        const cx = rect.left + rect.width * 0.6;
        const cy = rect.top + rect.height * 0.38;
        for (let i = 0; i < 30; i++) {
            const sp = document.createElement('div');
            sp.className = 'cam-spark';
            const angle = Math.random() * Math.PI * 2;
            const dist = 40 + Math.random() * 120;
            sp.style.setProperty('--tx', Math.cos(angle) * dist + 'px');
            sp.style.setProperty('--ty', Math.sin(angle) * dist + 'px');
            sp.style.setProperty('--sd', (0.4 + Math.random() * 0.5) + 's');
            sp.style.left = (cx + (Math.random() - 0.5) * 16) + 'px';
            sp.style.top = (cy + (Math.random() - 0.5) * 16) + 'px';
            const cols = ['#ffcc50', '#ffaa30', '#ff7820', '#ffffff', '#ffe090'];
            sp.style.background = cols[Math.floor(Math.random() * cols.length)];
            const sz = 2 + Math.random() * 3;
            sp.style.width = sp.style.height = sz + 'px';
            document.body.appendChild(sp);
            setTimeout(() => sp.remove(), 1200);
        }
    };

    const fireFlash = () => {
        let shot = 0;
        const dur = [160, 100, 70];
        const peak = [0.42, 0.28, 0.18];
        const doShot = () => {
            flash.style.transition = `opacity ${dur[shot] * 0.3}ms ease-out`;
            flash.style.opacity = String(peak[shot]);
            setTimeout(() => {
                flash.style.transition = `opacity ${dur[shot] * 0.6}ms ease-in`;
                flash.style.opacity = '0';
                setTimeout(() => {
                    shot++;
                    if (shot < dur.length) {
                        setTimeout(doShot, shot === 1 ? 90 : 60);
                    } else {
                        setTimeout(spawnSparks, 80);
                    }
                }, dur[shot - 1] * 0.6);
            }, dur[shot] * 0.4);
        };
        doShot();
    };

    const S = 'http://www.w3.org/2000/svg';
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
        t += 0.008;
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
            e.a = e.life < 0.12 ? (e.life / 0.12) * e.maxA : e.life > 0.75 ? ((1 - e.life) / 0.25) * e.maxA : e.maxA;

            const g = ct.createRadialGradient(e.x * W, e.y * H, 0, e.x * W, e.y * H, e.r * 5);
            g.addColorStop(0, `rgba(224,60,20,${e.a})`);
            g.addColorStop(0.5, `rgba(200,30,10,${e.a * 0.4})`);
            g.addColorStop(1, 'rgba(0,0,0,0)');
            ct.fillStyle = g;
            ct.beginPath();
            ct.arc(e.x * W, e.y * H, e.r * 5, 0, Math.PI * 2);
            ct.fill();
        });

        rafCanvas = requestAnimationFrame(draw);
    };

    let rx = 0;
    let ry = 0;
    const animateCursor = () => {
        rx += (mx - rx) * 0.1;
        ry += (my - ry) * 0.1;
        cursorRing.style.left = `${rx}px`;
        cursorRing.style.top = `${ry}px`;
        rafCursor = requestAnimationFrame(animateCursor);
    };

    mouseHandler = (e) => {
        mx = e.clientX;
        my = e.clientY;
        cursor.style.left = `${mx}px`;
        cursor.style.top = `${my}px`;
    };

    const attachHoverListeners = (rootEl) => {
        rootEl.querySelectorAll('a,button,.rframe').forEach((el) => {
            el.addEventListener('mouseenter', () => {
                cursorRing.style.width = '48px';
                cursorRing.style.height = '48px';
            });
            el.addEventListener('mouseleave', () => {
                cursorRing.style.width = '30px';
                cursorRing.style.height = '30px';
            });
        });
    };

    const scenes = [
        {
            bg: '#1a0808',
            yr: '2016',
            label: 'Launch Day',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    if (f) f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: h * 0.55, width: w, height: h * 0.45, fill: '#2a1008' }, sv);
                [[0.2, 0.55], [0.5, 0.5], [0.78, 0.56]].forEach(([x, y]) => {
                    r('ellipse', { cx: x * w, cy: y * h, rx: w * 0.07, ry: h * 0.14, fill: '#3a1808' }, sv);
                    r('ellipse', { cx: x * w, cy: (y - 0.17) * h, rx: w * 0.045, ry: h * 0.065, fill: '#4a2010' }, sv);
                });
                r('circle', { cx: w * 0.7, cy: h * 0.2, r: w * 0.12, fill: 'rgba(224,48,32,.2)' }, sv);
            },
        },
        {
            bg: '#0d1020',
            yr: '2017',
            label: 'Team Offsite',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: h * 0.45, width: w, height: h * 0.55, fill: '#182030' }, sv);
                r('rect', { x: 0, y: 0, width: w, height: h * 0.48, fill: '#0a1828' }, sv);
                for (let i = 0; i < 5; i++) {
                    for (let j = 0; j < 3; j++) {
                        const lit = Math.random() > 0.4;
                        r('rect', {
                            x: w * 0.06 + i * (w * 0.19),
                            y: h * 0.06 + j * (h * 0.14),
                            width: w * 0.12,
                            height: h * 0.1,
                            rx: 1,
                            fill: lit ? 'rgba(255,180,60,.3)' : 'rgba(20,40,80,.5)',
                        }, sv);
                    }
                }
            },
        },
        {
            bg: '#1a0a04',
            yr: '2018',
            label: 'Awards Night',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('path', { d: `M${w * 0.38} ${h * 0.18} Q${w * 0.28} ${h * 0.42} ${w * 0.36} ${h * 0.55} L${w * 0.64} ${h * 0.55} Q${w * 0.72} ${h * 0.42} ${w * 0.62} ${h * 0.18} Z`, fill: 'rgba(200,140,20,.7)' }, sv);
                r('rect', { x: w * 0.44, y: h * 0.55, width: w * 0.12, height: h * 0.1, fill: 'rgba(180,120,15,.7)' }, sv);
                r('rect', { x: w * 0.34, y: h * 0.65, width: w * 0.32, height: h * 0.07, rx: 2, fill: 'rgba(160,100,10,.6)' }, sv);
                r('circle', { cx: w * 0.5, cy: h * 0.32, r: w * 0.09, fill: 'rgba(255,190,40,.35)' }, sv);
                ['\u2605', '\u2605', '\u2605'].forEach((star, i) => {
                    const t2 = document.createElementNS(S, 'text');
                    t2.setAttribute('x', w * (0.15 + i * 0.3));
                    t2.setAttribute('y', h * 0.85);
                    t2.setAttribute('font-size', '10');
                    t2.setAttribute('fill', 'rgba(200,160,30,.6)');
                    t2.textContent = star;
                    sv.appendChild(t2);
                });
            },
        },
        {
            bg: '#080d08',
            yr: '2019',
            label: 'CSR Event',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: 0, width: w, height: h * 0.5, fill: '#0a1a1a' }, sv);
                r('rect', { x: 0, y: h * 0.5, width: w, height: h * 0.5, fill: '#142010' }, sv);
                [[0.15, 0.52, 0.1], [0.52, 0.46, 0.13], [0.82, 0.5, 0.09]].forEach(([x, y, s]) => {
                    r('ellipse', { cx: x * w, cy: y * h, rx: s * w, ry: s * 1.8 * h, fill: '#1e3a14' }, sv);
                    r('rect', { x: (x - 0.01) * w, y: (y + 0.04) * h, width: 0.018 * w, height: 0.22 * h, fill: '#162808' }, sv);
                });
            },
        },
        {
            bg: '#0e0a18',
            yr: '2020',
            label: 'Virtual Summit',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: w * 0.08, y: h * 0.1, width: w * 0.84, height: h * 0.6, rx: 4, fill: 'rgba(30,20,60,.8)', stroke: 'rgba(100,60,200,.3)', 'stroke-width': 1 }, sv);
                for (let i = 0; i < 3; i++) {
                    for (let j = 0; j < 2; j++) {
                        r('rect', {
                            x: w * 0.12 + i * (w * 0.27),
                            y: h * 0.18 + j * (h * 0.25),
                            width: w * 0.22,
                            height: h * 0.2,
                            rx: 3,
                            fill: `rgba(${40 + i * 20},${20 + j * 15},${80 + i * 15},.5)`,
                        }, sv);
                    }
                }
                r('circle', { cx: w * 0.5, cy: h * 0.82, r: 4, fill: 'rgba(224,48,32,.7)' }, sv);
            },
        },
        {
            bg: '#18060a',
            yr: '2021',
            label: "Founder's Day",
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                [[0.1, 0.12, '#e03020'], [0.35, 0.08, '#e08020'], [0.62, 0.14, '#8020e0'], [0.88, 0.1, '#e02080']].forEach(([x, y, c]) => {
                    r('circle', { cx: x * w, cy: y * h, r: 4, fill: c, opacity: 0.8 }, sv);
                    r('line', { x1: x * w, y1: y * h, x2: x * w + (Math.random() - 0.5) * 20, y2: y * h + 30, stroke: c, opacity: 0.3, 'stroke-width': 1 }, sv);
                });
                [[0.22, 0.7], [0.5, 0.65], [0.78, 0.7]].forEach(([x, y]) => {
                    r('ellipse', { cx: x * w, cy: y * h, rx: w * 0.065, ry: h * 0.14, fill: '#2a1020' }, sv);
                    r('ellipse', { cx: x * w, cy: (y - 0.17) * h, rx: w * 0.04, ry: h * 0.065, fill: '#3a1830' }, sv);
                });
            },
        },
        {
            bg: '#0a0c10',
            yr: '2022',
            label: 'Product Launch',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: w * 0.18, y: h * 0.15, width: w * 0.64, height: h * 0.48, rx: 4, fill: 'rgba(15,25,50,.8)', stroke: 'rgba(40,100,200,.25)', 'stroke-width': 1 }, sv);
                for (let i = 0; i < 3; i++) {
                    for (let j = 0; j < 3; j++) {
                        r('rect', {
                            x: w * 0.22 + i * (w * 0.19),
                            y: h * 0.22 + j * (h * 0.12),
                            width: w * 0.14,
                            height: h * 0.09,
                            rx: 2,
                            fill: `rgba(${30 + i * 15},${50 + j * 20},${120 + i * 20},.45)`,
                        }, sv);
                    }
                }
                r('circle', { cx: w * 0.5, cy: h * 0.37, r: w * 0.09, fill: 'rgba(50,120,255,.25)' }, sv);
                r('circle', { cx: w * 0.5, cy: h * 0.37, r: w * 0.04, fill: 'rgba(100,160,255,.4)' }, sv);
            },
        },
        {
            bg: '#0d1008',
            yr: '2023',
            label: 'Year End Gala',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: h * 0.6, width: w, height: h * 0.4, fill: '#152018' }, sv);
                [[0.15, 0.68], [0.3, 0.64], [0.5, 0.7], [0.68, 0.65], [0.85, 0.67]].forEach(([x, y]) => {
                    r('ellipse', { cx: x * w, cy: y * h, rx: w * 0.06, ry: h * 0.13, fill: '#1e3020' }, sv);
                    r('ellipse', { cx: x * w, cy: (y - 0.16) * h, rx: w * 0.038, ry: h * 0.058, fill: '#283828' }, sv);
                });
                r('circle', { cx: w * 0.5, cy: h * 0.22, r: w * 0.15, fill: 'rgba(224,48,32,.12)' }, sv);
            },
        },
        {
            bg: '#100808',
            yr: '2024',
            label: 'Anniversary',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: w * 0.3, y: h * 0.45, width: w * 0.4, height: h * 0.3, rx: 3, fill: 'rgba(180,40,20,.35)' }, sv);
                r('rect', { x: w * 0.25, y: h * 0.38, width: w * 0.5, height: h * 0.12, rx: 3, fill: 'rgba(200,60,30,.3)' }, sv);
                [0.38, 0.5, 0.62].forEach((x) => {
                    r('rect', { x: x * w - 3, y: h * 0.28, width: 6, height: h * 0.12, rx: 1, fill: 'rgba(224,48,32,.5)' }, sv);
                    r('ellipse', { cx: x * w, cy: h * 0.28, rx: 4, ry: 6, fill: 'rgba(255,180,40,.6)' }, sv);
                });
            },
        },
        {
            bg: '#080e10',
            yr: '2025',
            label: 'Sports Day',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: 0, width: w, height: h * 0.62, fill: '#0a141a' }, sv);
                r('rect', { x: 0, y: h * 0.62, width: w, height: h * 0.38, fill: '#0d1f10' }, sv);
                r('circle', { cx: w * 0.5, cy: h * 0.74, r: w * 0.16, fill: 'none', stroke: 'rgba(255,255,255,.12)', 'stroke-width': 1 }, sv);
                r('line', { x1: 0, y1: h * 0.74, x2: w, y2: h * 0.74, stroke: 'rgba(255,255,255,.1)', 'stroke-width': 0.8 }, sv);
                r('path', { d: `M${w * 0.38} ${h * 0.14} Q${w * 0.3} ${h * 0.32} ${w * 0.36} ${h * 0.42} L${w * 0.64} ${h * 0.42} Q${w * 0.7} ${h * 0.32} ${w * 0.62} ${h * 0.14} Z`, fill: 'rgba(220,160,20,.55)' }, sv);
                r('path', { d: `M${w * 0.38} ${h * 0.2} Q${w * 0.26} ${h * 0.24} ${w * 0.28} ${h * 0.34} Q${w * 0.3} ${h * 0.38} ${w * 0.36} ${h * 0.36}`, fill: 'none', stroke: 'rgba(220,160,20,.5)', 'stroke-width': 1.5 }, sv);
                r('path', { d: `M${w * 0.62} ${h * 0.2} Q${w * 0.74} ${h * 0.24} ${w * 0.72} ${h * 0.34} Q${w * 0.7} ${h * 0.38} ${w * 0.64} ${h * 0.36}`, fill: 'none', stroke: 'rgba(220,160,20,.5)', 'stroke-width': 1.5 }, sv);
                r('rect', { x: w * 0.47, y: h * 0.42, width: w * 0.06, height: h * 0.1, rx: 1, fill: 'rgba(200,140,15,.5)' }, sv);
                r('rect', { x: w * 0.37, y: h * 0.52, width: w * 0.26, height: h * 0.05, rx: 2, fill: 'rgba(200,140,15,.45)' }, sv);
                r('ellipse', { cx: w * 0.5, cy: h * 0.3, rx: w * 0.2, ry: h * 0.18, fill: 'rgba(220,160,20,.07)' }, sv);
                [[0.15, 0.1, 'rgba(224,48,32,.7)'], [0.22, 0.22, 'rgba(40,180,80,.6)'], [0.78, 0.12, 'rgba(60,140,255,.6)'], [0.85, 0.25, 'rgba(220,160,20,.7)'], [0.1, 0.35, 'rgba(200,60,200,.5)'], [0.9, 0.4, 'rgba(224,48,32,.5)']].forEach(([x, y, c]) => {
                    r('circle', { cx: x * w, cy: y * h, r: 2.2, fill: c }, sv);
                });
                [[0.32, 0.88], [0.5, 0.86], [0.68, 0.88]].forEach(([x, y]) => {
                    r('circle', { cx: x * w, cy: y * h, r: 5, fill: 'rgba(220,160,20,.18)', stroke: 'rgba(220,160,20,.45)', 'stroke-width': 0.8 }, sv);
                    r('circle', { cx: x * w, cy: y * h, r: 2, fill: 'rgba(220,160,20,.6)' }, sv);
                });
            },
        },
        {
            bg: '#060c0e',
            yr: '2026',
            label: 'Team Retreat',
            draw: (sv, w, h) => {
                const r = (t, a, f) => {
                    const e = document.createElementNS(S, t);
                    for (const k in a) e.setAttribute(k, a[k]);
                    f.appendChild(e);
                    return e;
                };
                r('rect', { x: 0, y: 0, width: w, height: h * 0.58, fill: '#080e14' }, sv);
                r('rect', { x: 0, y: h * 0.58, width: w, height: h * 0.42, fill: '#0a1820' }, sv);
                r('ellipse', { cx: w * 0.5, cy: h * 0.62, rx: w * 0.42, ry: h * 0.06, fill: 'rgba(20,80,120,.25)' }, sv);
                r('path', { d: `M0 ${h * 0.58} L${w * 0.18} ${h * 0.24} L${w * 0.36} ${h * 0.52} L${w * 0.52} ${h * 0.18} L${w * 0.68} ${h * 0.48} L${w * 0.84} ${h * 0.28} L${w} ${h * 0.5} L${w} ${h * 0.58} Z`, fill: '#0e1e28' }, sv);
                r('path', { d: `M${w * 0.52} ${h * 0.18} L${w * 0.46} ${h * 0.32} L${w * 0.58} ${h * 0.32} Z`, fill: 'rgba(200,220,230,.18)' }, sv);
                r('path', { d: `M${w * 0.84} ${h * 0.28} L${w * 0.79} ${h * 0.38} L${w * 0.89} ${h * 0.38} Z`, fill: 'rgba(200,220,230,.12)' }, sv);
                r('circle', { cx: w * 0.78, cy: h * 0.16, r: w * 0.07, fill: 'rgba(255,220,120,.12)' }, sv);
                r('circle', { cx: w * 0.78, cy: h * 0.16, r: w * 0.04, fill: 'rgba(255,220,120,.35)' }, sv);
                [[0.1, 0.08], [0.2, 0.15], [0.35, 0.06], [0.6, 0.1], [0.45, 0.18], [0.15, 0.28]].forEach(([x, y]) => {
                    r('circle', { cx: x * w, cy: y * h, r: 0.9, fill: 'rgba(220,230,255,.55)' }, sv);
                });
                r('path', { d: `M${w * 0.3} ${h * 0.72} L${w * 0.5} ${h * 0.52} L${w * 0.7} ${h * 0.72} Z`, fill: 'rgba(224,48,32,.35)', stroke: 'rgba(224,48,32,.5)', 'stroke-width': 0.8 }, sv);
                r('path', { d: `M${w * 0.46} ${h * 0.72} L${w * 0.5} ${h * 0.58} L${w * 0.54} ${h * 0.72} Z`, fill: 'rgba(10,20,30,.8)' }, sv);
                r('ellipse', { cx: w * 0.5, cy: h * 0.84, rx: w * 0.06, ry: h * 0.02, fill: 'rgba(224,80,20,.2)' }, sv);
                r('ellipse', { cx: w * 0.5, cy: h * 0.82, rx: 4, ry: 6, fill: 'rgba(255,140,30,.45)' }, sv);
                r('ellipse', { cx: w * 0.5, cy: h * 0.81, rx: 2.5, ry: 4, fill: 'rgba(255,200,60,.6)' }, sv);
                r('path', { d: `M${w * 0.34} ${h * 0.66} L${w * 0.5} ${h * 0.76} L${w * 0.66} ${h * 0.66}`, fill: 'none', stroke: 'rgba(224,48,32,.1)', 'stroke-width': 0.8 }, sv);
            },
        },
    ];

    [...scenes, ...scenes].forEach((s, index) => {
        const frame = document.createElement('div');
        frame.className = 'rframe';

        const svg = document.createElementNS(S, 'svg');
        svg.setAttribute('viewBox', '0 0 130 88');
        svg.setAttribute('width', '130');
        svg.setAttribute('height', '88');

        const bg = document.createElementNS(S, 'rect');
        bg.setAttribute('width', '130');
        bg.setAttribute('height', '88');
        bg.setAttribute('fill', s.bg);
        svg.appendChild(bg);

        s.draw(svg, 130, 88);

        const gradId = `ro-${index}`;
        const defs = document.createElementNS(S, 'defs');
        const gr = document.createElementNS(S, 'linearGradient');
        gr.setAttribute('id', gradId);
        gr.setAttribute('x1', '0');
        gr.setAttribute('y1', '0');
        gr.setAttribute('x2', '0');
        gr.setAttribute('y2', '1');
        const stop1 = document.createElementNS(S, 'stop');
        stop1.setAttribute('offset', '0');
        stop1.setAttribute('stop-color', 'rgba(224,48,32,.06)');
        const stop2 = document.createElementNS(S, 'stop');
        stop2.setAttribute('offset', '1');
        stop2.setAttribute('stop-color', 'rgba(0,0,0,.35)');
        gr.appendChild(stop1);
        gr.appendChild(stop2);
        defs.appendChild(gr);
        svg.insertBefore(defs, svg.firstChild);

        const overlay = document.createElementNS(S, 'rect');
        overlay.setAttribute('width', '130');
        overlay.setAttribute('height', '88');
        overlay.setAttribute('fill', `url(#${gradId})`);
        svg.appendChild(overlay);

        frame.appendChild(svg);

        const year = document.createElement('div');
        year.className = 'rframe-yr';
        year.textContent = s.yr;
        frame.appendChild(year);

        reelTrack.appendChild(frame);

        frame.addEventListener('mouseenter', () => {
            cursorRing.style.width = '48px';
            cursorRing.style.height = '48px';
        });

        frame.addEventListener('mouseleave', () => {
            cursorRing.style.width = '30px';
            cursorRing.style.height = '30px';
        });
    });

    const holesCount = Math.ceil(window.innerWidth / 24) + 6;
    for (let i = 0; i < holesCount * 2; i++) {
        const h1 = document.createElement('div');
        h1.className = 'hole';
        holesTop.appendChild(h1);

        const h2 = document.createElement('div');
        h2.className = 'hole';
        holesBottom.appendChild(h2);
    }

    const actualLen = Math.ceil(path.getTotalLength()) + 10;
    path.style.setProperty('--len', actualLen);
    path.style.setProperty('--dur', '5.5s');
    path.style.setProperty('--delay', '0.5s');
    path.classList.add('draw');
    setTimeout(() => {
        path.classList.add('drawn');
        fireFlash();
    }, 2500);

    resize();
    buildHex();
    draw();
    animateCursor();

    resizeHandler = () => {
        resize();
        buildHex();
    };

    window.addEventListener('resize', resizeHandler);
    document.addEventListener('mousemove', mouseHandler);
    attachHoverListeners(document);
});

onBeforeUnmount(() => {
    if (resizeHandler) window.removeEventListener('resize', resizeHandler);
    if (mouseHandler) document.removeEventListener('mousemove', mouseHandler);
    if (rafCanvas) cancelAnimationFrame(rafCanvas);
    if (rafCursor) cancelAnimationFrame(rafCursor);
});
</script>

<template>
    <Head title="Welcome" />

    <div class="landing-one">
        <canvas id="bgc" ref="canvasRef"></canvas>
        <div class="noise"></div>
        <div class="vig"></div>
        <div ref="flashRef" class="cam-flash"></div>

        <div id="cur" ref="cursorRef"></div>
        <div id="cur-ring" ref="cursorRingRef"></div>

        <nav class="nav">
            <div class="logo">
                <img src="/images/cx-logo-light.svg" alt="Cypherox Memories" class="logo-image">
            </div>
            <div class="nav-status">
                <div class="nav-dot"></div>
                INTERNAL ONLY
            </div>
        </nav>

        <div class="page">
            <div class="stage">
                <div class="stage-glow"></div>

                <div class="side-dots">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>

                <div class="cx-mark cx-tl">CX</div>
                <div class="cx-mark cx-tr">CX</div>
                <div class="cx-mark cx-bl">CX</div>
                <div class="cx-mark cx-br">CX</div>

                <svg id="char-svg" viewBox="0 0 2267.7 2494.5" xmlns="http://www.w3.org/2000/svg"
                    style="enable-background:new 0 0 2267.7 2494.5;" xml:space="preserve">
                    <path ref="pathRef" id="p-draw" d="M415.7,1737.9h582.1c0,0,12.5-86.7-2.7-115.6c0,0,35.8,13.5,46.6-11c0,0,22.7-54,52.4-85.3
c0,0,20.7-20,27.4-55.2c0,0,2.3-40.7,21.5-63c0,0,7-6.3,7-24.3c0,0,0-41.5,22.3-74.7c0,0,20.9-26.2,8.6-39.9
c0,0-36.9-40.2-67.9-49.7c-2.7-0.8-5.6,0.2-7.1,2.6c-11.4,17.1-56.4,87.7-31.9,99.8c0,0,41.7,21.1,65.2,26.9c0,0,6.8,2.1,12.5-16.4
c0,0,16.2-61.5,29.7-84.6c0,0,10.7-16.3,34.7-32c0,0,32.1-31,40.7-58.8c0,0,6.3-10.8,19-19.7c0,0,17.2-9.1,11.7-33.7
c0,0-7.6-21.4-19.3-28.4l-10.7,19.8c0,0,11.2,15.4,5.7,24.5l-34.3,21.6l20.5-30.3c0,0-84.7,61.8-135.6-30.9
c0,0-27.3-66.7,42.7-101.8c7.8-3.9,16.3-6.1,25-6.3c21.5-0.6,62.6,4.1,78.8,45.7c0,0,18.3,42.3-15.9,72.5c0,0-28.4,26.3-75.9,17
c0,0-37.8-11.5-41.5-43.8c0,0-9.2-54.6,41.6-71.3c3-1,6.1-1.5,9.2-1.8c12.4-0.9,44.2-0.3,58.8,26.5c3.3,6.2,5.2,13,5.9,20
c1.7,17.7-0.6,55.7-49.1,60.3c0,0-41-0.5-45.1-40.4c0,0-4.1-27.1,15.1-38.4c11.5-6.7,26.1-5.4,36.3,3.1c1.6,1.3,3.1,2.9,4.5,4.7
c6.6,8.6,5.9,20.9-1.4,28.8c-4.5,4.8-11,7-19.7-2.5c-2.1-2.2-3.2-5.2-3-8.3c0.3-4.7,3.8-10.1,19.7-6.6c0,0-20.3-5.8-25.3,9
c-2.3,6.9-0.7,14.6,4.4,19.8c2.6,2.7,6.2,4.9,11.3,5.1c0,0,17.9-1.3,13.5,9.3c-0.7,1.8-2,3.3-3.5,4.5c-2.6,2.1-7.4,7.2-7.5,15.5
c0,3-0.4,6-1.4,8.8c-1.6,4.6-5,9.8-11.8,9.4c-3.7-0.2-7.1-2-9.6-4.7c-8-8.8-34.5-35.5-59.2-31.9c0,0,3.9,19.5-4.4,39.6
c0,0-3.4,21.9,6.8,47c0,0,6.8,23-2.3,35c0,0-20.9-32.3-23.7-85.3c0,0-12.5,54.5-18.3,58.7c0,0-48-63.8-113.3-33.3
c0,0-68.1,34.8-93.1,56.3c0,0,55.7-7.4,90.9,31.4c0,0,76.2,84.4,64,117.3c0,0-28.8,68.2-51.3,89.1c0,0-13.3,14.1-24,10.2
c0,0-10.4-12,39.7-33.7c0,0,63.4-48.4,81.9-99.9c0,0,12,14.5,33.9,13c0,0,37.8-1.7,30.7,24.7c-1.7,6.3-5.6,11.8-10.6,15.9
c-10.8,8.8-23.9,14-36.9,18.9c-6,2.3-11.3,4.6-16,9.1c-14.1,14-9.2,35.1-1.3,51c0.6,1.3,1.3,2.5,2,3.8c0,0,26.3,48-29.2,79.6
c-3.8,2.2-7.1,5.7-9.6,9.2c-2.7,3.8-4.8,8.1-6.1,12.6c-1.9,6.4-1.3,13.2-3.2,19.7c-1,3.3-2,6.7-3.3,9.9c-3.2,8.4-7.4,16.7-13.4,23.5
c-3.5,3.9-8,7.9-13.1,9.8c-6.3,2.4-11.3,0.3-16.8-2.8c-8-4.4-15.2-10.1-21.7-16.4c-25-24.4-38.4-60.6-51.9-92.7
c-14.7-34.9-27.4-70.6-37.2-107.2c-11-41.2-19.1-84.5-16.4-127.4c1.1-16.9,3-35.1,12.2-49.7c8.6-13.8,23.1-22.8,38.1-28.2
c12.6-4.7,24.8-10.4,36.8-16.5c21.3-10.8,43.3-23.4,59.7-41.1c3.5-3.8,6.8-7.9,9.4-12.4c5-8.6,5.6-18.3,15.1-23.2
c8.2-4.2,18.2-3.6,27-1.7c11.4,2.4,22.2,7.1,32.6,12.4c0,0-56.3-19.7-3.9-61c0.5-0.4,0.9,4.9,0.8,5.3c-0.3,5.4-1.9,10.8-3,16.1
c-0.7,3-0.9,6.7-2.9,9.2c-0.8-6.7,0.7-13.5,1.9-20c1.3-6.7,2.3-13.4,3.4-20.1c0.8-4.9,1.7-9.8,2.6-14.7c0.2-1.1-4.4-6.3-5-7.4
c-3.5-5.8-6.5-12.1-8.6-18.6c-5.5-17.5-2.5-35.6,11.9-47.9c3-2.6,6.5-6.7,10.7-6c1.4,0.2,2.8,0.8,4.1,1.4c4.7,2.1,8.6,5.8,11.7,9.9
c8.2,11.2,11,25.8,12.3,39.3c0,0,4.3-10.6,34.4-12.9c0,0-2.5-23.5,17.4-27l-0.2-20.2h-11.2V913l56.2,1.2c0,0,29.2,14,77.7,8.5
c0,0,14.3,0,13.3,34.2c0,0,3.9-42.3,15.9-53.2c0,0,13.3-49.3-17.7-72c-6.5-5-13.5-9.4-20.7-13.4c-5.8-3.2-11.9-6.2-18.2-8.2
c-2.9-0.9-5.8-1.7-8.8-2c-7.1-0.8-13.9,1.4-21-0.3c-8.6-2.1-16.5-7.1-25.2-9c-4.9-1-10-1.5-14.9-1.9c-11.3-0.7-22.9,0.2-33.5,4
c-10.7,3.8-21.5,12-26.7,22.3c-5.7,11.3,0.6,25.7,7,35.4c6,9.3,14.4,16.8,23.7,22.9c-1-0.6-1.8-7-2.2-8.5c-1-3.4-2.2-6.7-3.5-9.9
c-2.4-5.6-5.4-11.1-9.3-15.8c-3.7-4.6-8.3-8.6-13.4-11.6c-4.2-2.4-11.2-6.7-16.2-4.9c-2.8,1-5.7,3.8-7.9,5.7
c-8.9,7.7-15.6,17.8-19.9,28.8c-2.5,6.4-4.1,13-5.1,19.7c-1,13.3-2.2,26.5-3.4,39.8c-1,10.9-2.1,21.8-3.3,32.7
c-0.7,6.6-2.3,13.1-2.7,19.7c-0.6,8.5,1.2,16.9,3,25.1c1.6,7,3.4,14.5,9,19.5c1.9,1.7,4.3,2.3,6.8,1.8c5.4-1.1,4.2-4.1,4-8.5
c-0.2-5.3,0.3-10.7,1.6-15.9c3.5-14.1,13.8-22.4,22.4-33.2c4-5.1,8.2-10.1,12.1-15.4c3.3-4.5,9.1-10.6,9.7-16.2
c2.2-19,8.2-40.4,30.1-44.2c0,0,18.1,20.9,34.2,19l1.3,20.9l-14.1,2.6l1,16.7c3.7,1.7,7.2,4,10.4,6.5c2.9,2.3,5.7,4.8,8,7.7
c1.7,2.2,2.3,5.1,4.1,7.2c1.4,1.6,3.3,2.6,5.1,3.7c2.9,1.8,5.9,3.5,9,4.9c6,2.7,12.8,5.4,19.6,5.4c2.8,0,5.5-0.4,8-1.6
c4-1.8,5.8-6.7,9.7-2.4c3.8,4.2,5.9,10.5,7.8,15.7c0,0,10,4.5,21.7,6.8v7.6l16.7,1.6c0,0-35-3.1-39.7,5c0,0-0.3,18.7-1.2,35.5
c0,0,7.4-5.6,13.5-9c3.6-2,8-2.2,11.7-0.4c6.4,3.2,15.7,9.2,20.4,15c0,0-8.2-8.7-16-13.8c-4.8-3.1-11-3.3-16-0.6
c-3.3,1.7-7.8,4.5-13.5,8.8c0,0,0.6,11.1-2.2,18.5c0,0,5.7,2.7,9.4,10c-0.1-0.4,2.6-2.1,2.8-2.3c2.2-1.5,4.6-2.6,7.2-3
c2.5-0.4,5,0,7.2,1.1c2.3,1.1,4.6,2.5,5.9,4.7c1.6,2.6,0.9,6-1.4,7.9c-3.9,3.3-9.9,1.9-14.3,0.8c0,0,9.3,10.6,10.2,23
c0.1,1.5,0.8,2.9,1.9,3.9c2.1,1.8,5.6,4.8,8.8,8c5.7,5.7,7.2,14.4,3.3,21.5c-5.4,10-17.9,20.2-46.9,26.1c0,0,39.3-22.1,61,1
c0,0,38.9,45.2,71.5,21.7c0,0,10-27.4-21.4-54.4c-2.3-2-4.8-3.7-7.4-5.2c-9.4-5.4-39.4-28.2-25.7-95.7c0,0,7.4-52.2-15.7-62.7
c0,0-61.1-21.7-59.7,6.8c0.2,3.6,1.2,8.5,4.5,10.6c4.2,2.8,9.2-0.9,12.4-3.6c0,0,14.1-5.7,13.1,9.2c0,0,18-5.1,18,13.3
c0,10,12.1,15.5,19.9,18.7c6.4,2.7,10.7,7.4,14.1,13.5c3.9,6.9,5.4,14.7,4.5,22.5c0,0-0.2,5.3,0.4,13.2
c2.3,30.8,22.7,57.3,51.8,67.7c24.1,8.6,58.9,24.7,67.7,48c0,0,3.1,11.7,3.5,25.1c0.6,21.8-21.3,37-41.5,28.8
c-9-3.6-18.8-8.5-26.4-14.4c-8.9-7-11.4-19.4-6.2-29.4c6.5-12.7,14.5-33.8,4.1-47.1c0,0,29.4,11.6,69.6,32.8
c6.7,3.5,13.6,6.4,20.9,8.4c18.9,5.4,59.9,21.1,67.6,56.7c0,0,3.4,13.6,19.5,30.7c6.6,7,12,15.1,15.6,24c0.2,0.5,0.4,1,0.6,1.5
c12.4,32.4-12.8,66.9-47.6,65.7c-10.4-0.4-22.6-2.5-36.1-8c-19.2-7.9-35.8-20.8-48.7-37.1c-30.7-38.9-117.3-146.6-113.1-117.6
c0,0,41.9,90.8,50.1,131.1c0,0,0.7,8.6,25.9,1.4c4.5-1.3,9.4-1,13.6,1.1c3.5,1.7,6.9,4.8,8.7,10.2c1.2,3.7,3.2,7.1,6.1,9.6
c6.6,5.7,20.5,13.1,49,13.6c0,0-45.2-28.4-65.3-48.7c-3.3-3.3-7.2-6.1-11.6-7.7c-10.5-3.9-26.3-4.2-19.3,34.9c0,0-8.6-9-73.3-0.4
l-45.4-116.4c0,0,41.5-14.8,54.3-29.7c0,0,15.4,19.3,2.3,27.1l-38.3,21.4c0,0-31-41.9-35.2-69.1c0,0-13.3-9.1-27.1-8.1
c0,0,42,169.6,61.8,244.2c0,0,25.3,72.3,50.5,109.3c5.4,7.9,10.3,16.2,14.3,24.9c8.7,18.6,22.5,50.1,30.7,79.2
c0,0,23.7,70.3,39.7,91.5l-193,1.6c0,0-4.7-161-32.3-281.5c0,0-29.5-125-34.2-155.1c0,0-25.4,12.5-1.2,123.3
c0,0,21.1,106.8,23.9,181.6c0,0,13.3,106.6,11.3,131.7l257.1-13.2c0,0-70.3-112.3-78.4-169.1c-1-6.9-2.5-13.7-4.9-20.2
c-4.5-12.4-13.3-31.9-30.1-55.5c0,0-38.9-45.5-38.1-99.5c0,0-4-44.8-10.7-46.8c0,0-19.8,20.5,14.7,39.9c0,0,48.5,24.8,88-1.8
c0,0,45.1,105,66.5,186.4c0,0,50.1,146.1,72.5,179.7h362.6" />
                </svg>
            </div>

            <div class="right">
                <div class="tag-line">Cypherox Memories</div>
                <h1>Capturing<br>Every <em>Memory.</em></h1>
                <div class="divider"></div>
                <p class="sub">Securely stored, beautifully organized, and forever cherished - the memories that define our journey.</p>

                <div class="cta-row">
                    <a href="/dashboard" class="btn" id="eb">
                        <span>Access Now</span>
                        <svg viewBox="0 0 15 15" fill="none">
                            <path d="M2 7.5h11M8 3l4.5 4.5L8 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </div>

                <div class="reel-wrap">
                    <div class="reel-strip">
                        <div ref="holesTopRef" class="holes-row holes-top"></div>
                        <div ref="holesBottomRef" class="holes-row holes-bot"></div>
                        <div ref="reelTrackRef" class="reel-track"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:ital,wght@0,300;0,400;0,600;0,700;0,900;1,700&family=Barlow:wght@300;400&display=swap');

*,:before,:after { box-sizing: border-box; }

/* .cam-flash { position: fixed; inset: 0; z-index: 200; opacity: 0; pointer-events: none; background: radial-gradient(ellipse at center, rgba(255, 250, 240, 240) 0%, rgba(158, 145, 128, 0.38) 40%, rgba(255,175,70,.12) 70%, transparent 100%); } */
.cam-flash { position: fixed; inset: 0; z-index: 200; opacity: 0; pointer-events: none; background: radial-gradient(ellipse at center, #fff 0%, #ffe8d0 40%, rgba(255,180,80,.3) 70%, transparent 100%); }
.cam-spark { position: fixed; border-radius: 50%; pointer-events: none; z-index: 150; animation: camSpk var(--sd, .6s) ease-out forwards; }
@keyframes camSpk { 0% { transform: translate(0,0) scale(1); opacity: 1; } 100% { transform: translate(var(--tx), var(--ty)) scale(0); opacity: 0; } }

.landing-one {
    --bg: #0a0404;
    --panel: #110606;
    --red: #e03020;
    --red2: #c42010;
    --redglow: #ff4422;
    --dim: rgba(224, 48, 32, 0.35);
    --dimmer: rgba(224, 48, 32, 0.12);
    --white: #f5eeee;
    position: fixed;
    inset: 0;
    overflow: hidden;
    background: var(--bg);
    color: var(--white);
    cursor: none;
    font-family: 'Barlow Condensed', sans-serif;
}

#cur { position: fixed; width: 7px; height: 7px; background: var(--red); border-radius: 50%; pointer-events: none; z-index: 9999; transform: translate(-50%, -50%); }
#cur-ring { position: fixed; width: 30px; height: 30px; border: 1px solid rgba(224,48,32,.5); border-radius: 50%; pointer-events: none; z-index: 9998; transform: translate(-50%, -50%); transition: width .3s,height .3s; }
#bgc { position: fixed; inset: 0; z-index: 0; }

.noise { position: fixed; inset: -80px; opacity: .03; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 400 400' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='400' height='400' filter='url(%23n)'/%3E%3C/svg%3E"); animation: ns .4s steps(2) infinite; pointer-events: none; z-index: 1; }
@keyframes ns { 0% { transform: translate(0, 0); } 50% { transform: translate(-2%, 2%); } 100% { transform: translate(1%, -1%); } }

.vig { position: fixed; inset: 0; z-index: 2; background: radial-gradient(ellipse 75% 75% at 50% 50%, transparent 20%, rgba(10,4,4,.85) 100%); pointer-events: none; }
.page { position: fixed; inset: 0; z-index: 10; display: grid; grid-template-columns: 1fr 1fr; overflow: hidden; }
.stage { position: relative; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.stage-glow { position: absolute; width: 520px; height: 520px; border-radius: 50%; background: radial-gradient(circle, rgba(180,30,15,.18) 0%, transparent 70%); pointer-events: none; opacity: 0; animation: glowIn 1s 5.5s ease forwards; }
@keyframes glowIn { to { opacity: 1; } }
.ground { position: absolute; bottom: 18%; left: 5%; right: 5%; height: 1px; background: linear-gradient(90deg, transparent, rgba(224,48,32,.25), transparent); opacity: 0; animation: fadeI .6s 6s ease forwards; }

#char-svg { width: 100%; height: auto; position: relative; z-index: 5; overflow: visible; }
#char-svg path,#char-svg line,#char-svg circle,#char-svg ellipse,#char-svg rect,#char-svg polyline { fill: none; stroke: var(--red); stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; vector-effect: non-scaling-stroke; }
.draw { stroke-dasharray: var(--len, 800); stroke-dashoffset: var(--len, 800); animation: draw var(--dur, .8s) var(--delay, 0s) cubic-bezier(.4, 0, .2, 1) forwards; }
@keyframes draw { to { stroke-dashoffset: 0; } }
#char-svg path.drawn,#char-svg line.drawn,#char-svg circle.drawn { filter: drop-shadow(0 0 3px rgba(224,48,32,.5)); }

.cx-mark { position: absolute; font-size: 11px; font-weight: 600; letter-spacing: .2em; color: rgba(224,48,32,.3); opacity: 0; animation: fadeI .5s 6s forwards; }
.cx-tl { top: 24px; left: 24px; }
.cx-tr { top: 24px; right: 24px; text-align: right; }
.cx-bl { bottom: 24px; left: 24px; }
.cx-br { bottom: 24px; right: 24px; text-align: right; }

.side-dots { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); display: flex; flex-direction: column; gap: 14px; opacity: 0; animation: fadeI .5s 6s forwards; }
.side-dots span { width: 5px; height: 5px; border-radius: 50%; background: rgba(224,48,32,.25); display: block; }
.side-dots span:nth-child(3) { background: var(--red); box-shadow: 0 0 6px var(--red); }

.right { display: flex; flex-direction: column; justify-content: center; padding: 0 64px 0 52px; position: relative; overflow: hidden; }
.right:before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 2px; background: linear-gradient(180deg, transparent, var(--red), transparent); opacity: .4; }

.tag-line { font-size: 10px; letter-spacing: .38em; text-transform: uppercase; color: var(--red); display: flex; align-items: center; gap: 14px; margin-bottom: 22px; opacity: 0; animation: slideR .7s .2s cubic-bezier(.22, 1, .36, 1) forwards; }
.tag-line:before { content: ''; display: block; width: 36px; height: 1px; background: var(--red); opacity: .6; }
h1 { font-family: 'Bebas Neue', sans-serif; font-size: clamp(52px, 6vw, 84px); line-height: .95; letter-spacing: .02em; color: var(--white); opacity: 0; animation: slideR .8s .4s cubic-bezier(.22, 1, .36, 1) forwards; }
h1 em { font-family: 'Barlow Condensed', sans-serif; font-style: italic; font-weight: 700; color: var(--red); }
.divider { margin: 24px 0 22px; width: 60px; height: 1px; background: linear-gradient(90deg, var(--red), transparent); opacity: 0; animation: fadeI .5s .8s ease forwards; }
p.sub { font-family: 'Barlow', sans-serif; font-size: 14px; font-weight: 300; line-height: 1.85; color: rgba(245,238,238,.45); max-width: 360px; opacity: 0; animation: slideR .7s .9s cubic-bezier(.22, 1, .36, 1) forwards; }

.cta-row { margin-top: 38px; display: flex; align-items: center; gap: 22px; opacity: 0; animation: slideR .7s 1s cubic-bezier(.22, 1, .36, 1) forwards; }
.btn { display: inline-flex; align-items: center; gap: 12px; padding: 14px 38px; background: transparent; border: 1px solid rgba(224,48,32,.5); color: var(--white); font-size: 12px; font-weight: 600; letter-spacing: .22em; text-transform: uppercase; text-decoration: none; cursor: none; position: relative; overflow: hidden; transition: border-color .3s,color .3s; }
.btn:before { content: ''; position: absolute; inset: 0; background: var(--red2); transform: translateX(-101%); transition: transform .45s cubic-bezier(.22, 1, .36, 1); }
.btn:hover { border-color: var(--red); color: #fff; }
.btn:hover:before { transform: translateX(0); }
.btn span,.btn svg { position: relative; z-index: 1; }
.btn svg { width: 15px; height: 15px; transition: transform .3s; }
.btn:hover svg { transform: translateX(3px); }

.reel-wrap { position: absolute; bottom: 0; left: -40px; right: -40px; height: 130px; opacity: 0; transform: translateY(20px); animation: reelIn .9s .4s cubic-bezier(.22, 1, .36, 1) forwards; overflow: hidden; }
@keyframes reelIn { to { opacity: 1; transform: translateY(0); } }
.reel-strip { position: absolute; left: 0; right: 0; top: 0; bottom: 0; background: rgba(20,5,5,.95); border-top: 1px solid rgba(224,48,32,.15); }
.holes-row { position: absolute; left: -20px; right: -20px; display: flex; gap: 10px; padding: 0 8px; }
.holes-top { top: 7px; }
.holes-bot { bottom: 7px; }
:deep(.hole) { flex-shrink: 0; width: 14px; height: 9px; border-radius: 2px; border: 1px solid rgba(224,48,32,.2); background: rgba(10,4,4,.9); }
.reel-track { position: absolute; top: 18px; bottom: 18px; left: 0; display: flex; gap: 10px; align-items: center; padding: 0 20px; animation: reelScroll 35s linear infinite; width: max-content; }
.reel-track:hover { animation-play-state: paused; }
@keyframes reelScroll { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
:deep(.rframe) {
    flex-shrink: 0;
    width: 130px;
    height: 88px;
    border: 1px solid rgba(224, 48, 32, .15);
    overflow: hidden;
    position: relative;
    cursor: none;
    transition: border-color .3s, transform .3s;
}
:deep(.rframe:hover) {
    border-color: rgba(224, 48, 32, .6);
    transform: scale(1.06) translateY(-4px);
    z-index: 10;
}
:deep(.rframe svg) {
    display: block;
    width: 100%;
    height: 100%;
}
:deep(.rframe::after) {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(224, 48, 32, .12), transparent 60%);
    opacity: 0;
    transition: opacity .3s;
}
:deep(.rframe:hover::after) {
    opacity: 1;
}
:deep(.rframe-yr) {
    position: absolute;
    bottom: 5px;
    right: 7px;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px;
    letter-spacing: .18em;
    color: rgba(224, 48, 32, .7);
}
.reel-wrap::before, .reel-wrap::after { content: ''; position: absolute; top: 0; bottom: 0; width: 100px; z-index: 5; pointer-events: none; }
.reel-wrap::before { left: 0; background: linear-gradient(90deg, rgba(17,6,6,1), transparent); }
.reel-wrap::after { right: 0; background: linear-gradient(-90deg, rgba(17,6,6,1), transparent); }

@keyframes fadeI { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideR { from { opacity: 0; transform: translateX(-18px); } to { opacity: 1; transform: translateX(0); } }

.nav { position: fixed; top: 0; left: 0; right: 0; display: flex; align-items: center; justify-content: space-between; padding: 26px 52px; z-index: 30; opacity: 0; animation: fadeI .5s .2s ease forwards; }
.logo { display: flex; align-items: center; width: 200px; }
.logo-image { width: 100%; height: auto; display: block; }
.nav-status { display: flex; align-items: center; gap: 8px; font-size: 10px; letter-spacing: .28em; text-transform: uppercase; color: rgba(224,48,32,.5); }
.nav-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--red); animation: blink 2s ease-in-out infinite; }
@keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: .3; } }

@media (max-width: 900px) {
    .landing-one { overflow-y: auto; }
    .page { position: relative; display: flex; flex-direction: column; overflow: visible; }
    .nav { padding: 24px 20px; background: linear-gradient(to bottom, rgba(10,4,4,.9), transparent); }
    .logo { width: 140px; }
    .nav-status { display: none; }
    .stage { padding-top: 100px; min-height: auto; padding-bottom: 40px; }
    #char-svg { width: 100%; }
    .ground { bottom: 5%; }
    .right { padding: 0 24px 80px; align-items: center; text-align: center; }
    .right:before { display: none; }
    .tag-line { justify-content: center; }
    .tag-line:before { display: none; }
    h1 { font-size: clamp(42px, 12vw, 64px); }
    p.sub { margin-left: auto; margin-right: auto; }
    .cta-row { justify-content: center; flex-wrap: wrap; }
    .reel-wrap { position: relative; bottom: auto; left: -24px; right: -24px; width: calc(100% + 48px); margin-top: 60px; }
}
</style>
