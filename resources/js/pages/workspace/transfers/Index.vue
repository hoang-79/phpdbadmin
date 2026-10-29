<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    BarChart3,
    Check,
    Database,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import TransferList from '@/components/transfers/TransferList.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTransferPolling } from '@/composables/useTransferPolling';
import { titlePrefix } from '@/lib/environment';
import { formatNumber, t, tc } from '@/lib/i18n';
import { databaseQuery, transfersHref } from '@/lib/transfers';
import {
    analyze,
    exportMethod,
    importMethod,
    status,
} from '@/routes/workspace/transfers';
import type {
    DelimiterName,
    TransferFormat,
    TransferMode,
    TransferSummary,
    Workspace,
} from '@/types';

/**
 * Export und Import je Verbindung (Wireframe 11): Export als CSV, JSON oder SQL (E-01), Datei für einen Import
 * hochladen (E-02), Statistik auf Knopfdruck (E-06), Aufträge mit Stand und Download (B-07).
 */
const props = defineProps<{
    workspace: Workspace;
    transfers: TransferSummary[];
    preset: { table: string | null };
    mode: TransferMode;
    canWrite: boolean;
    today: string;
}>();

const items = (key: string) =>
    props.workspace.groups.find((group) => group.key === key)?.items ?? [];
const tables = computed(() => items('tables'));
const views = computed(() => items('views'));
const objects = computed(() => [...tables.value, ...views.value]);

const schemaWord = computed(() =>
    props.workspace.schemaKind === 'schema' ? t('Schema') : t('Datenbank'),
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Export und Import')} · ${props.workspace.connection.name}`,
);
const isMySql = computed(() =>
    ['mysql', 'mariadb'].includes(props.workspace.connection.driver),
);

const transfers = useTransferPolling(
    () =>
        status.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
    () => props.transfers,
);

const formats: { value: TransferFormat; label: string; hint: string }[] = [
    { value: 'csv', label: 'CSV', hint: t('für Tabellenkalkulation') },
    { value: 'json', label: 'JSON', hint: t('eine Zeile je Objekt') },
    { value: 'sql', label: 'SQL', hint: t('INSERT, Struktur oder beides') },
];

const delimiters: { value: DelimiterName; label: string }[] = [
    { value: 'semicolon', label: t('Strichpunkt ;') },
    { value: 'comma', label: t('Komma ,') },
    { value: 'tab', label: t('Tabulator') },
    { value: 'pipe', label: t('Senkrechter Strich |') },
];

const exportForm = useForm({
    schema: props.workspace.schema ?? '',
    scope: (objects.value.length > 0 ? 'table' : 'schema') as
        | 'table'
        | 'schema',
    table: props.preset.table ?? objects.value[0]?.name ?? '',
    format: 'csv' as TransferFormat,
    delimiter: 'semicolon' as DelimiterName,
    bom: true,
    header: true,
    binary: 'base64' as 'base64' | 'skip',
    content: 'both' as 'both' | 'structure' | 'data',
    drop: false,
});

// CSV gibt es nur für eine Tabelle — bei «ganze Datenbank» auf JSON wechseln.
watch(
    () => exportForm.scope,
    (scope) => {
        if (scope === 'schema' && exportForm.format === 'csv') {
            exportForm.format = 'json';
        }
    },
);

const fileName = computed(() => {
    const name =
        exportForm.scope === 'table'
            ? exportForm.table
            : (props.workspace.schema ?? 'export');

    return `${name.replace(/[^\p{L}\p{N}._-]+/gu, '_')}-${props.today}.${exportForm.format}`;
});

function submitExport(): void {
    exportForm.post(
        exportMethod.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
        { preserveScroll: true },
    );
}

const importForm = useForm({
    format: 'csv' as 'csv' | 'sql',
    schema: props.workspace.schema ?? '',
    table:
        (props.preset.table &&
        tables.value.some((table) => table.name === props.preset.table)
            ? props.preset.table
            : tables.value[0]?.name) ?? '',
    file: null as File | null,
});

function chooseFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importForm.file = file;

    if (file?.name.toLowerCase().endsWith('.sql')) {
        importForm.format = 'sql';
    } else if (file) {
        importForm.format = 'csv';
    }
}

function submitImport(): void {
    importForm.post(
        importMethod.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
    );
}

const statisticsForm = useForm({
    schema: props.workspace.schema ?? '',
    table:
        (props.preset.table &&
        tables.value.some((table) => table.name === props.preset.table)
            ? props.preset.table
            : tables.value[0]?.name) ?? '',
});

const analyzeCommand = computed(() =>
    isMySql.value
        ? `ANALYZE TABLE ${statisticsForm.table}`
        : `ANALYZE ${statisticsForm.table}`,
);

function submitStatistics(): void {
    statisticsForm.post(
        analyze.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
        { preserveScroll: true },
    );
}

function chooseSchema(event: Event): void {
    router.get(
        transfersHref(props.workspace, {
            schema: (event.target as HTMLSelectElement).value,
        }),
    );
}

const modeNote = computed(() => {
    const limits = {
        rows: formatNumber(props.mode.maxRows),
        mb: formatNumber(props.mode.maxMegabytes),
    };

    if (props.mode.background) {
        return t(
            'Grosse Exporte und Importe laufen im Hintergrund weiter — Sie können arbeiten und die Datei später herunterladen. Lesen ist auch auf schreibgeschützten Verbindungen erlaubt.',
        );
    }

    return props.mode.passwordOnlyInSession
        ? t(
              'Diese Verbindung speichert ihr Passwort nicht, deshalb laufen Aufträge hier in der Anfrage: höchstens :rows Zeilen bzw. :mb MB.',
              limits,
          )
        : t(
              'Ohne Hintergrund-Worker laufen Aufträge hier in der Anfrage: höchstens :rows Zeilen bzw. :mb MB.',
              limits,
          );
});

const selectClass =
    'h-9 w-full min-w-0 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
            <div class="flex min-w-0 flex-col gap-1">
                <h1 class="text-xl font-semibold">
                    {{ t('Export und Import') }}
                </h1>
                <p class="text-sm text-text-secondary">
                    {{ tc(':count Tabelle|:count Tabellen', tables.length) }}
                    · {{ tc(':count Sicht|:count Sichten', views.length) }}
                </p>
            </div>
            <div
                v-if="workspace.schemas.length > 1"
                class="flex min-w-0 items-center gap-2 sm:ml-auto"
            >
                <Label for="transfer-schema" class="shrink-0 text-[13px]">{{
                    schemaWord
                }}</Label>
                <select
                    id="transfer-schema"
                    :class="[selectClass, 'font-mono sm:w-60']"
                    :value="workspace.schema ?? ''"
                    @change="chooseSchema"
                >
                    <option
                        v-for="schema in workspace.schemas"
                        :key="schema"
                        :value="schema"
                    >
                        {{ schema }}
                    </option>
                </select>
            </div>
        </div>

        <div
            class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,360px)]"
        >
            <div class="flex min-w-0 flex-col gap-5">
                <!-- Exportieren -->
                <form
                    class="flex flex-col gap-4 rounded-lg border p-4 sm:p-5"
                    novalidate
                    @submit.prevent="submitExport"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <ArrowDownToLine class="size-4 text-text-secondary" />{{
                            t('Exportieren')
                        }}
                    </h2>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-[13px] font-medium">
                            {{ t('Umfang') }}
                        </legend>
                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="exportForm.scope"
                                type="radio"
                                value="table"
                                class="mt-0.5 size-4 accent-primary"
                                :disabled="objects.length === 0"
                            />
                            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span>{{ t('Eine Tabelle oder Sicht') }}</span>
                                <select
                                    v-model="exportForm.table"
                                    :class="[selectClass, 'font-mono']"
                                    :aria-label="t('Tabelle')"
                                    :disabled="exportForm.scope !== 'table'"
                                >
                                    <option
                                        v-for="item in objects"
                                        :key="`${item.kind}:${item.name}`"
                                        :value="item.name"
                                    >
                                        {{ item.name
                                        }}{{
                                            item.kind === 'view'
                                                ? ` (${t('Sicht')})`
                                                : ''
                                        }}
                                    </option>
                                </select>
                            </span>
                        </label>
                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="exportForm.scope"
                                type="radio"
                                value="schema"
                                class="mt-0.5 size-4 accent-primary"
                            />
                            <span class="flex flex-col gap-0.5">
                                <span
                                    >{{
                                        workspace.schemaKind === 'schema'
                                            ? t('Ganzes Schema')
                                            : t('Ganze Datenbank')
                                    }}
                                    <span class="font-mono">{{
                                        workspace.schema
                                    }}</span></span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    t(
                                        'Alle Tabellen in Paketen über den Primärschlüssel, Sichten am Ende.',
                                    )
                                }}</span>
                            </span>
                        </label>
                        <InputError
                            :message="
                                exportForm.errors.scope ??
                                exportForm.errors.table ??
                                exportForm.errors.schema
                            "
                        />
                    </fieldset>

                    <fieldset class="flex flex-col gap-1.5">
                        <legend class="mb-1 text-[13px] font-medium">
                            {{ t('Format') }}
                        </legend>
                        <div
                            class="grid gap-2 sm:grid-cols-3"
                            role="radiogroup"
                            :aria-label="t('Format')"
                        >
                            <button
                                v-for="format in formats"
                                :key="format.value"
                                type="button"
                                role="radio"
                                :aria-checked="
                                    exportForm.format === format.value
                                "
                                :disabled="
                                    format.value === 'csv' &&
                                    exportForm.scope === 'schema'
                                "
                                :class="[
                                    'relative flex min-w-0 flex-col gap-1 rounded-lg border p-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50',
                                    exportForm.format === format.value
                                        ? 'border-2 border-primary bg-accent p-[11px]'
                                        : 'hover:bg-muted',
                                ]"
                                @click="exportForm.format = format.value"
                            >
                                <span
                                    v-if="exportForm.format === format.value"
                                    class="absolute top-2.5 right-2.5 grid size-[18px] place-items-center rounded-full bg-primary text-primary-foreground"
                                    ><Check class="size-3" :stroke-width="3"
                                /></span>
                                <span class="font-semibold">{{
                                    format.label
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    format.hint
                                }}</span>
                            </button>
                        </div>
                        <p
                            v-if="exportForm.scope === 'schema'"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'CSV gibt es nur für eine Tabelle — für eine ganze Datenbank wählen Sie JSON oder SQL.',
                                )
                            }}
                        </p>
                        <InputError :message="exportForm.errors.format" />
                    </fieldset>

                    <div
                        v-if="exportForm.format === 'csv'"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label for="export-delimiter" class="text-[13px]">{{
                                t('Trennzeichen')
                            }}</Label>
                            <select
                                id="export-delimiter"
                                v-model="exportForm.delimiter"
                                :class="selectClass"
                            >
                                <option
                                    v-for="delimiter in delimiters"
                                    :key="delimiter.value"
                                    :value="delimiter.value"
                                >
                                    {{ delimiter.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="export-charset" class="text-[13px]">{{
                                t('Zeichensatz')
                            }}</Label>
                            <select
                                id="export-charset"
                                v-model="exportForm.bom"
                                :class="selectClass"
                            >
                                <option :value="true">
                                    {{ t('UTF-8 mit BOM (für Excel)') }}
                                </option>
                                <option :value="false">UTF-8</option>
                            </select>
                        </div>
                        <label
                            class="flex items-center gap-2.5 text-sm sm:col-span-2"
                        >
                            <input
                                v-model="exportForm.header"
                                type="checkbox"
                                class="size-4 accent-primary"
                            />
                            {{ t('Erste Zeile mit Spaltennamen') }}
                        </label>
                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <Label for="export-binary" class="text-[13px]">{{
                                t('Binärspalten')
                            }}</Label>
                            <select
                                id="export-binary"
                                v-model="exportForm.binary"
                                :class="[selectClass, 'sm:w-1/2']"
                                aria-describedby="export-binary-hint"
                            >
                                <option value="base64">
                                    {{ t('als Base64') }}
                                </option>
                                <option value="skip">
                                    {{ t('weglassen') }}
                                </option>
                            </select>
                            <p
                                id="export-binary-hint"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    t(
                                        'CSV kann keine Binärdaten tragen; als Base64 lassen sie sich wieder importieren.',
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-else-if="exportForm.format === 'sql'"
                        class="flex flex-col gap-3"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label for="export-content" class="text-[13px]">{{
                                t('Inhalt')
                            }}</Label>
                            <select
                                id="export-content"
                                v-model="exportForm.content"
                                :class="[selectClass, 'sm:w-1/2']"
                            >
                                <option value="both">
                                    {{ t('Struktur und Daten') }}
                                </option>
                                <option value="structure">
                                    {{ t('nur Struktur') }}
                                </option>
                                <option value="data">
                                    {{ t('nur Daten') }}
                                </option>
                            </select>
                        </div>
                        <label
                            v-if="exportForm.content !== 'data'"
                            class="flex items-start gap-2.5 text-sm"
                        >
                            <input
                                v-model="exportForm.drop"
                                type="checkbox"
                                class="mt-0.5 size-4 accent-primary"
                            />
                            <span class="flex flex-col gap-0.5">
                                <span>{{
                                    t('Bestehende Tabellen ersetzen')
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    t(
                                        'DROP TABLE IF EXISTS vor jedem CREATE — beim Einspielen gehen vorhandene Daten verloren.',
                                    )
                                }}</span>
                            </span>
                        </label>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Nicht enthalten: Trigger, Routinen und Ereignisse.',
                                )
                            }}
                        </p>
                    </div>

                    <p v-else class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Eine Zeile je Objekt; JSON-Spalten eingebettet, Binärdaten als Base64.',
                            )
                        }}
                    </p>

                    <Notice>{{ modeNote }}</Notice>

                    <div
                        class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center"
                    >
                        <p class="min-w-0 truncate text-xs text-text-secondary">
                            {{ t('Datei:') }}
                            <span class="font-mono">{{ fileName }}</span>
                        </p>
                        <Button
                            type="submit"
                            class="sm:ml-auto"
                            :disabled="
                                exportForm.processing ||
                                (exportForm.scope === 'table' &&
                                    !exportForm.table)
                            "
                        >
                            <Spinner v-if="exportForm.processing" />
                            <ArrowDownToLine v-else />{{ t('Exportieren') }}
                        </Button>
                    </div>
                </form>

                <!-- Importieren -->
                <section
                    class="flex flex-col gap-4 rounded-lg border p-4 sm:p-5"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <ArrowUpFromLine class="size-4 text-text-secondary" />{{
                            t('Importieren')
                        }}
                    </h2>

                    <Notice v-if="!canWrite" variant="warning">
                        {{
                            workspace.connection.readOnly
                                ? t(
                                      'Diese Verbindung ist schreibgeschützt — Import und Statistik sind gesperrt.',
                                  )
                                : t(
                                      'Ihr Konto darf über diese Verbindung nicht schreiben — Import und Statistik sind gesperrt.',
                                  )
                        }}
                    </Notice>

                    <form
                        v-else
                        class="flex flex-col gap-4"
                        novalidate
                        @submit.prevent="submitImport"
                    >
                        <fieldset class="flex flex-col gap-2">
                            <legend class="mb-1 text-[13px] font-medium">
                                {{ t('Was importieren?') }}
                            </legend>
                            <label class="flex items-start gap-2.5 text-sm">
                                <input
                                    v-model="importForm.format"
                                    type="radio"
                                    value="csv"
                                    class="mt-0.5 size-4 accent-primary"
                                    :disabled="tables.length === 0"
                                />
                                <span
                                    class="flex min-w-0 flex-1 flex-col gap-1.5"
                                >
                                    <span>{{ t('CSV in eine Tabelle') }}</span>
                                    <select
                                        v-model="importForm.table"
                                        :class="[selectClass, 'font-mono']"
                                        :aria-label="t('Ziel-Tabelle')"
                                        :disabled="importForm.format !== 'csv'"
                                    >
                                        <option
                                            v-for="table in tables"
                                            :key="table.name"
                                            :value="table.name"
                                        >
                                            {{ table.name }}
                                        </option>
                                    </select>
                                </span>
                            </label>
                            <label class="flex items-start gap-2.5 text-sm">
                                <input
                                    v-model="importForm.format"
                                    type="radio"
                                    value="sql"
                                    class="mt-0.5 size-4 accent-primary"
                                />
                                <span class="flex flex-col gap-0.5">
                                    <span
                                        >{{ t('SQL-Datei ausführen in') }}
                                        <span class="font-mono">{{
                                            workspace.schema
                                        }}</span></span
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            isMySql
                                                ? t(
                                                      'Anweisung für Anweisung; bei einem Fehler bleibt, was davor lief.',
                                                  )
                                                : t(
                                                      'In einer Transaktion — bei einem Fehler bleibt nichts.',
                                                  )
                                        }}</span
                                    >
                                </span>
                            </label>
                            <InputError
                                :message="
                                    importForm.errors.table ??
                                    importForm.errors.schema ??
                                    importForm.errors.format
                                "
                            />
                        </fieldset>

                        <div class="flex flex-col gap-1.5">
                            <Label for="import-file" class="text-[13px]">{{
                                t('Datei')
                            }}</Label>
                            <input
                                id="import-file"
                                type="file"
                                :accept="
                                    importForm.format === 'sql'
                                        ? '.sql,.txt'
                                        : '.csv,.tsv,.txt'
                                "
                                class="block w-full min-w-0 text-sm file:mr-3 file:h-9 file:rounded-md file:border file:bg-background file:px-3 file:text-sm file:font-medium hover:file:bg-muted"
                                @change="chooseFile"
                            />
                            <InputError :message="importForm.errors.file" />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Danach ordnen Sie die Spalten zu und machen einen Probelauf — geschrieben wird erst beim Importieren.',
                                    )
                                }}
                            </p>
                        </div>

                        <div
                            class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center"
                        >
                            <p
                                v-if="importForm.progress"
                                class="text-xs text-text-secondary tabular-nums"
                            >
                                {{
                                    t('Hochgeladen: :percent %', {
                                        percent:
                                            importForm.progress.percentage ?? 0,
                                    })
                                }}
                            </p>
                            <Button
                                type="submit"
                                variant="outline"
                                class="sm:ml-auto"
                                :disabled="
                                    importForm.processing || !importForm.file
                                "
                            >
                                <Spinner v-if="importForm.processing" />
                                <ArrowUpFromLine v-else />{{
                                    t('Datei prüfen')
                                }}
                            </Button>
                        </div>
                    </form>
                </section>

                <!-- Statistik -->
                <form
                    v-if="canWrite && tables.length > 0"
                    class="flex flex-col gap-3 rounded-lg border p-4 sm:p-5"
                    novalidate
                    @submit.prevent="submitStatistics"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <BarChart3 class="size-4 text-text-secondary" />{{
                            t('Statistik aktualisieren')
                        }}
                    </h2>
                    <p class="text-sm text-text-secondary">
                        {{
                            t(
                                'Nach grossen Änderungen schätzt der Server die Zeilenzahl falsch und sortiert langsamer. Nach eigenen Importen geschieht das von selbst.',
                            )
                        }}
                    </p>
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <select
                            v-model="statisticsForm.table"
                            :class="[selectClass, 'font-mono sm:w-60']"
                            :aria-label="t('Tabelle')"
                        >
                            <option
                                v-for="table in tables"
                                :key="table.name"
                                :value="table.name"
                            >
                                {{ table.name }}
                            </option>
                        </select>
                        <code
                            class="min-w-0 truncate text-xs text-text-secondary"
                            >{{ analyzeCommand }}</code
                        >
                        <Button
                            type="submit"
                            variant="outline"
                            class="sm:ml-auto"
                            :disabled="statisticsForm.processing"
                        >
                            <Spinner v-if="statisticsForm.processing" />
                            <BarChart3 v-else />{{ t('Aktualisieren') }}
                        </Button>
                    </div>
                    <InputError :message="statisticsForm.errors.table" />
                </form>
            </div>

            <div class="order-first min-w-0 lg:sticky lg:top-5 lg:order-none">
                <TransferList :transfers="transfers" />
                <p
                    class="mt-2 flex items-center gap-1.5 px-1 text-xs text-muted-foreground"
                >
                    <Database class="size-3.5" />{{
                        t(
                            'Nur Ihre eigenen Aufträge; Dateien werden nach 24 Stunden gelöscht.',
                        )
                    }}
                </p>
            </div>
        </div>
    </div>
</template>
