<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { HEADER, relationPath, ROW, shortened } from '@/lib/diagram';
import { t, tc } from '@/lib/i18n';
import type { DiagramRelation, DiagramTable } from '@/types';

/**
 * Zeichenfläche des Beziehungsdiagramms (S-07): Kästen ziehen, Fläche verschieben, mit dem Mausrad oder den Knöpfen
 * zoomen; mit der Tastatur Pfeile (verschieben), + und − (zoomen), 0 (einpassen). Fährt man über einen Kasten,
 * treten seine Linien hervor; Doppelklick öffnet die Struktur der Tabelle.
 */
const props = defineProps<{
    tables: DiagramTable[];
    relations: DiagramRelation[];
}>();

const emit = defineEmits<{ open: [table: string] }>();

const canvas = ref<SVGSVGElement | null>(null);
const positions = reactive<Record<string, { x: number; y: number }>>({});
const view = reactive({ x: 0, y: 0, scale: 1 });
const hovered = ref<string | null>(null);

type Drag =
    | {
          kind: 'table';
          name: string;
          startX: number;
          startY: number;
          originX: number;
          originY: number;
      }
    | {
          kind: 'pan';
          startX: number;
          startY: number;
          originX: number;
          originY: number;
      };
let drag: Drag | null = null;

function resetPositions(): void {
    for (const key of Object.keys(positions)) {
        delete positions[key];
    }

    for (const table of props.tables) {
        positions[table.name] = { x: table.x, y: table.y };
    }
}

resetPositions();

const placed = computed<DiagramTable[]>(() =>
    props.tables.map((table) => ({
        ...table,
        x: positions[table.name]?.x ?? table.x,
        y: positions[table.name]?.y ?? table.y,
    })),
);

const byName = computed(
    () => new Map(placed.value.map((table) => [table.name, table])),
);

const links = computed(() =>
    props.relations.flatMap((relation) => {
        const child = byName.value.get(relation.table);
        const parent = byName.value.get(relation.foreignTable);

        if (!child || !parent) {
            return [];
        }

        return [
            {
                key: `${relation.table}:${relation.name}`,
                relation,
                path: relationPath(
                    child,
                    relation.columns[0] ?? '',
                    parent,
                    relation.foreignColumns[0] ?? '',
                ),
                active:
                    hovered.value !== null &&
                    (relation.table === hovered.value ||
                        relation.foreignTable === hovered.value),
            },
        ];
    }),
);

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/** Alles sichtbar, höchstens in Originalgrösse. */
function fit(): void {
    const element = canvas.value;

    if (!element || placed.value.length === 0) {
        return;
    }

    const minX = Math.min(...placed.value.map((table) => table.x));
    const minY = Math.min(...placed.value.map((table) => table.y));
    const maxX = Math.max(
        ...placed.value.map((table) => table.x + table.width),
    );
    const maxY = Math.max(
        ...placed.value.map((table) => table.y + table.height),
    );
    const width = element.clientWidth || 800;
    const height = element.clientHeight || 600;
    const scale = clamp(
        Math.min(
            (width - 32) / (maxX - minX || 1),
            (height - 32) / (maxY - minY || 1),
        ),
        0.15,
        1,
    );

    view.scale = scale;
    view.x = (width - (maxX - minX) * scale) / 2 - minX * scale;
    view.y = (height - (maxY - minY) * scale) / 2 - minY * scale;
}

/** Zoomen um einen Punkt der Fläche (ohne Punkt: die Mitte). */
function zoomBy(factor: number, at?: { x: number; y: number }): void {
    const element = canvas.value;
    const point = at ?? {
        x: (element?.clientWidth ?? 800) / 2,
        y: (element?.clientHeight ?? 600) / 2,
    };
    const scale = clamp(view.scale * factor, 0.15, 3);
    const contentX = (point.x - view.x) / view.scale;
    const contentY = (point.y - view.y) / view.scale;

    view.scale = scale;
    view.x = point.x - contentX * scale;
    view.y = point.y - contentY * scale;
}

function local(event: { clientX: number; clientY: number }): {
    x: number;
    y: number;
} {
    const box = canvas.value?.getBoundingClientRect();

    return {
        x: event.clientX - (box?.left ?? 0),
        y: event.clientY - (box?.top ?? 0),
    };
}

function onWheel(event: WheelEvent): void {
    event.preventDefault();
    zoomBy(Math.exp(-event.deltaY * 0.0015), local(event));
}

function startTable(event: PointerEvent, table: DiagramTable): void {
    if (event.button !== 0) {
        return;
    }

    event.stopPropagation();
    canvas.value?.setPointerCapture(event.pointerId);
    drag = {
        kind: 'table',
        name: table.name,
        startX: event.clientX,
        startY: event.clientY,
        originX: table.x,
        originY: table.y,
    };
}

function startPan(event: PointerEvent): void {
    if (event.button !== 0) {
        return;
    }

    canvas.value?.setPointerCapture(event.pointerId);
    drag = {
        kind: 'pan',
        startX: event.clientX,
        startY: event.clientY,
        originX: view.x,
        originY: view.y,
    };
}

function move(event: PointerEvent): void {
    if (!drag) {
        return;
    }

    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;

    if (drag.kind === 'table') {
        positions[drag.name] = {
            x: Math.round(drag.originX + dx / view.scale),
            y: Math.round(drag.originY + dy / view.scale),
        };
    } else {
        view.x = drag.originX + dx;
        view.y = drag.originY + dy;
    }
}

// Doppelklick selbst erkennen: mit gefangenem Zeiger landet «dblclick» auf der Fläche, nicht auf dem Kasten.
let lastClick: { name: string; at: number } | null = null;

function stop(event: PointerEvent): void {
    if (drag && canvas.value?.hasPointerCapture(event.pointerId)) {
        canvas.value.releasePointerCapture(event.pointerId);
    }

    const moved =
        drag !== null &&
        Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) >
            4;

    if (drag?.kind === 'table' && !moved) {
        const now = Date.now();

        if (lastClick?.name === drag.name && now - lastClick.at < 450) {
            emit('open', drag.name);
            lastClick = null;
        } else {
            lastClick = { name: drag.name, at: now };
        }
    }

    drag = null;
}

function onKey(event: KeyboardEvent): void {
    const steps: Record<string, [number, number]> = {
        ArrowLeft: [40, 0],
        ArrowRight: [-40, 0],
        ArrowUp: [0, 40],
        ArrowDown: [0, -40],
    };

    if (steps[event.key]) {
        event.preventDefault();
        view.x += steps[event.key][0];
        view.y += steps[event.key][1];
    } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        zoomBy(1.2);
    } else if (event.key === '-') {
        event.preventDefault();
        zoomBy(1 / 1.2);
    } else if (event.key === '0') {
        event.preventDefault();
        fit();
    }
}

/** Positionen für den Export — so, wie die Kästen gerade stehen. */
function currentPositions(): { name: string; x: number; y: number }[] {
    return placed.value.map((table) => ({
        name: table.name,
        x: table.x,
        y: table.y,
    }));
}

function rearrange(): void {
    resetPositions();
    void nextTick(fit);
}

defineExpose({ fit, zoomBy, positions: currentPositions, rearrange });

watch(
    () => props.tables,
    () => {
        resetPositions();
        void nextTick(fit);
    },
);

onMounted(() => {
    fit();
    canvas.value?.addEventListener('wheel', onWheel, { passive: false });
});

const label = computed(() =>
    t(
        'Beziehungsdiagramm: :tables, :relations. Pfeiltasten verschieben, Plus und Minus zoomen, 0 passt ein.',
        {
            tables: tc(':count Tabelle|:count Tabellen', props.tables.length),
            relations: tc(
                ':count Beziehung|:count Beziehungen',
                props.relations.length,
            ),
        },
    ),
);
</script>

<template>
    <svg
        ref="canvas"
        class="block h-full w-full touch-none bg-background outline-none select-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
        role="img"
        :aria-label="label"
        tabindex="0"
        @pointerdown="startPan"
        @pointermove="move"
        @pointerup="stop"
        @pointercancel="stop"
        @keydown="onKey"
    >
        <defs>
            <marker
                id="diagramm-pfeil"
                viewBox="0 0 10 10"
                refX="9"
                refY="5"
                markerWidth="8"
                markerHeight="8"
                orient="auto-start-reverse"
            >
                <path d="M0,0 L10,5 L0,10 z" class="fill-primary" />
            </marker>
        </defs>
        <g :transform="`translate(${view.x} ${view.y}) scale(${view.scale})`">
            <g>
                <path
                    v-for="link in links"
                    :key="link.key"
                    :d="link.path"
                    fill="none"
                    marker-end="url(#diagramm-pfeil)"
                    :class="[
                        'stroke-primary',
                        hovered !== null && !link.active && 'opacity-25',
                    ]"
                    :stroke-width="link.active ? 2.5 : 1.5"
                >
                    <title>
                        {{ link.relation.name }}: {{ link.relation.table }}({{
                            link.relation.columns.join(', ')
                        }}) → {{ link.relation.foreignTable }}({{
                            link.relation.foreignColumns.join(', ')
                        }})
                    </title>
                </path>
            </g>
            <g
                v-for="table in placed"
                :key="table.name"
                :transform="`translate(${table.x} ${table.y})`"
                class="cursor-grab active:cursor-grabbing"
                @pointerdown="startTable($event, table)"
                @pointerenter="hovered = table.name"
                @pointerleave="hovered = null"
            >
                <title>{{ table.name }}</title>
                <rect
                    :width="table.width"
                    :height="table.height"
                    rx="6"
                    class="fill-background"
                />
                <rect
                    :width="table.width"
                    :height="HEADER"
                    rx="6"
                    class="fill-accent"
                />
                <rect
                    :y="HEADER - 6"
                    :width="table.width"
                    height="6"
                    class="fill-accent"
                />
                <line
                    x1="0"
                    :y1="HEADER"
                    :x2="table.width"
                    :y2="HEADER"
                    class="stroke-border"
                />
                <rect
                    :width="table.width"
                    :height="table.height"
                    rx="6"
                    fill="none"
                    :class="
                        hovered === table.name
                            ? 'stroke-primary'
                            : 'stroke-text-secondary/50'
                    "
                    :stroke-width="hovered === table.name ? 2 : 1"
                />
                <text
                    x="10"
                    y="19"
                    class="fill-foreground text-[13px] font-semibold"
                >
                    {{ shortened(table.name, table.width - 20, 7.8) }}
                </text>
                <g
                    v-for="(column, index) in table.columns"
                    :key="column.name"
                    :transform="`translate(0 ${HEADER + index * ROW + 14})`"
                >
                    <text
                        v-if="column.primary || column.foreign"
                        x="8"
                        :class="[
                            'text-[10px] font-semibold',
                            column.primary
                                ? 'fill-primary'
                                : 'fill-text-secondary',
                        ]"
                    >
                        {{ column.primary ? 'PK' : 'FK' }}
                    </text>
                    <text x="30" class="fill-foreground font-mono text-[12px]">
                        {{ shortened(column.name, table.width * 0.55) }}
                    </text>
                    <text
                        :x="table.width - 8"
                        text-anchor="end"
                        class="fill-muted-foreground font-mono text-[12px]"
                    >
                        {{ shortened(column.type, table.width * 0.35) }}
                    </text>
                </g>
                <text
                    v-if="table.more > 0"
                    x="30"
                    :y="HEADER + table.columns.length * ROW + 14"
                    class="fill-muted-foreground text-[12px] italic"
                >
                    {{
                        tc(
                            '… :count weitere Spalte|… :count weitere Spalten',
                            table.more,
                        )
                    }}
                </text>
            </g>
        </g>
    </svg>
</template>
