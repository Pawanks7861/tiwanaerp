import Decimal from 'decimal.js';

/**
 * Display formatting only. Amounts arrive from the server as decimal strings and are never
 * converted to JavaScript floats; the server remains the source of truth for all calculations.
 */

const EMPTY = '—';

function toDecimal(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    try {
        return new Decimal(String(value).replace(/,/g, ''));
    } catch {
        return null;
    }
}

/** Groups an unsigned integer string the Indian way: 12,34,56,789. */
function groupIndian(integer) {
    if (integer.length <= 3) {
        return integer;
    }
    const last3 = integer.slice(-3);
    const rest = integer.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');

    return `${rest},${last3}`;
}

/**
 * @param {string|number|null} value
 * @param {number} minDecimals
 * @param {number} maxDecimals trailing zeros beyond minDecimals are dropped
 */
export function formatNumber(value, minDecimals = 0, maxDecimals = minDecimals) {
    const d = toDecimal(value);
    if (d === null) {
        return EMPTY;
    }

    let fixed = d.toFixed(maxDecimals, Decimal.ROUND_HALF_UP);
    if (maxDecimals > minDecimals) {
        const [i, f = ''] = fixed.split('.');
        const trimmed = f.replace(/0+$/, '').padEnd(minDecimals, '0');
        fixed = trimmed ? `${i}.${trimmed}` : i;
    }

    const negative = fixed.startsWith('-');
    const unsigned = negative ? fixed.slice(1) : fixed;
    const [integer, fraction] = unsigned.split('.');
    const grouped = groupIndian(integer) + (fraction ? `.${fraction}` : '');

    return negative && /[1-9]/.test(unsigned) ? `-${grouped}` : grouped;
}

/** ₹ 12,34,567.00 */
export function formatMoney(value) {
    const formatted = formatNumber(value, 2);
    if (formatted === EMPTY) {
        return EMPTY;
    }

    return formatted.startsWith('-') ? `-₹ ${formatted.slice(1)}` : `₹ ${formatted}`;
}

/** Compact amounts for dashboards: ₹ 1.25 Cr, ₹ 4.50 L. */
export function formatMoneyShort(value) {
    const d = toDecimal(value);
    if (d === null) {
        return EMPTY;
    }
    const abs = d.abs();
    if (abs.gte(1e7)) {
        return `₹ ${formatNumber(d.div(1e7), 2)} Cr`;
    }
    if (abs.gte(1e5)) {
        return `₹ ${formatNumber(d.div(1e5), 2)} L`;
    }

    return formatMoney(d.toString());
}

/** Unit rates: at least 2 and up to 4 decimals. */
export function formatRate(value) {
    const formatted = formatNumber(value, 2, 4);

    return formatted === EMPTY ? EMPTY : `₹ ${formatted}`;
}

export function formatQty(value, decimals = 4) {
    return formatNumber(value, 0, decimals);
}

export function formatPercent(value) {
    const formatted = formatNumber(value, 0, 4);

    return formatted === EMPTY ? EMPTY : `${formatted}%`;
}

const dateFormatter = new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
const dateTimeFormatter = new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

/** Date-only strings (YYYY-MM-DD) are parsed as local dates so they never shift by time zone. */
function parseDate(value) {
    if (!value) {
        return null;
    }
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    const date = m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDate(value) {
    const date = parseDate(value);

    return date ? dateFormatter.format(date) : EMPTY;
}

export function formatDateTime(value) {
    const date = parseDate(value);

    return date ? dateTimeFormatter.format(date) : EMPTY;
}

export function timeAgo(value) {
    const date = parseDate(value);
    if (!date) {
        return EMPTY;
    }
    const seconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (seconds < 60) {
        return 'just now';
    }
    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    for (const [unit, size] of units) {
        if (seconds >= size) {
            const n = Math.floor(seconds / size);

            return `${n} ${unit}${n > 1 ? 's' : ''} ago`;
        }
    }

    return EMPTY;
}

export function initials(name) {
    return (name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}
