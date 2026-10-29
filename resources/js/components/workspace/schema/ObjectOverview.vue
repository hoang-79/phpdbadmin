<script setup lang="ts">
import { Link, useRemember } from '@inertiajs/vue3';
import {
    ArrowDownNarrowWide,
    ArrowUpNarrowWide,
    Eye,
    LayoutGrid,
    List,
    Search,
    Table2,
    TableProperties,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, watch } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { putJson } from '@/lib/api';
import { formatRows } from '@/lib/environment';
import { formatBytes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { dataHref } from '@/lib/workspace';
import { schemaOverview } from '@/routes/account/preferences';
import type { NavigatorItem, SchemaOverviewSettings, Workspace } from '@/types';

/**
 * Tabellen und Sichten eines Schemas: als Kacheln, als Liste mit Spalten oder kompakt; filtern, nach Art einschränken,
 * sortieren, in Seiten blättern. Jede Änderung speichert das Konto von selbst (SchemaOverview.php) — der Filter nur für
 * dieses Schema, alles andere für alle. Die Seite merkt sich Inertia im Verlauf, damit «zurück» dort weitermacht.
 */
const props = defineProps<{
    workspace: Workspace;
    items: NavigatorItem[];
    overview: SchemaOverviewSettings;
}>();

type Sort = SchemaOverviewSettings['sort'];
type View = SchemaOverviewSettings['view'];

const settings = reactive<SchemaOverviewSettings>({ ...props.overview });
const scope = computed(
    () =>
        `${props.workspace.connection.id}:${props.workspace.database ?? ''}:${props.workspace.schema ?? ''}`,
);
const remembered = useRemember(
    reactive({ page: 1 }),
    `schema-uebersicht:${scope.value}`,
) as { page: number };

const views: { key: View; label: string; icon: unknown }[] = [
    { key: 'cards', label: t('Kacheln'), icon: LayoutGrid },
    { key: 'list', label: t('Liste'), icon: TableProperties },
    { key: 'compact', label: t('Kompakt'), icon: List },
];
const sorts: { key: Sort; label: string }[] = [
    { key: 'name', label: t('Name') },
    { key: 'rows', label: t('Zeilen') },
    { key: 'size', label: t('Grösse') },
    { key: 'kind', label: t('Art') },
];
const pageSizes = [25, 50, 100, 250, 0];

const tableCount = computed(
    () => props.items.filter((item) => item.kind !== 'view').length,
);
const viewCount = computed(() => props.items.length - tableCount.value);

const filtered = computed(() => {
    const term = settings.filter.trim().toLowerCase();

    return props.items.filter((item) => {
        if (settings.kind === 'tables' && item.kind === 'view') {
            return false;
        }

        if (settings.kind === 'views' && item.kind !== 'view') {
            return false;
        }

        return (
            term === '' ||
            item.name.toLowerCase().includes(term) ||
            (item.comment ?? '').toLowerCase().includes(term)
        );
    });
});

function byName(a: NavigatorItem, b: NavigatorItem): number {
    return a.name.localeCompare(b.name, undefined, {
        numeric: true,
        sensitivity: 'base',
    });
}

/** Unbekannte Werte (Sichten, Treiber ohne Schätzung) stehen immer hinten. */
function compare(a: NavigatorItem, b: NavigatorItem): number {
    const direction = settings.direction === 'asc' ? 1 : -1;

    if (settings.sort === 'name') {
        return direction * byName(a, b);
    }

    if (settings.sort === 'kind') {
        const rank = (item: NavigatorItem) => (item.kind === 'view' ? 1 : 0);

        return rank(a) !== rank(b)
            ? direction * (rank(a) - rank(b))
            : byName(a, b);
    }

    const left = a[settings.sort];
    const right = b[settings.sort];

    if (left === null || right === null) {
        return left === right ? byName(a, b) : left === null ? 1 : -1;
    }

    return left !== right ? direction * (left - right) : byName(a, b);
}

const sorted = computed(() => [...filtered.value].sort(compare));
const pageCount = computed(() =>
    settings.perPage === 0
        ? 1
        : Math.max(1, Math.ceil(sorted.value.length / settings.perPage)),
);
const page = computed({
    get: () => Math.min(remembered.page, pageCount.value),
    set: (value: number) => (remembered.page = value),
});
const visible = computed(() =>
    settings.perPage === 0
        ? sorted.value
        : sorted.value.slice(
              (page.value - 1) * settings.perPage,
              page.value * settings.perPage,
          ),
);
const range = computed(() => {
    if (sorted.value.length === 0) {
        return { from: 0, to: 0 };
    }

    const from =
        settings.perPage === 0 ? 1 : (page.value - 1) * settings.perPage + 1;

    return { from, to: from + visible.value.length - 1 };
});

/** Andere Spalte: Name und Art von A nach Z, Zeilen und Grösse die grössten zuerst; dieselbe Spalte dreht um. */
function sortBy(key: Sort): void {
    if (settings.sort === key) {
        settings.direction = settings.direction === 'asc' ? 'desc' : 'asc';

        return;
    }

    settings.sort = key;
    settings.direction = key === 'rows' || key === 'size' ? 'desc' : 'asc';
}

function ariaSort(key: Sort): 'ascending' | 'descending' | undefined {
    if (settings.sort !== key) {
        return undefined;
    }

    return settings.direction === 'asc' ? 'ascending' : 'descending';
}

// Neue Auswahl beginnt vorne.
watch(
    () => [
        settings.filter,
        settings.kind,
        settings.sort,
        settings.direction,
        settings.perPage,
    ],
    () => (remembered.page = 1),
);

// Anderes Schema (gleiche Seite, neue Daten): dessen Einstellungen übernehmen.
watch(
    () => props.overview,
    (next) => Object.assign(settings, next),
);

// Automatisch speichern, sobald eine Weile nichts mehr geändert wird.
watchDebounced(
    () => ({ ...settings }),
    (current) => {
        putJson(schemaOverview.url(), {
            ...current,
            connection: props.workspace.connection.id,
            database: props.workspace.database,
            schema: props.workspace.schema,
        }).catch(() => {
            // Nicht gespeichert heisst nur: beim nächsten Besuch die alte Einstellung. Die Seite bleibt bedienbar.
        });
    },
    { debounce: 600, deep: true },
);

function rowsLabel(item: NavigatorItem): string {
    return item.kind === 'view' ? t('Sicht') : formatRows(item.rows);
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <label class="relative w-full sm:w-72">
                <span class="sr-only">{{
                    t('Tabellen und Sichten filtern')
                }}</span>
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="settings.filter"
                    type="search"
                    maxlength="100"
                    :placeholder="t('Tabellen und Sichten filtern')"
                    class="h-9 w-full rounded-md border bg-background pr-2 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </label>

            <div
                v-if="viewCount > 0"
                class="inline-flex rounded-md border p-0.5"
                role="group"
                :aria-label="t('Art')"
            >
                <button
                    v-for="option in [
                        { key: 'all', label: t('Alle'), count: items.length },
                        {
                            key: 'tables',
                            label: t('Tabellen'),
                            count: tableCount,
                        },
                        { key: 'views', label: t('Sichten'), count: viewCount },
                    ] as const"
                    :key="option.key"
                    type="button"
                    :class="[
                        'h-7 rounded px-2.5 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        settings.kind === option.key
                            ? 'bg-accent font-medium text-foreground'
                            : 'text-text-secondary hover:text-foreground',
                    ]"
                    :aria-pressed="settings.kind === option.key"
                    @click="settings.kind = option.key"
                >
                    {{ option.label }}
                    <span class="text-muted-foreground tabular-nums">{{
                        option.count
                    }}</span>
                </button>
            </div>

            <div class="flex items-center gap-1 sm:ml-auto">
                <label for="uebersicht-sortierung" class="sr-only">{{
                    t('Sortieren nach')
                }}</label>
                <select
                    id="uebersicht-sortierung"
                    :value="settings.sort"
                    class="h-9 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @change="
                        sortBy(
                            ($event.target as HTMLSelectElement).value as Sort,
                        )
                    "
                >
                    <option
                        v-for="option in sorts"
                        :key="option.key"
                        :value="option.key"
                    >
                        {{ t('Sortieren: :field', { field: option.label }) }}
                    </option>
                </select>
                <button
                    type="button"
                    class="inline-grid size-9 place-items-center rounded-md border text-text-secondary outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="
                        settings.direction === 'asc'
                            ? t('Aufsteigend — umdrehen')
                            : t('Absteigend — umdrehen')
                    "
                    :title="
                        settings.direction === 'asc'
                            ? t('Aufsteigend')
                            : t('Absteigend')
                    "
                    @click="
                        settings.direction =
                            settings.direction === 'asc' ? 'desc' : 'asc'
                    "
                >
                    <component
                        :is="
                            settings.direction === 'asc'
                                ? ArrowUpNarrowWide
                                : ArrowDownNarrowWide
                        "
                        class="size-4"
                    />
                </button>

                <div
                    class="ml-1 inline-flex rounded-md border p-0.5"
                    role="group"
                    :aria-label="t('Ansicht')"
                >
                    <button
                        v-for="option in views"
                        :key="option.key"
                        type="button"
                        :class="[
                            'inline-grid size-7 place-items-center rounded outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            settings.view === option.key
                                ? 'bg-accent text-foreground'
                                : 'text-text-secondary hover:text-foreground',
                        ]"
                        :aria-pressed="settings.view === option.key"
                        :aria-label="option.label"
                        :title="option.label"
                        @click="settings.view = option.key"
                    >
                        <component :is="option.icon" class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <p
            v-if="sorted.length === 0"
            class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
        >
            {{ t('Nichts passt zu diesem Filter.') }}
            <button
                type="button"
                class="text-primary underline underline-offset-2"
                @click="
                    settings.filter = '';
                    settings.kind = 'all';
                "
            >
                {{ t('Filter zurücksetzen') }}
            </button>
        </p>

        <!-- Kacheln -->
        <ul
            v-else-if="settings.view === 'cards'"
            class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li v-for="item in visible" :key="`${item.kind}:${item.name}`">
                <Link
                    :href="dataHref(workspace, item.name)"
                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm outline-none hover:border-text-secondary focus-visible:ring-2 focus-visible:ring-ring"
                    :title="item.comment ?? undefined"
                >
                    <component
                        :is="item.kind === 'view' ? Eye : Table2"
                        class="size-4 shrink-0 text-text-secondary"
                    />
                    <span class="truncate font-mono">{{ item.name }}</span>
                    <span
                        class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                        >{{ rowsLabel(item) }}</span
                    >
                </Link>
            </li>
        </ul>

        <!-- Liste mit Spalten; ein Klick auf den Spaltenkopf sortiert. -->
        <div
            v-else-if="settings.view === 'list'"
            class="overflow-x-auto rounded-lg border"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted text-left text-[13px] text-text-secondary"
                >
                    <tr>
                        <th
                            v-for="column in [
                                { key: 'name', label: t('Name'), end: false },
                                { key: 'kind', label: t('Art'), end: false },
                                { key: 'rows', label: t('Zeilen'), end: true },
                                { key: 'size', label: t('Grösse'), end: true },
                            ] as const"
                            :key="column.key"
                            scope="col"
                            :aria-sort="ariaSort(column.key)"
                            :class="[
                                'px-3 py-2 font-medium',
                                column.end && 'text-right',
                            ]"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <component
                                    :is="
                                        settings.direction === 'asc'
                                            ? ArrowUpNarrowWide
                                            : ArrowDownNarrowWide
                                    "
                                    v-if="settings.sort === column.key"
                                    class="size-3.5"
                                />
                            </button>
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            {{ t('Kommentar') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in visible"
                        :key="`${item.kind}:${item.name}`"
                        class="border-t hover:bg-muted/60"
                    >
                        <td class="max-w-0 min-w-56 px-3 py-1.5">
                            <Link
                                :href="dataHref(workspace, item.name)"
                                class="flex items-center gap-2 rounded outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <component
                                    :is="item.kind === 'view' ? Eye : Table2"
                                    class="size-4 shrink-0 text-text-secondary"
                                />
                                <span class="truncate font-mono">{{
                                    item.name
                                }}</span>
                            </Link>
                        </td>
                        <td
                            class="px-3 py-1.5 whitespace-nowrap text-text-secondary"
                        >
                            {{
                                item.kind === 'view' ? t('Sicht') : t('Tabelle')
                            }}
                        </td>
                        <td
                            class="px-3 py-1.5 text-right whitespace-nowrap text-text-secondary tabular-nums"
                        >
                            {{
                                item.kind === 'view'
                                    ? ''
                                    : formatRows(item.rows)
                            }}
                        </td>
                        <td
                            class="px-3 py-1.5 text-right whitespace-nowrap text-text-secondary tabular-nums"
                        >
                            {{ formatBytes(item.size) }}
                        </td>
                        <td
                            class="max-w-0 px-3 py-1.5 text-text-secondary"
                            :title="item.comment ?? undefined"
                        >
                            <span class="block truncate">{{
                                item.comment
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Kompakt: nur Namen, in Spalten -->
        <ul
            v-else
            class="columns-1 gap-x-6 text-[13px] sm:columns-2 lg:columns-3 2xl:columns-4"
        >
            <li
                v-for="item in visible"
                :key="`${item.kind}:${item.name}`"
                class="break-inside-avoid"
            >
                <Link
                    :href="dataHref(workspace, item.name)"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    :title="item.comment ?? undefined"
                >
                    <component
                        :is="item.kind === 'view' ? Eye : Table2"
                        class="size-3.5 shrink-0 text-text-secondary"
                    />
                    <span class="truncate font-mono">{{ item.name }}</span>
                </Link>
            </li>
        </ul>

        <div
            v-if="sorted.length > 0"
            class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-text-secondary"
        >
            <span aria-live="polite" class="tabular-nums">{{
                t(':from–:to von :total', {
                    from: range.from,
                    to: range.to,
                    total: sorted.length,
                })
            }}</span>
            <Pagination
                v-if="pageCount > 1"
                v-model:page="page"
                :page-count="pageCount"
            />
            <label class="inline-flex items-center gap-2">
                {{ t('Pro Seite') }}
                <select
                    v-model.number="settings.perPage"
                    class="h-8 rounded-md border bg-background px-2 text-[13px] text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <option v-for="size in pageSizes" :key="size" :value="size">
                        {{ size === 0 ? t('Alle') : size }}
                    </option>
                </select>
            </label>
        </div>
    </div>
</template>
