import { ref, unref } from 'vue';

const resolveItems = (source) => {
    const value = unref(source);
    return Array.isArray(value) ? value : [];
};

export const useMediaPreview = (defaultItemsSource = []) => {
    const showPreviewModal = ref(false);
    const previewMedia = ref(null);
    const currentIndex = ref(0);
    const items = ref(resolveItems(defaultItemsSource));

    const openPreview = (media, itemSource = null) => {
        items.value = itemSource ? resolveItems(itemSource) : resolveItems(defaultItemsSource);

        const index = items.value.findIndex((item) => item.id === media.id);
        currentIndex.value = index !== -1 ? index : 0;
        previewMedia.value = media;
        showPreviewModal.value = true;
    };

    const closePreview = () => {
        showPreviewModal.value = false;
        previewMedia.value = null;
    };

    const goToNext = () => {
        if (currentIndex.value < items.value.length - 1) {
            currentIndex.value += 1;
            previewMedia.value = items.value[currentIndex.value];
        }
    };

    const goToPrevious = () => {
        if (currentIndex.value > 0) {
            currentIndex.value -= 1;
            previewMedia.value = items.value[currentIndex.value];
        }
    };

    return {
        currentIndex,
        goToNext,
        goToPrevious,
        items,
        openPreview,
        closePreview,
        previewMedia,
        showPreviewModal,
    };
};
