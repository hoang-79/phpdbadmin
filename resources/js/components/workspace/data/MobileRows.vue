<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { cellText } from '@/lib/grid';
import { t, tc } from '@/lib/i18n';
import type { Cell, GridColumn } from '@/types';

/**
 * Raster auf dem Telefon (Wireframe 05 mobil): je Zeile eine Karte mit Schlüssel und den ersten Feldern, «alle
 * Felder» öffnet die Zeile. Nachladen über einen Knopf statt beim Scrollen.
 */
const props = defineProps<{
    columns: { column: GridColumn; index: number }[];
    rows: Cell[][];
    labels: Record<string, Record<string, string>>;
    hasMore: boolean;
    loading: boolean;
}>();

const emit = defineEmits<{ open: [row: number]; loadMore: [] }>();

const primary = computed(() =>
    props.columns.filter(({ column }) => column.primary),
);
const shown = computed(() =>
    props.columns.filter(({ column }) => !column.primary).slice(0, 4),
);

function label(column: GridColumn, cell: Cell): string | null {
    return cell === null || typeof cell === 'object'
        ? null
        : (props.labels[column.name]?.[String(cell)] ?? null);
}
</script>

<template>
    <div class="flex flex-col gap-2 px-4 py-3">
        <button
            v-for="(row, rowIndex) in rows"
            :key="rowIndex"
            type="button"
            class="flex flex-col gap-1 rounded-lg border bg-background px-3.5 py-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @click="emit('open', rowIndex)"
        >
            <span class="flex items-center gap-2">
                <span class="truncate font-mono text-sm font-semibold">
                    <template v-if="primary.length">
                        <template
                            v-for="{ column, index } in primary"
                            :key="column.name"
                            >{{ column.name }} {{ cellText(row[index]) }}
                        </template>
                    </template>
                    <template v-else>{{
                        t('Zeile :number', { number: rowIndex + 1 })
                    }}</template>
                </span>
                <span
                    class="ml-auto flex shrink-0 items-center text-[13px] text-text-secondary"
                    >{{
                        tc(
                            'alle :count Felder|alle :count Felder',
                            columns.length,
                        )
                    }}<ChevronRight class="size-4"
                /></span>
            </span>
            <span class="flex flex-wrap gap-x-4 gap-y-0.5 text-sm">
                <span
                    v-for="{ column, index } in shown"
                    :key="column.name"
                    class="flex max-w-full min-w-0 gap-1.5"
                >
                    <span
                        class="shrink-0 font-mono text-[13px] text-text-secondary"
                        >{{ column.name }}</span
                    >
                    <span
                        :class="[
                            'truncate',
                            row[index] === null &&
                                'text-muted-foreground italic',
                            column.references &&
                                row[index] !== null &&
                                'text-primary',
                        ]"
                        ><template
                            v-if="column.references && row[index] !== null"
                            >→ </template
                        >{{ cellText(row[index]) }}
                        <template v-if="label(column, row[index])">{{
                            label(column, row[index])
                        }}</template></span
                    >
                </span>
            </span>
        </button>
        <Button
            v-if="hasMore"
            variant="outline"
            :disabled="loading"
            @click="emit('loadMore')"
        >
            {{
                loading
                    ? t('Weitere Zeilen werden geladen …')
                    : t('Weitere Zeilen laden')
            }}
        </Button>
    </div>
</template>
