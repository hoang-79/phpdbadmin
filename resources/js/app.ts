import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import WorkspaceLayout from '@/layouts/WorkspaceLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'phpdbadmin';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('probe/'):
                return null;
            case name.startsWith('auth/'):
            case name.startsWith('onboarding/'):
            case name.startsWith('connect/'):
                return AuthLayout;
            case name.startsWith('workspace/'):
                return WorkspaceLayout;
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#0b6e82',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Sprache wechselt ohne neues Laden der Seite (N-06): Vorlesefunktionen und Silbentrennung folgen.
router.on('navigate', (event) => {
    const locale = event.detail.page.props.locale;

    if (typeof locale === 'string') {
        document.documentElement.lang = locale;
    }
});
