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
