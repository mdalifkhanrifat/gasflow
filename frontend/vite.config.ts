import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import { defineConfig } from 'vite-plus';

const api = process.env.GASFLOW_API_PROXY ?? 'http://localhost:8047';

export default defineConfig({
    plugins: [
        vue(),
        tailwindcss(),
        // Shell-only service worker: it precaches the built app (scripts,
        // styles, fonts, icons) and answers page loads with index.html.
        // API, CSRF and health requests always go to the network, so
        // invoices, balances and payments are never stored on the device.
        VitePWA({
            registerType: 'prompt',
            injectRegister: false,
            manifest: false,
            workbox: {
                globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2,webp}'],
                navigateFallback: '/index.html',
                navigateFallbackDenylist: [
                    /^\/api(?:\/|$)/,
                    /^\/sanctum(?:\/|$)/,
                    /^\/up$/,
                ],
                cleanupOutdatedCaches: true,
                runtimeCaching: [],
            },
            devOptions: { enabled: false },
        }),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
        host: process.env.DOCKER_VITE === '1' ? '0.0.0.0' : 'localhost',
        port: 5173,
        strictPort: true,
        proxy: {
            '/api': api,
            '/sanctum': api,
        },
    },
    lint: {
        options: { denyWarnings: true, typeAware: true },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: './src/styles/app.css',
        },
    },
});
