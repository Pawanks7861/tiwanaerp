import Decimal from 'decimal.js';
import { formatMoney, formatNumber } from '@/lib/format';

/** Hide rupee amounts when the user lacks cost permission. */
export function formatCost(value, canView = true) {
    if (!canView) {
        return '—';
    }

    return formatMoney(value);
}

/** Display unit rates; amounts still redacted when costs are hidden. */
export function formatUnitRate(value, unit = '', canView = true) {
    if (!canView || value === null || value === undefined || value === '') {
        return '—';
    }
    const n = formatNumber(value, 2, 4);

    return unit ? `${n} / ${unit}` : n;
}

/**
 * Client-side preview of reading metrics (server remains authoritative).
 * @returns {{ days: number|null, kwh_consumption: string|null, kvah_consumption: string|null, billing_demand: string|null, power_factor: string|null }}
 */
export function previewReading(form) {
    const from = form.from_date;
    const to = form.to_date;
    if (!from || !to || to < from) {
        return { days: null, kwh_consumption: null, kvah_consumption: null, billing_demand: null, power_factor: null };
    }

    let mf;
    try {
        mf = new Decimal(String(form.multiplication_factor || '1').replace(/,/g, ''));
        if (mf.lte(0)) {
            return { days: null, kwh_consumption: null, kvah_consumption: null, billing_demand: null, power_factor: null };
        }
    } catch {
        return { days: null, kwh_consumption: null, kvah_consumption: null, billing_demand: null, power_factor: null };
    }

    const days = Math.max(1, Math.round((new Date(to) - new Date(from)) / 86400000) + 1);

    try {
        const openingKwh = new Decimal(String(form.opening_kwh || '0').replace(/,/g, ''));
        const closingKwh = new Decimal(String(form.closing_kwh || '0').replace(/,/g, ''));
        if (closingKwh.lt(openingKwh)) {
            return { days, kwh_consumption: null, kvah_consumption: null, billing_demand: null, power_factor: null };
        }
        const kwh = closingKwh.minus(openingKwh).times(mf);

        let kvah = null;
        let pf = null;
        if (form.opening_kvah !== '' && form.opening_kvah != null && form.closing_kvah !== '' && form.closing_kvah != null) {
            const openingKvah = new Decimal(String(form.opening_kvah).replace(/,/g, ''));
            const closingKvah = new Decimal(String(form.closing_kvah).replace(/,/g, ''));
            if (!closingKvah.lt(openingKvah)) {
                const kvahQty = closingKvah.minus(openingKvah).times(mf);
                kvah = kvahQty;
                if (!kvahQty.isZero()) {
                    pf = kwh.div(kvahQty).toDecimalPlaces(6);
                }
            }
        }

        let demand = null;
        if (form.mdi_reading !== '' && form.mdi_reading != null) {
            demand = new Decimal(String(form.mdi_reading).replace(/,/g, '')).times(mf);
        }

        return {
            days,
            kwh_consumption: kwh.toFixed(4),
            kvah_consumption: kvah ? kvah.toFixed(4) : null,
            billing_demand: demand ? demand.toFixed(4) : null,
            power_factor: pf ? pf.toFixed(6) : null,
        };
    } catch {
        return { days, kwh_consumption: null, kvah_consumption: null, billing_demand: null, power_factor: null };
    }
}
