export function formatNumber(value, options = {}) {
    const defaultOptions = {
        maximumFractionDigits: 0,
    };

    const normalized = typeof value === 'string'
        ? value.replace(/,/g, '').trim()
        : value;

    const numeric = Number(normalized);

    if (!Number.isFinite(numeric)) {
        if (value === null || value === undefined || value === '') {
            return '0';
        }

        return String(value);
    }

    return new Intl.NumberFormat('en-US', {
        ...defaultOptions,
        ...options,
    }).format(numeric);
}
