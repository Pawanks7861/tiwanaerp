import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // Bind IPv4 explicitly so pages opened at 127.0.0.1:8000 can load Vite modules.
    // On this machine "localhost" resolves to [::1], which leaves a blank Inertia page.
    // server: {
    //     host: '127.0.0.1',
    //     port: 5173,
    //     strictPort: true,
    // },
    // build: {
        sourcemap: false,
        chunkSizeWarningLimit: 700,
        // Vite 8 / Rolldown: PLUGIN_TIMINGS is informational (Tailwind/Vue dominate wall time).
        // Suppress the noisy false-positive warning; build still succeeds either way.
        rolldownOptions: {
            checks: {
                bundlerTimings: false,
            },
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
