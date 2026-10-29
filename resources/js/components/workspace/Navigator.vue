<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    Clock,
    Database,
    Eye,
    FunctionSquare,
    Hash,
    Layers,
    Plus,
    Search,
    Shapes,
    Table2,
    Waypoints,
    Zap,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, computed, watch } from 'vue';
import NavigatorDropButton from '@/components/workspace/NavigatorDropButton.vue';
import DropDialog from '@/components/workspace/schema/DropDialog.vue';
import { formatRows } from '@/lib/environment';
import { t } from '@/lib/i18n';
import {
    definitionCreateHref,
    diagramHref,
    objectHref,
    schemaHref,
    structureBlock,
} from '@/lib/workspace';
import { workspace as workspaceRoute } from '@/routes';
import type {
    NavigatorGroup,
    NavigatorItem,
    ObjectKindKey,
    Workspace,
} from '@/types';

/**
 * Navigator (O-01, Wireframe 05): Verbindung → Datenbank → Schema (PostgreSQL) → Objektgruppen → Objekte, mit Filter.
 * Für alle vier Treiber dieselben Gruppen und Begriffe (O-04); was ein Treiber nicht kennt, steht mit Erklärung da.
 */
const props = defineProps<{
    workspace: Workspace;
    selected?: string | null;
}>();

const filter = ref('');
const input = ref<HTMLInputElement | null>(null);
const collapsed = ref<Record<string, boolean>>({
    views: true,
    routines: true,
    triggers: true,
    events: true,
    sequences: true,
    types: true,
});

// Detailseite eines Objekts (S-06): Art und Name kommen als Seiten-Prop `current`.
const page = usePage<{ current?: { kind: string; name: string } | null }>();

function isCurrent(item: NavigatorItem): boolean {
    const current = page.props.current;

    return current
        ? current.kind === item.kind && current.name === item.name
        : props.selected === item.name &&
              (item.kind === 'table' || item.kind === 'view');
}

// Neues Objekt je Gruppe (S-06) — nur, wer hier schreiben darf.
const createKinds: Record<string, ObjectKindKey> = {
    views: 'view',
    routines: 'function',
    triggers: 'trigger',
    events: 'event',
    sequences: 'sequence',
    types: 'type',
};
const mayCreate = computed(
    () =>
        props.workspace.connection.canWrite &&
        (!props.workspace.connection.readOnly ||
            props.workspace.connection.unlockedUntil !== null),
);

const term = computed(() => filter.value.trim().toLowerCase());

// PostgreSQL mit «alle Datenbanken»: übrige Datenbanken über und unter der geöffneten.
const currentIndex = computed(
    () =>
        props.workspace.databases?.indexOf(props.workspace.database ?? '') ??
        -1,
);
const databasesBefore = computed(() =>
    (props.workspace.databases ?? []).slice(0, Math.max(0, currentIndex.value)),
);
const databasesAfter = computed(() =>
    currentIndex.value < 0
        ? []
        : (props.workspace.databases ?? []).slice(currentIndex.value + 1),
);

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
    type: Shapes,
};

// Geöffnete Datenbank und geöffnetes Schema lassen sich zuklappen; nach einem Wechsel stehen sie wieder offen.
const databaseCollapsed = ref(false);
const schemaCollapsed = ref(false);
const databaseOpen = computed(
    () => term.value !== '' || !databaseCollapsed.value,
);
const schemaOpen = computed(() => term.value !== '' || !schemaCollapsed.value);

watch(
    () => props.workspace.database,
    () => (databaseCollapsed.value = false),
);
watch(
    () => props.workspace.schema,
    () => (schemaCollapsed.value = false),
);

// Datenbank bzw. Schema direkt hier löschen (S-05): Papierkorb beim Überfahren, Bestätigung mit dem Namen im Dialog.
const dropTarget = ref<{ name: string; scope: 'schema' | 'database' } | null>(
    null,
);
const dropOpen = ref(false);
const dropBlocked = computed(() => structureBlock(props.workspace.connection));

/** Ganze Datenbanken nur bei PostgreSQL mit «alle Datenbanken» — nie die Wartungsdatenbank, über die verbunden wird. */
function canDropDatabase(name: string): boolean {
    return (
        props.workspace.connection.canWrite &&
        props.workspace.databases !== null &&
        name !== 'postgres'
    );
}

/** Schemas (PostgreSQL) bzw. Datenbanken (MySQL, MariaDB — nur ohne festen Datenbanknamen); SQLite nie. */
const canDropSchemas = computed(() => {
    const { connection } = props.workspace;

    return (
        connection.canWrite &&
        (connection.driver === 'pgsql' ||
            (connection.driver !== 'sqlite' && !props.workspace.databaseFixed))
    );
});

function askDrop(name: string, scope: 'schema' | 'database'): void {
    dropTarget.value = { name, scope };
    dropOpen.value = true;
}

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
    <nav class="flex min-h-0 flex-1 flex-col" :aria-label="t('Navigator')">
        <div class="p-3">
            <label class="relative block">
                <span class="sr-only">{{ t('Objekte filtern') }}</span>
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    ref="input"
                    v-model="filter"
                    type="search"
                    :placeholder="t('Objekte filtern')"
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
                v-for="name in databasesBefore"
                :key="`db-${name}`"
                class="group flex items-center rounded-md hover:bg-background"
            >
                <Link
                    :href="
                        workspaceRoute(workspace.connection.id, {
                            query: { db: name },
                        })
                    "
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5"
                >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-text-secondary"
                    />
                    <Database class="size-4 shrink-0 text-text-secondary" />
                    <span class="truncate">{{ name }}</span>
                </Link>
                <NavigatorDropButton
                    v-if="canDropDatabase(name)"
                    :name="name"
                    :blocked="dropBlocked"
                    @drop="askDrop(name, 'database')"
                />
            </div>
            <div
                v-if="workspace.databaseLabel"
                class="group flex items-center rounded-md hover:bg-background"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left font-medium"
                    :aria-expanded="databaseOpen"
                    :aria-current="workspace.databases ? 'location' : undefined"
                    :title="databaseOpen ? t('Zuklappen') : t('Aufklappen')"
                    @click="databaseCollapsed = !databaseCollapsed"
                >
                    <component
                        :is="databaseOpen ? ChevronDown : ChevronRight"
                        class="size-3.5 shrink-0 text-text-secondary"
                    />
                    <Database class="size-4 shrink-0 text-text-secondary" />
                    <span class="truncate">{{ workspace.databaseLabel }}</span>
                    <span
                        class="ml-auto shrink-0 text-xs font-normal text-muted-foreground"
                        >{{ workspace.connection.driverLabel }}
                        {{ workspace.connection.serverVersion }}</span
                    >
                </button>
                <NavigatorDropButton
                    v-if="
                        workspace.database &&
                        canDropDatabase(workspace.database)
                    "
                    :name="workspace.database"
                    :blocked="dropBlocked"
                    @drop="askDrop(workspace.database, 'database')"
                />
            </div>

            <ul
                v-if="databaseOpen"
                :class="workspace.databaseLabel ? 'pl-3' : ''"
            >
                <li v-for="name in workspace.schemas" :key="name">
                    <div
                        v-if="name === workspace.schema"
                        class="group flex items-center rounded-md font-medium"
                    >
                        <!-- Der Pfeil klappt zu und auf, der Name führt zur Übersicht. -->
                        <button
                            type="button"
                            class="flex shrink-0 items-center self-stretch rounded-md pr-1.5 pl-2 text-text-secondary outline-none hover:bg-background focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-expanded="schemaOpen"
                            :aria-label="name"
                            :title="
                                schemaOpen ? t('Zuklappen') : t('Aufklappen')
                            "
                            @click="schemaCollapsed = !schemaCollapsed"
                        >
                            <component
                                :is="schemaOpen ? ChevronDown : ChevronRight"
                                class="size-3.5"
                            />
                        </button>
                        <Link
                            :href="schemaHref(workspace, name)"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded-md py-1.5 pr-2 pl-0.5 hover:bg-background"
                            aria-current="page"
                            @click="schemaCollapsed = false"
                        >
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
                                v-if="!workspace.databaseLabel"
                                class="ml-auto shrink-0 text-xs font-normal text-muted-foreground"
                                >{{ workspace.connection.driverLabel }}
                                {{ workspace.connection.serverVersion }}</span
                            >
                        </Link>
                        <NavigatorDropButton
                            v-if="canDropSchemas"
                            :name="name"
                            :blocked="dropBlocked"
                            @drop="askDrop(name, 'schema')"
                        />
                    </div>
                    <div
                        v-else
                        class="group flex items-center rounded-md hover:bg-background"
                    >
                        <Link
                            :href="schemaHref(workspace, name)"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5"
                        >
                            <ChevronRight
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
                        </Link>
                        <NavigatorDropButton
                            v-if="canDropSchemas"
                            :name="name"
                            :blocked="dropBlocked"
                            @drop="askDrop(name, 'schema')"
                        />
                    </div>

                    <ul
                        v-if="name === workspace.schema && schemaOpen"
                        class="pl-4"
                    >
                        <li>
                            <Link
                                :href="diagramHref(workspace)"
                                :class="[
                                    'flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-background',
                                    page.component === 'workspace/Diagram' &&
                                        'bg-accent font-medium shadow-[inset_3px_0_0_var(--primary)]',
                                ]"
                                :aria-current="
                                    page.component === 'workspace/Diagram'
                                        ? 'page'
                                        : undefined
                                "
                            >
                                <Waypoints
                                    class="size-4 shrink-0 text-text-secondary"
                                />
                                <span class="truncate">{{
                                    t('Beziehungsdiagramm')
                                }}</span>
                            </Link>
                        </li>
                        <li v-for="group in groups" :key="group.key">
                            <div class="flex items-center">
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-background"
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
                                <Link
                                    v-if="
                                        mayCreate &&
                                        !group.note &&
                                        createKinds[group.key]
                                    "
                                    :href="
                                        definitionCreateHref(
                                            workspace,
                                            createKinds[group.key],
                                        )
                                    "
                                    class="ml-0.5 inline-grid size-7 shrink-0 place-items-center rounded-md text-text-secondary outline-none hover:bg-background focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        t('Neu in «:group»', {
                                            group: group.label,
                                        })
                                    "
                                    :title="
                                        t('Neu in «:group»', {
                                            group: group.label,
                                        })
                                    "
                                >
                                    <Plus class="size-3.5" />
                                </Link>
                            </div>

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
                                            :is="
                                                objectHref(workspace, item)
                                                    ? Link
                                                    : 'div'
                                            "
                                            :href="
                                                objectHref(workspace, item) ??
                                                undefined
                                            "
                                            :class="[
                                                'flex items-center gap-2 rounded-md py-1.5 pr-2 pl-6',
                                                objectHref(workspace, item) &&
                                                    'hover:bg-background',
                                                isCurrent(item) &&
                                                    'bg-accent font-medium shadow-[inset_3px_0_0_var(--primary)]',
                                            ]"
                                            :aria-current="
                                                isCurrent(item)
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
                                        {{ t('nichts gefunden') }}
                                    </li>
                                </ul>
                            </template>
                        </li>
                    </ul>
                </li>
            </ul>

            <div
                v-for="name in databasesAfter"
                :key="`db-${name}`"
                class="group flex items-center rounded-md hover:bg-background"
            >
                <Link
                    :href="
                        workspaceRoute(workspace.connection.id, {
                            query: { db: name },
                        })
                    "
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5"
                >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-text-secondary"
                    />
                    <Database class="size-4 shrink-0 text-text-secondary" />
                    <span class="truncate">{{ name }}</span>
                </Link>
                <NavigatorDropButton
                    v-if="canDropDatabase(name)"
                    :name="name"
                    :blocked="dropBlocked"
                    @drop="askDrop(name, 'database')"
                />
            </div>
        </div>

        <DropDialog
            v-if="dropTarget"
            v-model:open="dropOpen"
            :workspace="workspace"
            :name="dropTarget.name"
            :scope="dropTarget.scope"
        />
    </nav>
</template>
