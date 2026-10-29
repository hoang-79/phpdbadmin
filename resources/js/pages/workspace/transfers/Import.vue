<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleCheck,
    CircleX,
    FileText,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
    Upload,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTransferPolling } from '@/composables/useTransferPolling';
import { titlePrefix } from '@/lib/environment';
import { formatBytes } from '@/lib/format';
import { formatNumber, t, tc } from '@/lib/i18n';
import { transfersHref } from '@/lib/transfers';
import { run, update } from '@/routes/workspace/transfers';
import type {
    CsvResult,
    DelimiterName,
    ImportColumn,
    ImportTransfer,
    SqlImportResult,
    TransferMode,
    Workspace,
} from '@/types';

/**
 * Import in Schritten (E-02, Wireframe 11): Datei → Zuordnung → Probelauf → Importieren. Der Probelauf schreibt
 * nichts; Fehlerzeilen kommen mit Zeilennummer und Grund. Auf Produktion verlangt der echte Lauf den Namen der
 * Verbindung.
 */
const props = defineProps<{
    workspace: Workspace;
    transfer: ImportTransfer;
    columns: ImportColumn[];
    mode: TransferMode;
    canWrite: boolean;
    requiresConfirmation: boolean;
}>();

const isCsv = computed(() => props.transfer.format === 'csv');
const csv = computed(() =>
    isCsv.value ? (props.transfer.result as CsvResult | null) : null,
);
const sql = computed(() =>
    isCsv.value ? null : (props.transfer.result as SqlImportResult | null),
);
const checked = computed(
    () =>
        props.transfer.result !== null &&
        props.transfer.dryRun &&
        props.transfer.status === 'done',
);
const finished = computed(() => props.transfer.imported);
const isMySql = computed(() =>
    ['mysql', 'mariadb'].includes(props.workspace.connection.driver),
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Importieren')} · ${props.transfer.table ?? props.transfer.schema}`,
);

// Läuft der Auftrag, den Stand abfragen; ist er fertig, die Seite mit dem Ergebnis neu laden.
const polled = useTransferPolling(
    () => props.transfer.statusUrl,
    () => (props.transfer.active ? [props.transfer] : []),
    (done) => {
        if (done.id === props.transfer.id) {
            router.reload();
        }
    },
);
const live = computed(
    () =>
        polled.value.find((item) => item.id === props.transfer.id) ??
        props.transfer,
);

const delimiters: { value: DelimiterName; label: string }[] = [
    { value: 'semicolon', label: t('Strichpunkt ;') },
    { value: 'comma', label: t('Komma ,') },
    { value: 'tab', label: t('Tabulator') },
    { value: 'pipe', label: t('Senkrechter Strich |') },
];

const readingForm = useForm({
    delimiter: props.transfer.options.delimiter,
    encoding: props.transfer.options.encoding ?? 'utf-8',
    header: props.transfer.options.header,
});
const showReading = ref(false);

function reread(): void {
    readingForm.patch(
        update.url([props.workspace.connection.id, props.transfer.id]),
        { preserveScroll: true, onSuccess: () => (showReading.value = false) },
    );
}

const runForm = useForm({
    dry_run: true,
    mapping: props.transfer.options.columns.map(
        (column) => props.transfer.options.mapping[column.index] ?? '',
    ),
    on_error: props.transfer.options.onError,
    analyze: props.transfer.options.analyze,
    confirmation: '',
});

// «run»: Grenze ohne Worker überschritten (kein Feld des Formulars).
const runError = computed(
    () =>
        (runForm.errors as Record<string, string | undefined>).run ??
        runForm.errors.on_error,
);

const mappedCount = computed(
    () => runForm.mapping.filter((name) => name !== '').length,
);
const missingRequired = computed(() =>
    props.columns
        .filter((column) => column.required)
        .filter((column) => !runForm.mapping.includes(column.name))
        .map((column) => column.name),
);

const pending = ref<'check' | 'import' | null>(null);

function start(dryRun: boolean): void {
    pending.value = dryRun ? 'check' : 'import';
    runForm
        .transform((data) => ({
            ...data,
            dry_run: dryRun,
            mapping: data.mapping.map((name) => (name === '' ? null : name)),
        }))
        .post(run.url([props.workspace.connection.id, props.transfer.id]), {
            preserveScroll: true,
            onFinish: () => (pending.value = null),
        });
}

const canImport = computed(
    () =>
        props.canWrite &&
        !finished.value &&
        !live.value.active &&
        (!isCsv.value || mappedCount.value > 0) &&
        (!props.requiresConfirmation ||
            runForm.confirmation === props.workspace.connection.name),
);

const steps = computed(() => [
    { label: t('Datei'), done: true, current: false },
    ...(isCsv.value
        ? [
              {
                  label: t('Zuordnung'),
                  done: checked.value || finished.value,
                  current: !checked.value && !finished.value,
              },
          ]
        : []),
    {
        label: t('Probelauf'),
        done: checked.value || finished.value,
        current: isCsv.value ? false : !checked.value && !finished.value,
    },
    { label: t('Importieren'), done: finished.value, current: checked.value },
]);

function samples(values: (string | null)[]): string {
    return values
        .map((value) => (value === null ? t('leer') : value))
        .join(' · ');
}

function columnHint(name: string): string {
    const column = props.columns.find((item) => item.name === name);

    if (!column) {
        return '';
    }

    return [
        column.type,
        column.required ? t('nicht NULL') : null,
        column.kind === 'binary' ? t('als Base64') : null,
        column.kind === 'boolean' ? t('ja/nein → 1/0') : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <div
            class="flex flex-col gap-3 border-b pb-4 lg:flex-row lg:items-center"
        >
            <div class="flex min-w-0 items-baseline gap-3">
                <h1 class="truncate font-mono text-xl font-semibold">
                    {{ transfer.table ?? transfer.schema }}
                </h1>
                <span class="shrink-0 text-sm text-text-secondary"
                    >{{ t('Daten importieren') }} ·
                    {{ transfer.format.toUpperCase() }}</span
                >
            </div>
            <ol
                class="flex flex-wrap items-center gap-x-3 gap-y-1 lg:ml-auto"
                :aria-label="t('Schritte')"
            >
                <li
                    v-for="(step, index) in steps"
                    :key="step.label"
                    class="flex items-center gap-2 text-sm"
                    :aria-current="step.current ? 'step' : undefined"
                >
                    <span
                        :class="[
                            'grid size-5 place-items-center rounded-full border text-xs',
                            step.done
                                ? 'border-primary bg-primary text-primary-foreground'
                                : step.current
                                  ? 'border-2 border-primary font-semibold text-primary'
                                  : 'text-text-secondary',
                        ]"
                        ><Check
                            v-if="step.done"
                            class="size-3"
                            :stroke-width="3"
                        /><template v-else>{{ index + 1 }}</template></span
                    >
                    <span :class="step.current ? 'font-semibold' : ''">{{
                        step.label
                    }}</span>
                </li>
            </ol>
        </div>

        <div
            class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,460px)]"
        >
            <div class="flex min-w-0 flex-col gap-5">
                <!-- Datei -->
                <section class="flex flex-col gap-3 rounded-lg border p-4">
                    <div
                        class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1"
                    >
                        <FileText class="size-4 shrink-0 text-text-secondary" />
                        <span class="min-w-0 truncate font-mono text-sm">{{
                            transfer.fileName
                        }}</span>
                        <span class="text-xs text-text-secondary">
                            {{
                                [
                                    transfer.options.records !== null
                                        ? tc(
                                              ':count Zeile|:count Zeilen',
                                              transfer.options.records,
                                          )
                                        : null,
                                    formatBytes(transfer.fileSize),
                                    isCsv
                                        ? (transfer.options.encoding ===
                                          'windows-1252'
                                              ? 'Windows-1252'
                                              : 'UTF-8') +
                                          (transfer.options.bom ? ' BOM' : '')
                                        : null,
                                    isCsv
                                        ? delimiters.find(
                                              (item) =>
                                                  item.value ===
                                                  transfer.options.delimiter,
                                          )?.label
                                        : null,
                                    isCsv && transfer.options.header
                                        ? t('erste Zeile = Spaltennamen')
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </span>
                        <button
                            v-if="isCsv && canWrite && !finished"
                            type="button"
                            class="text-sm text-primary underline underline-offset-2 sm:ml-auto"
                            :aria-expanded="showReading"
                            @click="showReading = !showReading"
                        >
                            {{ t('anders lesen') }}
                        </button>
                    </div>

                    <form
                        v-if="showReading"
                        class="grid gap-3 border-t pt-3 sm:grid-cols-3"
                        novalidate
                        @submit.prevent="reread"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label for="read-delimiter" class="text-[13px]">{{
                                t('Trennzeichen')
                            }}</Label>
                            <select
                                id="read-delimiter"
                                v-model="readingForm.delimiter"
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
                            <Label for="read-encoding" class="text-[13px]">{{
                                t('Kodierung')
                            }}</Label>
                            <select
                                id="read-encoding"
                                v-model="readingForm.encoding"
                                :class="selectClass"
                            >
                                <option value="utf-8">UTF-8</option>
                                <option value="windows-1252">
                                    Windows-1252
                                </option>
                            </select>
                        </div>
                        <label
                            class="flex items-center gap-2.5 text-sm sm:mt-6"
                        >
                            <input
                                v-model="readingForm.header"
                                type="checkbox"
                                class="size-4 accent-primary"
                            />
                            {{ t('Erste Zeile mit Spaltennamen') }}
                        </label>
                        <div class="sm:col-span-3">
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="readingForm.processing"
                            >
                                <Spinner v-if="readingForm.processing" />
                                <RefreshCw v-else />{{ t('Neu lesen') }}
                            </Button>
                        </div>
                    </form>
                </section>

                <!-- Zuordnung (CSV) -->
                <section
                    v-if="isCsv"
                    class="flex flex-col overflow-hidden rounded-lg border"
                >
                    <h2
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b px-4 py-3"
                    >
                        <span class="text-[15px] font-semibold">{{
                            t('Zuordnung')
                        }}</span>
                        <span class="text-xs text-text-secondary">{{
                            t('nach Namen vorgeschlagen')
                        }}</span>
                    </h2>
                    <div
                        class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16px_minmax(0,1.2fr)] gap-3 border-b bg-muted px-4 py-2 text-xs font-medium text-text-secondary md:grid"
                    >
                        <span>{{ t('Spalte in der Datei') }}</span>
                        <span>{{ t('Beispiel') }}</span>
                        <span />
                        <span>{{
                            t('Spalte in :table', {
                                table: transfer.table ?? '',
                            })
                        }}</span>
                    </div>
                    <ul class="divide-y">
                        <li
                            v-for="column in transfer.options.columns"
                            :key="column.index"
                            class="grid gap-x-3 gap-y-1.5 px-4 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16px_minmax(0,1.2fr)] md:items-start"
                        >
                            <span class="truncate text-sm font-medium">{{
                                column.name ||
                                t('Spalte :number', {
                                    number: column.index + 1,
                                })
                            }}</span>
                            <span
                                class="truncate text-sm text-text-secondary"
                                >{{ samples(column.samples) }}</span
                            >
                            <ArrowRight
                                class="mt-2 hidden size-4 text-muted-foreground md:block"
                            />
                            <div class="flex min-w-0 flex-col gap-1">
                                <select
                                    v-model="runForm.mapping[column.index]"
                                    :class="[selectClass, 'font-mono']"
                                    :aria-label="
                                        t('Ziel für :column', {
                                            column:
                                                column.name ||
                                                String(column.index + 1),
                                        })
                                    "
                                    :disabled="!canWrite || finished"
                                >
                                    <option value="">
                                        — {{ t('nicht importieren') }} —
                                    </option>
                                    <option
                                        v-for="target in columns"
                                        :key="target.name"
                                        :value="target.name"
                                    >
                                        {{ target.name }}
                                    </option>
                                </select>
                                <span
                                    v-if="runForm.mapping[column.index]"
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        columnHint(
                                            runForm.mapping[column.index] ?? '',
                                        )
                                    }}</span
                                >
                            </div>
                        </li>
                    </ul>
                    <p
                        v-if="missingRequired.length"
                        class="border-t px-4 py-2.5 text-xs text-warning"
                    >
                        {{
                            t(
                                'Ohne Zuordnung, aber nicht NULL und ohne Vorgabe: :columns — jede Zeile wird scheitern.',
                                { columns: missingRequired.join(', ') },
                            )
                        }}
                    </p>
                    <InputError
                        class="border-t px-4 py-2.5"
                        :message="runForm.errors.mapping"
                    />
                </section>
            </div>

            <!-- Probelauf und Import -->
            <div class="flex min-w-0 flex-col gap-4 xl:sticky xl:top-5">
                <section class="flex flex-col gap-4 rounded-lg border p-4">
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <Zap class="size-4 text-text-secondary" />{{
                            finished ? t('Ergebnis') : t('Probelauf')
                        }}
                    </h2>

                    <template v-if="live.active">
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="live.progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="t('Fortschritt')"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-[width]"
                                :style="{ width: `${live.progress}%` }"
                            />
                        </div>
                        <p class="text-sm text-text-secondary tabular-nums">
                            {{ live.statusLabel }} · {{ live.progress }} % ·
                            {{ tc(':count Zeile|:count Zeilen', live.rows) }}
                        </p>
                    </template>

                    <Notice
                        v-else-if="
                            transfer.status === 'failed' && transfer.error
                        "
                        variant="danger"
                    >
                        <b class="font-semibold">{{ transfer.error.text }}</b>
                        <span
                            v-if="transfer.error.detail"
                            class="mt-1 block font-mono text-xs break-words"
                            >{{ transfer.error.detail }}</span
                        >
                    </Notice>

                    <template v-if="csv && !live.active">
                        <Notice
                            v-if="checked"
                            variant="info"
                            :icon="ShieldCheck"
                        >
                            <b class="font-semibold">{{
                                t('Nichts wurde geschrieben.')
                            }}</b>
                            {{
                                csv.checked_only
                                    ? t(
                                          'Die Tabelle kennt keine Transaktionen; der Probelauf hat nur die Werte geprüft.',
                                      )
                                    : t(
                                          'Der Probelauf hat alle :count Zeilen in einer Transaktion eingefügt und danach zurückgerollt.',
                                          { count: formatNumber(csv.records) },
                                      )
                            }}
                        </Notice>
                        <p
                            v-if="finished"
                            class="flex items-center gap-2 font-semibold text-success"
                        >
                            <CircleCheck class="size-4" />{{
                                tc(
                                    ':count Zeile importiert|:count Zeilen importiert',
                                    csv.imported,
                                )
                            }}
                        </p>
                        <p
                            v-else
                            class="flex items-center gap-2 font-semibold text-success"
                        >
                            <CircleCheck class="size-4" />{{
                                tc(
                                    ':count Zeile bereit|:count Zeilen bereit',
                                    csv.ready,
                                )
                            }}
                        </p>
                        <template v-if="csv.error_count > 0">
                            <p
                                class="flex items-center gap-2 font-semibold text-destructive"
                            >
                                <CircleX class="size-4" />{{
                                    tc(
                                        ':count Zeile mit Fehlern|:count Zeilen mit Fehlern',
                                        csv.error_count,
                                    )
                                }}
                            </p>
                            <ul
                                class="flex max-h-80 flex-col divide-y overflow-auto"
                            >
                                <li
                                    v-for="error in csv.errors"
                                    :key="`${error.line}-${error.text}`"
                                    class="grid grid-cols-[72px_minmax(0,1fr)] gap-2 py-2 text-sm"
                                >
                                    <span
                                        class="font-mono text-xs text-text-secondary"
                                        >{{
                                            t('Zeile :line', {
                                                line: error.line,
                                            })
                                        }}</span
                                    >
                                    <span class="flex min-w-0 flex-col gap-0.5"
                                        ><span>{{ error.text }}</span
                                        ><span
                                            v-if="error.detail"
                                            class="font-mono text-xs break-words text-text-secondary"
                                            >{{ error.detail }}</span
                                        ></span
                                    >
                                </li>
                            </ul>
                            <p
                                v-if="csv.error_count > csv.errors.length"
                                class="text-xs text-text-secondary"
                            >
                                {{
                                    t('Gezeigt: die ersten :count.', {
                                        count: csv.errors.length,
                                    })
                                }}
                            </p>
                        </template>
                        <p
                            v-if="finished && csv.analyzed"
                            class="text-sm text-text-secondary"
                        >
                            {{ t('Statistik danach aktualisiert.') }}
                        </p>
                    </template>

                    <template v-if="sql && !live.active">
                        <Notice
                            v-if="checked"
                            variant="info"
                            :icon="ShieldCheck"
                        >
                            <b class="font-semibold">{{
                                t('Nichts wurde ausgeführt.')
                            }}</b>
                            {{
                                t(
                                    'Der Probelauf hat die Datei nur in Anweisungen zerlegt.',
                                )
                            }}
                        </Notice>
                        <p class="font-semibold">
                            {{
                                finished
                                    ? tc(
                                          ':count Anweisung ausgeführt|:count Anweisungen ausgeführt',
                                          sql.executed,
                                      )
                                    : tc(
                                          ':count Anweisung|:count Anweisungen',
                                          sql.statements,
                                      )
                            }}
                        </p>
                        <p class="text-sm text-text-secondary">
                            {{
                                Object.entries(sql.kinds)
                                    .map(
                                        ([kind, count]) =>
                                            `${kind || '?'} ${formatNumber(count)}`,
                                    )
                                    .join(' · ')
                            }}
                        </p>
                        <Notice
                            v-if="sql.dangerous_count > 0 && !finished"
                            variant="warning"
                            :icon="TriangleAlert"
                        >
                            <b class="font-semibold">{{
                                tc(
                                    ':count Anweisung löscht oder ändert ohne Bedingung|:count Anweisungen löschen oder ändern ohne Bedingung',
                                    sql.dangerous_count,
                                )
                            }}</b>
                            {{
                                sql.dangerous
                                    .map(
                                        (item) =>
                                            `${item.keyword} (${t('Zeile :line', { line: item.line })})`,
                                    )
                                    .join(', ')
                            }}
                        </Notice>
                        <p v-if="finished" class="text-sm text-text-secondary">
                            {{
                                tc(
                                    ':count Zeile betroffen|:count Zeilen betroffen',
                                    sql.affected,
                                )
                            }}
                        </p>
                    </template>

                    <p
                        v-if="
                            !transfer.result &&
                            !live.active &&
                            transfer.status !== 'failed'
                        "
                        class="text-sm text-text-secondary"
                    >
                        {{
                            isCsv
                                ? t(
                                      'Der Probelauf fügt alle Zeilen in einer Transaktion ein und rollt sie zurück — er schreibt nichts und zeigt jede Fehlerzeile.',
                                  )
                                : t(
                                      'Der Probelauf zerlegt die Datei in Anweisungen und warnt vor DROP, TRUNCATE und DELETE oder UPDATE ohne WHERE.',
                                  )
                        }}
                    </p>

                    <template v-if="canWrite && !finished">
                        <fieldset
                            v-if="isCsv"
                            class="flex flex-col gap-2 border-t pt-4"
                        >
                            <legend class="sr-only">
                                {{ t('Bei Fehlern') }}
                            </legend>
                            <label class="flex items-start gap-2.5 text-sm">
                                <input
                                    v-model="runForm.on_error"
                                    type="radio"
                                    value="skip"
                                    class="mt-0.5 size-4 accent-primary"
                                />
                                <span>{{
                                    t('Fehlerzeilen überspringen')
                                }}</span>
                            </label>
                            <label class="flex items-start gap-2.5 text-sm">
                                <input
                                    v-model="runForm.on_error"
                                    type="radio"
                                    value="abort"
                                    class="mt-0.5 size-4 accent-primary"
                                />
                                <span>{{
                                    t('Bei einem Fehler nichts importieren')
                                }}</span>
                            </label>
                            <label class="flex items-start gap-2.5 text-sm">
                                <input
                                    v-model="runForm.analyze"
                                    type="checkbox"
                                    class="mt-0.5 size-4 accent-primary"
                                />
                                <span class="flex flex-col gap-0.5"
                                    ><span
                                        >{{
                                            t('Danach Statistik aktualisieren')
                                        }}
                                        <code class="text-xs">{{
                                            isMySql
                                                ? `ANALYZE TABLE ${transfer.table}`
                                                : `ANALYZE ${transfer.table}`
                                        }}</code></span
                                    ><span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                'Ohne aktuelle Statistik schätzt der Server die Zeilenzahl falsch und sortiert nach grossen Importen langsamer.',
                                            )
                                        }}</span
                                    ></span
                                >
                            </label>
                        </fieldset>

                        <div
                            v-if="requiresConfirmation"
                            class="flex flex-col gap-1.5 border-t pt-4"
                        >
                            <Label for="confirmation" class="text-[13px]">{{
                                t(
                                    'Produktion: zum Importieren den Namen der Verbindung eintippen',
                                )
                            }}</Label>
                            <Input
                                id="confirmation"
                                v-model="runForm.confirmation"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="workspace.connection.name"
                                :aria-invalid="
                                    runForm.errors.confirmation
                                        ? true
                                        : undefined
                                "
                            />
                            <InputError
                                :message="runForm.errors.confirmation"
                            />
                        </div>

                        <InputError :message="runError" />
                    </template>
                </section>

                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center"
                >
                    <Button variant="ghost" as-child>
                        <Link
                            :href="
                                transfersHref(workspace, {
                                    schema: transfer.schema,
                                    table: transfer.table,
                                })
                            "
                            ><ArrowLeft />{{ t('Zurück') }}</Link
                        >
                    </Button>
                    <template v-if="canWrite && !finished">
                        <Button
                            type="button"
                            variant="outline"
                            class="sm:ml-auto"
                            :disabled="
                                runForm.processing ||
                                live.active ||
                                (isCsv && mappedCount === 0)
                            "
                            @click="start(true)"
                        >
                            <Spinner v-if="pending === 'check'" />
                            <RefreshCw v-else />{{
                                transfer.result
                                    ? t('Erneut prüfen')
                                    : t('Probelauf')
                            }}
                        </Button>
                        <Button
                            type="button"
                            :disabled="runForm.processing || !canImport"
                            @click="start(false)"
                        >
                            <Spinner v-if="pending === 'import'" />
                            <Upload v-else />{{
                                csv && checked
                                    ? tc(
                                          ':count Zeile importieren|:count Zeilen importieren',
                                          csv.ready,
                                      )
                                    : isCsv
                                      ? t('Importieren')
                                      : t('Ausführen')
                            }}
                        </Button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
