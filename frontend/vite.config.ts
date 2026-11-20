import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite-plus';

const api = process.env.GASFLOW_API_PROXY ?? 'http://localhost:8047';

export default defineConfig({
    plugins: [vue(), tailwindcss()],
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
