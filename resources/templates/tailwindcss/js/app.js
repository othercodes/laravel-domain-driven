import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import Alert from './Components/Alert.vue';
import { ZiggyVue } from '../../../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // Alert renders nothing of its own: SweetAlert2 mounts its dialog on
        // the body. So it sits beside the page rather than inside a layout,
        // and a flashed alert reaches the guest pages too. Banner stays in
        // AppLayout, being a bar that belongs to the chrome around a page.
        return createApp({ render: () => [h(App, props), h(Alert)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
