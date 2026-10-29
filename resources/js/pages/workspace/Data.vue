<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ClipboardCopy,
    Columns3,
    Download,
    Eye,
    Plus,
    RotateCcw,
    Undo2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ChangePreviewSheet from '@/components/workspace/data/ChangePreviewSheet.vue';
import DataGrid from '@/components/workspace/data/DataGrid.vue';
import type { RowMeta } from '@/components/workspace/data/DataGrid.vue';
import FilterBar from '@/components/workspace/data/FilterBar.vue';
import MobileRows from '@/components/workspace/data/MobileRows.vue';
import RowDetailSheet from '@/components/workspace/data/RowDetailSheet.vue';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import { rowId, useRowChanges } from '@/composables/useRowChanges';
import type { EditValue } from '@/composables/useRowChanges';
import { ApiError, getJson, postJson, putJson } from '@/lib/api';
import { formatTime } from '@/lib/format';
import { titlePrefix } from '@/lib/environment';
import { formatSort, parseSort, toggleSort } from '@/lib/grid';
import { formatNumber, t, tc } from '@/lib/i18n';
import { transfersHref } from '@/lib/transfers';
import {
    countUrl,
    dataHref,
    gridParams,
    rowsUrl,
    viewUrl,
} from '@/lib/workspace';
import { copy as copyRoute } from '@/routes/workspace/object';
import { undo as undoRoute } from '@/routes/workspace/object/changes';
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
    editing: {
        allowed: boolean;
        reason: 'view' | 'right' | 'readonly' | 'key' | null;
    };
    lastChange: { auditId: number; at: string | null; rows: number } | null;
}>();

const changes = useRowChanges();
const previewOpen = ref(false);
const undoing = ref(false);

/** Neue Zeilen oben, darunter die geladenen mit eingesetzten Änderungen. */
const displayRows = computed<Cell[][]>(() => [
    ...changes.inserts.value.map((row) =>
        props.columns.map((column) =>
            column.name in row.values ? (row.values[column.name] as Cell) : '',
        ),
    ),
    ...rows.value.map((row, index) => {
        const edit = changes.edits.value.get(rowId(keys.value[index] ?? []));

        return edit
            ? row.map((cell, column) =>
                  (props.columns[column]?.name ?? '') in edit.values
                      ? (edit.values[props.columns[column].name] as Cell)
                      : cell,
              )
            : row;
    }),
]);

const rowMeta = computed<RowMeta[]>(() => [
    ...changes.inserts.value.map(() => ({
        state: 'new' as const,
        changed: [],
    })),
    ...rows.value.map((_, index) => {
        const id = rowId(keys.value[index] ?? []);

        if (changes.deletes.value.has(id)) {
            return { state: 'deleted' as const, changed: [] };
        }

        const edit = changes.edits.value.get(id);

        return edit ? { state: null, changed: Object.keys(edit.values) } : null;
    }),
]);

function onEdit(displayIndex: number, column: string, value: EditValue): void {
    const insertCount = changes.inserts.value.length;

    if (displayIndex < insertCount) {
        changes.setInsertCell(
            changes.inserts.value[displayIndex].id,
            column,
            value,
        );

        return;
    }

    const index = displayIndex - insertCount;
    const position = props.columns.findIndex((item) => item.name === column);
    const cell = rows.value[index]?.[position] ?? null;
    const original: EditValue =
        cell === null || typeof cell === 'object'
            ? null
            : typeof value === 'string'
              ? String(cell)
              : cell;

    changes.setCell(keys.value[index] ?? [], column, value, original);
}

function onOpen(displayIndex: number): void {
    const index = displayIndex - changes.inserts.value.length;

    if (index >= 0) {
        openRow(index);
    }
}

function insertRow(values: Record<string, EditValue> = {}): void {
    changes.insert(values);
}

function onApplied(result: {
    auditId: number;
    inserted: number;
    updated: number;
    deleted: number;
}): void {
    toast.success(
        t('Angewendet: :inserted neu, :updated geändert, :deleted gelöscht.', {
            inserted: result.inserted,
            updated: result.updated,
            deleted: result.deleted,
        }),
    );
    changes.discard();
    router.reload({ only: ['grid', 'gridError', 'lastChange', 'object'] });
}

async function undoLast(): Promise<void> {
    if (!props.lastChange) {
        return;
    }

    undoing.value = true;

    try {
        await postJson(
            undoRoute.url(
                [
                    props.workspace.connection.id,
                    encodeURIComponent(props.workspace.schema ?? ''),
                    encodeURIComponent(props.object.name),
                    props.lastChange.auditId,
                ],
                {
                    query: props.workspace.database
                        ? { db: props.workspace.database }
                        : {},
                },
            ),
            {},
        );
        toast.success(t('Rückgängig gemacht.'));
        router.reload({ only: ['grid', 'gridError', 'lastChange', 'object'] });
    } catch (error) {
        toast.error(
            error instanceof ApiError && 'message' in error.failure
                ? String((error.failure as { message: string }).message)
                : t('Rückgängig ging nicht.'),
        );
    } finally {
        undoing.value = false;
    }
}

async function copyAs(format: 'tsv' | 'markdown' | 'insert'): Promise<void> {
    try {
        const result = await getJson<{ text: string; rows: number }>(
            copyRoute.url(
                [
                    props.workspace.connection.id,
                    encodeURIComponent(props.workspace.schema ?? ''),
                    encodeURIComponent(props.object.name),
                ],
                {
                    query: {
                        ...(props.workspace.database
                            ? { db: props.workspace.database }
                            : {}),
                        ...gridParams(props.query),
                        format,
                    },
                },
            ),
        );
        await navigator.clipboard.writeText(result.text);
        toast.success(
            tc(':count Zeile kopiert.|:count Zeilen kopiert.', result.rows),
        );
    } catch {
        toast.error(t('Kopieren ging nicht.'));
    }
}

const readOnlyHint = computed(
    () =>
        ({
            view: t('Sicht — nur lesen'),
            right: t('Ihr Konto darf hier nur lesen'),
            readonly: t(
                'schreibgeschützt — Schutz auf Zeit aufheben im Menü der Verbindung',
            ),
            key: t('ohne Primärschlüssel — nur lesen'),
        })[props.editing.reason ?? 'view'],
);

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
        <ObjectHeader :workspace="workspace" :object="object" active="data">
            <template #actions>
                <Button size="sm" variant="outline" as-child>
                    <Link
                        :href="
                            transfersHref(workspace, {
                                schema: workspace.schema,
                                table: object.name,
                            })
                        "
                        ><Download />{{ t('Exportieren') }}</Link
                    >
                </Button>
                <Button
                    v-if="editing.allowed"
                    size="sm"
                    variant="outline"
                    @click="insertRow()"
                >
                    <Plus />{{ t('Zeile einfügen') }}
                </Button>
            </template>
        </ObjectHeader>

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
                v-if="!displayRows.length"
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
                    :rows="displayRows"
                    :row-meta="rowMeta"
                    :editable="editing.allowed"
                    :labels="labels"
                    :widths="widths"
                    :sort="sort"
                    :has-more="hasMore"
                    :loading="loadingMore"
                    @sort="onSort"
                    @load-more="loadMore"
                    @open="onOpen"
                    @edit="onEdit"
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

            <div
                v-if="changes.total.value > 0"
                class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-t bg-accent/60 px-4 py-2.5 text-[13px] sm:px-6"
                role="status"
            >
                <b class="text-sm font-semibold">{{
                    tc(
                        ':count Änderung noch nicht angewendet|:count Änderungen noch nicht angewendet',
                        changes.total.value,
                    )
                }}</b>
                <span
                    v-if="changes.counts.value.cells"
                    class="inline-flex items-center gap-1.5"
                    ><i
                        class="size-3 rounded-sm bg-[var(--cell-changed)] ring-1 ring-border"
                    />{{
                        tc(
                            ':count Zelle geändert|:count Zellen geändert',
                            changes.counts.value.cells,
                        )
                    }}</span
                >
                <span
                    v-if="changes.counts.value.inserts"
                    class="inline-flex items-center gap-1.5"
                    ><i
                        class="size-3 rounded-sm bg-[var(--cell-new)] ring-1 ring-border"
                    />{{
                        tc(
                            ':count Zeile neu|:count Zeilen neu',
                            changes.counts.value.inserts,
                        )
                    }}</span
                >
                <span
                    v-if="changes.counts.value.deletes"
                    class="inline-flex items-center gap-1.5"
                    ><i
                        class="size-3 rounded-sm bg-[var(--cell-deleted)] ring-1 ring-border"
                    />{{
                        tc(
                            ':count Zeile zum Löschen|:count Zeilen zum Löschen',
                            changes.counts.value.deletes,
                        )
                    }}</span
                >
                <span class="ml-auto flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="previewOpen = true"
                        ><Eye />{{ t('SQL ansehen') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="changes.discard()"
                        ><RotateCcw />{{ t('Verwerfen') }}</Button
                    >
                    <Button size="sm" @click="previewOpen = true">{{
                        t('Anwenden …')
                    }}</Button>
                </span>
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
                <span v-if="!editing.allowed" class="hidden lg:inline">{{
                    readOnlyHint
                }}</span>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        class="inline-flex items-center gap-1 rounded-sm outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <ClipboardCopy class="size-3.5" />{{
                            t('Kopieren als …')
                        }}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem @select="copyAs('tsv')">{{
                            t('TSV (Tabellenkalkulation)')
                        }}</DropdownMenuItem>
                        <DropdownMenuItem @select="copyAs('markdown')">{{
                            t('Markdown-Tabelle')
                        }}</DropdownMenuItem>
                        <DropdownMenuItem @select="copyAs('insert')">{{
                            t('INSERT-Anweisungen')
                        }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span v-if="lastChange" class="inline-flex items-center gap-1">
                    <Undo2 class="size-3.5" />{{
                        t('Zuletzt angewendet :time · :rows', {
                            time: formatTime(lastChange.at),
                            rows: tc(
                                ':count Zeile|:count Zeilen',
                                lastChange.rows,
                            ),
                        })
                    }}
                    ·
                    <button
                        type="button"
                        class="text-primary underline disabled:opacity-60"
                        :disabled="undoing"
                        @click="undoLast"
                    >
                        {{ t('Rückgängig machen') }}
                    </button>
                </span>
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
            :editable="editing.allowed"
            :pending="
                detailKey
                    ? (changes.edits.value.get(rowId(detailKey))?.values ??
                      null)
                    : null
            "
            :deleted="
                detailKey ? changes.deletes.value.has(rowId(detailKey)) : false
            "
            @edit="
                (column, value) =>
                    detailIndex !== null &&
                    onEdit(
                        detailIndex + changes.inserts.value.length,
                        column,
                        value,
                    )
            "
            @duplicate="(values) => insertRow(values)"
            @toggle-delete="detailKey && changes.toggleDelete(detailKey)"
        />

        <ChangePreviewSheet
            v-model:open="previewOpen"
            :workspace="workspace"
            :object="object.name"
            :changes="previewOpen ? changes.payload() : []"
            @applied="onApplied"
        />
    </div>
</template>
