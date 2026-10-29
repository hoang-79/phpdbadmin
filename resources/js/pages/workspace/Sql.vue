<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    CircleAlert,
    CircleCheck,
    CircleSlash,
    Clock,
    History,
    ListTree,
    PanelRight,
    Play,
    Save,
    Search,
    Square,
    Trash2,
    Sparkles,
    WandSparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Notice from '@/components/Notice.vue';
import AiAssistDialog from '@/components/workspace/sql/AiAssistDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import ExplainPanel from '@/components/workspace/sql/ExplainPanel.vue';
import ResultPanel from '@/components/workspace/sql/ResultPanel.vue';
import SqlEditor from '@/components/workspace/sql/SqlEditor.vue';
import { ApiError, deleteJson, getJson, postJson } from '@/lib/api';
import { titlePrefix } from '@/lib/environment';
import { formatRecent } from '@/lib/format';
import { formatNumber, t } from '@/lib/i18n';
import { formatSql } from '@/lib/sqlFormat';
import { sql as sqlRoute } from '@/routes/workspace';
import {
    cancel as cancelRoute,
    history as historyRoute,
    run as runRoute,
} from '@/routes/workspace/sql';
import {
    destroy as destroySavedRoute,
    store as storeSavedRoute,
} from '@/routes/workspace/sql/saved';
import type {
    ExplainRequest,
    SavedSqlQuery,
    SqlEditorSettings,
    SqlHistoryEntry,
    SqlResult,
    Workspace,
} from '@/types';

/**
 * SQL-Editor (Q-01 bis Q-04, Q-07, Wireframe 10). Der Text jeder Abfrage bleibt im Browser (je Verbindung und Reiter),
 * damit Neuladen nichts verliert. Gefährliche Anweisungen fragen nach, in Produktion mit dem Namen der Verbindung.
 */
const props = defineProps<{
    workspace: Workspace;
    queryTab: number;
    editor: SqlEditorSettings;
    saved: SavedSqlQuery[];
    history: SqlHistoryEntry[];
    completion?: Record<string, string[]>;
    template: string | null;
}>();

type Mode = 'statement' | 'selection' | 'all';

const storageKey = computed(
    () => `phpdbadmin.sql.${props.workspace.connection.id}.${props.queryTab}`,
);
const text = ref('');
const mode = ref<Mode>('statement');
const running = ref(false);
const runId = ref<string | null>(null);
const results = ref<SqlResult[]>([]);
const failure = ref<string | null>(null);
const loadingMore = ref(false);
const savedList = ref<SavedSqlQuery[]>([...props.saved]);
const historyList = ref<SqlHistoryEntry[]>([...props.history]);
const historySearch = ref('');
const panelOpen = ref(false);
const editorRef = ref<InstanceType<typeof SqlEditor> | null>(null);
const confirming = ref<{
    dangers: { sql: string; reason: string }[];
    production: boolean;
    wrongName: boolean;
    payload: Record<string, unknown>;
} | null>(null);
const confirmationName = ref('');
const saving = ref(false);
const saveName = ref('');

onMounted(() => {
    const stored = window.localStorage.getItem(storageKey.value) ?? '';
    text.value =
        stored.trim() === '' && props.template ? props.template : stored;
});

// EXPLAIN (Q-06): die Anweisung unter der Schreibmarke bzw. die Auswahl, ⌘E — der Plan ersetzt die Ergebnisse, bis
// man ihn schliesst.
const explainRequest = ref<ExplainRequest | null>(null);

function explainStatement(): void {
    const selected = editorRef.value?.selection() ?? '';
    const isSelection = selected.trim() !== '';
    const sqlText = isSelection ? selected : text.value;

    if (sqlText.trim() === '') {
        failure.value = t(
            'Da steht keine Anweisung — nur Kommentare oder nichts.',
        );

        return;
    }

    failure.value = null;
    explainRequest.value = {
        sql: sqlText,
        cursor: isSelection ? null : (editorRef.value?.cursorByte() ?? 0),
        selection: isSelection,
        schema: props.workspace.schema,
        at: Date.now(),
    };
}

function onExplainKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        (event.metaKey || event.ctrlKey) &&
        event.key.toLowerCase() === 'e' &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '')
    ) {
        event.preventDefault();
        explainStatement();
    }
}

onMounted(() => window.addEventListener('keydown', onExplainKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onExplainKey));

watch(storageKey, (key) => {
    text.value = window.localStorage.getItem(key) ?? '';
    results.value = [];
});

watch(text, (value) => {
    try {
        window.localStorage.setItem(storageKey.value, value);
    } catch {
        // Speicher voll: der Text bleibt bis zum Neuladen.
    }
});

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Abfrage :number', { number: props.queryTab })} · ${props.workspace.connection.name}`,
);

const modes = computed<{ value: Mode; label: string }[]>(() => [
    { value: 'statement', label: t('Aktuelle Anweisung') },
    { value: 'selection', label: t('Auswahl') },
    { value: 'all', label: t('Alles') },
]);

function newRunId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `lauf-${Date.now()}-${Math.round(Math.random() * 1e9)}`;
}

function explain(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return t('Die Anfrage ist gescheitert.');
    }

    switch (error.failure.reason) {
        case 'session':
            return t(
                'Ihre Sitzung ist abgelaufen. Laden Sie die Seite neu und melden Sie sich an.',
            );
        case 'password':
            return t(
                'Das Passwort dieser Verbindung gilt nicht mehr für diese Sitzung. Laden Sie die Seite neu.',
            );
        case 'connection':
            return t('Die Verbindung zur Datenbank ist abgerissen: :reason', {
                reason: error.failure.problem.title,
            });
        case 'empty':
            return t('Da steht keine Anweisung — nur Kommentare oder nichts.');
        default:
            return t('Die Anfrage ist gescheitert.');
    }
}

async function send(
    payload: Record<string, unknown>,
    replaceIndex: number | null = null,
): Promise<void> {
    failure.value = null;
    runId.value = String(payload.run);

    try {
        const response = await postJson<{
            results: SqlResult[];
            history: SqlHistoryEntry[];
        }>(
            runRoute.url(props.workspace.connection.id, {
                query: props.workspace.database
                    ? { db: props.workspace.database }
                    : {},
            }),
            payload,
        );

        if (replaceIndex !== null && response.results[0]) {
            results.value = results.value.map((result, index) =>
                index === replaceIndex ? response.results[0] : result,
            );
        } else {
            results.value = response.results;
        }

        historyList.value = response.history;
    } catch (error) {
        if (error instanceof ApiError && error.failure.reason === 'confirm') {
            confirming.value = {
                dangers: error.failure.dangers,
                production: error.failure.production,
                wrongName: error.failure.wrongName ?? false,
                payload,
            };

            return;
        }

        failure.value = explain(error);
    } finally {
        runId.value = null;
    }
}

async function run(requested: Mode | 'all' = mode.value): Promise<void> {
    if (running.value) {
        return;
    }

    explainRequest.value = null;

    const selected = editorRef.value?.selection() ?? '';
    const effective: Mode =
        requested === 'statement' && selected.trim() !== ''
            ? 'selection'
            : requested;
    const payload: Record<string, unknown> = {
        sql: effective === 'selection' ? selected : text.value,
        mode: effective === 'statement' ? 'statement' : 'all',
        cursor: editorRef.value?.cursorByte() ?? 0,
        run: newRunId(),
        schema: props.workspace.schema,
        limit: props.editor.rowLimit,
    };

    if (String(payload.sql).trim() === '') {
        failure.value = t(
            'Da steht keine Anweisung — nur Kommentare oder nichts.',
        );

        return;
    }

    running.value = true;

    try {
        await send(payload);
    } finally {
        running.value = false;
    }
}

async function confirmDangerous(): Promise<void> {
    const pending = confirming.value;

    if (!pending) {
        return;
    }

    confirming.value = null;
    running.value = true;

    try {
        await send({
            ...pending.payload,
            run: newRunId(),
            confirmed: true,
            confirmation: pending.production
                ? confirmationName.value
                : undefined,
        });
    } finally {
        running.value = false;
        confirmationName.value = '';
    }
}

async function cancelRun(): Promise<void> {
    if (!runId.value) {
        return;
    }

    try {
        await postJson(cancelRoute.url(props.workspace.connection.id), {
            run: runId.value,
        });
    } catch {
        // Der Lauf endet ohnehin an der Zeitgrenze.
    }
}

async function loadMore(index: number, rows?: number): Promise<void> {
    const result = results.value[index];

    if (!result) {
        return;
    }

    loadingMore.value = true;

    try {
        await send(
            {
                sql: result.sql,
                mode: 'all',
                run: newRunId(),
                schema: props.workspace.schema,
                limit: Math.min(
                    props.editor.maxRowLimit,
                    rows ?? result.rows.length + props.editor.rowLimit * 4,
                ),
                confirmed: true,
            },
            index,
        );
    } finally {
        loadingMore.value = false;
    }
}

const page = usePage();
const ai = computed(() => page.props.auth.user?.ai ?? null);
const assistOpen = ref(false);

/** Vorschlag der KI-Hilfe ans Ende hängen — der Text im Editor bleibt, nichts läuft von selbst. */
function insertSuggestion(sql: string): void {
    text.value = text.value.trim() ? `${text.value.trimEnd()}\n\n${sql}` : sql;
    editorRef.value?.focus();
}

/** Nach dem Bearbeiten eines Ergebnisses (Q-05) dieselbe Abfrage noch einmal — mit ebenso vielen Zeilen. */
function refresh(index: number): void {
    void loadMore(
        index,
        Math.max(props.editor.rowLimit, results.value[index]?.rows.length ?? 0),
    );
}

function format(): void {
    text.value = formatSql(text.value);
}

function load(sqlText: string): void {
    text.value = sqlText;
    panelOpen.value = false;
    editorRef.value?.focus();
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(historySearch, (term) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        try {
            historyList.value = await getJson<SqlHistoryEntry[]>(
                historyRoute.url(props.workspace.connection.id, {
                    query: term.trim() ? { q: term.trim() } : {},
                }),
            );
        } catch {
            // Suche ist Komfort.
        }
    }, 250);
});

async function saveQuery(): Promise<void> {
    if (saveName.value.trim() === '' || text.value.trim() === '') {
        return;
    }

    try {
        const saved = await postJson<SavedSqlQuery>(
            storeSavedRoute.url(props.workspace.connection.id),
            {
                name: saveName.value.trim(),
                sql: text.value,
            },
        );
        savedList.value = [
            ...savedList.value.filter((item) => item.name !== saved.name),
            saved,
        ].sort((a, b) => a.name.localeCompare(b.name));
        saving.value = false;
        saveName.value = '';
    } catch (error) {
        failure.value = explain(error);
    }
}

async function forget(query: SavedSqlQuery): Promise<void> {
    try {
        await deleteJson(
            destroySavedRoute.url([props.workspace.connection.id, query.id]),
        );
        savedList.value = savedList.value.filter(
            (item) => item.id !== query.id,
        );
    } catch (error) {
        failure.value = explain(error);
    }
}

function switchSchema(schema: string): void {
    router.get(
        sqlRoute.url(props.workspace.connection.id, {
            query: {
                tab: props.queryTab,
                schema,
                ...(props.workspace.database
                    ? { db: props.workspace.database }
                    : {}),
            },
        }),
        {},
        { preserveState: true },
    );
}

const statusIcons: Record<string, unknown> = {
    ok: CircleCheck,
    error: CircleAlert,
    cancelled: CircleSlash,
    timeout: Clock,
};
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full min-h-0">
        <div class="flex min-w-0 flex-1 flex-col">
            <div
                class="flex shrink-0 flex-wrap items-center gap-2 border-b px-3 py-2 sm:px-4"
            >
                <Button size="sm" :disabled="running" @click="run()">
                    <Spinner v-if="running" /><Play v-else />{{
                        t('Ausführen')
                    }}
                </Button>
                <kbd
                    class="hidden rounded border bg-muted px-1.5 font-mono text-[11px] leading-5 text-muted-foreground sm:inline"
                    >⌘↵</kbd
                >
                <div
                    role="radiogroup"
                    :aria-label="t('Was ausführen')"
                    class="flex rounded-md border text-[13px]"
                >
                    <button
                        v-for="option in modes"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="mode === option.value"
                        :class="[
                            'h-8 px-2.5 outline-none first:rounded-l-md last:rounded-r-md focus-visible:ring-2 focus-visible:ring-ring',
                            mode === option.value
                                ? 'bg-accent font-semibold'
                                : 'hover:bg-muted',
                        ]"
                        @click="mode = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    :title="t('Plan der Anweisung an der Schreibmarke (⌘E)')"
                    @click="explainStatement"
                >
                    <ListTree />{{ t('Erklären') }}
                </Button>
                <Button size="sm" variant="outline" @click="format">
                    <WandSparkles />{{ t('Formatieren') }}
                </Button>
                <Button
                    v-if="ai && workspace.schema"
                    size="sm"
                    variant="outline"
                    @click="assistOpen = true"
                >
                    <Sparkles />{{ t('KI fragen') }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!text.trim()"
                    @click="saving = true"
                >
                    <Save />{{ t('Speichern …') }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!running"
                    @click="cancelRun"
                >
                    <Square />{{ t('Abbrechen') }}
                </Button>
                <label
                    v-if="workspace.schemas.length > 1"
                    class="flex items-center gap-1.5 text-[13px] text-text-secondary"
                >
                    {{
                        workspace.schemaKind === 'schema'
                            ? t('Schema')
                            : t('Datenbank')
                    }}
                    <select
                        :value="workspace.schema ?? ''"
                        class="h-8 rounded-md border bg-background px-2 font-mono text-[13px] text-foreground"
                        @change="
                            switchSchema(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="name in workspace.schemas"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                </label>
                <button
                    type="button"
                    class="ml-auto inline-grid size-8 place-items-center rounded-md border outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring xl:hidden"
                    :aria-label="t('Gespeichert und Verlauf')"
                    @click="panelOpen = true"
                >
                    <PanelRight class="size-4" />
                </button>
                <span
                    class="w-full text-[12px] text-text-secondary sm:ml-auto sm:w-auto xl:ml-auto"
                >
                    {{
                        t(
                            'Zeitgrenze :seconds s · höchstens :rows Zeilen je Ergebnis',
                            {
                                seconds: editor.timeoutSeconds,
                                rows: formatNumber(editor.rowLimit),
                            },
                        )
                    }}
                    <template v-if="!editor.canWrite">
                        · {{ t('nur lesen') }}</template
                    >
                </span>
            </div>

            <div class="h-[42%] min-h-40 shrink-0 border-b">
                <SqlEditor
                    ref="editorRef"
                    v-model="text"
                    :driver="workspace.connection.driver"
                    :schema="completion ?? null"
                    :default-schema="workspace.schema"
                    @run="(requested) => run(requested)"
                />
            </div>

            <div v-if="failure" class="shrink-0 px-4 py-2">
                <Notice variant="danger">{{ failure }}</Notice>
            </div>

            <ExplainPanel
                v-if="explainRequest"
                :workspace="workspace"
                :request="explainRequest"
                @close="explainRequest = null"
            />
            <ResultPanel
                v-else
                :workspace="workspace"
                :results="results"
                :max-row-limit="editor.maxRowLimit"
                :loading-more="loadingMore"
                @more="loadMore"
                @refresh="refresh"
            />
        </div>

        <aside
            class="hidden w-72 shrink-0 flex-col overflow-y-auto border-l bg-muted/30 xl:flex"
            :aria-label="t('Gespeichert und Verlauf')"
        >
            <section class="border-b p-3">
                <h2
                    class="mb-2 flex items-center gap-1.5 text-[13px] font-semibold"
                >
                    <Bookmark class="size-3.5" />{{ t('Gespeichert') }}
                    <span class="ml-auto font-normal text-muted-foreground">{{
                        savedList.length
                    }}</span>
                </h2>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="query in savedList"
                        :key="query.id"
                        class="group flex items-start gap-1"
                    >
                        <button
                            type="button"
                            class="min-w-0 flex-1 rounded-md px-2 py-1.5 text-left hover:bg-background"
                            @click="load(query.sql)"
                        >
                            <span class="block truncate text-sm font-medium">{{
                                query.name
                            }}</span>
                            <span class="block text-xs text-text-secondary">{{
                                formatRecent(query.updatedAt)
                            }}</span>
                        </button>
                        <button
                            type="button"
                            class="mt-1 inline-grid size-7 place-items-center rounded-md text-text-secondary opacity-0 group-hover:opacity-100 hover:bg-background focus-visible:opacity-100"
                            :aria-label="
                                t('«:name» entfernen', { name: query.name })
                            "
                            @click="forget(query)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </li>
                    <li
                        v-if="!savedList.length"
                        class="px-2 text-xs text-text-secondary"
                    >
                        {{ t('Noch nichts gespeichert.') }}
                    </li>
                </ul>
            </section>
            <section class="flex min-h-0 flex-1 flex-col p-3">
                <h2
                    class="mb-2 flex items-center gap-1.5 text-[13px] font-semibold"
                >
                    <History class="size-3.5" />{{ t('Verlauf') }}
                    <span class="ml-auto font-normal text-muted-foreground">{{
                        t('dieses Konto · diese Verbindung')
                    }}</span>
                </h2>
                <label class="relative mb-2 block">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="historySearch"
                        type="search"
                        :placeholder="t('Verlauf durchsuchen')"
                        :aria-label="t('Verlauf durchsuchen')"
                        class="h-8 w-full rounded-md border bg-background pr-2 pl-8 text-[13px] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </label>
                <ul class="flex flex-col gap-0.5">
                    <li v-for="entry in historyList" :key="entry.id">
                        <button
                            type="button"
                            class="w-full rounded-md px-2 py-1.5 text-left hover:bg-background"
                            @click="load(entry.sql)"
                        >
                            <span class="flex items-baseline gap-2">
                                <code
                                    class="min-w-0 flex-1 truncate text-[12px]"
                                    >{{ entry.sql.replace(/\s+/g, ' ') }}</code
                                >
                                <span
                                    class="shrink-0 text-[11px] text-muted-foreground"
                                    >{{ formatRecent(entry.at) }}</span
                                >
                            </span>
                            <span
                                :class="[
                                    'mt-0.5 flex items-center gap-1 text-[12px]',
                                    entry.status === 'ok' && 'text-success',
                                    entry.status === 'error' &&
                                        'text-destructive',
                                    (entry.status === 'timeout' ||
                                        entry.status === 'cancelled') &&
                                        'text-warning',
                                ]"
                            >
                                <component
                                    :is="
                                        statusIcons[entry.status] ?? CircleCheck
                                    "
                                    class="size-3"
                                />
                                {{
                                    entry.status === 'ok'
                                        ? entry.rows !== null
                                            ? t(':rows Zeilen · :ms ms', {
                                                  rows: formatNumber(
                                                      entry.rows,
                                                  ),
                                                  ms: entry.durationMs ?? 0,
                                              })
                                            : t(':ms ms', {
                                                  ms: entry.durationMs ?? 0,
                                              })
                                        : ({
                                              error: t('Fehler'),
                                              timeout: t('Zeitgrenze'),
                                              cancelled: t('abgebrochen'),
                                          }[entry.status] ?? entry.status)
                                }}
                            </span>
                        </button>
                    </li>
                    <li
                        v-if="!historyList.length"
                        class="px-2 text-xs text-text-secondary"
                    >
                        {{ t('Noch kein Verlauf.') }}
                    </li>
                </ul>
            </section>
        </aside>

        <Sheet v-model:open="panelOpen">
            <SheetContent side="right" class="w-[320px] overflow-y-auto p-3">
                <SheetTitle>{{ t('Gespeichert und Verlauf') }}</SheetTitle>
                <ul class="mt-3 flex flex-col gap-1">
                    <li v-for="query in savedList" :key="`s-${query.id}`">
                        <button
                            type="button"
                            class="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                            @click="load(query.sql)"
                        >
                            <Bookmark class="mr-1 inline size-3.5" />{{
                                query.name
                            }}
                        </button>
                    </li>
                    <li v-for="entry in historyList" :key="`h-${entry.id}`">
                        <button
                            type="button"
                            class="w-full rounded-md px-2 py-1.5 text-left hover:bg-muted"
                            @click="load(entry.sql)"
                        >
                            <code class="block truncate text-[12px]">{{
                                entry.sql.replace(/\s+/g, ' ')
                            }}</code>
                        </button>
                    </li>
                </ul>
            </SheetContent>
        </Sheet>

        <Dialog
            :open="confirming !== null"
            @update:open="(open) => !open && (confirming = null)"
        >
            <DialogContent v-if="confirming" class="sm:max-w-lg">
                <DialogTitle>{{ t('Wirklich ausführen?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Diese Anweisungen können Daten unwiederbringlich löschen oder ändern.',
                        )
                    }}
                </DialogDescription>
                <ul class="flex max-h-60 flex-col gap-2 overflow-y-auto">
                    <li
                        v-for="(danger, index) in confirming.dangers"
                        :key="index"
                        class="rounded-md border border-destructive bg-destructive-soft px-3 py-2 text-sm"
                    >
                        <code class="block break-words whitespace-pre-wrap">{{
                            danger.sql
                        }}</code>
                        <span class="mt-1 block text-[13px] text-destructive">{{
                            danger.reason
                        }}</span>
                    </li>
                </ul>
                <label
                    v-if="confirming.production"
                    class="flex flex-col gap-1 text-sm"
                >
                    <span>{{
                        t(
                            'Produktion: Geben Sie zur Bestätigung den Namen der Verbindung ein — «:name».',
                            {
                                name: workspace.connection.name,
                            },
                        )
                    }}</span>
                    <input
                        v-model="confirmationName"
                        v-focus
                        type="text"
                        autocomplete="off"
                        class="h-9 rounded-md border bg-background px-2 font-mono"
                    />
                    <span
                        v-if="confirming.wrongName"
                        class="text-[13px] text-destructive"
                        >{{ t('Der Name stimmt nicht.') }}</span
                    >
                </label>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="confirming = null">{{
                        t('Abbrechen')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="
                            confirming.production &&
                            confirmationName !== workspace.connection.name
                        "
                        @click="confirmDangerous"
                    >
                        {{ t('Ausführen') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="saving" @update:open="(open) => (saving = open)">
            <DialogContent class="sm:max-w-md">
                <DialogTitle>{{ t('Abfrage speichern') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Unter einem Namen für dieses Konto und diese Verbindung. Gleicher Name überschreibt.',
                    )
                }}</DialogDescription>
                <form class="flex flex-col gap-3" @submit.prevent="saveQuery">
                    <input
                        v-model="saveName"
                        v-focus
                        type="text"
                        maxlength="120"
                        :aria-label="t('Name')"
                        :placeholder="t('z. B. Offene Bestellungen > 7 Tage')"
                        class="h-9 rounded-md border bg-background px-2"
                    />
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="saving = false"
                            >{{ t('Abbrechen') }}</Button
                        >
                        <Button type="submit" :disabled="!saveName.trim()">{{
                            t('Speichern')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AiAssistDialog
            v-if="ai && workspace.schema"
            v-model:open="assistOpen"
            :workspace="workspace"
            :has-key="ai.hasKey"
            @insert="insertSuggestion"
        />
    </div>
</template>
