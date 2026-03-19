export const downloadFile = (url, fileName = 'download', target = '_blank') => {
    if (!url) {
        return;
    }

    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.target = target;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
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
