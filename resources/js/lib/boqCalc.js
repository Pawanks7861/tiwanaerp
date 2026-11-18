import Decimal from 'decimal.js';

/**
 * Live preview of BOQ / rate analysis maths while editing. Mirrors BoqCalculator and
 * RateAnalysisCalculator on the server (HALF_UP); the server recalculates on save and is authoritative.
 */

const d = (value) => {
    try {
        return new Decimal(value === null || value === undefined || value === '' ? 0 : String(value));
    } catch {
        return new Decimal(0);
    }
};

const round = (value, places) => value.toDecimalPlaces(places, Decimal.ROUND_HALF_UP);

/**
 * Preview Normal BOQ quantity from NOB × Nos/L/W/H. Server recalculates on save.
 * formula: number | length | area | volume (or inferred from unitSymbol when auto).
 * Blank NOB is treated as 1 (historical compatibility).
 */
export function simpleBoqQty(row, unitSymbol = null) {
    if (row.measurement_mode !== 'auto') {
        return row.quantity == null || row.quantity === '' ? null : round(d(row.quantity), 4).toFixed(4);
    }

    const formula = row.measurement_formula || inferSimpleFormula(unitSymbol);
    if (!formula) {
        return null;
    }

    const nobRaw = row.measurement_nob;
    const nob = (nobRaw === null || nobRaw === undefined || nobRaw === '') ? d(1) : d(nobRaw);
    if (!nob.isInteger() || nob.lte(0)) {
        return null;
    }

    const nos = d(row.measurement_nos);
    const length = d(row.measurement_length);
    const width = d(row.measurement_width);
    const height = d(row.measurement_height);

    try {
        let qty;
        if (formula === 'number') {
            qty = nob.times(nos);
        } else if (formula === 'length') {
            qty = nob.times(nos).times(length);
        } else if (formula === 'area') {
            qty = nob.times(nos).times(length).times(width);
        } else if (formula === 'volume') {
            qty = nob.times(nos).times(length).times(width).times(height);
        } else {
            return null;
        }
        if (qty.isNeg()) {
            return null;
        }

        return round(qty, 4).toFixed(4);
    } catch {
        return null;
    }
}

export function inferSimpleFormula(unitSymbol) {
    const key = String(unitSymbol || '')
        .toLowerCase()
        .replace(/[.\s]/g, '');
    if (['cum', 'm3', 'cum', 'cmt', 'cubicmetre', 'cubicmeter'].includes(key)) return 'volume';
    if (['sqm', 'm2', 'sqmt', 'squaremetre', 'squaremeter'].includes(key)) return 'area';
    if (['rmt', 'rm', 'rmt', 'mtr', 'm', 'metre', 'meter'].includes(key)) return 'length';
    if (['nos', 'no', 'each', 'ea', 'nr', 'number'].includes(key)) return 'number';

    return null;
}

/**
 * @param {Record<string, unknown>} row
 * @param {{ simplifiedCommercial?: boolean }} [options]
 *   simplifiedCommercial: Material Rate (client_rate) never falls back to cost+labour selling rate.
 *   Labour Rate stays separate from commercial Amount (Qty × Material Rate).
 */
export function boqLine(row, options = {}) {
    const qty = round(d(row.quantity), 4);
    const costRate = round(
        ['material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate'].reduce((sum, key) => sum.plus(round(d(row[key]), 4)), new Decimal(0)),
        4,
    );
    const margin = round(d(row.margin_percent), 4);
    const sellingRate = round(costRate.plus(costRate.times(margin).div(100)), 4);
    const override = row.client_rate;
    const hasOverride = !(override === null || override === undefined || override === '');
    // Simplified/Normal BOQ: empty Material Rate is 0 — do not show Labour Rate as Material Rate.
    const clientRate = hasOverride
        ? round(d(override), 4)
        : (options.simplifiedCommercial ? round(d(0), 4) : sellingRate);

    return {
        cost_rate: costRate.toFixed(4),
        cost_amount: round(qty.times(costRate), 2).toFixed(2),
        selling_rate: sellingRate.toFixed(4),
        client_rate: clientRate.toFixed(4),
        client_amount: round(qty.times(clientRate), 2).toFixed(2),
    };
}

export function sum(values) {
    return values.reduce((total, value) => total.plus(d(value)), new Decimal(0)).toFixed(2);
}

export function rateAnalysis(header, items) {
    const heads = { material: new Decimal(0), labour: new Decimal(0), equipment: new Decimal(0), subcontract: new Decimal(0), other: new Decimal(0) };
    const lines = items.map((item) => {
        const qty = round(d(item.quantity), 4);
        const effective = qty.plus(qty.times(round(d(item.wastage_percent), 4)).div(100));
        const amount = round(effective.times(round(d(item.rate), 4)), 2);
        if (heads[item.resource_type]) {
            heads[item.resource_type] = heads[item.resource_type].plus(amount);
        }

        return { effective_quantity: round(effective, 4).toFixed(4), amount: amount.toFixed(2) };
    });

    const direct = Object.values(heads).reduce((a, b) => a.plus(b), new Decimal(0));
    const overhead = round(direct.times(d(header.overhead_percent)).div(100), 2);
    const profit = round(direct.plus(overhead).times(d(header.profit_percent)).div(100), 2);
    const total = direct.plus(overhead).plus(profit);
    const outputQty = round(d(header.output_quantity), 4);

    return {
        items: lines,
        material_cost: heads.material.toFixed(2),
        labour_cost: heads.labour.toFixed(2),
        equipment_cost: heads.equipment.toFixed(2),
        subcontract_cost: heads.subcontract.toFixed(2),
        other_cost: heads.other.toFixed(2),
        direct_cost: direct.toFixed(2),
        overhead_amount: overhead.toFixed(2),
        profit_amount: profit.toFixed(2),
        total_cost: total.toFixed(2),
        unit_rate: outputQty.gt(0) ? total.div(outputQty).toDecimalPlaces(4, Decimal.ROUND_HALF_UP).toFixed(4) : null,
    };
}
