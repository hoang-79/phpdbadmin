<script setup lang="ts">
import {
    ChevronDown,
    ChevronRight,
    Gauge,
    ListTree,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, postJson } from '@/lib/api';
import { formatNumber, intlLocale, t, tc } from '@/lib/i18n';
import { withDb } from '@/lib/workspace';
import { explain as explainRoute } from '@/routes/workspace/sql';
import type {
    ExplainHint,
    ExplainNode,
    ExplainRequest,
    ExplainResult,
    Workspace,
} from '@/types';

/**
 * EXPLAIN als Baum mit Hinweisen (Q-06): Operation, Objekt, geschätzte Zeilen, Kosten und Details je Knoten, mit
 * ANALYZE auch tatsächliche Zeilen und Zeit. Hinweise oben (vollständiger Durchlauf, Filesort, temporäre Tabelle,
 * Index nicht genutzt, Schätzung daneben) markieren ihren Knoten. ANALYZE nur auf Wunsch und nur für lesende
 * Anweisungen — der Server führt sie dann wirklich aus, im Schreibschutz-Wächter.
 */
const props = defineProps<{
    workspace: Workspace;
    request: ExplainRequest;
}>();

const emit = defineEmits<{ close: [] }>();

const loading = ref(false);
const failure = ref<string | null>(null);
const result = ref<ExplainResult | null>(null);
const collapsed = ref<Set<number>>(new Set());
const marked = ref<number | null>(null);

async function load(analyze: boolean): Promise<void> {
    loading.value = true;
    failure.value = null;

    try {
        result.value = await postJson<ExplainResult>(
            explainRoute.url(
                props.workspace.connection.id,
                withDb(props.workspace),
            ),
            {
                sql: props.request.sql,
                cursor: props.request.cursor,
                selection: props.request.selection,
                schema: props.request.schema,
                analyze,
            },
        );
        collapsed.value = new Set();
        marked.value = null;
    } catch (error) {
        result.value = null;
        failure.value = explainFailure(error);
    } finally {
        loading.value = false;
    }
}

function explainFailure(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return t('Die Anfrage ist gescheitert.');
    }

    const failed = error.failure as { reason: string; message?: string };

    switch (failed.reason) {
        case 'explain':
            return failed.message ?? t('Die Anfrage ist gescheitert.');
        case 'session':
            return t(
                'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
            );
        case 'password':
            return t(
                'Das Passwort dieser Verbindung gilt nicht mehr für diese Sitzung. Laden Sie die Seite neu.',
            );
        case 'connection':
            return error.failure.reason === 'connection'
                ? t('Die Verbindung zur Datenbank ist abgerissen: :reason', {
                      reason: error.failure.problem.title,
                  })
                : t('Die Anfrage ist gescheitert.');
        default:
            return t('Die Anfrage ist gescheitert.');
    }
}

watch(
    () => props.request.at,
    () => load(false),
    { immediate: true },
);

type Row = { node: ExplainNode; depth: number };

/** Der Baum als Zeilen mit Tiefe; eingeklappte Knoten verbergen ihre Nachkommen. */
const rows = computed<Row[]>(() => {
    const list: Row[] = [];
    const visit = (node: ExplainNode, depth: number): void => {
        list.push({ node, depth });

        if (!collapsed.value.has(node.id)) {
            node.children.forEach((child) => visit(child, depth + 1));
        }
    };

    if (result.value) {
        visit(result.value.plan, 0);
    }

    return list;
});

const hintsByNode = computed(() => {
    const map = new Map<number, ExplainHint[]>();

    for (const hint of result.value?.hints ?? []) {
        map.set(hint.node, [...(map.get(hint.node) ?? []), hint]);
    }

    return map;
});

const hintLabels = computed<Record<ExplainHint['kind'], string>>(() => ({
    full_scan: t('Vollständiger Durchlauf'),
    filesort: t('Sortieren ohne Index'),
    temporary: t('Temporäre Tabelle'),
    index_unused: t('Index nicht genutzt'),
    estimate: t('Schätzung daneben'),
}));

function toggle(id: number): void {
    const next = new Set(collapsed.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    collapsed.value = next;
}

/** Hinweis anklicken: Knoten sichtbar machen (Vorfahren aufklappen) und markieren. */
function show(hint: ExplainHint): void {
    const path: number[] = [];
    const find = (node: ExplainNode): boolean => {
        if (node.id === hint.node) {
            return true;
        }

        for (const child of node.children) {
            if (find(child)) {
                path.push(node.id);

                return true;
            }
        }

        return false;
    };

    if (result.value && find(result.value.plan)) {
        const next = new Set(collapsed.value);
        path.forEach((id) => next.delete(id));
        collapsed.value = next;
    }

    marked.value = hint.node;
    document
        .getElementById(`plan-knoten-${hint.node}`)
        ?.scrollIntoView({ block: 'nearest' });
}

function rowsText(value: number): string {
    return tc('≈ :count Zeile|≈ :count Zeilen', Math.round(value));
}

function round(value: number, digits = 1): string {
    return new Intl.NumberFormat(intlLocale(), {
        maximumFractionDigits: digits,
    }).format(value);
}
</script>

<template>
    <section
        class="flex min-h-0 flex-1 flex-col"
        :aria-label="t('Plan der Anweisung')"
    >
        <div
            class="flex shrink-0 flex-wrap items-center gap-2 border-b px-3 py-2 sm:px-4"
        >
            <h2 class="flex items-center gap-1.5 text-[13px] font-semibold">
                <ListTree class="size-4 text-primary" />{{ t('Plan') }}
            </h2>
            <span
                v-if="result"
                :class="[
                    'rounded-full border px-2 py-px text-xs',
                    result.analyzed
                        ? 'border-warning text-warning'
                        : 'text-text-secondary',
                ]"
                >{{
                    result.analyzed
                        ? t('mit ANALYZE gemessen')
                        : t('geschätzt, nicht ausgeführt')
                }}</span
            >
            <span v-if="result" class="text-xs text-text-secondary">{{
                t(':ms ms', { ms: round(result.durationMs) })
            }}</span>
            <div class="ml-auto flex flex-wrap items-center gap-1.5">
                <Button
                    v-if="result?.analyzable && !result.analyzed"
                    size="sm"
                    variant="outline"
                    :disabled="loading"
                    :title="
                        t(
                            'Führt die Anweisung aus — nur lesend, mit Zeitgrenze — und misst Zeilen und Zeit je Schritt.',
                        )
                    "
                    @click="load(true)"
                >
                    <Gauge />{{ t('Mit ANALYZE messen') }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :aria-label="t('Plan schliessen')"
                    @click="emit('close')"
                >
                    <X />{{ t('Zu den Ergebnissen') }}
                </Button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3 sm:px-4">
            <div
                v-if="loading"
                class="flex items-center gap-2 text-sm text-text-secondary"
                role="status"
            >
                <Spinner />{{ t('Plan wird erstellt …') }}
            </div>

            <Notice v-else-if="failure" variant="danger">{{ failure }}</Notice>

            <template v-else-if="result">
                <code
                    class="mb-3 block font-mono text-[12px] wrap-break-word whitespace-pre-wrap text-text-secondary"
                    >{{ result.sql }}</code
                >

                <ul
                    v-if="result.hints.length"
                    class="mb-3 flex flex-col gap-1.5"
                >
                    <li v-for="(hint, index) in result.hints" :key="index">
                        <button
                            type="button"
                            class="flex w-full items-start gap-2 rounded-md border border-l-4 border-warning/60 border-l-warning px-3 py-2 text-left text-sm outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                            @click="show(hint)"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            <span>
                                <b class="font-semibold">{{
                                    hintLabels[hint.kind]
                                }}</b>
                                · {{ hint.message }}
                            </span>
                        </button>
                    </li>
                </ul>
                <p v-else class="mb-3 text-sm text-text-secondary">
                    {{ t('Keine Hinweise — der Plan sieht unauffällig aus.') }}
                </p>

                <ol class="flex flex-col text-sm">
                    <li
                        v-for="row in rows"
                        :id="`plan-knoten-${row.node.id}`"
                        :key="row.node.id"
                        :class="[
                            'flex flex-col gap-0.5 border-l-2 py-1.5 pr-2',
                            hintsByNode.has(row.node.id)
                                ? 'border-l-warning bg-warning/5'
                                : 'border-l-transparent',
                            marked === row.node.id &&
                                'ring-2 ring-ring ring-inset',
                        ]"
                        :style="{
                            paddingLeft: `${Math.min(row.depth, 12) * 16 + 6}px`,
                        }"
                    >
                        <div
                            class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5"
                        >
                            <button
                                v-if="row.node.children.length"
                                type="button"
                                class="inline-grid size-5 shrink-0 place-items-center self-center rounded text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-expanded="!collapsed.has(row.node.id)"
                                :aria-label="
                                    collapsed.has(row.node.id)
                                        ? t('Aufklappen')
                                        : t('Zuklappen')
                                "
                                @click="toggle(row.node.id)"
                            >
                                <component
                                    :is="
                                        collapsed.has(row.node.id)
                                            ? ChevronRight
                                            : ChevronDown
                                    "
                                    class="size-3.5"
                                />
                            </button>
                            <span v-else class="size-5 shrink-0" />
                            <TriangleAlert
                                v-if="hintsByNode.has(row.node.id)"
                                class="size-3.5 shrink-0 self-center text-warning"
                                :aria-label="t('Hinweis')"
                            />
                            <span class="font-medium">{{
                                row.node.operation
                            }}</span>
                            <span
                                v-if="row.node.object"
                                class="font-mono text-[13px] text-primary"
                                >{{ row.node.object }}</span
                            >
                            <span
                                class="flex flex-wrap gap-x-3 text-xs text-text-secondary tabular-nums sm:ml-auto"
                            >
                                <span v-if="row.node.rows !== null">{{
                                    rowsText(row.node.rows)
                                }}</span>
                                <span v-if="row.node.actualRows !== null">{{
                                    t(':rows tatsächlich', {
                                        rows: formatNumber(
                                            Math.round(row.node.actualRows),
                                        ),
                                    })
                                }}</span>
                                <span
                                    v-if="
                                        row.node.loops !== null &&
                                        row.node.loops > 1
                                    "
                                    >{{
                                        t('× :loops', {
                                            loops: formatNumber(row.node.loops),
                                        })
                                    }}</span
                                >
                                <span v-if="row.node.cost !== null">{{
                                    t('Kosten :cost', {
                                        cost: round(row.node.cost, 2),
                                    })
                                }}</span>
                                <span v-if="row.node.time !== null">{{
                                    t(':ms ms', { ms: round(row.node.time, 2) })
                                }}</span>
                            </span>
                        </div>
                        <ul
                            v-if="row.node.details.length"
                            class="flex flex-col pl-7 font-mono text-[12px] wrap-break-word text-text-secondary"
                        >
                            <li
                                v-for="(detail, index) in row.node.details"
                                :key="index"
                            >
                                {{ detail }}
                            </li>
                        </ul>
                    </li>
                </ol>

                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-text-secondary">
                        {{ t('Ausgabe des Servers') }}
                    </summary>
                    <pre
                        class="mt-2 rounded-md border bg-muted px-3 py-2 font-mono text-[12px] wrap-break-word whitespace-pre-wrap"
                        >{{ result.raw }}</pre>
                </details>
            </template>
        </div>
    </section>
</template>
