<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleSlash,
    Clock,
    Copy,
    Eye,
    Lock,
    PencilLine,
    RotateCcw,
    Table2,
    Undo2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import ChangePreviewSheet from '@/components/workspace/data/ChangePreviewSheet.vue';
import DataGrid from '@/components/workspace/data/DataGrid.vue';
import type { RowMeta } from '@/components/workspace/data/DataGrid.vue';
import { rowId, useRowChanges } from '@/composables/useRowChanges';
import type { EditValue } from '@/composables/useRowChanges';
import { ApiError, postJson } from '@/lib/api';
import { cellText } from '@/lib/grid';
import { formatNumber, t, tc } from '@/lib/i18n';
import { undo as undoRoute } from '@/routes/workspace/object/changes';
import type { Cell, GridColumn, SqlResult, Workspace } from '@/types';

/**
 * Ergebnisse des SQL-Editors (Q-02, Wireframe 10): je Anweisung ein Reiter mit Zeilen, Dauer und Stand; abgebrochen,
 * Zeitgrenze und Fehler in Klartext. Zeilen über der Grenze lädt «mehr laden» nach.
 *
 * Liest eine Abfrage genau eine Tabelle mit ihrem Primärschlüssel (Q-05), lassen sich die Zellen bearbeiten wie im
 * Raster: Änderungen sammeln, Vorschau, Anwenden in einer Transaktion, Rückgängig — über die Endpunkte des Rasters.
 * Sonst steht der Grund daneben, warum nur gelesen wird.
 */
const props = defineProps<{
    workspace: Workspace;
    results: SqlResult[];
    maxRowLimit: number;
    loadingMore: boolean;
}>();

const emit = defineEmits<{ more: [index: number]; refresh: [index: number] }>();

const active = ref(0);
const widths = ref<Record<string, number>>({});
const changes = useRowChanges();
const previewOpen = ref(false);
const undoing = ref(false);
const lastApplied = ref<{
    auditId: number;
    schema: string;
    table: string;
    rows: number;
    undoable: boolean;
} | null>(null);

watch(
    () => props.results,
    (results, previous) => {
        if (results.length !== previous?.length) {
            const firstWithRows = results.findIndex(
                (result) => result.status !== 'ok' || result.columns.length,
            );
            active.value = Math.max(
                0,
                firstWithRows === -1 ? results.length - 1 : firstWithRows,
            );
            lastApplied.value = null;
        }

        changes.discard();
    },
);

const current = computed(() => props.results[active.value] ?? null);
const source = computed(() => current.value?.source ?? null);
const editable = computed(() => !!source.value?.editable);

function selectResult(index: number): void {
    if (index !== active.value && changes.total.value > 0) {
        toast.warning(
            t(
                'Erst anwenden oder verwerfen — die Änderungen gehören zu diesem Ergebnis.',
            ),
        );

        return;
    }

    active.value = index;
}

const columns = computed<{ column: GridColumn; index: number }[]>(() =>
    (current.value?.columns ?? []).map((column, index) => {
        const origin = editable.value
            ? (source.value?.columns[index] ?? null)
            : null;

        return {
            index,
            // Angezeigt wird der Name im Ergebnis (auch ein Alias); Art und NULL erlaubt aus der Tabelle.
            column: origin
                ? { ...origin, name: column.name, references: null }
                : {
                      name: column.name,
                      type: column.type ?? '',
                      kind: isNumeric(column.type, index) ? 'number' : 'other',
                      kindLabel: '',
                      nullable: true,
                      primary: false,
                      references: null,
                  },
        };
    }),
);

const readOnlyColumns = computed(() =>
    (current.value?.columns ?? [])
        .map((_, index) => index)
        .filter((index) => !source.value?.columns[index]),
);

/** Zahl, wenn der Treiber einen Zahlentyp nennt oder alle Werte Zahlen sind (PostgreSQL liefert numeric als Text). */
function isNumeric(type: string | null, index: number): boolean {
    if (
        type &&
        /int|numeric|decimal|float|double|real|money|number/i.test(type)
    ) {
        return true;
    }

    const rows = current.value?.rows ?? [];

    return (
        rows.length > 0 &&
        rows.every(
            (row) => row[index] === null || typeof row[index] === 'number',
        )
    );
}

/** Schlüssel einer Zeile als Text — so, wie der RowEditor ihn erwartet. */
function keyOf(row: Cell[]): (string | null)[] {
    return (source.value?.keyColumns ?? []).map((index) => {
        const cell = row[index];

        if (cell === null || typeof cell === 'object') {
            return null;
        }

        return typeof cell === 'boolean' ? (cell ? '1' : '0') : String(cell);
    });
}

const keys = computed(() =>
    editable.value ? (current.value?.rows ?? []).map(keyOf) : [],
);

/**
 * Wahrheitswerte wie im Raster: MySQL, MariaDB und SQLite liefern im Ergebnis 1/0 — für eine Spalte, die in der
 * Tabelle ein Wahrheitswert ist, als true/false zeigen und umschalten.
 */
function normalized(cell: Cell, index: number): Cell {
    return source.value?.columns[index]?.kind === 'boolean' &&
        (cell === 0 || cell === 1 || cell === '0' || cell === '1')
        ? cell === 1 || cell === '1'
        : cell;
}

const baseRows = computed<Cell[][]>(() =>
    editable.value
        ? (current.value?.rows ?? []).map((row) =>
              row.map((cell, index) => normalized(cell, index)),
          )
        : (current.value?.rows ?? []),
);

const displayRows = computed<Cell[][]>(() => {
    const rows = baseRows.value;

    if (!editable.value || changes.total.value === 0) {
        return rows;
    }

    return rows.map((row, position) => {
        const edit = changes.edits.value.get(rowId(keys.value[position]));

        return edit
            ? row.map((cell, index) => {
                  const name = source.value?.columns[index]?.name;

                  return name !== undefined && name in edit.values
                      ? (edit.values[name] as Cell)
                      : cell;
              })
            : row;
    });
});

const rowMeta = computed<RowMeta[]>(() =>
    editable.value
        ? (current.value?.rows ?? []).map((_, position) => {
              const edit = changes.edits.value.get(rowId(keys.value[position]));

              return edit
                  ? {
                        state: null,
                        changed: (current.value?.columns ?? [])
                            .filter(
                                (_column, index) =>
                                    (source.value?.columns[index]?.name ??
                                        '') in edit.values,
                            )
                            .map((column) => column.name),
                    }
                  : null;
          })
        : [],
);

function onEdit(
    position: number,
    _column: string,
    value: EditValue,
    index: number,
): void {
    const origin = source.value?.columns[index];
    const cell = baseRows.value[position]?.[index] ?? null;

    if (!origin || !editable.value) {
        return;
    }

    const original: EditValue =
        cell === null || typeof cell === 'object'
            ? null
            : typeof value === 'string'
              ? String(cell)
              : cell;

    changes.setCell(keys.value[position] ?? [], origin.name, value, original);
}

/** Vorschau und Anwenden gehen an die Tabelle des Ergebnisses — auch wenn sie in einem anderen Schema liegt. */
const target = computed(() => ({
    ...props.workspace,
    schema: source.value?.schema ?? props.workspace.schema,
}));

function onApplied(result: {
    auditId: number;
    updated: number;
    undoable: boolean;
}): void {
    toast.success(
        tc(':count Zeile geändert.|:count Zeilen geändert.', result.updated),
    );
    lastApplied.value = {
        auditId: result.auditId,
        schema: source.value?.schema ?? '',
        table: source.value?.table ?? '',
        rows: result.updated,
        undoable: result.undoable,
    };
    changes.discard();
    emit('refresh', active.value);
}

async function undoLast(): Promise<void> {
    const applied = lastApplied.value;

    if (!applied) {
        return;
    }

    undoing.value = true;

    try {
        await postJson(
            undoRoute.url(
                [
                    props.workspace.connection.id,
                    encodeURIComponent(applied.schema),
                    encodeURIComponent(applied.table),
                    applied.auditId,
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
        lastApplied.value = null;
        emit('refresh', active.value);
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

const icons = {
    ok: CircleCheck,
    error: CircleAlert,
    cancelled: CircleSlash,
    timeout: Clock,
    skipped: CircleSlash,
};

function summary(result: SqlResult): string {
    if (result.status !== 'ok') {
        return (
            {
                error: t('Fehler'),
                cancelled: t('abgebrochen'),
                timeout: t('Zeitgrenze'),
                skipped: t('nicht ausgeführt'),
            }[result.status] ?? ''
        );
    }

    return result.columns.length
        ? tc(':count Zeile|:count Zeilen', result.rows.length) +
              (result.truncated ? '+' : '')
        : result.affected !== null
          ? tc(
                ':count Zeile betroffen|:count Zeilen betroffen',
                result.affected,
            )
          : t('ausgeführt');
}

async function copy(): Promise<void> {
    const result = current.value;

    if (!result) {
        return;
    }

    const tsv = [
        result.columns.map((column) => column.name).join('\t'),
        ...result.rows.map((row) =>
            row.map((cell) => (cell === null ? '' : cellText(cell))).join('\t'),
        ),
    ].join('\n');

    try {
        await navigator.clipboard.writeText(tsv);
        toast.success(t('Ergebnis als TSV kopiert.'));
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}
</script>

<template>
    <section
        class="flex min-h-0 flex-1 flex-col border-t"
        :aria-label="t('Ergebnisse')"
    >
        <div
            v-if="results.length"
            class="flex shrink-0 items-center gap-1 overflow-x-auto border-b bg-muted/40 px-2"
            role="tablist"
        >
            <button
                v-for="(result, index) in results"
                :key="index"
                type="button"
                role="tab"
                :aria-selected="index === active"
                :class="[
                    'flex h-9 shrink-0 items-center gap-1.5 border-b-2 px-2.5 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    index === active
                        ? 'border-primary font-semibold text-foreground'
                        : 'border-transparent text-text-secondary hover:text-foreground',
                ]"
                @click="selectResult(index)"
            >
                <component
                    :is="icons[result.status]"
                    :class="[
                        'size-3.5',
                        result.status === 'ok' && 'text-success',
                        result.status === 'error' && 'text-destructive',
                        (result.status === 'timeout' ||
                            result.status === 'cancelled') &&
                            'text-warning',
                    ]"
                />
                {{ t('Ergebnis :number', { number: index + 1 }) }}
                <span class="font-normal text-muted-foreground">{{
                    summary(result)
                }}</span>
            </button>
        </div>

        <div
            v-if="!results.length"
            class="grid flex-1 place-items-center p-6 text-center text-sm text-text-secondary"
        >
            {{
                t(
                    'Noch nichts ausgeführt. ⌘↵ führt die Anweisung an der Schreibmarke aus, ⇧⌘↵ alles.',
                )
            }}
        </div>

        <template v-else-if="current">
            <div
                class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1 border-b px-4 py-1.5 text-[13px] text-text-secondary"
            >
                <code
                    class="max-w-full min-w-0 truncate font-mono text-foreground"
                    >{{ current.sql.replace(/\s+/g, ' ') }}</code
                >
                <span class="tabular-nums"
                    >{{ formatNumber(current.durationMs) }} ms</span
                >
                <span
                    v-if="source && editable"
                    class="inline-flex min-w-0 items-center gap-1 text-foreground"
                    ><PencilLine class="size-3.5 shrink-0 text-primary" />
                    <span class="truncate">{{
                        t('bearbeitbar · :table', {
                            table: `${source.schema}.${source.table}`,
                        })
                    }}</span></span
                >
                <span
                    v-else-if="source?.reason"
                    class="inline-flex max-w-full min-w-0 items-center gap-1"
                    :title="source.reason"
                    ><Lock class="size-3.5 shrink-0" />
                    <span class="truncate"
                        >{{ t('nur lesen') }}: {{ source.reason }}</span
                    ></span
                >
                <button
                    v-if="current.columns.length"
                    type="button"
                    class="ml-auto inline-flex items-center gap-1 rounded-sm outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    @click="copy"
                >
                    <Copy class="size-3.5" />{{ t('kopieren') }}
                </button>
            </div>

            <div v-if="current.status !== 'ok'" class="p-4">
                <Notice
                    :variant="current.status === 'error' ? 'danger' : 'warning'"
                >
                    <b class="font-semibold">{{
                        {
                            error: t(
                                'Die Datenbank hat die Anweisung abgelehnt.',
                            ),
                            cancelled: t('Abgebrochen.'),
                            timeout: t('Die Zeitgrenze ist abgelaufen.'),
                            skipped: t(
                                'Nicht ausgeführt, weil eine Anweisung davor nicht durchlief.',
                            ),
                        }[current.status]
                    }}</b>
                    <code
                        v-if="current.message"
                        class="mt-1 block text-[13px] break-words whitespace-pre-wrap text-text-secondary"
                        >{{ current.message }}</code
                    >
                </Notice>
            </div>

            <div
                v-else-if="!current.columns.length"
                class="flex items-center gap-2 p-4 text-sm"
            >
                <Table2 class="size-4 text-text-secondary" />
                {{
                    current.affected !== null
                        ? tc(
                              ':count Zeile betroffen|:count Zeilen betroffen',
                              current.affected,
                          )
                        : t('Ausgeführt — die Anweisung liefert keine Zeilen.')
                }}
            </div>

            <template v-else>
                <DataGrid
                    :workspace="workspace"
                    :columns="columns"
                    :rows="displayRows"
                    :row-meta="rowMeta"
                    :editable="editable"
                    :read-only-columns="readOnlyColumns"
                    :labels="{}"
                    :widths="widths"
                    :sort="[]"
                    :has-more="false"
                    :loading="false"
                    @edit="onEdit"
                    @resize="
                        (column, width) =>
                            (widths = { ...widths, [column]: width })
                    "
                />
                <div
                    v-if="current.truncated"
                    class="flex shrink-0 items-center gap-2 border-t px-4 py-1.5 text-[13px] text-text-secondary"
                >
                    {{
                        t('Nur die ersten :count Zeilen.', {
                            count: formatNumber(current.rows.length),
                        })
                    }}
                    <button
                        v-if="current.rows.length < maxRowLimit"
                        type="button"
                        class="text-primary underline disabled:opacity-60"
                        :disabled="loadingMore || changes.total.value > 0"
                        @click="emit('more', active)"
                    >
                        {{ t('mehr laden') }}
                    </button>
                </div>

                <div
                    v-if="changes.total.value > 0"
                    class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-t bg-accent/60 px-4 py-2.5 text-[13px]"
                    role="status"
                >
                    <b class="inline-flex items-center gap-1.5 font-semibold"
                        ><i
                            class="size-3 rounded-sm bg-[var(--cell-changed)] ring-1 ring-border"
                        />{{
                            tc(
                                ':count Zelle geändert|:count Zellen geändert',
                                changes.counts.value.cells,
                            )
                        }}</b
                    >
                    <span class="ml-auto flex flex-wrap gap-2">
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

                <div
                    v-else-if="lastApplied"
                    class="flex shrink-0 flex-wrap items-center gap-x-2 gap-y-1 border-t px-4 py-1.5 text-[13px] text-text-secondary"
                >
                    <Undo2 class="size-3.5" />
                    {{
                        tc(
                            ':count Zeile geändert.|:count Zeilen geändert.',
                            lastApplied.rows,
                        )
                    }}
                    <button
                        v-if="lastApplied.undoable"
                        type="button"
                        class="text-primary underline disabled:opacity-60"
                        :disabled="undoing"
                        @click="undoLast"
                    >
                        {{ t('Rückgängig machen') }}
                    </button>
                </div>
            </template>
        </template>

        <ChangePreviewSheet
            v-if="source && editable && source.table"
            v-model:open="previewOpen"
            :workspace="target"
            :object="source.table"
            :changes="previewOpen ? changes.payload() : []"
            @applied="onApplied"
        />
    </section>
</template>
