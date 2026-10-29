<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleSlash,
    Clock,
    Copy,
    Table2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Notice from '@/components/Notice.vue';
import DataGrid from '@/components/workspace/data/DataGrid.vue';
import { cellText } from '@/lib/grid';
import { formatNumber, t, tc } from '@/lib/i18n';
import type { GridColumn, SqlResult, Workspace } from '@/types';

/**
 * Ergebnisse des SQL-Editors (Q-02, Wireframe 10): je Anweisung ein Reiter mit Zeilen, Dauer und Stand; abgebrochen,
 * Zeitgrenze und Fehler in Klartext. Zeilen über der Grenze lädt «mehr laden» nach.
 */
const props = defineProps<{
    workspace: Workspace;
    results: SqlResult[];
    maxRowLimit: number;
    loadingMore: boolean;
}>();

const emit = defineEmits<{ more: [index: number] }>();

const active = ref(0);
const widths = ref<Record<string, number>>({});

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
        }
    },
);

const current = computed(() => props.results[active.value] ?? null);

const columns = computed<{ column: GridColumn; index: number }[]>(() =>
    (current.value?.columns ?? []).map((column, index) => ({
        index,
        column: {
            name: column.name,
            type: column.type ?? '',
            kind: isNumeric(column.type, index) ? 'number' : 'other',
            kindLabel: '',
            nullable: true,
            primary: false,
            references: null,
        },
    })),
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
                @click="active = index"
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
                <code class="max-w-full truncate font-mono text-foreground">{{
                    current.sql.replace(/\s+/g, ' ')
                }}</code>
                <span class="tabular-nums"
                    >{{ formatNumber(current.durationMs) }} ms</span
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
                    :rows="current.rows"
                    :labels="{}"
                    :widths="widths"
                    :sort="[]"
                    :has-more="false"
                    :loading="false"
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
                        :disabled="loadingMore"
                        @click="emit('more', active)"
                    >
                        {{ t('mehr laden') }}
                    </button>
                </div>
            </template>
        </template>
    </section>
</template>
