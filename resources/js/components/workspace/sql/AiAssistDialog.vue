<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles, TriangleAlert } from '@lucide/vue';
import { ref, watch } from 'vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, postJson } from '@/lib/api';
import { t, tc } from '@/lib/i18n';
import { account } from '@/routes';
import { assist } from '@/routes/workspace/sql';
import type { Workspace } from '@/types';

/**
 * SQL-Hilfe mit KI (Q-08): Frage in Worten, Vorschlag zum Prüfen. Gesendet werden nur Frage und Schema — nie Daten,
 * nie der Text im Editor. Übernommen wird der Vorschlag erst auf Knopfdruck, ausgeführt nie von selbst.
 */
const props = defineProps<{
    open: boolean;
    workspace: Workspace;
    hasKey: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    insert: [sql: string];
}>();

const question = ref('');
const asking = ref(false);
const failure = ref<string | null>(null);
const answer = ref<{
    sql: string;
    explanation: string;
    omitted: number;
} | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            failure.value = null;
            answer.value = null;
        }
    },
);

async function ask(): Promise<void> {
    asking.value = true;
    failure.value = null;
    answer.value = null;

    try {
        answer.value = await postJson(
            assist.url(props.workspace.connection.id, {
                query: props.workspace.database
                    ? { db: props.workspace.database }
                    : {},
            }),
            { question: question.value, schema: props.workspace.schema },
        );
    } catch (error) {
        const data =
            error instanceof ApiError
                ? (error.failure as {
                      message?: string;
                      errors?: Record<string, string[]>;
                  })
                : null;
        failure.value =
            data?.message ??
            Object.values(data?.errors ?? {})[0]?.[0] ??
            t('Die Anfrage ist gescheitert.');
    } finally {
        asking.value = false;
    }
}

function take(): void {
    if (answer.value) {
        emit('insert', answer.value.sql);
        emit('update:open', false);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-2xl">
            <DialogTitle class="flex items-center gap-2"
                ><Sparkles class="size-4" />{{
                    t('SQL-Hilfe mit KI')
                }}</DialogTitle
            >
            <DialogDescription>
                {{
                    t(
                        'Gesendet werden nur Ihre Frage und das Schema (Tabellen, Spalten, Typen, Schlüssel) — keine Daten und nicht der Text im Editor. Der Vorschlag läuft erst, wenn Sie ihn ausführen.',
                    )
                }}
            </DialogDescription>

            <Notice v-if="!hasKey" variant="warning">
                {{
                    t('Für die KI-Hilfe brauchen Sie einen eigenen Schlüssel.')
                }}
                <Link :href="`${account.url()}#ki-hilfe`" class="underline">{{
                    t('Schlüssel hinterlegen')
                }}</Link>
            </Notice>

            <form v-else class="flex flex-col gap-3" @submit.prevent="ask">
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium">{{
                        t('Was möchten Sie wissen?')
                    }}</span>
                    <textarea
                        v-model="question"
                        v-focus
                        rows="3"
                        maxlength="2000"
                        :placeholder="
                            t(
                                'z. B. Kunden aus Bern mit mehr als drei Bestellungen im letzten Jahr',
                            )
                        "
                        class="rounded-md border bg-background px-2.5 py-1.5 text-sm"
                        @keydown.meta.enter.prevent="ask"
                        @keydown.ctrl.enter.prevent="ask"
                    />
                </label>

                <Notice v-if="failure" variant="danger">{{ failure }}</Notice>

                <div v-if="answer" class="flex flex-col gap-2">
                    <pre
                        class="max-h-64 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-[13px] whitespace-pre-wrap"
                        >{{ answer.sql }}</pre>
                    <p
                        v-if="answer.explanation"
                        class="text-sm text-text-secondary"
                    >
                        {{ answer.explanation }}
                    </p>
                    <p
                        v-if="answer.omitted > 0"
                        class="flex items-center gap-1.5 text-[12px] text-warning"
                    >
                        <TriangleAlert class="size-3.5" />{{
                            tc(
                                'Das Schema war zu gross: :count Tabelle wurde nicht gesendet.|Das Schema war zu gross: :count Tabellen wurden nicht gesendet.',
                                answer.omitted,
                            )
                        }}
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="submit"
                        :variant="answer ? 'outline' : 'default'"
                        :disabled="asking || question.trim().length < 3"
                    >
                        <Spinner v-if="asking" />{{
                            answer ? t('Neu fragen') : t('Fragen')
                        }}
                    </Button>
                    <Button v-if="answer" type="button" @click="take">{{
                        t('In den Editor übernehmen')
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
