let libheifPromise = null;

const loadLibheif = async () => {
    if (!libheifPromise) {
        libheifPromise = import('libheif-js/wasm-bundle').then((module) => module.default ?? module);
    }

    return libheifPromise;
};

const scoreHeifImage = (image) => {
    const width = Number(image?.get_width?.() || 0);
    const height = Number(image?.get_height?.() || 0);
    const area = width * height;
    const primaryBoost = image?.is_primary?.() ? 1_000_000_000 : 0;

    return primaryBoost + area;
};

const pickBestImage = (images) => {
    if (!Array.isArray(images) || images.length === 0) {
        return null;
    }

    return [...images].sort((a, b) => scoreHeifImage(b) - scoreHeifImage(a))[0] || null;
};

const renderHeifImageToBlob = async (image, mimeType = 'image/png', quality = 0.98) => {
    const width = Number(image?.get_width?.() || 0);
    const height = Number(image?.get_height?.() || 0);

    if (width <= 0 || height <= 0) {
        throw new Error('Decoded HEIC image dimensions are invalid.');
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');
    if (!context) {
        throw new Error('Canvas 2D context is not available.');
    }

    const imageData = context.createImageData(width, height);

    await new Promise((resolve, reject) => {
        image.display(imageData, (displayData) => {
            if (!displayData) {
                reject(new Error('libheif-js failed to render image data.'));
                return;
            }

            context.putImageData(displayData, 0, 0);
            resolve();
        });
    });

    const renderedBlob = await new Promise((resolve) => {
        canvas.toBlob(resolve, mimeType, quality);
    });

    if (!renderedBlob) {
        throw new Error('Canvas failed to export decoded image blob.');
    }

    return renderedBlob;
};

export const decodeHeicBlobWithLibheif = async (blob, options = {}) => {
    const { mimeType = 'image/png', quality = 0.98 } = options;

    if (!(blob instanceof Blob)) {
        throw new Error('decodeHeicBlobWithLibheif expects a Blob input.');
    }

    const libheif = await loadLibheif();
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const decoder = new libheif.HeifDecoder();
    const images = decoder.decode(bytes);
    const bestImage = pickBestImage(images);

    if (!bestImage) {
        throw new Error('No decodable top-level image found in HEIC file.');
    }

    try {
        return await renderHeifImageToBlob(bestImage, mimeType, quality);
    } finally {
        for (const image of images || []) {
            if (typeof image?.free === 'function') {
                image.free();
            }
        }
    }
};
