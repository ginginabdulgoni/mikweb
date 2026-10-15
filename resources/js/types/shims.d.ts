/// <reference types="vite/client" />

import type { route as ziggyRoute } from 'ziggy-js';

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
    export default component;
}

declare global {
    /* Ziggy's route() helper, injected globally by ZiggyVue. */
    const route: typeof ziggyRoute;
}

declare module 'vue' {
    /* Makes route() resolvable inside component templates. */
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
    }
}

declare module '@inertiajs/vue3' {
    interface PageProps {
        auth: import('./index').PageProps['auth'];
        flash: import('./index').PageProps['flash'];
        app: import('./index').PageProps['app'];
        nav: import('./index').NavData;
    }
}

export {};
