import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';
import type { ConnectionEnvironment } from '@/types';

/**
 * Favicon nach Umgebung (Gestaltung § 3): Solange eine Verbindung der Umgebung Produktion offen ist, zeigt der Tab das
 * rote Symbol — auch zwischen vielen Tabs sieht man, wo Vorsicht gilt. Die erste Seite wählt es schon auf dem Server
 * (app.blade.php); hier folgt es dem Wechsel in der App und springt beim Verlassen des Arbeitsbereichs zurück. Die
 * Adressen stehen mit Prüfsumme an den Links (data-default, data-production).
 */
export function useEnvironmentIcon(
    environment: Ref<ConnectionEnvironment | null | undefined>,
): void {
    function show(production: boolean): void {
        document
            .querySelectorAll<HTMLLinkElement>(
                'link[rel="icon"][data-production]',
            )
            .forEach((link) => {
                const href = production
                    ? link.dataset.production
                    : link.dataset.default;

                if (href && link.getAttribute('href') !== href) {
                    link.setAttribute('href', href);
                }
            });
    }

    watch(environment, (value) => show(value === 'production'), {
        immediate: true,
    });
    onBeforeUnmount(() => show(false));
}
