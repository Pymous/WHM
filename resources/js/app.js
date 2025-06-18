import '@fontsource/oxygen';
import '@fortawesome/fontawesome-free/css/all.css';
import '../css/app.css';

import axios from 'axios';
window.axios = axios;

// Add axios response interceptor
window.axios.interceptors.response.use(
    (response) => {
        const data = response.data;
        if (typeof data === 'object' && data !== null) {
            // Check if response has success property
            if (data.success !== undefined) {
                // Create a global event to trigger the alert
                const event = new CustomEvent('show-alert', {
                    detail: {
                        type: 'success',
                        message: data.success, // Use the success property as the message
                    },
                });
                window.dispatchEvent(event);
            }
        }
        return response;
    },
    (error) => {
        return Promise.reject(error);
    },
);

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';

import Alert from './components/Alert.vue';
import AlertManager from './components/AlertManager.vue';
import ToonDisplay from './components/ToonDisplay.vue';
import UserDisplay from './components/UserDisplay.vue';
import AppLayout from './layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .component('AppLayout', AppLayout)
            .component('alert', Alert)
            .component('toon-display', ToonDisplay)
            .component('user-display', UserDisplay)
            .component('alert-manager', AlertManager)
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
