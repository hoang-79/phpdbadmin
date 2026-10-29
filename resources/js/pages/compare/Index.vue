<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ArrowRight,
    ChevronRight,
    Copy,
    Download,
    GitCompareArrows,
    KeyRound,
    Link2,
    List,
    Table2,
    TerminalSquare,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { copyText, downloadText } from '@/lib/download';
import { t, tc } from '@/lib/i18n';
import { compare, home } from '@/routes';
import type {
    CompareConnectionOption,
    CompareItem,
    CompareResult,
    CompareSide,
    CompareStatus,
} from '@/types';

/**
 * Schemavergleich (S-08): Quelle gegen Ziel, gleiche oder verschiedene Treiber. Die Liste zeigt, was neu ist, was
 * nur im Ziel steht und was sich unterscheidet; das Änderungsskript macht das Ziel gleich wie die Quelle — im
 * Dialekt des Ziels, zum Kopieren, Herunterladen oder Öffnen im SQL-Editor. Ausgeführt wird nichts.
 */
const props = defineProps<{
    connections: CompareConnectionOption[];
    source: CompareSide;
    target: CompareSide;
    withDrops: boolean;
    result: CompareResult | null;
}>();

type SideKey = 'source' | 'target';

const loading = ref(false);
const drops = ref(props.withDrops);

const sides = computed(() => [
    { key: 'source' as SideKey, label: t('Quelle'), side: props.source },
    { key: 'target' as SideKey, label: t('Ziel'), side: props.target },
]);

const ready = computed(
    () =>
        props.source.schema !== null &&
        props.target.schema !== null &&
        !props.source.problem &&
        !props.target.problem,
);

const outcome = computed(() =>
    props.result && props.result.error === null ? props.result : null,
);

/**
 * Auswahl, die schon unterwegs ist: Wählt man Quelle und Ziel schnell hintereinander, trägt die Seite die erste
 * Auswahl noch nicht — ohne diesen Stand ginge sie mit der zweiten verloren.
 */
let pending: Record<string, string | null> | null = null;

/** Adresse aus dem aktuellen Stand, mit einer Änderung — alles steht in der Adresse (neu laden, teilen). */
function params(
    changes: Partial<Record<string, string | null>> = {},
): Record<string, string> {
    const current: Record<string, string | null> = {
        ...(pending ?? {
            source: props.source.connection?.id ?? null,
            source_db: props.source.database,
            source_schema: props.source.schema,
            target: props.target.connection?.id ?? null,
            target_db: props.target.database,
            target_schema: props.target.schema,
        }),
        drops: drops.value ? '1' : null,
        ...changes,
    };

    return Object.fromEntries(
        Object.entries(current).filter(
            (entry): entry is [string, string] =>
                entry[1] !== null && entry[1] !== '',
        ),
    );
}

function visit(query: Record<string, string>): void {
    // Der Auftrag «vergleichen» gilt nur für diese eine Anfrage.
    pending = Object.fromEntries(
        Object.entries(query).filter(([key]) => key !== 'run'),
    );
    router.get(
        compare.url({ query }),
        {},
        {
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => {
                loading.value = false;
                pending = null;
            },
        },
    );
}

function choose(
    side: SideKey,
    field: 'connection' | 'db' | 'schema',
    value: string,
): void {
    const changes: Record<string, string | null> =
        field === 'connection'
            ? {
                  [side]: value,
                  [`${side}_db`]: null,
                  [`${side}_schema`]: null,
              }
            : field === 'db'
              ? { [`${side}_db`]: value, [`${side}_schema`]: null }
              : { [`${side}_schema`]: value };

    visit(params(changes));
}

function swap(): void {
    visit(
        params({
            source: props.target.connection?.id ?? null,
            source_db: props.target.database,
            source_schema: props.target.schema,
            target: props.source.connection?.id ?? null,
            target_db: props.source.database,
            target_schema: props.source.schema,
        }),
    );
}

function run(): void {
    visit({ ...params(), run: '1' });
}

function statusLabel(status: CompareStatus): string {
    return {
        added: t('neu'),
        removed: t('nur im Ziel'),
        changed: t('geändert'),
    }[status];
}

function statusClass(status: CompareStatus): string {
    return {
        added: 'border-success text-success',
        removed: 'border-destructive text-destructive',
        changed: 'border-warning text-warning',
    }[status];
}

function kindLabel(item: CompareItem): string {
    return {
        column: t('Spalte'),
        index: t('Index'),
        foreignKey: t('Fremdschlüssel'),
    }[item.kind];
}

function tableNote(status: CompareStatus): string {
    return status === 'added'
        ? t('Gibt es nur in der Quelle — wird im Ziel angelegt.')
        : t(
              'Gibt es nur im Ziel — wird nur entfernt, wenn Sie es unten wählen.',
          );
}
</script>

<template>
    <Head :title="t('Schemas vergleichen')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8">
        <div class="flex flex-col gap-1">
            <nav
                class="flex items-center gap-1 text-xs text-text-secondary"
                :aria-label="t('Pfad')"
            >
                <Link :href="home()" class="hover:text-foreground">{{
                    t('Verbindungen')
                }}</Link>
                <ChevronRight class="size-3.5" />
                <span>{{ t('Schemas vergleichen') }}</span>
            </nav>
            <h1 class="flex items-center gap-2 text-xl font-semibold">
                <GitCompareArrows class="size-5" />{{
                    t('Schemas vergleichen')
                }}
            </h1>
            <p class="max-w-3xl text-text-secondary">
                {{
                    t(
                        'Quelle gegen Ziel — auch zwischen MySQL, MariaDB, PostgreSQL und SQLite. Das Änderungsskript macht das Ziel gleich wie die Quelle, im Dialekt des Ziels. Ausgeführt wird nichts.',
                    )
                }}
            </p>
        </div>

        <div
            class="grid items-start gap-4 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]"
        >
            <template v-for="(entry, index) in sides" :key="entry.key">
                <section
                    class="flex min-w-0 flex-col gap-3 rounded-lg border bg-background p-4"
                    :aria-labelledby="`${entry.key}-title`"
                >
                    <h2
                        :id="`${entry.key}-title`"
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        {{ entry.label }}
                        <EnvironmentMark
                            v-if="entry.side.connection"
                            :environment="entry.side.connection.environment"
                        />
                    </h2>

                    <div class="flex flex-col gap-1.5">
                        <Label
                            :for="`${entry.key}-connection`"
                            class="text-[13px]"
                            >{{ t('Verbindung') }}</Label
                        >
                        <select
                            :id="`${entry.key}-connection`"
                            class="h-9 w-full min-w-0 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :value="entry.side.connection?.id ?? ''"
                            :disabled="loading"
                            @change="
                                choose(
                                    entry.key,
                                    'connection',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="" disabled>
                                {{ t('Verbindung wählen') }}
                            </option>
                            <option
                                v-for="option in connections"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }} · {{ option.driverLabel }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="entry.side.databases"
                        class="flex flex-col gap-1.5"
                    >
                        <Label :for="`${entry.key}-db`" class="text-[13px]">{{
                            t('Datenbank')
                        }}</Label>
                        <select
                            :id="`${entry.key}-db`"
                            class="h-9 w-full min-w-0 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :value="entry.side.database ?? ''"
                            :disabled="loading"
                            @change="
                                choose(
                                    entry.key,
                                    'db',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="database in entry.side.databases"
                                :key="database"
                                :value="database"
                            >
                                {{ database }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="entry.side.schemas.length"
                        class="flex flex-col gap-1.5"
                    >
                        <Label
                            :for="`${entry.key}-schema`"
                            class="text-[13px]"
                            >{{
                                ['mysql', 'mariadb'].includes(
                                    entry.side.connection?.driver ?? '',
                                )
                                    ? t('Datenbank')
                                    : t('Schema')
                            }}</Label
                        >
                        <select
                            :id="`${entry.key}-schema`"
                            class="h-9 w-full min-w-0 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :value="entry.side.schema ?? ''"
                            :disabled="loading"
                            @change="
                                choose(
                                    entry.key,
                                    'schema',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="schema in entry.side.schemas"
                                :key="schema"
                                :value="schema"
                            >
                                {{ schema }}
                            </option>
                        </select>
                    </div>

                    <Notice v-if="entry.side.problem" variant="danger">
                        <span class="flex flex-col gap-1">
                            <b class="font-semibold">{{
                                entry.side.problem.title
                            }}</b>
                            <span>{{ entry.side.problem.explanation }}</span>
                            <Link
                                v-if="entry.side.problem.passwordUrl"
                                :href="entry.side.problem.passwordUrl"
                                class="inline-flex items-center gap-1 font-medium text-primary underline-offset-2 hover:underline"
                                ><KeyRound class="size-3.5" />{{
                                    t('Passwort eingeben')
                                }}</Link
                            >
                        </span>
                    </Notice>
                </section>

                <div
                    v-if="index === 0"
                    class="flex items-center justify-center md:pt-12"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :aria-label="t('Quelle und Ziel tauschen')"
                        :title="t('Quelle und Ziel tauschen')"
                        :disabled="
                            loading || !source.connection || !target.connection
                        "
                        @click="swap"
                    >
                        <ArrowLeftRight class="rotate-90 md:rotate-0" />
                    </Button>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-start gap-2.5 text-sm">
                <input
                    v-model="drops"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-primary"
                />
                <span class="flex flex-col gap-0.5">
                    <span>{{
                        t('Auch entfernen, was nur im Ziel steht')
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        t(
                            'Tabellen, Spalten, Indizes und Fremdschlüssel — sonst bleiben sie und stehen als Hinweis im Skript.',
                        )
                    }}</span>
                </span>
            </label>
            <Button
                type="button"
                class="w-full sm:ml-auto sm:w-auto"
                :disabled="!ready || loading"
                @click="run"
            >
                <Spinner v-if="loading" /><GitCompareArrows v-else />{{
                    t('Vergleichen')
                }}
            </Button>
        </div>

        <Notice v-if="result && result.error !== null" variant="danger">
            {{ result.error }}
        </Notice>

        <template v-if="outcome">
            <Notice v-if="!outcome.tables.length" variant="success">
                <b class="font-semibold">{{ t('Keine Unterschiede.') }}</b>
                {{
                    tc(
                        ':count Tabelle ist gleich.|:count Tabellen sind gleich.',
                        outcome.identical,
                    )
                }}
            </Notice>

            <template v-else>
                <div
                    class="flex flex-wrap items-center gap-2 text-sm text-text-secondary"
                    role="status"
                >
                    <span>{{
                        tc(
                            ':count Tabelle gleich|:count Tabellen gleich',
                            outcome.identical,
                        )
                    }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{
                        tc(
                            ':count Tabelle neu oder nur im Ziel|:count Tabellen neu oder nur im Ziel',
                            outcome.counts.tables,
                        )
                    }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{
                        tc(
                            ':count Spalte|:count Spalten',
                            outcome.counts.columns,
                        )
                    }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{
                        tc(
                            ':count Index|:count Indizes',
                            outcome.counts.indexes,
                        )
                    }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{
                        tc(
                            ':count Fremdschlüssel|:count Fremdschlüssel',
                            outcome.counts.foreignKeys,
                        )
                    }}</span>
                    <span
                        v-if="!outcome.sameDriver"
                        class="basis-full text-xs"
                        >{{
                            t(
                                'Verschiedene Treiber: Spaltentypen werden über die Art verglichen (Zahl, Text, Datum …), Längen, wo beide eine kennen.',
                            )
                        }}</span
                    >
                </div>

                <section
                    v-for="table in outcome.tables"
                    :key="table.name"
                    class="rounded-lg border bg-background"
                    :aria-label="table.name"
                >
                    <h2
                        class="flex flex-wrap items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                    >
                        <Table2 class="size-4 shrink-0" />
                        <span class="min-w-0 font-mono break-all">{{
                            table.name
                        }}</span>
                        <span
                            :class="[
                                'rounded-full border px-2 py-0.5 text-xs font-medium',
                                statusClass(table.status),
                            ]"
                            >{{ statusLabel(table.status) }}</span
                        >
                    </h2>
                    <p
                        v-if="table.status !== 'changed'"
                        class="px-4 py-3 text-sm text-text-secondary"
                    >
                        {{ tableNote(table.status) }}
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="(item, position) in table.items"
                            :key="`${item.kind}-${position}`"
                            class="flex flex-col gap-1 px-4 py-2.5 text-[13px] leading-[18px]"
                        >
                            <span class="flex flex-wrap items-center gap-2">
                                <List
                                    v-if="item.kind === 'column'"
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <Link2
                                    v-else-if="item.kind === 'foreignKey'"
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <KeyRound
                                    v-else
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span class="text-text-secondary">{{
                                    kindLabel(item)
                                }}</span>
                                <b
                                    class="min-w-0 font-mono font-semibold break-all"
                                    >{{ item.name }}</b
                                >
                                <span
                                    :class="[
                                        'rounded-full border px-2 py-px text-xs font-medium',
                                        statusClass(item.status),
                                    ]"
                                    >{{ statusLabel(item.status) }}</span
                                >
                                <span
                                    v-if="item.changes.length"
                                    class="text-xs text-text-secondary"
                                    >{{ item.changes.join(', ') }}</span
                                >
                            </span>
                            <span
                                v-if="
                                    item.kind === 'column' &&
                                    (item.source || item.target)
                                "
                                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs break-all text-text-secondary"
                            >
                                <span>{{ item.source ?? '—' }}</span>
                                <ArrowRight
                                    class="size-3 shrink-0"
                                    :aria-label="t('im Ziel')"
                                />
                                <span>{{ item.target ?? '—' }}</span>
                            </span>
                        </li>
                    </ul>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-lg border bg-background"
                    :aria-label="t('Änderungsskript')"
                >
                    <div
                        class="flex flex-wrap items-center gap-2 border-b px-4 py-3"
                    >
                        <h2 class="text-[15px] font-semibold">
                            {{ t('Änderungsskript') }}
                        </h2>
                        <span class="text-xs text-text-secondary">{{
                            tc(
                                ':count Anweisung|:count Anweisungen',
                                outcome.statements,
                            )
                        }}</span>
                        <div
                            class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!outcome.script"
                                @click="
                                    copyText(
                                        outcome.script ?? '',
                                        t('Skript kopiert.'),
                                    )
                                "
                                ><Copy />{{ t('Kopieren') }}</Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!outcome.script"
                                @click="
                                    downloadText(
                                        outcome.fileName,
                                        outcome.script ?? '',
                                        'application/sql',
                                    )
                                "
                                ><Download />{{ t('Herunterladen') }}</Button
                            >
                            <Button v-if="outcome.editorUrl" size="sm" as-child>
                                <Link :href="outcome.editorUrl"
                                    ><TerminalSquare />{{
                                        t('Im SQL-Editor des Ziels öffnen')
                                    }}</Link
                                >
                            </Button>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 px-4 pb-4">
                        <p
                            v-if="outcome.script && !outcome.editorUrl"
                            class="text-xs text-text-secondary"
                        >
                            {{
                                t(
                                    'Zu lang für den SQL-Editor als Vorlage — kopieren oder herunterladen.',
                                )
                            }}
                        </p>
                        <Notice
                            v-for="note in outcome.notes"
                            :key="note"
                            variant="warning"
                            >{{ note }}</Notice
                        >
                        <pre
                            v-if="outcome.script"
                            class="max-h-[60svh] overflow-y-auto rounded-md border bg-muted px-3 py-2.5 font-mono text-xs leading-[18px] break-all whitespace-pre-wrap"
                            tabindex="0"
                            :aria-label="t('Änderungsskript')"
                            >{{ outcome.script }}</pre>
                        <p v-else class="text-sm text-text-secondary">
                            {{
                                t(
                                    'Nichts zu ändern — was übrig bleibt, steht nur im Ziel.',
                                )
                            }}
                        </p>
                    </div>
                </section>
            </template>
        </template>
    </div>
</template>
