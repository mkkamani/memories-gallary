import { onMounted, onUnmounted } from 'vue';

export const useEscapeKey = (handler, isEnabled = () => true) => {
    const onKeydown = (event) => {
        if (event.key !== 'Escape' || !isEnabled()) {
            return;
        }

        handler(event);
    };

    onMounted(() => document.addEventListener('keydown', onKeydown));
    onUnmounted(() => document.removeEventListener('keydown', onKeydown));

    return { onKeydown };
};
