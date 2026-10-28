<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Columns3, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import DataGrid from '@/components/workspace/data/DataGrid.vue';
import FilterBar from '@/components/workspace/data/FilterBar.vue';
import MobileRows from '@/components/workspace/data/MobileRows.vue';
import RowDetailSheet from '@/components/workspace/data/RowDetailSheet.vue';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import { ApiError, getJson, putJson } from '@/lib/api';
import { titlePrefix } from '@/lib/environment';
import { formatSort, parseSort, toggleSort } from '@/lib/grid';
import { formatNumber, t } from '@/lib/i18n';
import { countUrl, dataHref, rowsUrl, viewUrl } from '@/lib/workspace';
import type {
    Cell,
    FilterOperatorOption,
    GridColumn,
    GridError,
    GridPage,
    GridQueryState,
    GridView,
    ObjectStructure,
    Workspace,
} from '@/types';

/**
 * Daten einer Tabelle oder Sicht (D-01, D-02, D-03, D-06, O-09, Wireframe 05): Filterleiste, Raster, Zeile im
 * Detail. Der Ausschnitt steht in der Adresse; die erste Seite kommt mit der Seite, weitere beim Scrollen.
 */
const props = defineProps<{
    workspace: Workspace;
    object: ObjectStructure['object'];
    columns: GridColumn[];
    grid: Omit<GridPage, 'columns'> | null;
    gridError: GridError | null;
    query: GridQueryState;
    operators: FilterOperatorOption[];
    view: GridView;
}>();

const rows = ref<Cell[][]>([]);
const keys = ref<(string | null)[][]>([]);
const labels = ref<Record<string, Record<string, string>>>({});
const next = ref<GridPage['next']>(null);
const hasMore = ref(false);
const total = ref<GridPage['total'] | null>(null);
const loadingMore = ref(false);
const loadFailure = ref<string | null>(null);
const counting = ref(false);
const visible = ref<[number, number]>([0, 0]);
const detailIndex = ref<number | null>(null);

function reset(): void {
    rows.value = props.grid ? [...props.grid.rows] : [];
    keys.value = props.grid ? [...props.grid.keys] : [];
    labels.value = props.grid ? { ...props.grid.labels } : {};
    next.value = props.grid?.next ?? null;
    hasMore.value = props.grid?.hasMore ?? false;
    total.value = props.grid?.total ?? null;
    loadFailure.value = null;
    detailIndex.value = null;
}

watch(() => props.grid, reset, { immediate: true });

// Gemerkte Ansicht: ausgeblendete Spalten und Breiten je Konto und Tabelle.
const hidden = ref<string[]>([]);
const widths = ref<Record<string, number>>({});

watch(
    () => props.view,
    (view) => {
        hidden.value = [...view.hidden];
        widths.value = { ...view.widths };
    },
    { immediate: true },
);

let saveTimer: ReturnType<typeof setTimeout> | undefined;

function saveView(): void {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        putJson(viewUrl(props.workspace, props.object.name), {
            hidden: hidden.value,
            widths: widths.value,
        }).catch(() => {
            // Merken ist Komfort — ohne Verbindung zur App bleibt die Wahl bis zum Neuladen.
        });
    }, 600);
}

const visibleColumns = computed(() =>
    props.columns
        .map((column, index) => ({ column, index }))
        .filter(({ column }) => !hidden.value.includes(column.name)),
);
const sort = computed(() => parseSort(props.query.sort));
const isFiltered = computed(
    () => !!(props.query.f?.length || props.query.where || props.query.q),
);

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${props.object.name} · ${props.workspace.connection.name}`,
);

function apply(state: GridQueryState): void {
    router.get(
        dataHref(props.workspace, props.object.name, state),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['grid', 'gridError', 'query'],
        },
    );
}

function onSort(
    column: string,
    direction: 'asc' | 'desc' | null,
    keepOthers: boolean,
): void {
    const items = direction
        ? [
              { column, direction },
              ...(keepOthers
                  ? sort.value.filter((item) => item.column !== column)
                  : []),
          ]
        : toggleSort(sort.value, column, keepOthers);

    apply({ ...props.query, sort: formatSort(items) });
}

function explain(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return t('Die Zeilen liessen sich nicht laden.');
    }

    switch (error.failure.reason) {
        case 'session':
            return t(
                'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
            );
        case 'password':
            return t(
                'Das Passwort dieser Verbindung gilt nicht mehr für diese Sitzung. Laden Sie die Seite neu.',
            );
        case 'connection':
            return t('Die Verbindung zur Datenbank ist abgerissen: :reason', {
                reason: error.failure.problem.title,
            });
        case 'query':
            return error.failure.error.message;
        default:
            return t('Die Zeilen liessen sich nicht laden.');
    }
}

async function loadMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value || !next.value) {
        return;
    }

    loadingMore.value = true;
    loadFailure.value = null;

    try {
        const page = await getJson<GridPage>(
            rowsUrl(
                props.workspace,
                props.object.name,
                props.query,
                next.value,
            ),
        );
        rows.value = rows.value.concat(page.rows);
        keys.value = keys.value.concat(page.keys);

        for (const [column, values] of Object.entries(page.labels)) {
            labels.value[column] = { ...labels.value[column], ...values };
        }

        next.value = page.next;
        hasMore.value = page.hasMore;

        if (!page.hasMore && total.value && !total.value.exact) {
            total.value = { count: rows.value.length, exact: true };
        }
    } catch (error) {
        loadFailure.value = explain(error);
        hasMore.value = false;
    } finally {
        loadingMore.value = false;
    }
}

async function countExactly(): Promise<void> {
    counting.value = true;

    try {
        const result = await getJson<{ count: number }>(
            countUrl(props.workspace, props.object.name, props.query),
        );
        total.value = { count: result.count, exact: true };
    } catch (error) {
        loadFailure.value = explain(error);
    } finally {
        counting.value = false;
    }
}

function resize(column: string, width: number): void {
    widths.value = { ...widths.value, [column]: width };
    saveView();
}

function toggleColumn(column: string): void {
    hidden.value = hidden.value.includes(column)
        ? hidden.value.filter((name) => name !== column)
        : [...hidden.value, column];
    saveView();
}

function showAllColumns(): void {
    hidden.value = [];
    saveView();
}

const detailKey = computed(() =>
    detailIndex.value === null ? null : (keys.value[detailIndex.value] ?? null),
);
const canOpenRows = computed(() => !!props.grid?.primaryKey.length);

function openRow(index: number): void {
    if (canOpenRows.value) {
        detailIndex.value = index;
    }
}

function stepRow(step: number): void {
    if (detailIndex.value === null) {
        return;
    }

    const target = detailIndex.value + step;

    if (target >= rows.value.length && hasMore.value) {
        void loadMore().then(() => {
            if (target < rows.value.length) {
                detailIndex.value = target;
            }
        });

        return;
    }

    if (target >= 0 && target < rows.value.length) {
        detailIndex.value = target;
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full min-h-0 flex-col">
        <ObjectHeader :workspace="workspace" :object="object" active="data" />

        <FilterBar
            :columns="columns"
            :query="query"
            :operators="operators"
            :total="gridError ? null : total"
            :counting="counting"
            @apply="apply"
            @count="countExactly"
        />

        <div v-if="gridError" class="flex flex-col gap-3 p-4 sm:px-6">
            <Notice variant="danger">
                <b class="font-semibold">{{ gridError.message }}</b>
                <code
                    v-if="gridError.serverMessage"
                    class="mt-1 block text-[13px] break-words whitespace-pre-wrap text-text-secondary"
                    >{{ gridError.serverMessage }}</code
                >
            </Notice>
            <Button
                variant="outline"
                class="self-start"
                @click="apply({ sort: query.sort })"
            >
                <RotateCcw />{{ t('Filter zurücksetzen') }}
            </Button>
        </div>

        <template v-else-if="grid">
            <div
                v-if="!rows.length"
                class="m-4 flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center sm:mx-6"
            >
                <p class="font-semibold">
                    {{
                        isFiltered
                            ? t('Keine Zeile passt zu den Filtern.')
                            : t('Diese Tabelle ist leer.')
                    }}
                </p>
                <Button
                    v-if="isFiltered"
                    variant="outline"
                    @click="apply({ sort: query.sort })"
                >
                    <RotateCcw />{{ t('Filter zurücksetzen') }}
                </Button>
            </div>

            <template v-else>
                <DataGrid
                    class="hidden md:block"
                    :workspace="workspace"
                    :columns="visibleColumns"
                    :rows="rows"
                    :labels="labels"
                    :widths="widths"
                    :sort="sort"
                    :has-more="hasMore"
                    :loading="loadingMore"
                    @sort="onSort"
                    @load-more="loadMore"
                    @open="openRow"
                    @resize="resize"
                    @hide="toggleColumn"
                    @visible-range="(first, last) => (visible = [first, last])"
                />
                <MobileRows
                    class="min-h-0 flex-1 overflow-y-auto md:hidden"
                    :columns="visibleColumns"
                    :rows="rows"
                    :labels="labels"
                    :has-more="hasMore"
                    :loading="loadingMore"
                    @open="openRow"
                    @load-more="loadMore"
                />
            </template>

            <div v-if="loadFailure" class="border-t px-4 py-2 sm:px-6">
                <Notice variant="danger">{{ loadFailure }}</Notice>
            </div>

            <footer
                class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1 border-t px-4 py-2 text-[13px] text-text-secondary sm:px-6"
            >
                <span
                    v-if="rows.length"
                    class="hidden tabular-nums md:inline"
                    >{{
                        t('sichtbar Zeilen :first–:last', {
                            first: formatNumber(visible[0] + 1),
                            last: formatNumber(
                                Math.min(visible[1] + 1, rows.length),
                            ),
                        })
                    }}</span
                >
                <span class="tabular-nums">{{
                    hasMore
                        ? t('geladen :count · weitere beim Scrollen', {
                              count: formatNumber(rows.length),
                          })
                        : t('geladen :count', {
                              count: formatNumber(rows.length),
                          })
                }}</span>
                <Popover>
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-sm outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Columns3 class="size-3.5" />{{
                                t(':visible von :total Spalten', {
                                    visible: visibleColumns.length,
                                    total: columns.length,
                                })
                            }}
                        </button>
                    </PopoverTrigger>
                    <PopoverContent class="w-64 p-2">
                        <ul class="max-h-80 overflow-y-auto">
                            <li v-for="column in columns" :key="column.name">
                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-muted"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-[var(--primary)]"
                                        :checked="!hidden.includes(column.name)"
                                        @change="toggleColumn(column.name)"
                                    />
                                    <span class="truncate font-mono">{{
                                        column.name
                                    }}</span>
                                </label>
                            </li>
                        </ul>
                        <button
                            v-if="hidden.length"
                            type="button"
                            class="mt-1 w-full rounded-sm px-2 py-1.5 text-left text-sm text-primary hover:bg-muted"
                            @click="showAllColumns"
                        >
                            {{ t('Alle Spalten zeigen') }}
                        </button>
                    </PopoverContent>
                </Popover>
                <span v-if="!canOpenRows" class="text-text-secondary">{{
                    t('ohne Primärschlüssel — Zeilen nur im Raster')
                }}</span>
                <span class="ml-auto tabular-nums">{{
                    t('erste Seite in :ms ms', {
                        ms: formatNumber(grid.durationMs),
                    })
                }}</span>
            </footer>
        </template>

        <RowDetailSheet
            :workspace="workspace"
            :object="object.name"
            :columns="columns"
            :row-key="detailKey"
            :row-number="detailIndex"
            :has-previous="(detailIndex ?? 0) > 0"
            :has-next="
                detailIndex !== null &&
                (detailIndex < rows.length - 1 || hasMore)
            "
            @close="detailIndex = null"
            @previous="stepRow(-1)"
            @next="stepRow(1)"
        />
    </div>
</template>
