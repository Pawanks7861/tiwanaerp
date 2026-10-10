import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const packageRoot = dirname(require.resolve('@flyfish-dev/cad-viewer/package.json'));
const source = join(packageRoot, 'dist', 'wasm');
const target = join(process.cwd(), 'public', 'wasm');
const files = ['libredwg-web.js', 'libredwg-web.wasm', 'dwg-worker.js', 'dwfv-render.wasm'];

mkdirSync(target, { recursive: true });

for (const name of files) {
    const from = join(source, name);
    if (!existsSync(from)) {
        throw new Error(`Missing CAD runtime asset: ${name}`);
    }
    cpSync(from, join(target, name));
}

console.log(`Copied ${files.length} CAD runtime assets to public/wasm`);
