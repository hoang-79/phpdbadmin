<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Eye, Plus, Table2, TerminalSquare, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import { useOpenTabs } from '@/composables/useOpenTabs';
import { t } from '@/lib/i18n';
import { schemaHref } from '@/lib/workspace';
import { sql as sqlRoute } from '@/routes/workspace';
import type { Workspace } from '@/types';

/**
 * Reiter über dem Inhalt (O-03, Wireframe 05). Der Reiter des gewählten Objekts kommt beim Öffnen dazu und merkt
 * sich die Adresse samt Filtern; Schliessen wechselt zum Nachbarn.
 */
const props = defineProps<{ workspace: Workspace }>();

const page = usePage<{
    selected?: string | null;
    queryTab?: number | null;
    object?: { kind: 'table' | 'view' } | null;
    structure?: { object: { kind: 'table' | 'view' } } | null;
}>();
const { tabs, open, close } = useOpenTabs(props.workspace.connection.id);

function keyOf(object: string): string {
    return [
        props.workspace.database ?? '',
        props.workspace.schema ?? '',
        object,
    ].join('\u0000');
}

const activeKey = computed(() =>
    page.props.queryTab
        ? `query:${page.props.queryTab}`
        : page.props.selected
          ? keyOf(page.props.selected)
          : null,
);

watch(
    () => page.url,
    () => {
        const selected = page.props.selected;

        if (page.props.queryTab) {
            open({
                key: `query:${page.props.queryTab}`,
                title: String(page.props.queryTab),
                kind: 'query',
                href: page.url,
            });

            return;
        }

        if (!selected) {
            return;
        }

        open({
            key: keyOf(selected),
            title: selected,
            kind:
                (page.props.object?.kind ??
                    page.props.structure?.object.kind) === 'view'
                    ? 'view'
                    : 'table',
            href: page.url,
        });
    },
    { immediate: true },
);

function closeTab(key: string): void {
    const neighbour = close(key);

    if (key === activeKey.value) {
        router.visit(
            neighbour?.href ??
                schemaHref(props.workspace, props.workspace.schema ?? ''),
        );
    }
}

const icons = { table: Table2, view: Eye, query: TerminalSquare };

function newQuery(): void {
    const used = tabs.value
        .filter((tab) => tab.kind === 'query')
        .map((tab) => Number(tab.title));
    const next = Math.max(0, ...used) + 1;

    router.visit(
        sqlRoute.url(props.workspace.connection.id, {
            query: {
                tab: next,
                ...(props.workspace.schema
                    ? { schema: props.workspace.schema }
                    : {}),
                ...(props.workspace.database
                    ? { db: props.workspace.database }
                    : {}),
            },
        }),
    );
}
</script>

<template>
    <nav
        class="flex shrink-0 overflow-x-auto border-b bg-muted/40"
        :aria-label="t('Offene Reiter')"
    >
        <div
            v-for="tab in tabs"
            :key="tab.key"
            :class="[
                'group flex shrink-0 items-center border-r',
                tab.key === activeKey
                    ? 'bg-background shadow-[inset_0_-2px_0_var(--primary)]'
                    : 'hover:bg-background/60',
            ]"
        >
            <Link
                :href="tab.href"
                class="flex h-10 max-w-56 items-center gap-2 pr-1 pl-3 font-mono text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                :aria-current="tab.key === activeKey ? 'page' : undefined"
                preserve-scroll
            >
                <component
                    :is="icons[tab.kind]"
                    class="size-3.5 shrink-0 text-text-secondary"
                />
                <span class="truncate">{{
                    tab.kind === 'query'
                        ? t('Abfrage :number', { number: tab.title })
                        : tab.title
                }}</span>
            </Link>
            <button
                type="button"
                class="mr-1.5 inline-grid size-6 shrink-0 place-items-center rounded-sm text-text-secondary opacity-60 outline-none group-hover:opacity-100 hover:bg-muted focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="
                    t('Reiter :name schliessen', {
                        name:
                            tab.kind === 'query'
                                ? t('Abfrage :number', { number: tab.title })
                                : tab.title,
                    })
                "
                @click="closeTab(tab.key)"
            >
                <X class="size-3.5" />
            </button>
        </div>
        <button
            type="button"
            class="inline-grid h-10 w-10 shrink-0 place-items-center text-text-secondary outline-none hover:bg-background/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            :aria-label="t('Neue Abfrage')"
            :title="t('Neue Abfrage')"
            @click="newQuery"
        >
            <Plus class="size-4" />
        </button>
    </nav>
</template>
