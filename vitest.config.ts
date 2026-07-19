import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Unit tests for the main-app frontend (resources/js). The nurse PWA keeps its own
// config (nurse-pwa/vitest.config.ts, run via `npm run test:pwa`). TZ is pinned to a
// behind-UTC zone so the date-only timezone regression (M-2) is actually exercised.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.{test,spec}.ts'],
        environment: 'node',
        env: { TZ: 'America/Los_Angeles' },
    },
});
