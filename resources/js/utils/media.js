const triggerDownload = (url, fileName, target = '_self') => {
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.target = target;
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};

export const downloadFile = async (url, fileName = 'download', target = '_self') => {
    if (!url) {
        return;
    }

    try {
        const response = await fetch(url, { credentials: 'include' });

        if (response.ok) {
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            triggerDownload(blobUrl, fileName, '_self');

            window.setTimeout(() => {
                URL.revokeObjectURL(blobUrl);
            }, 2000);

            return;
        }
    } catch (_error) {
        // Fallback below handles cross-origin/CORS restricted URLs.
    }

    triggerDownload(url, fileName, target);
};

export const formatFileSize = (bytes) => {
    const normalizedBytes = Number(bytes) || 0;

    if (normalizedBytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const base = 1024;
    const index = Math.min(
        units.length - 1,
        Math.floor(Math.log(normalizedBytes) / Math.log(base)),
    );
    const value = normalizedBytes / (base ** index);
    const decimals = index === 0 ? 0 : value >= 100 ? 0 : value >= 10 ? 1 : 2;

    return `${value.toFixed(decimals)} ${units[index]}`;
};
