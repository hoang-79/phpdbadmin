<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CircleHelp,
    Columns3,
    Database,
    Eye,
    Keyboard,
    Search,
    Server,
    Table2,
    TerminalSquare,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useOpenTabs } from '@/composables/useOpenTabs';
import { getJson } from '@/lib/api';
import { formatRows } from '@/lib/environment';
import { t } from '@/lib/i18n';
import { dataHref, structureHref } from '@/lib/workspace';
import {
    help,
    home,
    palette as paletteRoute,
    workspace as workspaceRoute,
} from '@/routes';
import { server as serverRoute, sql as sqlRoute } from '@/routes/workspace';
import type { Workspace } from '@/types';

/**
 * Befehlspalette ⌘K (O-02, Wireframe 12): Objekte der offenen Verbindung öffnen, Aktionen ausführen, Verbindung
 * wechseln, zuletzt Geöffnetes. «>» am Anfang zeigt nur Aktionen. Ganz mit der Tastatur bedienbar.
 */
const props = defineProps<{ open: boolean; workspace: Workspace | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; shortcuts: [] }>();

type Entry = {
    key: string;
    section: string;
    icon: Component;
    title: string;
    detail: string;
    run: () => void;
};

const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
const connections = ref<
    {
        id: string;
        name: string;
        group: string | null;
        driverLabel: string;
        environment: string;
    }[]
>([]);
const recent = props.workspace
    ? useOpenTabs(props.workspace.connection.id).tabs
    : ref([]);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        query.value = '';
        active.value = 0;
        await nextTick();
        input.value?.focus();

        if (!connections.value.length) {
            try {
                connections.value = (
                    await getJson<{ connections: typeof connections.value }>(
                        paletteRoute.url(),
                    )
                ).connections;
            } catch {
                // Ohne Liste bleibt die Palette für Objekte und Aktionen nutzbar.
            }
        }
    },
);

function visit(href: string): void {
    emit('update:open', false);
    router.visit(href);
}

function score(name: string, term: string): number {
    const lower = name.toLowerCase();

    if (lower === term) {
        return 0;
    }

    if (lower.startsWith(term)) {
        return 1;
    }

    return lower.includes(term) ? 2 : -1;
}

function newQueryHref(template?: string): string {
    const used = recent.value
        .filter((tab) => tab.kind === 'query')
        .map((tab) => Number(tab.title));
    const workspace = props.workspace!;

    return sqlRoute.url(workspace.connection.id, {
        query: {
            tab: Math.max(0, ...used) + 1,
            ...(workspace.schema ? { schema: workspace.schema } : {}),
            ...(workspace.database ? { db: workspace.database } : {}),
            ...(template ? { vorlage: template } : {}),
        },
    });
}

const entries = computed<Entry[]>(() => {
    const raw = query.value.trim();
    const onlyActions = raw.startsWith('>');
    const term = (onlyActions ? raw.slice(1) : raw).trim().toLowerCase();
    const workspace = props.workspace;
    const list: Entry[] = [];

    const objects = workspace
        ? workspace.groups
              .flatMap((group) => group.items)
              .filter((item) => item.kind === 'table' || item.kind === 'view')
              .map((item) => ({
                  item,
                  rank: term ? score(item.name, term) : -1,
              }))
              .filter(({ rank }) => term !== '' && rank >= 0)
              .sort(
                  (a, b) =>
                      a.rank - b.rank || a.item.name.localeCompare(b.item.name),
              )
              .slice(0, 8)
        : [];

    if (!onlyActions && workspace) {
        for (const { item } of objects) {
            list.push({
                key: `objekt-${item.kind}-${item.name}`,
                section: t('Objekte in :connection', {
                    connection: workspace.connection.name,
                }),
                icon: item.kind === 'view' ? Eye : Table2,
                title: item.name,
                detail:
                    item.kind === 'view'
                        ? t('Sicht')
                        : [t('Tabelle'), formatRows(item.rows)]
                              .filter(Boolean)
                              .join(' · '),
                run: () => visit(dataHref(workspace, item.name)),
            });
        }

        if (term === '') {
            for (const tab of recent.value.slice(-6).reverse()) {
                list.push({
                    key: `zuletzt-${tab.key}`,
                    section: t('Zuletzt geöffnet'),
                    icon:
                        tab.kind === 'query'
                            ? TerminalSquare
                            : tab.kind === 'view'
                              ? Eye
                              : Table2,
                    title:
                        tab.kind === 'query'
                            ? t('Abfrage :number', { number: tab.title })
                            : tab.title,
                    detail: '',
                    run: () => visit(tab.href),
                });
            }
        }

        for (const connection of connections.value
            .filter(
                (item) =>
                    item.id !== workspace.connection.id &&
                    (term === '' || score(item.name, term) >= 0),
            )
            .slice(0, 5)) {
            list.push({
                key: `verbindung-${connection.id}`,
                section: t('Andere Verbindungen'),
                icon: ArrowRightLeft,
                title: connection.name,
                detail: [connection.driverLabel, connection.group]
                    .filter(Boolean)
                    .join(' · '),
                run: () => visit(workspaceRoute.url(connection.id)),
            });
        }
    }

    const top = objects[0]?.item;

    if (workspace && top) {
        list.push(
            {
                key: 'aktion-abfrage-objekt',
                section: t('Aktionen'),
                icon: TerminalSquare,
                title: t('Neue Abfrage auf :name', { name: top.name }),
                detail: t('SELECT … FROM :name im SQL-Editor', {
                    name: top.name,
                }),
                run: () =>
                    visit(newQueryHref(`SELECT * FROM ${top.name} LIMIT 100;`)),
            },
            {
                key: 'aktion-struktur',
                section: t('Aktionen'),
                icon: Columns3,
                title: t('Struktur von :name', { name: top.name }),
                detail: t('Spalten, Indizes, Fremdschlüssel, DDL'),
                run: () => visit(structureHref(workspace, top.name)),
            },
        );
    }

    const actions: Entry[] = [
        ...(workspace
            ? [
                  {
                      key: 'aktion-abfrage',
                      section: t('Aktionen'),
                      icon: TerminalSquare,
                      title: t('Neue Abfrage'),
                      detail: t('SQL-Editor'),
                      run: () => visit(newQueryHref()),
                  },
                  {
                      key: 'aktion-server',
                      section: t('Aktionen'),
                      icon: Server,
                      title: t('Server'),
                      detail: t(
                          'Übersicht, Prozesse, Variablen, Konten, Wartung',
                      ),
                      run: () =>
                          visit(serverRoute.url(workspace.connection.id)),
                  },
              ]
            : []),
        {
            key: 'aktion-verbindungen',
            section: t('Aktionen'),
            icon: Database,
            title: t('Alle Verbindungen'),
            detail: '',
            run: () => visit(home.url()),
        },
        {
            key: 'aktion-hilfe',
            section: t('Aktionen'),
            icon: CircleHelp,
            title: t('Hilfe'),
            detail: '',
            run: () => visit(help.url()),
        },
        {
            key: 'aktion-kuerzel',
            section: t('Aktionen'),
            icon: Keyboard,
            title: t('Tastenkürzel'),
            detail: '?',
            run: () => {
                emit('update:open', false);
                emit('shortcuts');
            },
        },
    ];

    list.push(
        ...actions.filter(
            (action) =>
                term === '' || action.title.toLowerCase().includes(term),
        ),
    );

    return list;
});

watch(query, () => {
    active.value = 0;
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(entries.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        entries.value[active.value]?.run();
    }

    document
        .getElementById(`palette-${active.value}`)
        ?.scrollIntoView({ block: 'nearest' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="top-[12vh] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-2xl"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">{{ t('Befehlspalette') }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                t('Tabelle, Abfrage oder Befehl suchen')
            }}</DialogDescription>
            <div class="flex items-center gap-2 border-b px-4">
                <Search class="size-4 shrink-0 text-text-secondary" />
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-liste"
                    :aria-activedescendant="
                        entries.length ? `palette-${active}` : undefined
                    "
                    :placeholder="t('Tabelle, Abfrage oder Befehl suchen …')"
                    class="h-12 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
                <kbd
                    class="rounded border bg-muted px-1.5 font-mono text-[11px] text-muted-foreground"
                    >esc</kbd
                >
            </div>
            <ul
                id="palette-liste"
                role="listbox"
                class="max-h-[60vh] overflow-y-auto p-2"
            >
                <template v-for="(entry, index) in entries" :key="entry.key">
                    <li
                        v-if="
                            index === 0 ||
                            entries[index - 1].section !== entry.section
                        "
                        role="presentation"
                        class="px-2 pt-2 pb-1 text-[12px] font-semibold text-text-secondary"
                    >
                        {{ entry.section }}
                    </li>
                    <li
                        :id="`palette-${index}`"
                        role="option"
                        :aria-selected="index === active"
                        :class="[
                            'flex cursor-pointer items-center gap-3 rounded-md px-3 py-2',
                            index === active
                                ? 'bg-accent shadow-[inset_3px_0_0_var(--primary)]'
                                : 'hover:bg-muted',
                        ]"
                        @mousemove="active = index"
                        @click="entry.run()"
                    >
                        <component
                            :is="entry.icon"
                            class="size-4 shrink-0 text-text-secondary"
                        />
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate font-mono text-sm">{{
                                entry.title
                            }}</span>
                            <span
                                v-if="entry.detail"
                                class="truncate text-xs text-text-secondary"
                                >{{ entry.detail }}</span
                            >
                        </span>
                        <span
                            v-if="index === active"
                            class="ml-auto flex shrink-0 items-center gap-1 text-xs text-text-secondary"
                            ><kbd class="rounded border bg-muted px-1 font-mono"
                                >↵</kbd
                            >{{ t('öffnen') }}</span
                        >
                    </li>
                </template>
                <li
                    v-if="!entries.length"
                    class="px-3 py-6 text-center text-sm text-text-secondary"
                >
                    {{ t('Nichts gefunden.') }}
                </li>
            </ul>
            <div
                class="hidden items-center gap-4 border-t px-4 py-2 text-xs text-text-secondary sm:flex"
            >
                <span
                    ><kbd class="rounded border bg-muted px-1 font-mono">↑</kbd>
                    <kbd class="rounded border bg-muted px-1 font-mono">↓</kbd>
                    {{ t('wählen') }}</span
                >
                <span
                    ><kbd class="rounded border bg-muted px-1 font-mono">↵</kbd>
                    {{ t('öffnen') }}</span
                >
                <span
                    ><kbd class="rounded border bg-muted px-1 font-mono"
                        >esc</kbd
                    >
                    {{ t('schliessen') }}</span
                >
                <span class="ml-auto">{{
                    t('«>» am Anfang zeigt nur Aktionen')
                }}</span>
            </div>
        </DialogContent>
    </Dialog>
</template>
