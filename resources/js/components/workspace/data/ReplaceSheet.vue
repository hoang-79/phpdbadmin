<script setup lang="ts">
import { Eye, Layers, Replace, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, postJson } from '@/lib/api';
import { formatNumber, t, tc } from '@/lib/i18n';
import { replace as replaceRoute } from '@/routes/workspace/object';
import { preview as previewRoute } from '@/routes/workspace/object/replace';
import type { GridColumn, Workspace } from '@/types';

/**
 * Suchen und Ersetzen in einer Tabelle (D-08): Spalten, Suchtext, Ersatz, Gross-/Kleinschreibung, ganzer Wert oder
 * jedes Vorkommen. Erst die Vorschau mit Zahl der Zeilen und Beispielen vorher/nachher, dann Ersetzen in einer
 * Transaktion; Rückgängig wie beim Bearbeiten. Ändert sich eine Eingabe, gilt die Vorschau nicht mehr.
 */
const props = defineProps<{
    open: boolean;
    workspace: Workspace;
    object: string;
    columns: GridColumn[];
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    applied: [result: { auditId: number; rows: number; undoable: boolean }];
}>();

type Preview = {
    rows: number;
    cells: number;
    samples: {
        key: (string | null)[];
        column: string;
        before: string;
        after: string;
    }[];
    undoable: boolean;
    tooMany: boolean;
    limit: number;
    undoLimit: number;
    sql: string;
};

const KINDS = ['text', 'choice', 'json', 'identifier'];

const candidates = computed(() =>
    props.columns.filter(
        (column) => KINDS.includes(column.kind) && !column.primary,
    ),
);

const chosen = ref<string[]>([]);
const search = ref('');
const replacement = ref('');
const matchCase = ref(false);
const wholeValue = ref(false);
const withoutUndo = ref(false);
const confirmation = ref('');
const preview = ref<Preview | null>(null);
const failure = ref<string | null>(null);
const loading = ref(false);
const applying = ref(false);

const production = computed(
    () => props.workspace.connection.environment === 'production',
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            chosen.value = candidates.value.map((column) => column.name);
            preview.value = null;
            failure.value = null;
            withoutUndo.value = false;
            confirmation.value = '';
        }
    },
);

// Jede Änderung an der Eingabe macht die Vorschau ungültig.
watch(
    [chosen, search, replacement, matchCase, wholeValue],
    () => {
        preview.value = null;
        withoutUndo.value = false;
    },
    { deep: true },
);

const args = computed(
    () =>
        [
            props.workspace.connection.id,
            encodeURIComponent(props.workspace.schema ?? ''),
            encodeURIComponent(props.object),
        ] as [string, string, string],
);
const query = computed(() =>
    props.workspace.database ? { db: props.workspace.database } : {},
);
const payload = computed(() => ({
    columns: chosen.value,
    search: search.value,
    replace: replacement.value,
    match_case: matchCase.value,
    whole_value: wholeValue.value,
}));

function explain(error: unknown): string {
    if (error instanceof ApiError) {
        const data = error.failure as {
            reason: string;
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (data.message) {
            return data.message;
        }

        if (data.reason === 'session') {
            return t(
                'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
            );
        }
    }

    return t('Die Anfrage ist gescheitert.');
}

async function showPreview(): Promise<void> {
    loading.value = true;
    failure.value = null;

    try {
        preview.value = await postJson<Preview>(
            previewRoute.url(args.value, { query: query.value }),
            payload.value,
        );
    } catch (error) {
        failure.value = explain(error);
    } finally {
        loading.value = false;
    }
}

async function apply(): Promise<void> {
    applying.value = true;
    failure.value = null;

    try {
        const result = await postJson<{
            auditId: number;
            rows: number;
            cells: number;
            undoable: boolean;
        }>(replaceRoute.url(args.value, { query: query.value }), {
            ...payload.value,
            without_undo: withoutUndo.value,
            confirmation: production.value ? confirmation.value : undefined,
        });
        toast.success(
            tc(':count Zeile ersetzt.|:count Zeilen ersetzt.', result.rows),
        );
        emit('applied', result);
        emit('update:open', false);
    } catch (error) {
        failure.value = explain(error);
    } finally {
        applying.value = false;
    }
}

const canApply = computed(
    () =>
        !!preview.value &&
        preview.value.rows > 0 &&
        !preview.value.tooMany &&
        (preview.value.undoable || withoutUndo.value) &&
        (!production.value ||
            confirmation.value === props.workspace.connection.name),
);

const inputClass =
    'h-9 w-full min-w-0 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-[640px]"
        >
            <div
                class="flex flex-wrap items-center gap-2 border-b py-3 pr-12 pl-4"
            >
                <SheetTitle class="text-lg">{{
                    t('Suchen und Ersetzen')
                }}</SheetTitle>
                <span class="font-mono text-[13px] text-text-secondary">{{
                    object
                }}</span>
                <SheetDescription class="sr-only">{{
                    t(
                        'Text in gewählten Spalten dieser Tabelle ersetzen — erst die Vorschau, dann in einer Transaktion.',
                    )
                }}</SheetDescription>
            </div>

            <form
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4"
                novalidate
                @submit.prevent="showPreview"
            >
                <Notice v-if="!candidates.length" variant="warning">
                    {{
                        t(
                            'Diese Tabelle hat ausserhalb des Primärschlüssels keine Textspalte zum Ersetzen.',
                        )
                    }}
                </Notice>

                <fieldset v-else class="flex flex-col gap-1.5">
                    <legend class="mb-1 text-[13px] font-medium">
                        {{ t('Spalten') }}
                    </legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                        <label
                            v-for="column in candidates"
                            :key="column.name"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="chosen"
                                type="checkbox"
                                :value="column.name"
                                class="size-4 accent-primary"
                            />
                            <span class="font-mono">{{ column.name }}</span>
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="replace-search" class="text-[13px]">{{
                            t('Suchen nach')
                        }}</Label>
                        <input
                            id="replace-search"
                            v-model="search"
                            type="text"
                            maxlength="1000"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="replace-with" class="text-[13px]">{{
                            t('Ersetzen durch')
                        }}</Label>
                        <input
                            id="replace-with"
                            v-model="replacement"
                            type="text"
                            maxlength="10000"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <label class="flex items-center gap-2.5">
                        <input
                            v-model="matchCase"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                        {{ t('Gross- und Kleinschreibung beachten') }}
                    </label>
                    <label class="flex items-start gap-2.5">
                        <input
                            v-model="wholeValue"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span>{{ t('Nur ganzer Wert') }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Sonst wird jedes Vorkommen im Text ersetzt. Zeichen wie % und _ gelten wörtlich.',
                                )
                            }}</span>
                        </span>
                    </label>
                </div>

                <Notice v-if="failure" variant="danger">{{ failure }}</Notice>

                <template v-if="preview">
                    <Notice
                        :variant="preview.tooMany ? 'danger' : 'info'"
                        :icon="Layers"
                    >
                        <b class="font-semibold">{{
                            preview.tooMany
                                ? t(
                                      'Mehr als :limit Zeilen — zu viele für einmal.',
                                      { limit: formatNumber(preview.limit) },
                                  )
                                : tc(
                                      ':count Zeile ändert sich|:count Zeilen ändern sich',
                                      preview.rows,
                                  )
                        }}</b>
                        {{
                            preview.tooMany
                                ? t(
                                      'Grenzen Sie die Spalten oder den Suchtext ein — oder nutzen Sie den SQL-Editor.',
                                  )
                                : tc(
                                      '(:count Zelle) — in einer Transaktion: Scheitert eine Zeile, bleibt alles, wie es war.|(:count Zellen) — in einer Transaktion: Scheitert eine Zeile, bleibt alles, wie es war.',
                                      preview.cells,
                                  )
                        }}
                    </Notice>

                    <div
                        v-if="preview.samples.length"
                        class="overflow-hidden rounded-lg border text-[13px]"
                    >
                        <table class="w-full table-fixed">
                            <caption class="sr-only">
                                {{
                                    t('Beispiele vorher und nachher')
                                }}
                            </caption>
                            <thead class="bg-muted text-left">
                                <tr>
                                    <th
                                        scope="col"
                                        class="w-1/4 px-2 py-1.5 font-medium"
                                    >
                                        {{ t('Spalte') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-2 py-1.5 font-medium"
                                    >
                                        {{ t('vorher') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-2 py-1.5 font-medium"
                                    >
                                        {{ t('nachher') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(sample, index) in preview.samples"
                                    :key="index"
                                    class="align-top"
                                >
                                    <td
                                        class="truncate px-2 py-1.5 font-mono"
                                        :title="sample.key.join(', ')"
                                    >
                                        {{ sample.column }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 font-mono break-words whitespace-pre-wrap text-text-secondary line-through decoration-destructive/60"
                                    >
                                        {{ sample.before }}
                                    </td>
                                    <td
                                        class="bg-[var(--cell-changed)] px-2 py-1.5 font-mono break-words whitespace-pre-wrap"
                                    >
                                        {{ sample.after }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <code
                        v-if="preview.sql"
                        class="block rounded-lg border bg-muted/40 px-3 py-2 font-mono text-[12px] break-words whitespace-pre-wrap"
                        >{{ preview.sql }};</code
                    >

                    <label
                        v-if="!preview.undoable && !preview.tooMany"
                        class="flex items-start gap-2.5 rounded-lg border border-warning p-3 text-sm"
                    >
                        <input
                            v-model="withoutUndo"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span>{{
                            t(
                                'Über :limit Zeilen sichert phpdbadmin keine Vorher-Werte. Ich ersetze ohne Rückgängig.',
                                { limit: formatNumber(preview.undoLimit) },
                            )
                        }}</span>
                    </label>

                    <div
                        v-if="
                            production && preview.rows > 0 && !preview.tooMany
                        "
                        class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft p-3 text-sm"
                    >
                        <b class="flex items-center gap-1.5 text-destructive"
                            ><TriangleAlert class="size-4" />{{
                                t('Änderung auf Produktion')
                            }}</b
                        >
                        <span>{{
                            t(
                                'Geben Sie zur Bestätigung den Namen der Verbindung ein: :name',
                                { name: workspace.connection.name },
                            )
                        }}</span>
                        <input
                            v-model="confirmation"
                            type="text"
                            autocomplete="off"
                            :aria-label="t('Name der Verbindung')"
                            class="h-9 rounded-md border bg-background px-2 font-mono"
                        />
                    </div>
                </template>
            </form>

            <div class="flex flex-wrap items-center gap-2 border-t p-3">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="loading || !search || !chosen.length"
                    @click="showPreview"
                >
                    <Spinner v-if="loading" /><Eye v-else />{{ t('Vorschau') }}
                </Button>
                <span class="flex-1" />
                <Button
                    variant="ghost"
                    size="sm"
                    @click="emit('update:open', false)"
                    >{{ t('Abbrechen') }}</Button
                >
                <Button
                    size="sm"
                    :variant="production ? 'destructive' : 'default'"
                    :disabled="!canApply || applying"
                    @click="apply"
                >
                    <Spinner v-if="applying" /><Replace v-else />{{
                        t('Ersetzen')
                    }}
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
