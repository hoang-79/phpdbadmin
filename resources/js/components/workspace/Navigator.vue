<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    Clock,
    Database,
    Eye,
    FunctionSquare,
    Hash,
    Layers,
    Search,
    Table2,
    Zap,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, computed } from 'vue';
import { formatRows } from '@/lib/environment';
import { schema as schemaRoute } from '@/routes/workspace';
import type { NavigatorGroup, NavigatorItem, Workspace } from '@/types';

/**
 * Navigator (O-01, Wireframe 05): Verbindung → Datenbank → Schema (PostgreSQL) → Objektgruppen → Objekte, mit Filter.
 * Für alle vier Treiber dieselben Gruppen und Begriffe (O-04); was ein Treiber nicht kennt, steht mit Erklärung da.
 */
const props = defineProps<{
    workspace: Workspace;
    selected?: string | null;
    linkFor?: (item: NavigatorItem) => string | null;
}>();

const filter = ref('');
const input = ref<HTMLInputElement | null>(null);
const collapsed = ref<Record<string, boolean>>({
    views: true,
    routines: true,
    triggers: true,
    events: true,
    sequences: true,
});

const term = computed(() => filter.value.trim().toLowerCase());

const groups = computed<(NavigatorGroup & { visible: NavigatorItem[] })[]>(() =>
    props.workspace.groups.map((group) => ({
        ...group,
        visible: term.value
            ? group.items.filter((item) =>
                  item.name.toLowerCase().includes(term.value),
              )
            : group.items,
    })),
);

const icons: Record<string, unknown> = {
    table: Table2,
    view: Eye,
    function: FunctionSquare,
    procedure: FunctionSquare,
    trigger: Zap,
    event: Clock,
    sequence: Hash,
};

function isOpen(group: NavigatorGroup): boolean {
    return term.value !== '' || !collapsed.value[group.key];
}

function toggle(group: NavigatorGroup): void {
    collapsed.value[group.key] = !collapsed.value[group.key];
}

// «/» springt in den Filter (Wireframe 05).
function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        event.key === '/' &&
        !['INPUT', 'TEXTAREA'].includes(target?.tagName ?? '') &&
        !target?.isContentEditable
    ) {
        event.preventDefault();
        input.value?.focus();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <nav class="flex min-h-0 flex-1 flex-col" aria-label="Navigator">
        <div class="p-3">
            <label class="relative block">
                <span class="sr-only">Objekte filtern</span>
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    ref="input"
                    v-model="filter"
                    type="search"
                    placeholder="Objekte filtern"
                    class="h-9 w-full rounded-md border bg-background pr-8 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <kbd
                    class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border bg-muted px-1.5 font-mono text-[11px] text-muted-foreground"
                    >/</kbd
                >
            </label>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-3 text-sm">
            <div
                v-if="workspace.databaseLabel"
                class="flex items-center gap-2 px-2 py-1.5 font-medium"
            >
                <Database class="size-4 shrink-0 text-text-secondary" />
                <span class="truncate">{{ workspace.databaseLabel }}</span>
                <span
                    class="ml-auto shrink-0 text-xs font-normal text-muted-foreground"
                    >{{ workspace.connection.driverLabel }}
                    {{ workspace.connection.serverVersion }}</span
                >
            </div>

            <ul :class="workspace.databaseLabel ? 'pl-3' : ''">
                <li v-for="name in workspace.schemas" :key="name">
                    <Link
                        :href="schemaRoute([workspace.connection.id, name])"
                        :class="[
                            'flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-background',
                            name === workspace.schema && 'font-medium',
                        ]"
                        :aria-current="
                            name === workspace.schema ? 'page' : undefined
                        "
                    >
                        <component
                            :is="
                                name === workspace.schema
                                    ? ChevronDown
                                    : ChevronRight
                            "
                            class="size-3.5 shrink-0 text-text-secondary"
                        />
                        <component
                            :is="
                                workspace.schemaKind === 'schema'
                                    ? Layers
                                    : Database
                            "
                            class="size-4 shrink-0 text-text-secondary"
                        />
                        <span class="truncate">{{ name }}</span>
                        <span
                            v-if="
                                !workspace.databaseLabel &&
                                name === workspace.schema
                            "
                            class="ml-auto shrink-0 text-xs font-normal text-muted-foreground"
                            >{{ workspace.connection.driverLabel }}
                            {{ workspace.connection.serverVersion }}</span
                        >
                    </Link>

                    <ul v-if="name === workspace.schema" class="pl-4">
                        <li v-for="group in groups" :key="group.key">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-background"
                                :aria-expanded="isOpen(group)"
                                @click="toggle(group)"
                            >
                                <component
                                    :is="
                                        isOpen(group)
                                            ? ChevronDown
                                            : ChevronRight
                                    "
                                    class="size-3.5 shrink-0 text-text-secondary"
                                />
                                <span>{{ group.label }}</span>
                                <span
                                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        group.note
                                            ? '—'
                                            : term
                                              ? `${group.visible.length} / ${group.items.length}`
                                              : group.items.length
                                    }}</span
                                >
                            </button>

                            <template v-if="isOpen(group)">
                                <p
                                    v-if="group.note"
                                    class="px-2 py-1 pl-8 text-xs text-muted-foreground"
                                >
                                    {{ group.note }}
                                </p>
                                <ul v-else>
                                    <li
                                        v-for="item in group.visible"
                                        :key="`${item.kind}:${item.name}`"
                                    >
                                        <component
                                            :is="linkFor?.(item) ? Link : 'div'"
                                            :href="linkFor?.(item) ?? undefined"
                                            :class="[
                                                'flex items-center gap-2 rounded-md py-1.5 pr-2 pl-6',
                                                linkFor?.(item) &&
                                                    'hover:bg-background',
                                                selected === item.name &&
                                                    (item.kind === 'table' ||
                                                        item.kind === 'view') &&
                                                    'bg-accent font-medium shadow-[inset_3px_0_0_var(--primary)]',
                                            ]"
                                            :aria-current="
                                                selected === item.name
                                                    ? 'page'
                                                    : undefined
                                            "
                                        >
                                            <component
                                                :is="icons[item.kind] ?? Table2"
                                                class="size-4 shrink-0 text-text-secondary"
                                            />
                                            <span class="truncate">{{
                                                item.name
                                            }}</span>
                                            <span
                                                v-if="
                                                    item.rows !== null ||
                                                    item.detail
                                                "
                                                class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                                                >{{
                                                    item.rows !== null
                                                        ? formatRows(item.rows)
                                                        : item.detail
                                                }}</span
                                            >
                                        </component>
                                    </li>
                                    <li
                                        v-if="term && !group.visible.length"
                                        class="px-2 py-1 pl-8 text-xs text-muted-foreground"
                                    >
                                        nichts gefunden
                                    </li>
                                </ul>
                            </template>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
</template>
