<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Database, Eye, Table2, Waypoints } from '@lucide/vue';
import { computed } from 'vue';
import Notice from '@/components/Notice.vue';
import { formatRows, titlePrefix } from '@/lib/environment';
import { t, tc } from '@/lib/i18n';
import { dataHref, diagramHref } from '@/lib/workspace';
import type { Workspace } from '@/types';

/** Schema bzw. Datenbank im Überblick (Wireframe 05 ohne gewähltes Objekt). */
const props = defineProps<{
    workspace: Workspace;
}>();

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

        <ul
            v-if="tables.length || views.length"
            class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="item in [...tables, ...views]"
                :key="`${item.kind}:${item.name}`"
            >
                <Link
                    :href="dataHref(workspace, item.name)"
                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm outline-none hover:border-text-secondary focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <component
                        :is="item.kind === 'view' ? Eye : Table2"
                        class="size-4 shrink-0 text-text-secondary"
                    />
                    <span class="truncate font-mono">{{ item.name }}</span>
                    <span
                        class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                        >{{
                            item.kind === 'view'
                                ? t('Sicht')
                                : formatRows(item.rows)
                        }}</span
                    >
                </Link>
            </li>
        </ul>
        <p
            v-else-if="workspace.schema"
            class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
        >
            {{ t('Diese Datenbank ist leer.') }}
        </p>
    </div>
</template>
