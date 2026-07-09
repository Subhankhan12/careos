import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    root: 'nurse-pwa',
    base: '/nurse-pwa/',
    plugins: [
        vue(),
        VitePWA({
            registerType: 'autoUpdate',
            strategies: 'generateSW',
            includeAssets: ['favicon.ico'],
            manifest: {
                name: 'CareOS Nurse',
                short_name: 'CareOS Nurse',
                display: 'standalone',
                start_url: '/nurse-pwa/',
                theme_color: '#0f766e',
                background_color: '#f8fafc',
                icons: [],
            },
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,webmanifest}'],
                navigateFallback: '/nurse-pwa/index.html',
            },
        }),
    ],
    build: {
        outDir: '../public/nurse-pwa',
        emptyOutDir: true,
    },
    resolve: {
        alias: {
            '@nurse': '/nurse-pwa/src',
        },
    },
});
