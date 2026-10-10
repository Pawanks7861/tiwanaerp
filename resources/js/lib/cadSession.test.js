import assert from 'node:assert/strict';
import test from 'node:test';
import { assertPrivateStream, cadErrorMessage, fetchCadBuffer, openCadBuffer, usesBrowserCad } from './cadSession.js';

test('dwg and dxf use the browser viewer and other files do not', () => {
    assert.equal(usesBrowserCad({ strategy: 'cad', extension: 'dwg' }), true);
    assert.equal(usesBrowserCad({ strategy: 'cad', extension: 'DXF' }), true);
    assert.equal(usesBrowserCad({ strategy: 'pdf', extension: 'pdf' }), false);
    assert.equal(usesBrowserCad({ strategy: 'image', extension: 'png' }), false);
    assert.equal(usesBrowserCad({ strategy: 'cad', extension: 'pdf' }), false);
});

test('the loader uses the private stream and rejects an external url', () => {
    assert.equal(assertPrivateStream('/files/attachment/4/stream'), '/files/attachment/4/stream');
    assert.throws(() => assertPrivateStream('https://iframe.sharecad.org/cadframe/load?url=1'), /private stream|this server/);
    assert.throws(() => assertPrivateStream('/external-file-preview/token'), /private stream/);
});

test('an authorized stream is passed to the viewer with the real filename', async () => {
    const bytes = new Uint8Array([65, 67, 49, 48]).buffer;
    const calls = [];
    const fetchImpl = async (url) => {
        calls.push(url);

        return { ok: true, arrayBuffer: async () => bytes };
    };
    const viewer = {
        async loadBuffer(buffer, fileName) {
            calls.push([buffer, fileName]);
        },
    };

    await openCadBuffer(viewer, '/files/drawing_revision/9/stream', 'plan.dwg', fetchImpl);

    assert.equal(calls[0], '/files/drawing_revision/9/stream');
    assert.equal(calls[1][0], bytes);
    assert.equal(calls[1][1], 'plan.dwg');
});

test('failed streams and parser errors stay safe', async () => {
    const forbidden = fetchCadBuffer('/files/attachment/1/stream', async () => ({ ok: false, status: 403 }));
    await assert.rejects(forbidden, (error) => error.status === 403);
    assert.equal(cadErrorMessage({ status: 403 }), 'Permission denied');
    assert.equal(cadErrorMessage({ status: 404 }), 'Drawing not found');
    assert.equal(cadErrorMessage(new Error('DWG parse failed')), 'This drawing could not be opened.');
    assert.equal(cadErrorMessage(new Error('wasm init failed at /wasm/libredwg-web.wasm')), 'The CAD engine could not start in this browser.');
    assert.equal(cadErrorMessage({ name: 'AbortError' }), 'Loading cancelled.');
});
