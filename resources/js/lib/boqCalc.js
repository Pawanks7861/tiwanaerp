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

export function boqLine(row) {
    const qty = round(d(row.quantity), 4);
    const costRate = round(
        ['material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate'].reduce((sum, key) => sum.plus(round(d(row[key]), 4)), new Decimal(0)),
        4,
    );
    const margin = round(d(row.margin_percent), 4);
    const sellingRate = round(costRate.plus(costRate.times(margin).div(100)), 4);
    const override = row.client_rate;
    const clientRate = override === null || override === undefined || override === '' ? sellingRate : round(d(override), 4);

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
