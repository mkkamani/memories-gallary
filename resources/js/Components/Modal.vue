<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useEscapeKey } from '@/composables/useEscapeKey';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    contained: {
        type: Boolean,
        default: false,
    },
    closeOnBackdrop: {
        type: Boolean,
        default: false,
    },
    showCloseButton: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);
const showSlot = ref(props.show);
const panelNudge = ref(false);
let panelNudgeTimeout;

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
            showSlot.value = true;
        } else {
            document.body.style.overflow = '';

            setTimeout(() => {
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const onBackdropClick = () => {
    if (!props.closeOnBackdrop) {
        panelNudge.value = false;
        requestAnimationFrame(() => {
            panelNudge.value = true;
            clearTimeout(panelNudgeTimeout);
            panelNudgeTimeout = setTimeout(() => {
                panelNudge.value = false;
            }, 380);
        });
    }

    if (props.closeOnBackdrop) {
        close();
    }
};

const containerClass = computed(() => {
    return props.contained
    ? 'fixed top-16 bottom-0 z-[70] modal-contained-shell'
        : 'fixed inset-0 z-50';
});

const containerStyle = computed(() => {
    return undefined;
});

const backdropClass = computed(() => {
    return props.contained
        ? 'absolute inset-0 modal-backdrop-base modal-backdrop-contained'
        : 'absolute inset-0 modal-backdrop-base modal-backdrop-full';
});

useEscapeKey(
    (event) => {
        event.preventDefault();
        close();
    },
    () => props.show,
);

onUnmounted(() => {
    document.body.style.overflow = '';
    clearTimeout(panelNudgeTimeout);
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="showSlot"
            :class="containerClass"
            :style="containerStyle"
            scroll-region
        >
            <div class="absolute inset-0 flex min-h-full items-center justify-center overflow-y-auto px-4 py-6 sm:px-0">
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-show="show"
                        class="absolute inset-0 transform transition-all"
                        @click="onBackdropClick"
                    >
                        <div class="absolute inset-0" :class="backdropClass">
                            <div class="modal-backdrop-glow" />
                        </div>
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="relative my-auto transform overflow-hidden rounded-lg shadow-xl transition-all sm:mx-auto sm:w-full"
                        :class="[maxWidthClass, panelNudge ? 'modal-panel-nudge' : '']"
                    >
                        <button
                            v-if="showCloseButton && closeable"
                            type="button"
                            class="absolute top-3 right-3 z-20 inline-flex h-8 w-8 items-center justify-center rounded-full border border-border/60 bg-bg-elevated/90 text-muted-foreground hover:text-foreground hover:border-primary/40 hover:bg-bg-hover transition-colors"
                            aria-label="Close modal"
                            @click="close"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.modal-backdrop-base {
    pointer-events: auto;
    backdrop-filter: blur(10px) saturate(120%);
    -webkit-backdrop-filter: blur(10px) saturate(120%);
}

.modal-backdrop-contained {
    background: linear-gradient(180deg, hsla(220, 18%, 16%, 0.42), hsla(220, 22%, 10%, 0.54));
}

.modal-backdrop-full {
    background: linear-gradient(180deg, hsla(220, 18%, 14%, 0.5), hsla(220, 22%, 9%, 0.62));
}

.modal-backdrop-glow {
    position: absolute;
    inset: 0;
}

.modal-panel-nudge {
    animation: modalNudge 380ms cubic-bezier(0.2, 0.9, 0.2, 1);
}

.modal-contained-shell {
    left: 0;
    right: 0;
}

@media (min-width: 768px) {
    .modal-contained-shell {
        left: var(--layout-content-left, 14rem);
    }
}

@keyframes modalNudge {
    0% {
        transform: translate3d(0, 0, 0) scale(1);
    }
    22% {
        transform: translate3d(-8px, 0, 0) scale(1.004);
    }
    44% {
        transform: translate3d(7px, 0, 0) scale(1.004);
    }
    68% {
        transform: translate3d(-4px, 0, 0) scale(1.002);
    }
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }
}
</style>
