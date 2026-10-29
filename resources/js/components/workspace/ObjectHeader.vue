<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Eye, Lock, Table2 } from '@lucide/vue';
import { computed } from 'vue';
import { useOpenTabs } from '@/composables/useOpenTabs';
import { formatBytes } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { dataHref, definitionHref, structureHref } from '@/lib/workspace';
import { sql as sqlRoute } from '@/routes/workspace';
import type { ObjectStructure, Workspace } from '@/types';

/**
 * Kopf eines Objekts (Wireframe 05, 08): Name, Art und Kennzahlen, darunter die Reiter. Reiter erscheinen, sobald es
 * ihre Ansicht gibt; Sichten haben zusätzlich ihre Definition (S-06).
 */
const props = defineProps<{
    workspace: Workspace;
    object: ObjectStructure['object'];
    active: 'data' | 'structure' | 'definition' | 'sql';
}>();

const facts = computed(() =>
    [
        props.object.kind === 'view' ? t('Sicht') : t('Tabelle'),
        props.object.engine,
        props.object.rows !== null
            ? tc('≈ :count Zeile|≈ :count Zeilen', props.object.rows)
            : null,
        props.object.size !== null ? formatBytes(props.object.size) : null,
        props.object.collation,
    ].filter((fact): fact is string => !!fact),
);

const { tabs: openTabs } = useOpenTabs(props.workspace.connection.id);
const nextQueryTab = computed(
    () =>
        Math.max(
            0,
            ...openTabs.value
                .filter((tab) => tab.kind === 'query')
                .map((tab) => Number(tab.title)),
        ) + 1,
);

const tabs = computed(() => [
    {
        key: 'data',
        label: t('Daten'),
        href: dataHref(props.workspace, props.object.name),
    },
    {
        key: 'structure',
        label: t('Struktur'),
        href: structureHref(props.workspace, props.object.name),
    },
    // Sichten: Definition ansehen und ändern (S-06).
    ...(props.object.kind === 'view'
        ? [
              {
                  key: 'definition',
                  label: t('Definition'),
                  href: definitionHref(
                      props.workspace,
                      'view',
                      props.object.name,
                  ),
              },
          ]
        : []),
    {
        key: 'sql',
        label: t('SQL'),
        href: sqlRoute.url(props.workspace.connection.id, {
            query: {
                tab: nextQueryTab.value,
                ...(props.workspace.schema
                    ? { schema: props.workspace.schema }
                    : {}),
                ...(props.workspace.database
                    ? { db: props.workspace.database }
                    : {}),
                vorlage: `SELECT * FROM ${quoteName(props.object.name)} LIMIT 100;`,
            },
        }),
    },
]);

/** Name für die Vorlage im Editor, gequotet nach Treiber, wenn nötig. */
function quoteName(name: string): string {
    if (/^[a-z_][a-z0-9_]*$/.test(name)) {
        return name;
    }

    return ['mysql', 'mariadb'].includes(props.workspace.connection.driver)
        ? `\`${name.replaceAll('`', '``')}\``
        : `"${name.replaceAll('"', '""')}"`;
}
</script>

<template>
    <div class="flex flex-col gap-3 border-b px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1.5">
            <h1
                class="flex min-w-0 items-center gap-2 font-mono text-xl font-semibold"
            >
                <component
                    :is="object.kind === 'view' ? Eye : Table2"
                    class="size-5 shrink-0 text-text-secondary"
                    aria-hidden="true"
                />
                <span class="truncate">{{ object.name }}</span>
            </h1>
            <p class="hidden text-sm text-text-secondary sm:block">
                {{ facts.join(' · ') }}
            </p>
            <span
                class="rounded-sm border px-1.5 py-px text-xs font-medium text-text-secondary sm:hidden"
                >{{ workspace.connection.driverLabel }}
                {{ workspace.connection.serverVersion }}</span
            >
            <span
                v-if="workspace.connection.readOnly"
                class="inline-flex items-center gap-1 rounded-full border border-destructive bg-destructive-soft px-2 py-px text-xs font-medium text-destructive sm:hidden"
                ><Lock class="size-3" />{{ t('schreibgeschützt') }}</span
            >
            <div class="ml-auto flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <p
            v-if="object.comment"
            class="-mt-1 text-sm text-text-secondary italic"
        >
            {{ object.comment }}
        </p>
        <nav class="-mb-px flex gap-1" :aria-label="t('Ansichten des Objekts')">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                :class="[
                    'border-b-2 px-2.5 pb-2 text-[15px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    tab.key === active
                        ? 'border-primary font-medium text-primary'
                        : 'border-transparent text-text-secondary hover:text-foreground',
                ]"
                :aria-current="tab.key === active ? 'page' : undefined"
                preserve-scroll
            >
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
