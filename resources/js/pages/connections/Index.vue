<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Clock,
    Database,
    Folder,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
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
import { create, destroy, edit } from '@/routes/connections';
import type { ConnectionEnvironment, ConnectionSummary } from '@/types';

/** Startseite = Verbindungsübersicht (A-04, Wireframe 03): nach Gruppe, Umgebung sichtbar. */
const props = defineProps<{
    connections: ConnectionSummary[];
}>();

const search = ref('');
const environmentFilter = ref<ConnectionEnvironment | null>(null);
const removing = ref<ConnectionSummary | null>(null);
const isRemoving = ref(false);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.connections.filter(
        (connection) =>
            (environmentFilter.value === null ||
                connection.environment === environmentFilter.value) &&
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

const groups = computed(() => {
    const map = new Map<string, ConnectionSummary[]>();

    for (const connection of filtered.value) {
        const key = connection.group ?? 'Ohne Gruppe';
        map.set(key, [...(map.get(key) ?? []), connection]);
    }

    return [...map.entries()];
});

const productionCount = computed(
    () =>
        props.connections.filter((c) => c.environment === 'production').length,
);

const groupCount = computed(
    () => new Set(props.connections.map((c) => c.group ?? '')).size,
);

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
    <Head title="Verbindungen" />

    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">Verbindungen</h1>
                <p class="text-text-secondary">
                    <template v-if="connections.length">
                        {{ connections.length }}
                        {{
                            connections.length === 1
                                ? 'Verbindung'
                                : 'Verbindungen'
                        }}
                        in {{ groupCount }}
                        {{ groupCount === 1 ? 'Gruppe' : 'Gruppen' }}
                    </template>
                    <template v-else
                        >Ihre Datenbanken auf einen Blick.</template
                    >
                </p>
            </div>
            <Button class="w-full sm:ml-auto sm:w-auto" as-child>
                <Link :href="create()"><Plus />Verbindung anlegen</Link>
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
                        placeholder="Verbindungen suchen"
                        aria-label="Verbindungen suchen"
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
                    :aria-pressed="environmentFilter === null"
                    @click="environmentFilter = null"
                >
                    Alle {{ connections.length }}
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
            </div>

            <section v-for="[group, items] in groups" :key="group">
                <h2
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
                            'flex min-w-0 flex-col gap-2 rounded-lg border bg-background px-4 py-3.5',
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
                                ><Lock class="size-3" />schreibgeschützt</span
                            >
                            <DropdownMenu v-if="connection.canManage">
                                <DropdownMenuTrigger
                                    class="ml-auto inline-grid size-7 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="`Aktionen für ${connection.name}`"
                                >
                                    <MoreHorizontal class="size-4" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="edit(connection.id)"
                                            class="gap-2"
                                            ><Pencil
                                                class="size-4"
                                            />Bearbeiten</Link
                                        >
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="gap-2 text-destructive"
                                        @select="removing = connection"
                                    >
                                        <Trash2 class="size-4" />Entfernen
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="font-mono text-base font-semibold wrap-break-word"
                                >{{ connection.name }}</span
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
                                <template v-if="connection.database"
                                    >Datenbank
                                    {{ connection.database }}</template
                                >
                                <template v-if="connection.username">
                                    <template v-if="connection.database">
                                        ·
                                    </template>
                                    Konto
                                    <code>{{ connection.username }}</code>
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
                                connection.lastTestedAt
                                    ? `zuletzt getestet ${formatRecent(connection.lastTestedAt)}`
                                    : 'noch nicht getestet'
                            }}
                        </div>
                    </article>
                </div>
            </section>

            <p v-if="!filtered.length" class="text-sm text-text-secondary">
                Keine Verbindung passt zur Suche.
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
            <h2 class="font-semibold">Noch keine Verbindung</h2>
            <p class="max-w-md text-sm text-text-secondary">
                Legen Sie Ihre erste Verbindung an — MariaDB, MySQL, PostgreSQL
                oder eine SQLite-Datei.
            </p>
        </section>

        <Dialog
            :open="removing !== null"
            @update:open="(open) => !open && (removing = null)"
        >
            <DialogContent v-if="removing">
                <DialogTitle
                    >Verbindung «{{ removing.name }}» entfernen?</DialogTitle
                >
                <DialogDescription>
                    Entfernt nur den Eintrag in phpdbadmin mit gespeichertem
                    Passwort. Die Datenbank selbst bleibt unberührt.
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline"
                            >Abbrechen</Button
                        >
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="isRemoving"
                        @click="remove(removing)"
                    >
                        <Spinner v-if="isRemoving" />Entfernen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
