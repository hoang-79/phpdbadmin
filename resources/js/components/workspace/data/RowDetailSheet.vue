<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    ChevronUp,
    KeyRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Notice from '@/components/Notice.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError, getJson } from '@/lib/api';
import { formatBytes } from '@/lib/format';
import { isBinaryCell, isTruncatedCell } from '@/lib/grid';
import { formatNumber, t, tc } from '@/lib/i18n';
import { dataHref, rowUrl } from '@/lib/workspace';
import type { Cell, GridColumn, RowDetailData, Workspace } from '@/types';

/**
 * Zeile im Detail (Wireframe 07, D-03): alle Felder mit Typ, Werte ungekürzt bis zu einer Grenze, rechts die
 * Verweise in beide Richtungen. Pfeile blättern zur vorigen und nächsten Zeile. Bearbeiten kommt mit TB 04.
 */
const props = defineProps<{
    workspace: Workspace;
    object: string;
    columns: GridColumn[];
    rowKey: (string | null)[] | null;
    rowNumber: number | null;
    hasPrevious: boolean;
    hasNext: boolean;
}>();

const emit = defineEmits<{ close: []; previous: []; next: [] }>();

const detail = ref<RowDetailData | null>(null);
const failure = ref<string | null>(null);
const loading = ref(false);

watch(
    () => props.rowKey,
    async (key) => {
        detail.value = null;
        failure.value = null;

        if (!key) {
            return;
        }

        loading.value = true;

        try {
            detail.value = await getJson<RowDetailData>(
                rowUrl(props.workspace, props.object, key),
            );
        } catch (error) {
            failure.value =
                error instanceof ApiError && error.failure.reason === 'missing'
                    ? t(
                          'Diese Zeile gibt es nicht mehr — vielleicht wurde sie inzwischen gelöscht.',
                      )
                    : error instanceof ApiError &&
                        error.failure.reason === 'session'
                      ? t(
                            'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
                        )
                      : t('Die Zeile liess sich nicht laden.');
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const title = computed(() => {
    const primary = props.columns
        .map((column, index) => ({ column, index }))
        .filter(({ column }) => column.primary);

    return primary
        .map(
            ({ column }, position) =>
                `${column.name} ${props.rowKey?.[position] ?? ''}`,
        )
        .join(' · ');
});

function pretty(cell: Cell, column: GridColumn): string {
    if (typeof cell === 'string' && column.kind === 'json') {
        try {
            return JSON.stringify(JSON.parse(cell), null, 2);
        } catch {
            return cell;
        }
    }

    if (typeof cell === 'boolean') {
        return cell ? 'true' : 'false';
    }

    if (isTruncatedCell(cell)) {
        return cell.text;
    }

    return cell === null || typeof cell === 'object' ? '' : String(cell);
}

function filterHref(
    table: string,
    columns: string[],
    values: (string | null)[],
): string {
    return dataHref(props.workspace, table, {
        f: columns.map((column, index) => ({
            c: column,
            o: 'eq',
            v: values[index] ?? '',
        })),
    });
}
</script>

<template>
    <Sheet
        :open="rowKey !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-[880px]"
        >
            <div class="flex items-center gap-2 border-b py-3 pr-12 pl-4">
                <SheetTitle class="flex min-w-0 items-baseline gap-2 text-lg">
                    {{ t('Zeile') }}
                    <span
                        class="truncate font-mono text-sm font-normal text-text-secondary"
                        >{{ object }} · {{ title }}</span
                    >
                </SheetTitle>
                <SheetDescription class="sr-only">{{
                    t('Alle Felder der Zeile und ihre Verweise')
                }}</SheetDescription>
                <div class="ml-auto flex gap-1">
                    <button
                        type="button"
                        class="inline-grid size-8 place-items-center rounded-md border outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40"
                        :disabled="!hasPrevious"
                        :aria-label="t('Vorige Zeile')"
                        @click="emit('previous')"
                    >
                        <ChevronUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-grid size-8 place-items-center rounded-md border outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40"
                        :disabled="!hasNext"
                        :aria-label="t('Nächste Zeile')"
                        @click="emit('next')"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div v-if="loading" class="flex flex-col gap-3 p-4">
                    <Skeleton v-for="index in 6" :key="index" class="h-10" />
                </div>
                <div v-else-if="failure" class="p-4">
                    <Notice variant="danger">{{ failure }}</Notice>
                </div>
                <div
                    v-else-if="detail"
                    class="grid gap-6 p-4 lg:grid-cols-[1fr_17rem]"
                >
                    <dl class="flex flex-col divide-y">
                        <div
                            v-for="(column, index) in columns"
                            :key="column.name"
                            class="grid gap-1 py-2.5 sm:grid-cols-[11rem_1fr] sm:gap-3"
                        >
                            <dt class="flex flex-col">
                                <span
                                    class="flex items-center gap-1 font-mono text-sm font-medium"
                                    >{{ column.name
                                    }}<KeyRound
                                        v-if="column.primary"
                                        class="size-3.5 text-primary"
                                        :aria-label="t('Primärschlüssel')"
                                /></span>
                                <span
                                    class="font-mono text-xs text-text-secondary"
                                    >{{ column.type
                                    }}<template v-if="column.nullable">
                                        · {{ t('NULL erlaubt') }}</template
                                    ></span
                                >
                            </dt>
                            <dd class="min-w-0">
                                <span
                                    v-if="detail.values[index] === null"
                                    class="text-sm text-muted-foreground italic"
                                    >NULL</span
                                >
                                <span
                                    v-else-if="
                                        isBinaryCell(detail.values[index])
                                    "
                                    class="text-sm text-text-secondary"
                                    >{{
                                        t(
                                            'Binärdaten · :size · beginnt mit :hex',
                                            {
                                                size: formatBytes(
                                                    (
                                                        detail.values[
                                                            index
                                                        ] as { binary: number }
                                                    ).binary,
                                                ),
                                                hex: (
                                                    detail.values[index] as {
                                                        hex: string;
                                                    }
                                                ).hex,
                                            },
                                        )
                                    }}</span
                                >
                                <template v-else>
                                    <pre
                                        :class="[
                                            'max-h-80 overflow-auto rounded-md border bg-muted px-2.5 py-1.5 text-sm break-words whitespace-pre-wrap',
                                            (column.kind === 'json' ||
                                                column.kind === 'number' ||
                                                column.kind === 'datetime') &&
                                                'font-mono',
                                        ]"
                                        >{{
                                            pretty(detail.values[index], column)
                                        }}</pre>
                                    <span
                                        v-if="
                                            isTruncatedCell(
                                                detail.values[index],
                                            )
                                        "
                                        class="text-xs text-text-secondary"
                                        >{{
                                            t(
                                                'gekürzt — :length Zeichen insgesamt',
                                                {
                                                    length: formatNumber(
                                                        (
                                                            detail.values[
                                                                index
                                                            ] as {
                                                                length: number;
                                                            }
                                                        ).length,
                                                    ),
                                                },
                                            )
                                        }}</span
                                    >
                                </template>
                            </dd>
                        </div>
                    </dl>

                    <aside class="flex flex-col gap-2">
                        <h3 class="text-[15px] font-semibold">
                            {{ t('Verweise') }}
                        </h3>
                        <ul
                            v-if="
                                detail.outgoing.length || detail.incoming.length
                            "
                            class="divide-y rounded-lg border text-sm"
                        >
                            <li
                                v-for="reference in detail.outgoing"
                                :key="`aus-${reference.column}`"
                                class="flex items-start gap-2 px-3 py-2.5"
                            >
                                <ArrowRight class="mt-0.5 size-4 shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-xs text-text-secondary"
                                        >{{ t('verweist auf') }}</span
                                    >
                                    <span class="font-mono">{{
                                        reference.table
                                    }}</span>
                                    · {{ reference.value }}
                                    <template v-if="reference.label">{{
                                        reference.label
                                    }}</template>
                                </span>
                                <Link
                                    :href="
                                        filterHref(
                                            reference.table,
                                            [reference.foreignColumn],
                                            [reference.value],
                                        )
                                    "
                                    class="shrink-0 text-primary underline"
                                    >{{ t('öffnen') }}</Link
                                >
                            </li>
                            <li
                                v-for="reference in detail.incoming"
                                :key="`ein-${reference.table}-${reference.name}`"
                                class="flex items-start gap-2 px-3 py-2.5"
                            >
                                <ArrowLeft class="mt-0.5 size-4 shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-xs text-text-secondary"
                                        >{{ t('wird verwiesen von') }}</span
                                    >
                                    <span class="font-mono break-all"
                                        >{{ reference.table }}.{{
                                            reference.columns.join(', ')
                                        }}</span
                                    >
                                    ·
                                    {{
                                        reference.count === null
                                            ? t('Anzahl offen')
                                            : reference.count === 0
                                              ? t('keine Zeilen')
                                              : tc(
                                                    ':count Zeile|:count Zeilen',
                                                    reference.count,
                                                )
                                    }}
                                </span>
                                <Link
                                    v-if="reference.count !== 0"
                                    :href="
                                        filterHref(
                                            reference.table,
                                            reference.columns,
                                            reference.values,
                                        )
                                    "
                                    class="shrink-0 text-primary underline"
                                    >{{ t('zeigen') }}</Link
                                >
                            </li>
                        </ul>
                        <p v-else class="text-sm text-text-secondary">
                            {{ t('Diese Zeile hat keine Verweise.') }}
                        </p>
                        <p
                            v-if="rowNumber !== null"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{
                                t('Zeile :number im Raster', {
                                    number: formatNumber(rowNumber + 1),
                                })
                            }}
                        </p>
                    </aside>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
