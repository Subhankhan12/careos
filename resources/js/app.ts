import './bootstrap';

// Self-host the Inter webfont (UI.F1) — the design tokens name 'Inter', but nothing was
// delivering it, so the app only rendered Inter where it happened to be system-installed and
// fell back to system-ui (Segoe UI / SF / Roboto) everywhere else — shifting type + rhythm on
// every page vs the Eucalyptus Glow prototype (which loads Inter). @fontsource bundles the
// woff2 (font-display: swap) into the build — CSP-safe, no external CDN. Weights 400/500/600/700
// cover every font-* utility the app uses (normal/medium/semibold/bold).
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';

import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18n } from './i18n';

const appName = 'CareOS';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#5c7d55',
    },
});
