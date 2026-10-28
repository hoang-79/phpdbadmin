<script setup lang="ts">
import { Database, HardDrive, Layers } from '@lucide/vue';
import { computed } from 'vue';
import ServerHeader from '@/components/server/ServerHeader.vue';
import { formatDateTime } from '@/lib/format';
import { formatNumber, t } from '@/lib/i18n';
import { formatBytes, formatDuration } from '@/lib/server';
import type {
    ServerFact,
    ServerMeta,
    ServerOverview,
    Workspace,
} from '@/types';

/** Server-Übersicht (W-01): Version, Laufzeit, Zeichensätze, Grösse je Datenbank bzw. Schema; SQLite: die Datei. */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    overview: ServerOverview;
}>();

const labels = computed<Record<string, string>>(() => ({
    version_comment: t('Ausgabe'),
    default_storage_engine: t('Standard-Engine'),
    threads_connected: t('Offene Verbindungen'),
    max_connections: t('Höchstens Verbindungen'),
    questions: t('Anweisungen seit dem Start'),
    innodb_buffer_pool_size: t('InnoDB-Pufferspeicher'),
    system_time_zone: t('Zeitzone des Systems'),
    sessions: t('Offene Sitzungen'),
    shared_buffers: t('Gemeinsamer Pufferspeicher'),
    started_at: t('Gestartet'),
    file: t('Datei'),
    file_size: t('Dateigrösse'),
    page_size: t('Seitengrösse'),
    page_count: t('Seiten'),
    freelist_count: t('Freie Seiten'),
    journal_mode: t('Journal-Modus'),
    auto_vacuum: t('Automatisches VACUUM'),
}));

function factValue(fact: ServerFact): string {
    switch (fact.kind) {
        case 'bytes':
            return formatBytes(Number(fact.value));
        case 'number':
            return formatNumber(Number(fact.value));
        case 'datetime':
            return formatDateTime(String(fact.value));
        default:
            return String(fact.value);
    }
}

const title = computed(() =>
    props.server.hasServer ? t('Server') : t('Datenbankdatei'),
);
const totalSize = computed(() =>
    props.overview.databases.reduce(
        (sum, database) => sum + (database.size ?? 0),
        0,
    ),
);
const databasesLabel = computed(() =>
    props.workspace.connection.driver === 'pgsql'
        ? t('Datenbanken')
        : props.server.hasServer
          ? t('Datenbanken (Schemas)')
          : t('Angehängte Datenbanken'),
);
</script>

<template>
    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <ServerHeader :workspace="workspace" :server="server" :title="title" />

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="flex flex-col rounded-lg border">
                <h2
                    class="flex items-center gap-2 border-b px-4 py-3 font-semibold"
                >
                    <HardDrive class="size-4 text-text-secondary" />{{
                        server.hasServer
                            ? t('Server')
                            : t('Bibliothek und Datei')
                    }}
                </h2>
                <dl
                    class="grid gap-x-4 px-4 py-2 text-sm sm:grid-cols-[auto_1fr]"
                >
                    <dt class="pt-2 text-text-secondary sm:py-1.5">
                        {{
                            server.hasServer
                                ? t('Version')
                                : t('SQLite-Bibliothek')
                        }}
                    </dt>
                    <dd class="pb-2 font-mono sm:py-1.5">
                        {{ workspace.connection.driverLabel }}
                        {{ overview.version }}
                    </dd>
                    <template v-if="overview.uptime !== null">
                        <dt class="pt-2 text-text-secondary sm:py-1.5">
                            {{ t('Laufzeit') }}
                        </dt>
                        <dd class="pb-2 tabular-nums sm:py-1.5">
                            {{ formatDuration(overview.uptime) }}
                        </dd>
                    </template>
                    <dt class="pt-2 text-text-secondary sm:py-1.5">
                        {{
                            server.hasServer
                                ? t('Zeichensatz (Vorgabe)')
                                : t('Kodierung')
                        }}
                    </dt>
                    <dd class="pb-2 font-mono sm:py-1.5">
                        {{ overview.charset ?? '—' }}
                    </dd>
                    <dt class="pt-2 text-text-secondary sm:py-1.5">
                        {{ t('Kollation (Vorgabe)') }}
                    </dt>
                    <dd class="pb-2 font-mono sm:py-1.5">
                        {{ overview.collation ?? '—' }}
                    </dd>
                    <template v-for="fact in overview.facts" :key="fact.key">
                        <dt class="pt-2 text-text-secondary sm:py-1.5">
                            {{ labels[fact.key] ?? fact.key }}
                        </dt>
                        <dd
                            :class="[
                                'pb-2 wrap-break-word sm:py-1.5',
                                fact.kind === 'text'
                                    ? 'font-mono'
                                    : 'tabular-nums',
                            ]"
                        >
                            {{ factValue(fact) }}
                        </dd>
                    </template>
                </dl>
            </section>

            <div class="flex flex-col gap-4">
                <section class="flex flex-col rounded-lg border">
                    <h2
                        class="flex flex-wrap items-center gap-2 border-b px-4 py-3 font-semibold"
                    >
                        <Database class="size-4 text-text-secondary" />{{
                            databasesLabel
                        }}
                        <span
                            class="font-normal text-muted-foreground tabular-nums"
                            >{{ overview.databases.length }}</span
                        >
                        <span
                            class="ml-auto text-xs font-normal text-text-secondary tabular-nums"
                            >{{
                                t('zusammen :size', {
                                    size: formatBytes(totalSize),
                                })
                            }}</span
                        >
                    </h2>
                    <ul v-if="overview.databases.length" class="text-sm">
                        <li
                            v-for="database in overview.databases"
                            :key="database.name"
                            class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b px-4 py-2 last:border-b-0"
                        >
                            <span
                                class="min-w-0 font-mono font-medium wrap-break-word"
                                >{{ database.name }}</span
                            >
                            <span
                                class="ml-auto shrink-0 tabular-nums"
                                :title="
                                    database.size === null
                                        ? t(
                                              'Grösse nicht lesbar: Dem Konto fehlt das Recht CONNECT.',
                                          )
                                        : undefined
                                "
                                >{{ formatBytes(database.size) }}</span
                            >
                            <span
                                v-if="database.charset || database.collation"
                                class="basis-full font-mono text-xs text-text-secondary"
                                >{{ database.charset
                                }}<template
                                    v-if="
                                        database.charset && database.collation
                                    "
                                >
                                    · </template
                                >{{ database.collation }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-sm text-text-secondary">
                        {{
                            t(
                                'Dieses Konto sieht keine Datenbank auf dem Server.',
                            )
                        }}
                    </p>
                </section>

                <section
                    v-if="overview.schemas"
                    class="flex flex-col rounded-lg border"
                >
                    <h2
                        class="flex flex-wrap items-center gap-2 border-b px-4 py-3 font-semibold"
                    >
                        <Layers class="size-4 text-text-secondary" />{{
                            t('Schemas in :database', {
                                database: workspace.databaseLabel ?? '',
                            })
                        }}
                    </h2>
                    <ul class="text-sm">
                        <li
                            v-for="schema in overview.schemas"
                            :key="schema.name"
                            class="flex items-baseline gap-3 border-b px-4 py-2 last:border-b-0"
                        >
                            <span class="min-w-0 font-mono wrap-break-word">{{
                                schema.name
                            }}</span>
                            <span class="ml-auto shrink-0 tabular-nums">{{
                                formatBytes(schema.size)
                            }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
