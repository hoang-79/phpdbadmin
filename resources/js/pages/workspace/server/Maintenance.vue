<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    Info,
    TriangleAlert,
    Wrench,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ConfirmChange from '@/components/server/ConfirmChange.vue';
import ServerHeader from '@/components/server/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { preview, run } from '@/routes/workspace/server/maintenance';
import type {
    ChangePreview,
    MaintenanceResult,
    ServerMeta,
    Workspace,
} from '@/types';

/**
 * Wartung (W-05): ANALYZE, VACUUM (PostgreSQL, SQLite), OPTIMIZE (MySQL, MariaDB), Integritätsprüfung — je Tabelle
 * oder für das ganze Schema, mit Vorschau und Bestätigung; das Ergebnis steht danach je Tabelle da.
 */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    operations: { key: string; forTables: boolean }[];
    tables?: string[];
}>();

type Outcome = {
    operation: string;
    target: string;
    results: MaintenanceResult[];
};

const page = usePage();
const schemas = computed(() => props.workspace.schemas);
const form = useForm({
    operation: props.operations[0]?.key ?? '',
    schema: schemas.value.includes('public')
        ? 'public'
        : (schemas.value[0] ?? ''),
    table: '',
    confirmation: '',
});
type Payload = {
    operation: string;
    schema: string;
    table: string;
    database: string;
};

const http = useHttp<Payload, ChangePreview>({
    operation: '',
    schema: '',
    table: '',
    database: '',
});
const planned = ref<ChangePreview | null>(null);
const confirming = ref(false);
const loadingTables = ref(false);
const outcome = ref<Outcome | null>(null);

const descriptions = computed<
    Record<string, { title: string; sql: string; text: string }>
>(() => ({
    analyze: {
        title: t('Statistik aktualisieren'),
        sql: 'ANALYZE',
        text: t(
            'Der Server plant Abfragen nach seiner Statistik — nach grossen Änderungen lohnt es sich.',
        ),
    },
    vacuum: {
        title: t('Platz freigeben'),
        sql: 'VACUUM',
        text:
            props.workspace.connection.driver === 'sqlite'
                ? t(
                      'Baut die Datei neu auf und gibt freie Seiten ans System zurück.',
                  )
                : t(
                      'Gibt den Platz gelöschter Zeilen zur Wiederverwendung frei.',
                  ),
    },
    optimize: {
        title: t('Tabellen neu aufbauen'),
        sql: 'OPTIMIZE TABLE',
        text: t('Ordnet Daten und Indizes neu und gibt Platz frei.'),
    },
    check: {
        title: t('Integrität prüfen'),
        sql:
            props.workspace.connection.driver === 'sqlite'
                ? 'PRAGMA integrity_check'
                : 'CHECK TABLE',
        text: t('Sucht nach beschädigten Seiten und Indizes; ändert nichts.'),
    },
}));

const chosen = computed(() =>
    props.operations.find((operation) => operation.key === form.operation),
);
const errors = computed<Record<string, string | undefined>>(() => ({
    ...(http.errors as Record<string, string | undefined>),
    ...(form.errors as Record<string, string | undefined>),
}));
const applyError = computed(
    () =>
        Object.entries(form.errors as Record<string, string | undefined>).find(
            ([field]) => field !== 'confirmation',
        )?.[1] ?? null,
);
const title = computed(
    () => descriptions.value[form.operation]?.title ?? t('Wartung'),
);

function chooseOperation(key: string): void {
    form.operation = key;

    if (!chosen.value?.forTables) {
        form.table = '';
    }
}

function loadTables(keepTable = false): void {
    if (!keepTable) {
        form.table = '';
    }

    if (!form.schema) {
        return;
    }

    router.reload({
        only: ['tables'],
        data: { tables_in: form.schema },
        preserveUrl: true,
        onStart: () => {
            loadingTables.value = true;
        },
        onFinish: () => {
            loadingTables.value = false;
        },
    });
}

function payload(): Payload {
    return {
        operation: form.operation,
        schema: form.schema,
        table: chosen.value?.forTables ? form.table : '',
        database: props.workspace.database ?? '',
    };
}

async function showPreview(): Promise<void> {
    http.clearErrors();
    form.clearErrors();
    planned.value = null;
    confirming.value = true;

    try {
        const response = await http
            .transform(() => payload())
            .post(preview.url(props.workspace.connection.id));

        if (response) {
            planned.value = response;
        } else {
            confirming.value = false;
        }
    } catch {
        confirming.value = false;
    }
}

function start(confirmation: string): void {
    form.transform(() => ({ ...payload(), confirmation })).post(
        run.url(props.workspace.connection.id),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                confirming.value = false;
                planned.value = null;
                // Nach der Weiterleitung fehlen die nachgeladenen Tabellen wieder.
                loadTables(true);
            },
        },
    );
}

const confirmOpen = computed({
    get: () => confirming.value,
    set: (open: boolean) => {
        if (!open) {
            confirming.value = false;
            planned.value = null;
        }
    },
});

// Das Ergebnis kommt als einmalige Meldung (Flash) und bleibt stehen, bis die nächste Wartung läuft.
watch(
    () => (page.flash as { maintenance?: Outcome } | undefined)?.maintenance,
    (value) => {
        if (value) {
            outcome.value = value;
        }
    },
    { immediate: true },
);

onMounted(() => loadTables());

const statusIcon = {
    ok: CircleCheck,
    note: Info,
    warning: TriangleAlert,
    error: CircleAlert,
};
const statusClass = {
    ok: 'text-success',
    note: 'text-text-secondary',
    warning: 'text-warning',
    error: 'text-destructive',
};
</script>

<template>
    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <ServerHeader
            :workspace="workspace"
            :server="server"
            :title="t('Wartung')"
        />

        <form
            class="flex flex-col gap-4 rounded-lg border px-4 py-4"
            @submit.prevent="showPreview"
        >
            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-1.5 text-[13px] font-medium">
                    {{ t('Was soll geschehen?') }}
                </legend>
                <div
                    class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
                    role="radiogroup"
                    :aria-label="t('Wartung')"
                >
                    <button
                        v-for="operation in operations"
                        :key="operation.key"
                        type="button"
                        role="radio"
                        :aria-checked="form.operation === operation.key"
                        :class="[
                            'flex min-w-0 flex-col gap-1 rounded-lg border p-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            form.operation === operation.key
                                ? 'border-2 border-primary bg-accent p-[11px]'
                                : 'hover:bg-muted',
                        ]"
                        @click="chooseOperation(operation.key)"
                    >
                        <span class="flex items-center gap-2 font-semibold"
                            ><Wrench class="size-4 shrink-0" />{{
                                descriptions[operation.key]?.title ??
                                operation.key
                            }}</span
                        >
                        <code class="font-mono text-xs text-text-secondary">{{
                            descriptions[operation.key]?.sql
                        }}</code>
                        <span class="text-xs text-text-secondary">{{
                            descriptions[operation.key]?.text
                        }}</span>
                    </button>
                </div>
                <InputError :message="errors.operation" />
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="maintenance-schema" class="text-[13px]">{{
                        workspace.schemaKind === 'schema'
                            ? t('Schema')
                            : t('Datenbank')
                    }}</Label>
                    <select
                        id="maintenance-schema"
                        v-model="form.schema"
                        class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @change="loadTables()"
                    >
                        <option
                            v-for="schema in schemas"
                            :key="schema"
                            :value="schema"
                        >
                            {{ schema }}
                        </option>
                    </select>
                    <InputError :message="errors.schema" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="maintenance-table" class="text-[13px]">{{
                        t('Tabelle')
                    }}</Label>
                    <select
                        id="maintenance-table"
                        v-model="form.table"
                        :disabled="!chosen?.forTables || loadingTables"
                        class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60"
                    >
                        <option value="">
                            {{
                                loadingTables
                                    ? t('Tabellen werden geladen …')
                                    : t('alle Tabellen')
                            }}
                        </option>
                        <option
                            v-for="table in tables ?? []"
                            :key="table"
                            :value="table"
                        >
                            {{ table }}
                        </option>
                    </select>
                    <InputError :message="errors.table" />
                    <span
                        v-if="!chosen?.forTables"
                        class="text-xs text-text-secondary"
                        >{{
                            t('VACUUM gilt bei SQLite immer der ganzen Datei.')
                        }}</span
                    >
                </div>
            </div>

            <InputError :message="errors.server" />

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    class="w-full sm:w-auto"
                    :disabled="!server.canChange || http.processing"
                    :title="
                        server.canChange
                            ? undefined
                            : server.blocked === 'read_only'
                              ? t('Die Verbindung ist schreibgeschützt.')
                              : t('Dafür braucht es das Recht «verwalten».')
                    "
                >
                    <Spinner v-if="http.processing" />{{ t('Vorschau zeigen') }}
                </Button>
            </div>
        </form>

        <section v-if="outcome" class="flex flex-col rounded-lg border">
            <h2
                class="flex flex-wrap items-center gap-2 border-b px-4 py-3 font-semibold"
            >
                {{ t('Ergebnis') }}
                <code class="font-mono text-sm font-normal text-text-secondary"
                    >{{ descriptions[outcome.operation]?.sql }}
                    {{ outcome.target }}</code
                >
            </h2>
            <ul class="text-sm">
                <li
                    v-for="(result, index) in outcome.results"
                    :key="index"
                    class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b px-4 py-2 last:border-b-0"
                >
                    <component
                        :is="statusIcon[result.status]"
                        :class="[
                            'size-4 shrink-0 self-center',
                            statusClass[result.status],
                        ]"
                        :aria-label="result.status"
                    />
                    <span class="min-w-0 font-mono wrap-break-word">{{
                        result.table ?? outcome.target
                    }}</span>
                    <span class="ml-auto text-text-secondary">{{
                        result.message
                    }}</span>
                </li>
            </ul>
        </section>

        <ConfirmChange
            v-model:open="confirmOpen"
            :title="title"
            :description="planned?.target ?? null"
            :statements="planned?.statements ?? []"
            :notes="planned?.notes ?? []"
            :confirm-name="server.confirmName"
            :confirm-label="t('Ausführen')"
            :loading="http.processing"
            :processing="form.processing"
            :error="applyError"
            :confirmation-error="errors.confirmation ?? null"
            @confirm="start"
        />
    </div>
</template>
