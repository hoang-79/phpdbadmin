<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Database, Waypoints } from '@lucide/vue';
import { computed } from 'vue';
import Notice from '@/components/Notice.vue';
import ObjectOverview from '@/components/workspace/schema/ObjectOverview.vue';
import SchemaActions from '@/components/workspace/schema/SchemaActions.vue';
import { titlePrefix } from '@/lib/environment';
import { t, tc } from '@/lib/i18n';
import { diagramHref } from '@/lib/workspace';
import type { SchemaOverviewSettings, Workspace } from '@/types';

/**
 * Schema bzw. Datenbank im Überblick (Wireframe 05 ohne gewähltes Objekt): Tabellen und Sichten in drei Ansichten,
 * mit Filter, Sortierung und Seiten (ObjectOverview).
 */
const props = defineProps<{
    workspace: Workspace;
    /** Ansicht, Sortierung, Seiten, Filter — fehlt, wenn kein Schema gewählt ist. */
    overview?: SchemaOverviewSettings;
}>();

const defaultOverview: SchemaOverviewSettings = {
    view: 'cards',
    sort: 'name',
    direction: 'asc',
    perPage: 100,
    kind: 'all',
    filter: '',
};

const tables = computed(
    () =>
        props.workspace.groups.find((group) => group.key === 'tables')?.items ??
        [],
);
const views = computed(
    () =>
        props.workspace.groups.find((group) => group.key === 'views')?.items ??
        [],
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${props.workspace.schema ?? props.workspace.connection.name} · ${props.workspace.connection.name}`,
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <Notice
            v-for="warning in workspace.connection.accountWarnings"
            :key="warning.key"
            variant="warning"
        >
            <b class="font-semibold">{{ warning.title }}</b>
            {{ warning.recommendation }}
        </Notice>

        <div v-if="workspace.schema" class="flex flex-col gap-1">
            <Link
                v-if="tables.length"
                :href="diagramHref(workspace)"
                class="inline-flex items-center gap-1.5 self-start text-sm text-primary underline-offset-2 hover:underline sm:order-last"
                ><Waypoints class="size-4" />{{ t('Beziehungsdiagramm') }}</Link
            >
            <h1 class="flex items-center gap-2 font-mono text-xl font-semibold">
                <Database class="size-5 text-text-secondary" />{{
                    workspace.schema
                }}
            </h1>
            <p class="text-sm text-text-secondary">
                {{
                    workspace.schemaKind === 'schema'
                        ? t('Schema')
                        : t('Datenbank')
                }}
                · {{ tc(':count Tabelle|:count Tabellen', tables.length) }} ·
                {{ tc(':count Sicht|:count Sichten', views.length) }}
            </p>
        </div>

        <p v-else class="text-text-secondary">
            {{ t('Dieses Konto sieht keine Datenbank auf dem Server.') }}
        </p>

        <SchemaActions :workspace="workspace" />

        <ObjectOverview
            v-if="tables.length || views.length"
            :key="`${workspace.connection.id}:${workspace.database ?? ''}:${workspace.schema}`"
            :workspace="workspace"
            :items="[...tables, ...views]"
            :overview="overview ?? defaultOverview"
        />
        <p
            v-else-if="workspace.schema"
            class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
        >
            {{ t('Diese Datenbank ist leer.') }}
        </p>
    </div>
</template>
