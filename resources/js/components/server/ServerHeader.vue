<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Lock, Server } from '@lucide/vue';
import { computed } from 'vue';
import HelpLink from '@/components/HelpLink.vue';
import Notice from '@/components/Notice.vue';
import { titlePrefix } from '@/lib/environment';
import { t } from '@/lib/i18n';
import { databaseQuery } from '@/lib/server';
import { server as overviewRoute } from '@/routes/workspace';
import {
    accounts,
    maintenance,
    processes,
    variables,
} from '@/routes/workspace/server';
import type { ServerMeta, ServerSection, Workspace } from '@/types';

/**
 * Kopf der Server-Verwaltung (W-01 bis W-05): Titel, Reiter für die fünf Bereiche (brechen um statt zu scrollen)
 * und — wo sich etwas ändern lässt — der Grund, wenn Änderungen gesperrt sind.
 */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    title: string;
}>();

const id = computed(() => props.workspace.connection.id);
const query = computed(() => databaseQuery(props.workspace.database));

const tabs = computed<{ key: ServerSection; label: string; href: string }[]>(
    () => [
        {
            key: 'overview',
            label: t('Übersicht'),
            href: overviewRoute.url(id.value, query.value),
        },
        {
            key: 'processes',
            label: t('Prozesse'),
            href: processes.url(id.value, query.value),
        },
        {
            key: 'variables',
            label: t('Variablen'),
            href: variables.url(id.value, query.value),
        },
        {
            key: 'accounts',
            label: t('Konten und Rechte'),
            href: accounts.url(id.value, query.value),
        },
        {
            key: 'maintenance',
            label: t('Wartung'),
            href: maintenance.url(id.value, query.value),
        },
    ],
);

// SQLite hat keine Prozesse und Konten — dort sagt die Seite selbst, warum es nichts zu ändern gibt.
const changes = computed(
    () =>
        props.server.section === 'maintenance' ||
        (props.server.hasServer &&
            ['processes', 'accounts'].includes(props.server.section)),
);
const pageTitle = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${props.title} · ${props.workspace.connection.name}`,
);
</script>

<template>
    <Head :title="pageTitle" />

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="flex items-center gap-2 text-xl font-semibold">
                <Server class="size-5 text-text-secondary" />{{ title }}
            </h1>
            <span
                class="rounded-sm border px-1.5 py-px text-xs font-medium whitespace-nowrap text-text-secondary"
                >{{ workspace.connection.driverLabel }}
                {{ workspace.connection.serverVersion }}</span
            >
            <span
                v-if="workspace.connection.readOnly"
                class="inline-flex items-center gap-1 rounded-full border border-destructive bg-destructive-soft px-2 py-px text-xs font-medium text-destructive"
                ><Lock class="size-3" />{{ t('schreibgeschützt') }}</span
            >
            <HelpLink anchor="server" class="ml-auto" />
        </div>

        <nav
            class="flex flex-wrap gap-x-5 gap-y-1 border-b"
            :aria-label="t('Bereiche der Server-Verwaltung')"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                :class="[
                    '-mb-px border-b-2 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    tab.key === server.section
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-text-secondary hover:text-foreground',
                ]"
                :aria-current="tab.key === server.section ? 'page' : undefined"
                >{{ tab.label }}</Link
            >
        </nav>

        <Notice
            v-if="changes && server.blocked === 'read_only'"
            variant="warning"
        >
            <b class="font-semibold">{{
                t('Diese Verbindung ist schreibgeschützt.')
            }}</b>
            {{
                t(
                    'Ansehen geht, Ändern nicht: Prozesse beenden, Konten ändern und Wartung sind gesperrt. Den Schreibschutz heben Sie in den Einstellungen der Verbindung auf.',
                )
            }}
        </Notice>
        <Notice
            v-else-if="changes && server.blocked === 'not_manager'"
            variant="info"
        >
            {{
                t(
                    'Ansehen geht, Ändern nicht: Dafür braucht es das Recht «verwalten» an dieser Verbindung.',
                )
            }}
        </Notice>
    </div>
</template>
