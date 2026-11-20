/**
 * Copy plain text to the clipboard. Prefers Clipboard API; falls back to a temporary textarea.
 *
 * @returns {Promise<boolean>} true when copy succeeded
 */
export async function copyText(text) {
    const value = text == null ? '' : String(text);
    if (value === '') {
        return false;
    }

    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);

            return true;
        } catch {
            // Fall through to legacy path (permissions / insecure context).
        }
    }

    try {
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        area.style.top = '0';
        document.body.appendChild(area);
        area.focus();
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);

        return ok;
    } catch {
        return false;
    }
}
