import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Offene Reiter je Verbindung (O-03, Wireframe 05): Tabellen, Sichten und später Abfragen. Im Browser gespeichert,
 * damit sie das Neuladen überstehen; jeder Reiter merkt sich seine letzte Adresse samt Filtern.
 */
export type OpenTab = {
    key: string;
    title: string;
    kind: 'table' | 'view' | 'query';
    href: string;
};

const STORAGE_PREFIX = 'phpdbadmin.reiter.';
const MAX_TABS = 20;

function read(connectionId: string): OpenTab[] {
    try {
        const raw = window.localStorage.getItem(STORAGE_PREFIX + connectionId);
        const parsed = raw ? (JSON.parse(raw) as unknown) : [];

        return Array.isArray(parsed)
            ? parsed.filter(
                  (tab): tab is OpenTab =>
                      typeof tab?.key === 'string' &&
                      typeof tab?.href === 'string' &&
                      typeof tab?.title === 'string',
              )
            : [];
    } catch {
        return [];
    }
}

const cache = new Map<string, Ref<OpenTab[]>>();

export function useOpenTabs(connectionId: string): {
    tabs: Ref<OpenTab[]>;
    open: (tab: OpenTab) => void;
    close: (key: string) => OpenTab | null;
} {
    let tabs = cache.get(connectionId);

    if (!tabs) {
        const created = ref<OpenTab[]>(
            typeof window === 'undefined' ? [] : read(connectionId),
        );
        watch(
            created,
            (value) => {
                try {
                    window.localStorage.setItem(
                        STORAGE_PREFIX + connectionId,
                        JSON.stringify(value),
                    );
                } catch {
                    // Speicher voll oder gesperrt: Reiter gelten dann nur bis zum Neuladen.
                }
            },
            { deep: true },
        );
        cache.set(connectionId, created);
        tabs = created;
    }

    const list = tabs;

    function open(tab: OpenTab): void {
        const index = list.value.findIndex((item) => item.key === tab.key);

        if (index >= 0) {
            list.value[index] = tab;

            return;
        }

        list.value = [...list.value, tab].slice(-MAX_TABS);
    }

    /** Schliesst den Reiter und nennt den Nachbarn, zu dem man wechseln kann. */
    function close(key: string): OpenTab | null {
        const index = list.value.findIndex((item) => item.key === key);

        if (index < 0) {
            return null;
        }

        list.value = list.value.filter((item) => item.key !== key);

        return list.value[index] ?? list.value[index - 1] ?? null;
    }

    return { tabs: list, open, close };
}
