const CAD_EXTENSIONS = ['dwg', 'dxf'];

export function usesBrowserCad(file) {
    const extension = String(file?.extension || '').toLowerCase();

    return file?.strategy === 'cad' && CAD_EXTENSIONS.includes(extension);
}

export function assertPrivateStream(url) {
    const value = String(url || '');
    if (!value.includes('/files/') || !value.includes('/stream')) {
        throw new Error('CAD preview requires the private stream.');
    }
    if (/sharecad|external-file-preview|googleapis|autodesk/i.test(value)) {
        throw new Error('CAD preview must stay on this server.');
    }

    return value;
}

export async function fetchCadBuffer(streamUrl, fetchImpl = fetch, signal) {
    const response = await fetchImpl(assertPrivateStream(streamUrl), {
        credentials: 'same-origin',
        signal,
    });
    if (!response.ok) {
        const error = new Error('Unable to load drawing');
        error.status = response.status;
        throw error;
    }

    return response.arrayBuffer();
}

export async function openCadBuffer(viewer, streamUrl, fileName, fetchImpl = fetch, signal) {
    const buffer = await fetchCadBuffer(streamUrl, fetchImpl, signal);
    await viewer.loadBuffer(buffer, fileName, { signal });

    return buffer;
}

export function cadErrorMessage(error) {
    if (error?.name === 'AbortError') {
        return 'Loading cancelled.';
    }
    if (error?.status === 403) {
        return 'Permission denied';
    }
    if (error?.status === 404) {
        return 'Drawing not found';
    }

    const text = String(error?.message || '');
    if (/wasm|worker/i.test(text)) {
        return 'The CAD engine could not start in this browser.';
    }
    if (/memory/i.test(text)) {
        return 'This drawing needs more browser memory than is available.';
    }

    return 'This drawing could not be opened.';
}

export function layerRows(document) {
    const layers = document?.layers || {};

    return Object.values(layers)
        .filter((layer) => layer && typeof layer.name === 'string' && layer.name !== '')
        .map((layer) => ({
            name: layer.name,
            visible: layer.isVisible !== false && layer.isFrozen !== true,
        }));
}

export function applyLayerVisibility(viewer, name, visible) {
    for (const document of [viewer.getDocument?.(), viewer.getSourceDocument?.()]) {
        if (document?.layers?.[name]) {
            document.layers[name].isVisible = visible;
            document.layers[name].isFrozen = false;
        }
    }
    const document = viewer.getDocument?.();
    if (document && typeof viewer.setDocument === 'function') {
        viewer.setDocument(document);
    }
}
