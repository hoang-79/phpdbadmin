<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Clock,
    Database,
    Folder,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Star,
    Trash2,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
import ShareDialog from '@/components/connections/ShareDialog.vue';
import Interpolate from '@/components/Interpolate.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatRecent } from '@/lib/format';
import { intlLocale, t, tc } from '@/lib/i18n';
import { workspace } from '@/routes';
import { create, destroy, edit, favorite } from '@/routes/connections';
import type {
    ConnectionEnvironment,
    ConnectionPermission,
    ConnectionSummary,
} from '@/types';

/**
 * Startseite = Verbindungsübersicht (A-04, Wireframe 03): nach Gruppe, Umgebung sichtbar; Favoriten oben, Sortierung
 * nach Gruppe, Name oder «zuletzt geöffnet» (V-06) — die Wahl merkt sich der Browser.
 */
const props = defineProps<{
    connections: ConnectionSummary[];
}>();

const search = ref('');
const environmentFilter = ref<ConnectionEnvironment | null>(null);
const removing = ref<ConnectionSummary | null>(null);
const sharing = ref<ConnectionSummary | null>(null);
const isRemoving = ref(false);
const onlyFavorites = ref(false);

type SortMode = 'group' | 'name' | 'recent';

const sortStorageKey = 'phpdbadmin.verbindungen.sortierung';
const sortMode = ref<SortMode>(storedSortMode());

watch(sortMode, (mode) => window.localStorage.setItem(sortStorageKey, mode));

function storedSortMode(): SortMode {
    const stored =
        typeof window === 'undefined'
            ? null
            : window.localStorage.getItem(sortStorageKey);

    return stored === 'name' || stored === 'recent' ? stored : 'group';
}

const sortOptions = computed<{ value: SortMode; label: string }[]>(() => [
    { value: 'group', label: t('Nach Gruppe, dann zuletzt geöffnet') },
    { value: 'name', label: t('Nach Name') },
    { value: 'recent', label: t('Zuletzt geöffnet zuerst') },
]);

const collator = computed(
    () =>
        new Intl.Collator(intlLocale(), { numeric: true, sensitivity: 'base' }),
);

/** Favoriten oben; danach je nach Wahl zuletzt geöffnet oder Name. */
function compare(a: ConnectionSummary, b: ConnectionSummary): number {
    if (a.favorite !== b.favorite) {
        return a.favorite ? -1 : 1;
    }

    if (sortMode.value !== 'name') {
        const opened =
            (b.lastOpenedAt ? Date.parse(b.lastOpenedAt) : 0) -
            (a.lastOpenedAt ? Date.parse(a.lastOpenedAt) : 0);

        if (opened !== 0) {
            return opened;
        }
    }

    return collator.value.compare(a.name, b.name);
}

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.connections.filter(
        (connection) =>
            (environmentFilter.value === null ||
                connection.environment === environmentFilter.value) &&
            (!onlyFavorites.value || connection.favorite) &&
            (term === '' ||
                [
                    connection.name,
                    connection.group,
                    connection.location,
                    connection.database,
                    connection.driverLabel,
                ].some((value) => value?.toLowerCase().includes(term))),
    );
});

/** Abschnitte: nach Gruppe (Reihenfolge vom Server) oder eine Liste ohne Überschrift. */
const groups = computed<[string | null, ConnectionSummary[]][]>(() => {
    if (sortMode.value !== 'group') {
        return filtered.value.length
            ? [[null, [...filtered.value].sort(compare)]]
            : [];
    }

    const map = new Map<string, ConnectionSummary[]>();

    for (const connection of filtered.value) {
        const key = connection.group ?? t('Ohne Gruppe');
        map.set(key, [...(map.get(key) ?? []), connection]);
    }

    return [...map.entries()].map(([group, items]) => [
        group,
        [...items].sort(compare),
    ]);
});

const favoriteCount = computed(
    () => props.connections.filter((c) => c.favorite).length,
);

function toggleFavorite(connection: ConnectionSummary): void {
    router.put(
        favorite.url(connection.id),
        { favorite: !connection.favorite },
        { preserveScroll: true, preserveState: true },
    );
}

const productionCount = computed(
    () =>
        props.connections.filter((c) => c.environment === 'production').length,
);

const groupCount = computed(
    () => new Set(props.connections.map((c) => c.group ?? '')).size,
);

function permissionLabel(permission: ConnectionPermission): string {
    return {
        read: t('lesen'),
        write: t('schreiben'),
        manage: t('verwalten'),
    }[permission];
}

function remove(connection: ConnectionSummary): void {
    isRemoving.value = true;
    router.delete(destroy.url(connection.id), {
        onSuccess: () => {
            removing.value = null;
        },
        onFinish: () => {
            isRemoving.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Verbindungen')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">{{ t('Verbindungen') }}</h1>
                <p class="text-text-secondary">
                    <template v-if="connections.length">
                        {{
                            tc(
                                ':count Verbindung in :groups|:count Verbindungen in :groups',
                                connections.length,
                                {
                                    groups: tc(
                                        ':count Gruppe|:count Gruppen',
                                        groupCount,
                                    ),
                                },
                            )
                        }}
                    </template>
                    <template v-else>{{
                        t('Ihre Datenbanken auf einen Blick.')
                    }}</template>
                </p>
            </div>
            <Button class="w-full sm:ml-auto sm:w-auto" as-child>
                <Link :href="create()"
                    ><Plus />{{ t('Verbindung anlegen') }}</Link
                >
            </Button>
        </div>

        <template v-if="connections.length">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('Verbindungen suchen')"
                        :aria-label="t('Verbindungen suchen')"
                        class="pl-8"
                    />
                </div>
                <button
                    type="button"
                    :class="[
                        'h-7 rounded-full border px-2.5 text-[13px]',
                        environmentFilter === null
                            ? 'border-primary bg-accent font-semibold'
                            : 'bg-muted',
                    ]"
                    :aria-pressed="environmentFilter === null && !onlyFavorites"
                    @click="
                        environmentFilter = null;
                        onlyFavorites = false;
                    "
                >
                    {{ t('Alle :count', { count: connections.length }) }}
                </button>
                <button
                    v-if="favoriteCount"
                    type="button"
                    :class="[
                        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[13px]',
                        onlyFavorites
                            ? 'border-primary bg-accent font-semibold'
                            : 'bg-muted',
                    ]"
                    :aria-pressed="onlyFavorites"
                    @click="onlyFavorites = !onlyFavorites"
                >
                    <Star class="size-3.5" />
                    {{ t('Favoriten :count', { count: favoriteCount }) }}
                </button>
                <button
                    v-if="productionCount"
                    type="button"
                    :class="[
                        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[13px]',
                        environmentFilter === 'production'
                            ? 'border-primary bg-accent font-semibold'
                            : 'bg-muted',
                    ]"
                    :aria-pressed="environmentFilter === 'production'"
                    @click="environmentFilter = 'production'"
                >
                    <EnvironmentMark environment="production" />
                    {{ productionCount }}
                </button>
                <label
                    class="inline-flex items-center gap-1.5 text-[13px] text-text-secondary sm:ml-auto"
                >
                    <ArrowUpDown class="size-3.5" />
                    <span class="sr-only">{{ t('Sortierung') }}</span>
                    <select
                        v-model="sortMode"
                        class="rounded-md bg-transparent py-1 pr-1 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option
                            v-for="option in sortOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
            </div>

            <section v-for="[group, items] in groups" :key="group ?? ''">
                <h2
                    v-if="group !== null"
                    class="mb-2.5 flex items-center gap-2 text-[13px] font-semibold text-text-secondary"
                >
                    <Folder class="size-3.5" />{{ group }}
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ items.length }}</span
                    >
                </h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:gap-4">
                    <article
                        v-for="connection in items"
                        :key="connection.id"
                        :class="[
                            'relative flex min-w-0 flex-col gap-2 rounded-lg border bg-background px-4 py-3.5 focus-within:ring-2 focus-within:ring-ring hover:border-text-secondary',
                            connection.environment === 'production' &&
                                'border-env-production shadow-[inset_0_3px_0_var(--env-production)]',
                            connection.environment === 'test' &&
                                'shadow-[inset_0_3px_0_var(--env-test)]',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <EnvironmentMark
                                :environment="connection.environment"
                            />
                            <span
                                v-if="connection.readOnly"
                                class="inline-flex items-center gap-1 rounded-full border border-destructive bg-destructive-soft px-2 py-px text-xs font-medium text-destructive"
                                ><Lock class="size-3" />{{
                                    t('schreibgeschützt')
                                }}</span
                            >
                            <span
                                v-if="connection.shared"
                                class="inline-flex items-center gap-1 rounded-full border px-2 py-px text-xs text-text-secondary"
                                :title="
                                    connection.isOwner || !connection.permission
                                        ? undefined
                                        : t('Ihr Recht: :permission', {
                                              permission: permissionLabel(
                                                  connection.permission,
                                              ),
                                          })
                                "
                                ><Users class="size-3" />{{
                                    connection.isOwner || !connection.permission
                                        ? t('geteilt')
                                        : t('geteilt · :permission', {
                                              permission: permissionLabel(
                                                  connection.permission,
                                              ),
                                          })
                                }}</span
                            >
                            <button
                                type="button"
                                class="relative z-10 ml-auto inline-grid size-7 shrink-0 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-pressed="connection.favorite"
                                :aria-label="
                                    connection.favorite
                                        ? t('«:name» ist Favorit — entfernen', {
                                              name: connection.name,
                                          })
                                        : t('«:name» als Favorit markieren', {
                                              name: connection.name,
                                          })
                                "
                                @click="toggleFavorite(connection)"
                            >
                                <Star
                                    :class="[
                                        'size-4',
                                        connection.favorite &&
                                            'fill-current text-warning',
                                    ]"
                                />
                            </button>
                            <DropdownMenu v-if="connection.canManage">
                                <DropdownMenuTrigger
                                    class="relative z-10 inline-grid size-7 shrink-0 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        t('Aktionen für :name', {
                                            name: connection.name,
                                        })
                                    "
                                >
                                    <MoreHorizontal class="size-4" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="edit(connection.id)"
                                            class="gap-2"
                                            ><Pencil class="size-4" />{{
                                                t('Bearbeiten')
                                            }}</Link
                                        >
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="connection.canShare"
                                        class="gap-2"
                                        @select="sharing = connection"
                                    >
                                        <Users class="size-4" />{{
                                            t('Teilen …')
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="gap-2 text-destructive"
                                        @select="removing = connection"
                                    >
                                        <Trash2 class="size-4" />{{
                                            t('Entfernen')
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="workspace(connection.id)"
                                class="font-mono text-base font-semibold wrap-break-word after:absolute after:inset-0 after:rounded-lg hover:underline focus-visible:outline-none"
                                >{{ connection.name }}</Link
                            >
                            <span
                                class="rounded-sm border px-1.5 py-px text-xs font-medium whitespace-nowrap text-text-secondary"
                                >{{ connection.driverLabel }}
                                {{ connection.serverVersion }}</span
                            >
                        </div>
                        <div
                            class="text-[13px] leading-[18px] text-text-secondary"
                        >
                            <span class="font-mono text-xs wrap-break-word">{{
                                connection.location
                            }}</span>
                            <template
                                v-if="
                                    connection.database || connection.username
                                "
                            >
                                <br />
                                <template v-if="connection.database">{{
                                    t('Datenbank :name', {
                                        name: connection.database,
                                    })
                                }}</template>
                                <template v-if="connection.username">
                                    <template v-if="connection.database">
                                        ·
                                    </template>
                                    <Interpolate :text="t('Konto :username')">
                                        <template #username
                                            ><code>{{
                                                connection.username
                                            }}</code></template
                                        >
                                    </Interpolate>
                                </template>
                            </template>
                        </div>
                        <div
                            v-for="warning in connection.accountWarnings"
                            :key="warning.key"
                            class="flex items-start gap-2 rounded-md border border-warning px-2.5 py-2 text-[13px] leading-[18px]"
                        >
                            <TriangleAlert
                                class="mt-px size-4 shrink-0 text-warning"
                            />
                            <span class="flex flex-col gap-1">
                                <b class="font-semibold">{{ warning.title }}</b>
                                <span class="text-text-secondary">{{
                                    warning.recommendation
                                }}</span>
                            </span>
                        </div>
                        <div
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Clock class="size-3.5" />
                            {{
                                connection.lastOpenedAt
                                    ? t('zuletzt geöffnet :time', {
                                          time: formatRecent(
                                              connection.lastOpenedAt,
                                          ),
                                      })
                                    : connection.lastTestedAt
                                      ? t('zuletzt getestet :time', {
                                            time: formatRecent(
                                                connection.lastTestedAt,
                                            ),
                                        })
                                      : t('noch nicht getestet')
                            }}
                        </div>
                    </article>
                </div>
            </section>

            <p v-if="!filtered.length" class="text-sm text-text-secondary">
                {{ t('Keine Verbindung passt zur Suche.') }}
            </p>
        </template>

        <section
            v-else
            class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center"
        >
            <span
                class="inline-grid size-12 place-items-center rounded-lg bg-muted text-text-secondary"
            >
                <Database class="size-6" />
            </span>
            <h2 class="font-semibold">{{ t('Noch keine Verbindung') }}</h2>
            <p class="max-w-md text-sm text-text-secondary">
                {{
                    t(
                        'Legen Sie Ihre erste Verbindung an — MariaDB, MySQL, PostgreSQL oder eine SQLite-Datei.',
                    )
                }}
            </p>
        </section>

        <ShareDialog :connection="sharing" @close="sharing = null" />

        <Dialog
            :open="removing !== null"
            @update:open="(open) => !open && (removing = null)"
        >
            <DialogContent v-if="removing">
                <DialogTitle>{{
                    t('Verbindung «:name» entfernen?', { name: removing.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Entfernt nur den Eintrag in phpdbadmin mit gespeichertem Passwort. Die Datenbank selbst bleibt unberührt.',
                        )
                    }}
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">{{
                            t('Abbrechen')
                        }}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="isRemoving"
                        @click="remove(removing)"
                    >
                        <Spinner v-if="isRemoving" />{{ t('Entfernen') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
