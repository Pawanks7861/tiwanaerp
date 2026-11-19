/**
 * Shared WBS dropdown / display label: "WBS-02 — Building".
 * Keep in sync with ProjectCostHead::formatLabel on the server.
 */
export function formatWbsLabel(code, category) {
    const c = String(code ?? '').trim();
    const n = String(category ?? '').trim();
    if (!c && !n) {
        return '';
    }
    if (!c) {
        return n;
    }
    if (!n) {
        return c;
    }

    return `${c} — ${n}`;
}
