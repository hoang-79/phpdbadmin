<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowRight,
    ArrowUp,
    EyeOff,
    KeyRound,
    MoreHorizontal,
} from '@lucide/vue';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    cellText,
    cellValue,
    isBinaryCell,
    isNumericColumn,
    isTruncatedCell,
} from '@/lib/grid';
import type { EditValue } from '@/composables/useRowChanges';
import type { SortItem } from '@/lib/grid';
import { t } from '@/lib/i18n';
import { dataHref } from '@/lib/workspace';
import type { Cell, GridColumn, Workspace } from '@/types';

/**
 * Datenraster (D-01, O-05, Wireframe 05): rendert nur die sichtbaren Zeilen, lädt beim Scrollen nach. Klick auf
 * eine Überschrift sortiert (mit Umschalt kommt die Spalte zur Sortierung dazu), am Rand zieht man die Breite.
 * Tastatur: Pfeile, Pos1/Ende, Bild auf/ab, Eingabe öffnet die Zeile — oder bearbeitet die Zelle, wenn das Raster
 * bearbeitbar ist (D-04); geänderte Zellen, neue und zum Löschen markierte Zeilen sind farbig.
 */
export type RowMeta = {
    state: 'new' | 'deleted' | null;
    changed: string[];
} | null;

const props = defineProps<{
    workspace: Workspace;
    columns: { column: GridColumn; index: number }[];
    rows: Cell[][];
    labels: Record<string, Record<string, string>>;
    widths: Record<string, number>;
    sort: SortItem[];
    hasMore: boolean;
    loading: boolean;
    rowMeta?: RowMeta[];
    editable?: boolean;
    /** Spalten (Index in der Zeile), die trotz `editable` nur lesen — Ausdrücke im Ergebnis einer Abfrage. */
    readOnlyColumns?: number[];
}>();

const emit = defineEmits<{
    sort: [
        column: string,
        direction: 'asc' | 'desc' | null,
        keepOthers: boolean,
    ];
    loadMore: [];
    open: [row: number];
    resize: [column: string, width: number];
    hide: [column: string];
    visibleRange: [first: number, last: number];
    edit: [row: number, column: string, value: EditValue, index: number];
}>();

const editing = ref<{ row: number; col: number; draft: string } | null>(null);

function metaOf(row: number): RowMeta {
    return props.rowMeta?.[row] ?? null;
}

/**
 * Gekürzter Text (über 1'000 Zeichen) wird nicht in der Zelle bearbeitet — gespeichert würde sonst nur der Anfang;
 * dafür gibt es das Zeilen-Detail mit dem ganzen Wert.
 */
function canEdit(row: number, col: number): boolean {
    const entry = props.columns[col];

    return (
        !!props.editable &&
        !!entry &&
        entry.column.kind !== 'binary' &&
        metaOf(row)?.state !== 'deleted' &&
        !isTruncatedCell(props.rows[row]?.[entry.index] ?? null) &&
        !props.readOnlyColumns?.includes(entry.index)
    );
}

function startEditing(row: number, col: number): void {
    if (!canEdit(row, col)) {
        return;
    }

    const { column, index } = props.columns[col];
    const cell = props.rows[row]?.[index] ?? null;

    if (column.kind === 'boolean') {
        emit('edit', row, column.name, cell === null ? true : !cell, index);

        return;
    }

    editing.value = {
        row,
        col,
        draft:
            cell === null || typeof cell === 'object'
                ? cell && 'text' in cell
                    ? cell.text
                    : ''
                : String(cell),
    };
}

function commitEditing(move: 0 | 1 = 0): void {
    const current = editing.value;

    if (!current) {
        return;
    }

    editing.value = null;
    const { column, index } = props.columns[current.col];
    const cell = props.rows[current.row]?.[index] ?? null;
    const original =
        cell === null || typeof cell === 'object' ? null : String(cell);

    if (current.draft !== original) {
        emit('edit', current.row, column.name, current.draft, index);
    }

    if (move) {
        moveTo(current.row, current.col + 1);
    }

    scroller.value?.focus();
}

function setNull(): void {
    const current = editing.value;

    if (!current) {
        return;
    }

    editing.value = null;
    emit(
        'edit',
        current.row,
        props.columns[current.col].column.name,
        null,
        props.columns[current.col].index,
    );
    scroller.value?.focus();
}

function onEditorKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        commitEditing();
    } else if (event.key === 'Tab') {
        event.preventDefault();
        commitEditing(1);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        editing.value = null;
        scroller.value?.focus();
    }

    event.stopPropagation();
}

const ROW_HEIGHT = 32;
const NUMBER_WIDTH = 56;

const scroller = ref<HTMLElement | null>(null);
const resizing = ref<{ column: string; width: number } | null>(null);
const active = ref<{ row: number; col: number } | null>(null);

const virtualizer = useVirtualizer(
    computed(() => ({
        count: props.rows.length,
        getScrollElement: () => scroller.value,
        estimateSize: () => ROW_HEIGHT,
        overscan: 12,
    })),
);
const items = computed(() => virtualizer.value.getVirtualItems());

watch(items, (visible) => {
    const first = visible[0];
    const last = visible.at(-1);

    if (!first || !last) {
        return;
    }

    emit('visibleRange', first.index, last.index);

    if (
        props.hasMore &&
        !props.loading &&
        last.index > props.rows.length - 60
    ) {
        emit('loadMore');
    }
});

// Neue Abfrage: Auswahl und Scrollstand zurücksetzen.
watch(
    () => props.rows,
    (rows, previous) => {
        if (
            rows.length < (previous?.length ?? 0) ||
            rows[0] !== previous?.[0]
        ) {
            active.value = null;
            scroller.value?.scrollTo({ top: 0 });
        }
    },
);

function defaultWidth(column: GridColumn): number {
    const byKind: Record<string, number> = {
        number: 110,
        boolean: 90,
        datetime: 170,
        json: 260,
        binary: 130,
        identifier: 290,
        choice: 130,
    };
    // Fremdschlüssel zeigen Wert und Beschriftung der Zielzeile («→ 8120 Rahel Graf»).
    const base = column.references ? 210 : (byKind[column.kind] ?? 200);

    return Math.max(base, Math.min(320, column.name.length * 8 + 48));
}

function widthOf(column: GridColumn): number {
    return resizing.value?.column === column.name
        ? resizing.value.width
        : (props.widths[column.name] ?? defaultWidth(column));
}

const totalWidth = computed(
    () =>
        NUMBER_WIDTH +
        props.columns.reduce((sum, { column }) => sum + widthOf(column), 0),
);

function sortOf(column: string): { item: SortItem; position: number } | null {
    const position = props.sort.findIndex((item) => item.column === column);

    return position < 0 ? null : { item: props.sort[position], position };
}

function ariaSort(column: string): 'ascending' | 'descending' | 'none' {
    const sorted = sortOf(column);

    return sorted
        ? sorted.item.direction === 'asc'
            ? 'ascending'
            : 'descending'
        : 'none';
}

function referenceHref(column: GridColumn, cell: Cell): string | null {
    const value = cellValue(cell);

    if (!column.references || value === null) {
        return null;
    }

    return dataHref(props.workspace, column.references.table, {
        f: [{ c: column.references.column, o: 'eq', v: value }],
    });
}

function labelOf(column: GridColumn, cell: Cell): string | null {
    const value = cellValue(cell);

    return value === null ? null : (props.labels[column.name]?.[value] ?? null);
}

function startResize(event: PointerEvent, column: GridColumn): void {
    event.preventDefault();
    const startX = event.clientX;
    const startWidth = widthOf(column);
    resizing.value = { column: column.name, width: startWidth };

    const move = (moved: PointerEvent): void => {
        resizing.value = {
            column: column.name,
            width: Math.min(
                1200,
                Math.max(60, startWidth + moved.clientX - startX),
            ),
        };
    };
    const stop = (): void => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);

        if (resizing.value) {
            emit('resize', resizing.value.column, resizing.value.width);
        }

        resizing.value = null;
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
}

function scrollColumnIntoView(col: number): void {
    const element = scroller.value;

    if (!element) {
        return;
    }

    const left =
        NUMBER_WIDTH +
        props.columns
            .slice(0, col)
            .reduce((sum, { column }) => sum + widthOf(column), 0);
    const width = widthOf(props.columns[col].column);

    if (left - NUMBER_WIDTH < element.scrollLeft) {
        element.scrollLeft = left - NUMBER_WIDTH;
    } else if (left + width > element.scrollLeft + element.clientWidth) {
        element.scrollLeft = left + width - element.clientWidth;
    }
}

function moveTo(row: number, col: number): void {
    if (!props.rows.length || !props.columns.length) {
        return;
    }

    const next = {
        row: Math.max(0, Math.min(props.rows.length - 1, row)),
        col: Math.max(0, Math.min(props.columns.length - 1, col)),
    };
    active.value = next;
    virtualizer.value.scrollToIndex(next.row, { align: 'auto' });
    scrollColumnIntoView(next.col);
}

function onKeydown(event: KeyboardEvent): void {
    const current = active.value ?? { row: 0, col: 0 };
    const page = Math.max(
        1,
        Math.floor((scroller.value?.clientHeight ?? 320) / ROW_HEIGHT) - 2,
    );
    const moves: Record<string, () => void> = {
        ArrowDown: () =>
            moveTo(active.value ? current.row + 1 : 0, current.col),
        ArrowUp: () => moveTo(current.row - 1, current.col),
        ArrowRight: () =>
            moveTo(current.row, active.value ? current.col + 1 : 0),
        ArrowLeft: () => moveTo(current.row, current.col - 1),
        PageDown: () => moveTo(current.row + page, current.col),
        PageUp: () => moveTo(current.row - page, current.col),
        Home: () =>
            event.metaKey || event.ctrlKey
                ? moveTo(0, 0)
                : moveTo(current.row, 0),
        End: () =>
            event.metaKey || event.ctrlKey
                ? moveTo(props.rows.length - 1, props.columns.length - 1)
                : moveTo(current.row, props.columns.length - 1),
        Enter: () => {
            if (!active.value) {
                return;
            }

            if (props.editable && canEdit(active.value.row, active.value.col)) {
                startEditing(active.value.row, active.value.col);
            } else {
                emit('open', active.value.row);
            }
        },
        F2: () => {
            if (active.value) {
                startEditing(active.value.row, active.value.col);
            }
        },
        ' ': () => {
            if (active.value) {
                emit('open', active.value.row);
            }
        },
        Escape: () => {
            active.value = null;
        },
    };

    const action = moves[event.key];

    if (action) {
        event.preventDefault();
        action();
    }
}

const activeId = computed(() =>
    active.value ? `zelle-${active.value.row}-${active.value.col}` : undefined,
);
</script>

<template>
    <div
        ref="scroller"
        class="relative min-h-0 flex-1 overflow-auto outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
        data-scroll-hint-top="[role='row'][aria-rowindex='1']"
        data-scroll-hint-left="[role='row'] > [role='columnheader']:first-child"
        role="grid"
        :aria-rowcount="rows.length + 1"
        :aria-colcount="columns.length + 1"
        :aria-activedescendant="activeId"
        :aria-label="t('Datenraster')"
        tabindex="0"
        @keydown="onKeydown"
    >
        <div :style="{ width: `${totalWidth}px` }" class="min-w-full">
            <div
                role="row"
                aria-rowindex="1"
                class="sticky top-0 z-20 flex border-b bg-muted text-[13px]"
            >
                <div
                    role="columnheader"
                    class="sticky left-0 z-10 flex shrink-0 items-center justify-end bg-muted px-3 text-text-secondary"
                    :style="{ width: `${NUMBER_WIDTH}px` }"
                >
                    #
                </div>
                <div
                    v-for="{ column } in columns"
                    :key="column.name"
                    role="columnheader"
                    :aria-sort="ariaSort(column.name)"
                    class="group relative flex shrink-0 items-stretch border-l"
                    :style="{ width: `${widthOf(column)}px` }"
                >
                    <button
                        type="button"
                        :class="[
                            'flex min-w-0 flex-1 flex-col justify-center px-2 py-1 text-left outline-none hover:bg-background/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                            isNumericColumn(column) &&
                                !column.references &&
                                'items-end text-right',
                        ]"
                        :title="t('Sortieren — mit Umschalt zusätzlich')"
                        @click="
                            emit('sort', column.name, null, $event.shiftKey)
                        "
                    >
                        <span
                            class="flex max-w-full items-center gap-1 font-semibold"
                        >
                            <KeyRound
                                v-if="column.primary"
                                class="size-3.5 shrink-0 text-primary"
                                :aria-label="t('Primärschlüssel')"
                            />
                            <span class="truncate">{{ column.name }}</span>
                            <template v-if="sortOf(column.name)">
                                <component
                                    :is="
                                        sortOf(column.name)?.item.direction ===
                                        'asc'
                                            ? ArrowUp
                                            : ArrowDown
                                    "
                                    class="size-3.5 shrink-0 text-primary"
                                />
                                <span
                                    v-if="sort.length > 1"
                                    class="text-[11px] text-primary tabular-nums"
                                    >{{
                                        (sortOf(column.name)?.position ?? 0) + 1
                                    }}</span
                                >
                            </template>
                        </span>
                        <span
                            class="max-w-full truncate font-mono text-xs font-normal text-text-secondary"
                            >{{ column.type
                            }}<template v-if="column.references">
                                → {{ column.references.table }}</template
                            ></span
                        >
                    </button>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            class="my-auto mr-1 inline-grid size-6 shrink-0 place-items-center rounded-sm text-text-secondary opacity-0 outline-none group-hover:opacity-100 hover:bg-background focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:opacity-100"
                            :aria-label="
                                t('Spalte :column', { column: column.name })
                            "
                        >
                            <MoreHorizontal class="size-3.5" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                class="gap-2"
                                @select="
                                    emit('sort', column.name, 'asc', false)
                                "
                            >
                                <ArrowUp class="size-4" />{{
                                    t('Aufsteigend sortieren')
                                }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="gap-2"
                                @select="
                                    emit('sort', column.name, 'desc', false)
                                "
                            >
                                <ArrowDown class="size-4" />{{
                                    t('Absteigend sortieren')
                                }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="gap-2"
                                @select="emit('hide', column.name)"
                            >
                                <EyeOff class="size-4" />{{ t('Ausblenden') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <div
                        role="separator"
                        aria-orientation="vertical"
                        :aria-label="
                            t('Breite von :column ändern', {
                                column: column.name,
                            })
                        "
                        class="absolute top-0 -right-1 z-10 h-full w-2 cursor-col-resize hover:bg-primary/40"
                        @pointerdown="startResize($event, column)"
                    />
                </div>
            </div>

            <div
                class="relative"
                :style="{ height: `${virtualizer.getTotalSize()}px` }"
            >
                <div
                    v-for="item in items"
                    :key="String(item.key)"
                    role="row"
                    :aria-rowindex="item.index + 2"
                    :class="[
                        'absolute left-0 flex w-full border-b text-sm hover:bg-muted/60',
                        metaOf(item.index)?.state === 'new' &&
                            'bg-[var(--cell-new)]',
                        metaOf(item.index)?.state === 'deleted' &&
                            'bg-[var(--cell-deleted)] text-text-secondary line-through',
                    ]"
                    :style="{
                        height: `${ROW_HEIGHT}px`,
                        transform: `translateY(${item.start}px)`,
                    }"
                >
                    <div
                        role="rowheader"
                        class="sticky left-0 z-10 flex shrink-0 items-center justify-end bg-background px-1"
                        :style="{ width: `${NUMBER_WIDTH}px` }"
                    >
                        <button
                            type="button"
                            class="rounded-sm px-1.5 text-[13px] text-muted-foreground tabular-nums outline-none hover:text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="
                                t('Zeile :number öffnen', {
                                    number: item.index + 1,
                                })
                            "
                            tabindex="-1"
                            @click="emit('open', item.index)"
                        >
                            <template v-if="metaOf(item.index)?.state === 'new'"
                                >+</template
                            ><template
                                v-else-if="
                                    metaOf(item.index)?.state === 'deleted'
                                "
                                >–</template
                            ><template v-else>{{ item.index + 1 }}</template>
                        </button>
                    </div>
                    <div
                        v-for="({ column, index }, position) in columns"
                        :id="`zelle-${item.index}-${position}`"
                        :key="column.name"
                        role="gridcell"
                        :class="[
                            'flex shrink-0 items-center border-l px-2',
                            isNumericColumn(column) &&
                                !column.references &&
                                'justify-end text-right tabular-nums',
                            active?.row === item.index &&
                                active?.col === position &&
                                'outline-2 -outline-offset-2 outline-primary',
                            metaOf(item.index)?.changed.includes(column.name) &&
                                'bg-[var(--cell-changed)] font-medium',
                            editable && 'select-none',
                        ]"
                        :style="{ width: `${widthOf(column)}px` }"
                        @click="active = { row: item.index, col: position }"
                        @dblclick="
                            editable
                                ? startEditing(item.index, position)
                                : emit('open', item.index)
                        "
                    >
                        <span
                            v-if="
                                editing &&
                                editing.row === item.index &&
                                editing.col === position
                            "
                            class="-mx-2 flex h-full w-[calc(100%+1rem)] items-center gap-1 bg-background px-1 ring-2 ring-primary ring-inset"
                        >
                            <input
                                v-model="editing.draft"
                                v-focus
                                type="text"
                                class="h-full min-w-0 flex-1 bg-transparent px-1 font-mono text-[13px] outline-none"
                                :aria-label="
                                    t('Wert für :column', {
                                        column: column.name,
                                    })
                                "
                                @keydown="onEditorKeydown"
                                @focus="
                                    ($event.target as HTMLInputElement).select()
                                "
                                @blur="commitEditing()"
                            />
                            <button
                                v-if="column.nullable"
                                type="button"
                                class="shrink-0 rounded-sm border px-1 text-[11px] text-text-secondary hover:bg-muted"
                                :title="t('Auf NULL setzen')"
                                @mousedown.prevent="setNull"
                            >
                                NULL
                            </button>
                        </span>
                        <Link
                            v-else-if="
                                referenceHref(column, rows[item.index][index])
                            "
                            :href="
                                referenceHref(column, rows[item.index][index])!
                            "
                            class="flex min-w-0 items-center gap-1 text-primary hover:underline"
                            tabindex="-1"
                            ><ArrowRight class="size-3.5 shrink-0" /><span
                                class="shrink-0"
                                >{{ cellText(rows[item.index][index]) }}</span
                            ><span
                                v-if="labelOf(column, rows[item.index][index])"
                                class="truncate text-foreground"
                                >{{
                                    labelOf(column, rows[item.index][index])
                                }}</span
                            ></Link
                        >
                        <span
                            v-else
                            :class="[
                                'truncate',
                                rows[item.index][index] === null &&
                                    'text-muted-foreground italic',
                                (isBinaryCell(rows[item.index][index]) ||
                                    isTruncatedCell(rows[item.index][index])) &&
                                    'text-text-secondary',
                                column.kind === 'json' &&
                                    'font-mono text-[13px]',
                            ]"
                            >{{ cellText(rows[item.index][index]) }}</span
                        >
                    </div>
                </div>
            </div>
            <div
                v-if="loading"
                class="sticky left-0 flex h-8 items-center px-4 text-[13px] text-text-secondary"
                aria-live="polite"
            >
                {{ t('Weitere Zeilen werden geladen …') }}
            </div>
        </div>
    </div>
</template>
