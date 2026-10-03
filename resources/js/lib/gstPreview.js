import Decimal from 'decimal.js';

/**
 * Live preview of quotation / purchase order amounts while a form is being edited. Mirrors
 * App\Services\Tax\GstCalculator (paisa rounding per line, HALF_UP); the server recalculates
 * and stores the authoritative values on save.
 */
const D = (value) => {
    try {
        return new Decimal(value === null || value === undefined || value === '' ? 0 : String(value));
    } catch {
        return new Decimal(0);
    }
};
const paisa = (d) => d.toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
const pct = (d, percent) => d.times(D(percent)).dividedBy(100);

function parts(quantity, rate, discountPercent) {
    const base = paisa(D(quantity).times(D(rate)));
    const discount = paisa(pct(base, discountPercent));

    return { base, discount, taxable: base.minus(discount) };
}

export function quotationLine(quantity, rate, discountPercent, taxPercent) {
    const { base, discount, taxable } = parts(quantity, rate, discountPercent);
    const tax = paisa(pct(taxable, taxPercent));

    return { base, discount, taxable, tax, amount: taxable.plus(tax) };
}

/** taxRate: { cgst_rate, sgst_rate, igst_rate } or null; intra: boolean */
export function orderLine(quantity, rate, discountPercent, taxRate, intra) {
    const { base, discount, taxable } = parts(quantity, rate, discountPercent);
    const cgst = taxRate && intra ? paisa(pct(taxable, taxRate.cgst_rate)) : D(0);
    const sgst = taxRate && intra ? paisa(pct(taxable, taxRate.sgst_rate)) : D(0);
    const igst = taxRate && !intra ? paisa(pct(taxable, taxRate.igst_rate)) : D(0);

    return { base, discount, taxable, cgst, sgst, igst, amount: taxable.plus(cgst).plus(sgst).plus(igst) };
}

export function orderTotals(lines, freight, other) {
    const sum = (key) => lines.reduce((total, line) => total.plus(line[key] ?? 0), D(0));
    const totals = {
        base: sum('base'),
        discount: sum('discount'),
        taxable: sum('taxable'),
        cgst: sum('cgst'),
        sgst: sum('sgst'),
        igst: sum('igst'),
        tax: lines.reduce((total, line) => total.plus(line.tax ?? line.cgst.plus(line.sgst).plus(line.igst)), D(0)),
        freight: paisa(D(freight)),
        other: paisa(D(other)),
    };
    const beforeRounding = totals.taxable.plus(totals.tax).plus(totals.freight).plus(totals.other);
    const grand = beforeRounding.toDecimalPlaces(0, Decimal.ROUND_HALF_UP);

    return { ...totals, beforeRounding, roundOff: grand.minus(beforeRounding), grand };
}

export const str = (d) => d.toFixed(2);
