<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Copy, Layers, TerminalSquare, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import HelpLink from '@/components/HelpLink.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import type { RowChange } from '@/composables/useRowChanges';
import { ApiError, postJson } from '@/lib/api';
import { t, tc } from '@/lib/i18n';
import { sql as sqlRoute } from '@/routes/workspace';
import { changes as changesRoute } from '@/routes/workspace/object';
import { preview as previewRoute } from '@/routes/workspace/object/changes';
import type { Workspace } from '@/types';

/**
 * SQL-Vorschau vor dem Anwenden (D-04, N-03, Wireframe 6): jede Anweisung mit ihren Werten, wie viele Zeilen sie
 * trifft und was ON DELETE CASCADE mitlöscht; in Produktion erst nach Eingabe des Namens der Verbindung.
 */
const props = defineProps<{
    open: boolean;
    workspace: Workspace;
    object: string;
    changes: RowChange[];
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    applied: [
        result: {
            auditId: number;
            inserted: number;
            updated: number;
            deleted: number;
            undoable: boolean;
        },
    ];
}>();

type Statement = {
    sql: string;
    op: string;
    rows: number | null;
    cascades: { table: string; count: number }[];
};

const statements = ref<Statement[] | null>(null);
const failure = ref<string | null>(null);
const applying = ref(false);
const confirmation = ref('');

const production = computed(
    () => props.workspace.connection.environment === 'production',
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

function explain(error: unknown): string {
    if (error instanceof ApiError) {
        const failureData = error.failure as {
            reason: string;
            message?: string;
        };

        if (failureData.message) {
            return failureData.message;
        }

        if (failureData.reason === 'session') {
            return t(
                'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
            );
        }
    }

    return t('Die Anfrage ist gescheitert.');
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        statements.value = null;
        failure.value = null;
        confirmation.value = '';

        try {
            statements.value = (
                await postJson<{ statements: Statement[] }>(
                    previewRoute.url(args.value, { query: query.value }),
                    {
                        changes: props.changes,
                    },
                )
            ).statements;
        } catch (error) {
            failure.value = explain(error);
        }
    },
);

const allSql = computed(() =>
    (statements.value ?? []).map((statement) => `${statement.sql};`).join('\n'),
);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(allSql.value);
        toast.success(t('SQL kopiert.'));
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}

function openInEditor(): void {
    emit('update:open', false);
    router.visit(
        sqlRoute.url(props.workspace.connection.id, {
            query: {
                tab: 99,
                ...(props.workspace.schema
                    ? { schema: props.workspace.schema }
                    : {}),
                ...query.value,
                vorlage: allSql.value,
            },
        }),
    );
}

async function apply(): Promise<void> {
    applying.value = true;
    failure.value = null;

    try {
        const result = await postJson<{
            auditId: number;
            inserted: number;
            updated: number;
            deleted: number;
            undoable: boolean;
        }>(changesRoute.url(args.value, { query: query.value }), {
            changes: props.changes,
            confirmation: production.value ? confirmation.value : undefined,
        });
        emit('applied', result);
        emit('update:open', false);
    } catch (error) {
        failure.value = explain(error);
    } finally {
        applying.value = false;
    }
}
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
                <SheetTitle class="text-lg">{{ t('SQL-Vorschau') }}</SheetTitle>
                <span class="text-[13px] text-text-secondary">
                    {{
                        tc(
                            ':count Anweisung|:count Anweisungen',
                            statements?.length ?? changes.length,
                        )
                    }}
                    ·
                    <span class="font-mono">{{ object }}</span>
                </span>
                <SheetDescription class="sr-only">{{
                    t('Die Anweisungen, die beim Anwenden ausgeführt werden')
                }}</SheetDescription>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
                <Notice variant="info" :icon="Layers">
                    <b class="font-semibold">{{
                        t(':driver: in einer Transaktion', {
                            driver: workspace.connection.driverLabel,
                        })
                    }}</b>
                    {{
                        t(
                            'Schlägt eine Anweisung fehl oder trifft sie nicht genau eine Zeile, wird keine geschrieben. Die Vorher-Werte werden gesichert; Sie können die Änderung danach rückgängig machen.',
                        )
                    }}
                </Notice>

                <div v-if="!statements && !failure" class="flex flex-col gap-2">
                    <Skeleton v-for="index in 3" :key="index" class="h-12" />
                </div>

                <ol
                    v-else-if="statements"
                    class="divide-y rounded-lg border bg-muted/40 font-mono text-[13px]"
                >
                    <li class="px-3 py-2 text-text-secondary">
                        START TRANSACTION;
                    </li>
                    <li
                        v-for="(statement, index) in statements"
                        :key="index"
                        class="grid grid-cols-[1.5rem_1fr_auto] gap-2 px-3 py-2"
                    >
                        <span class="text-muted-foreground">{{
                            index + 1
                        }}</span>
                        <span class="flex flex-col gap-1">
                            <code class="break-words whitespace-pre-wrap"
                                >{{ statement.sql }};</code
                            >
                            <span
                                v-for="cascade in statement.cascades"
                                :key="cascade.table"
                                class="flex items-start gap-1 font-sans text-[12px] text-warning"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-3.5 shrink-0"
                                />
                                {{
                                    tc(
                                        'löscht zusätzlich :count Zeile in :table (Fremdschlüssel mit ON DELETE CASCADE)|löscht zusätzlich :count Zeilen in :table (Fremdschlüssel mit ON DELETE CASCADE)',
                                        cascade.count,
                                        { table: cascade.table },
                                    )
                                }}
                            </span>
                        </span>
                        <span
                            class="font-sans text-[12px] whitespace-nowrap text-text-secondary"
                            >{{
                                statement.op === 'insert'
                                    ? t('1 Zeile neu')
                                    : t('1 Zeile')
                            }}</span
                        >
                    </li>
                    <li class="px-3 py-2 text-text-secondary">COMMIT;</li>
                </ol>

                <p
                    v-if="statements"
                    class="flex flex-wrap items-center gap-x-2 text-[12px] text-text-secondary"
                >
                    {{
                        t(
                            'Gezählt vor dem Anwenden. Werte stehen hier im Klartext, im Protokoll nur als Platzhalter.',
                        )
                    }}
                    <HelpLink anchor="bearbeiten" />
                </p>

                <Notice v-if="failure" variant="danger">{{ failure }}</Notice>

                <div
                    v-if="production && statements"
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
                            {
                                name: workspace.connection.name,
                            },
                        )
                    }}</span>
                    <input
                        v-model="confirmation"
                        type="text"
                        autocomplete="off"
                        :aria-label="t('Name der Verbindung')"
                        class="h-9 rounded-md border bg-background px-2 font-mono"
                    />
                    <span class="text-[12px] text-text-secondary">{{
                        t('Der Knopf wird aktiv, sobald der Name stimmt.')
                    }}</span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t p-3">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!statements"
                    @click="copy"
                >
                    <Copy />{{ t('Kopieren') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!statements"
                    @click="openInEditor"
                >
                    <TerminalSquare />{{ t('Im Editor öffnen') }}
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
                    :disabled="
                        !statements ||
                        applying ||
                        (production &&
                            confirmation !== workspace.connection.name)
                    "
                    @click="apply"
                >
                    <Spinner v-if="applying" />{{
                        production
                            ? t('Auf Produktion anwenden')
                            : t('Anwenden')
                    }}
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
