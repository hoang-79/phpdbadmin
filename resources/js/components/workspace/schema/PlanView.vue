<script setup lang="ts">
import { Check, CircleX, Layers, Minus, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type { SchemaFailure, SchemaPlanPreview } from '@/types';

/**
 * Erzeugtes SQL einer Strukturänderung (Wireframes 6 und 9): zuerst das Verfahren je Treiber (ADR-003) — «nicht
 * rückrollbar» bei MySQL und MariaDB, «in einer Transaktion» bei PostgreSQL, «Umbau» bei SQLite —, dann die
 * Anweisungen genau so, wie sie laufen, mit ihrer Art. Was das Verfahren selbst ausführt (BEGIN, PRAGMA), steht grau
 * und ohne Nummer davor und danach. Nach einem Fehler zeigt eine eigene Liste, welche Schritte gelaufen sind.
 */
const props = withDefaults(
    defineProps<{
        plan: SchemaPlanPreview | null;
        loading?: boolean;
        failure?: SchemaFailure | null;
        empty?: string | null;
    }>(),
    { loading: false, failure: null, empty: null },
);

const warns = computed(() => props.plan?.mode === 'statements');

/** Zustand einer Anweisung nach dem Fehler: gelaufen, gescheitert, nicht gelaufen. */
function state(index: number): 'applied' | 'failed' | 'skipped' {
    if (!props.failure || props.failure.rolledBack) {
        return 'skipped';
    }

    if (index < props.failure.applied) {
        return 'applied';
    }

    return props.failure.failed === index + 1 ? 'failed' : 'skipped';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-if="failure"
            class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
            role="alert"
        >
            <span class="flex items-center gap-2 font-semibold text-destructive"
                ><CircleX class="size-4 shrink-0" />{{
                    failure.rolledBack
                        ? t('Fehlgeschlagen — nichts wurde angewendet.')
                        : t(
                              'Angehalten nach :applied von :total Anweisungen — die gelaufenen bleiben bestehen.',
                              {
                                  applied: failure.applied,
                                  total: failure.statements.length,
                              },
                          )
                }}</span
            >
            <span
                class="font-mono text-xs wrap-break-word text-text-secondary"
                >{{ failure.message }}</span
            >
            <ol
                v-if="!failure.rolledBack"
                class="flex flex-col rounded-md border bg-background font-mono text-xs leading-5"
            >
                <li
                    v-for="(sql, index) in failure.statements"
                    :key="index"
                    class="flex gap-2 border-b px-2.5 py-1.5 last:border-b-0"
                >
                    <Check
                        v-if="state(index) === 'applied'"
                        class="mt-0.5 size-3.5 shrink-0 text-success"
                        :aria-label="t('angewendet')"
                    />
                    <CircleX
                        v-else-if="state(index) === 'failed'"
                        class="mt-0.5 size-3.5 shrink-0 text-destructive"
                        :aria-label="t('fehlgeschlagen')"
                    />
                    <Minus
                        v-else
                        class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                        :aria-label="t('nicht gelaufen')"
                    />
                    <code class="min-w-0 wrap-break-word">{{ sql }};</code>
                </li>
            </ol>
        </div>

        <div
            v-if="loading && !plan"
            class="flex items-center gap-2 text-sm text-text-secondary"
            role="status"
        >
            <Spinner />{{ t('Vorschau wird erstellt …') }}
        </div>

        <template v-if="plan">
            <div
                :class="[
                    'flex gap-2.5 rounded-lg border border-l-4 px-3.5 py-3',
                    warns ? 'border-warning' : 'border-l-primary',
                ]"
            >
                <component
                    :is="warns ? TriangleAlert : Layers"
                    :class="[
                        'mt-0.5 size-4 shrink-0',
                        warns ? 'text-warning' : 'text-primary',
                    ]"
                />
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span
                        :class="[
                            'text-[13px] font-semibold',
                            warns && 'text-warning',
                        ]"
                        >{{ plan.title }}</span
                    >
                    <span
                        class="text-[13px] leading-[18px] break-words text-text-secondary"
                        >{{ plan.explanation }}</span
                    >
                </div>
            </div>

            <ol
                v-if="plan.statements.length"
                :class="[
                    'flex flex-col rounded-md border bg-muted font-mono text-[13px] leading-5 transition-opacity',
                    loading && 'opacity-60',
                ]"
                :aria-label="t('SQL-Vorschau')"
                :aria-busy="loading"
            >
                <li
                    v-for="(line, index) in plan.before"
                    :key="`vor-${index}`"
                    class="border-b px-3 py-1.5 pl-10 text-muted-foreground"
                >
                    {{ line }};
                </li>
                <li
                    v-for="(statement, index) in plan.statements"
                    :key="index"
                    class="flex gap-3 border-b px-3 py-2 last:border-b-0"
                >
                    <span
                        class="w-4 shrink-0 text-right text-muted-foreground tabular-nums"
                        aria-hidden="true"
                        >{{ index + 1 }}</span
                    >
                    <code class="min-w-0 flex-1 wrap-break-word"
                        >{{ statement.sql }};</code
                    >
                    <span
                        v-if="statement.label"
                        class="shrink-0 font-sans text-xs text-text-secondary"
                        >{{ statement.label }}</span
                    >
                </li>
                <li
                    v-for="(line, index) in plan.after"
                    :key="`nach-${index}`"
                    class="border-t px-3 py-1.5 pl-10 text-muted-foreground"
                >
                    {{ line }};
                </li>
            </ol>
            <p
                v-else-if="empty"
                class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-text-secondary"
            >
                {{ empty }}
            </p>

            <ul
                v-if="plan.notes.length"
                class="flex flex-col gap-1 text-[13px] leading-[18px] text-text-secondary"
            >
                <li v-for="note in plan.notes" :key="note">{{ note }}</li>
            </ul>
        </template>
    </div>
</template>
