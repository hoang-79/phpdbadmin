<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Keyboard, LockOpen, PanelLeft, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import HelpLink from '@/components/HelpLink.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import Wordmark from '@/components/Wordmark.vue';
import CommandPalette from '@/components/workspace/CommandPalette.vue';
import ConnectionChip from '@/components/workspace/ConnectionChip.vue';
import Navigator from '@/components/workspace/Navigator.vue';
import ShortcutsDialog from '@/components/workspace/ShortcutsDialog.vue';
import TabBar from '@/components/workspace/TabBar.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
import { unlock as unlockRoute } from '@/routes/workspace';
import { destroy as lockRoute } from '@/routes/workspace/unlock';
import type { Workspace } from '@/types';

/**
 * Arbeitsbildschirm (Wireframe 05): Kopf mit Verbindung und Umgebung, Navigator links; auf dem Telefon als Schublade.
 * Produktion trägt oben einen roten Streifen (V-04, G-03). Das gewählte Objekt kommt als Seiten-Prop `selected`.
 */
const page = usePage<{ workspace?: Workspace; selected?: string | null }>();
const workspace = computed(() => page.props.workspace ?? null);
const selected = computed(() => page.props.selected ?? null);
const navigatorOpen = ref(false);
const shortcutsOpen = ref(false);
const paletteOpen = ref(false);
const unlockOpen = ref(false);
const unlockReason = ref('');
const unlockError = ref<string | null>(null);

function unlock(): void {
    if (!workspace.value) {
        return;
    }

    router.post(
        unlockRoute.url(workspace.value.connection.id),
        { reason: unlockReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                unlockOpen.value = false;
                unlockReason.value = '';
                unlockError.value = null;
            },
            onError: (errors) => {
                unlockError.value = errors.reason ?? null;
            },
        },
    );
}

function lockAgain(): void {
    if (workspace.value) {
        router.delete(lockRoute.url(workspace.value.connection.id), {
            preserveScroll: true,
        });
    }
}
const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);

// «?» öffnet die Kürzel-Übersicht (O-05) — nicht, während man tippt.
function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    // ⌘K / Ctrl+K öffnet die Befehlspalette (O-02) — auch aus dem Editor heraus.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value = true;

        return;
    }

    if (
        event.key === '?' &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '') &&
        !target?.isContentEditable
    ) {
        event.preventDefault();
        shortcutsOpen.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

// Nach jedem Seitenwechsel die Schublade schliessen.
watch(
    () => page.url,
    () => {
        navigatorOpen.value = false;
    },
);
</script>

<template>
    <div class="flex h-svh flex-col bg-background">
        <div
            v-if="workspace?.connection.environment === 'production'"
            class="h-0.5 shrink-0 bg-env-production"
            aria-hidden="true"
        />
        <header
            class="flex h-[52px] shrink-0 items-center gap-2 border-b px-3 sm:gap-3 sm:px-4"
        >
            <button
                v-if="workspace"
                type="button"
                class="inline-grid size-9 place-items-center rounded-md border text-text-secondary lg:hidden"
                :aria-label="t('Navigator öffnen')"
                @click="navigatorOpen = true"
            >
                <PanelLeft class="size-4" />
            </button>
            <Link
                :href="home()"
                class="hidden rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring sm:block"
                :aria-label="t('Verbindungen')"
            >
                <Wordmark />
            </Link>
            <!-- Das Dropdown rendert kein eigenes Element — die Klassen stehen deshalb aussen. -->
            <div v-if="workspace" class="min-w-0 sm:hidden">
                <ConnectionChip
                    :connection="workspace.connection"
                    compact
                    @unlock="unlockOpen = true"
                />
            </div>
            <div v-if="workspace" class="hidden min-w-0 sm:block">
                <ConnectionChip
                    :connection="workspace.connection"
                    @unlock="unlockOpen = true"
                />
            </div>
            <span
                v-if="workspace?.connection.unlockedUntil"
                class="hidden items-center gap-1.5 rounded-full border border-dashed border-destructive px-2.5 py-1 text-[13px] text-destructive lg:inline-flex"
                role="status"
            >
                <LockOpen class="size-3.5" />{{
                    t('Schreiben erlaubt bis :time', {
                        time: formatTime(workspace.connection.unlockedUntil),
                    })
                }}
                ·
                <button
                    type="button"
                    class="font-medium underline"
                    @click="lockAgain"
                >
                    {{ t('wieder sperren') }}
                </button>
            </span>
            <div class="flex flex-1 justify-center">
                <button
                    type="button"
                    class="hidden h-9 w-full max-w-md items-center gap-2 rounded-md border bg-muted/50 px-3 text-sm text-muted-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring md:flex"
                    @click="paletteOpen = true"
                >
                    <Search class="size-4" />
                    <span class="truncate">{{
                        t('Tabelle, Abfrage oder Befehl suchen …')
                    }}</span>
                    <kbd
                        class="ml-auto rounded border bg-background px-1.5 font-mono text-[11px]"
                        >{{ isMac ? '⌘K' : 'Ctrl K' }}</kbd
                    >
                </button>
            </div>
            <button
                type="button"
                class="inline-grid size-9 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring md:hidden"
                :aria-label="t('Befehlspalette')"
                @click="paletteOpen = true"
            >
                <Search class="size-4" />
            </button>
            <button
                type="button"
                class="hidden size-9 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring sm:inline-grid"
                :aria-label="t('Tastenkürzel')"
                :title="t('Tastenkürzel (?)')"
                @click="shortcutsOpen = true"
            >
                <Keyboard class="size-4" />
            </button>
            <AccountMenu />
        </header>

        <div class="flex min-h-0 flex-1">
            <aside
                v-if="workspace"
                class="hidden w-64 shrink-0 flex-col border-r bg-muted lg:flex"
            >
                <Navigator :workspace="workspace" :selected="selected" />
            </aside>
            <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <TabBar v-if="workspace" :workspace="workspace" />
                <div class="min-h-0 flex-1 overflow-auto">
                    <slot />
                </div>
            </main>
        </div>

        <Sheet v-if="workspace" v-model:open="navigatorOpen">
            <SheetContent
                side="left"
                class="flex w-[300px] flex-col gap-0 bg-muted p-0"
            >
                <SheetTitle class="sr-only">{{ t('Navigator') }}</SheetTitle>
                <SheetDescription class="sr-only">{{
                    t('Datenbanken, Schemas und Objekte dieser Verbindung')
                }}</SheetDescription>
                <div class="border-b p-3 pr-10">
                    <ConnectionChip :connection="workspace.connection" />
                </div>
                <Navigator :workspace="workspace" :selected="selected" />
            </SheetContent>
        </Sheet>

        <ShortcutsDialog v-model:open="shortcutsOpen" />
        <Dialog v-if="workspace" v-model:open="unlockOpen">
            <DialogContent class="sm:max-w-md">
                <DialogTitle>{{
                    t('Schreibschutz auf Zeit aufheben')
                }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        '«:name» wird für 15 Minuten beschreibbar — nur für Sie, mit Grund im Protokoll. Danach gilt der Schutz wieder.',
                        {
                            name: workspace.connection.name,
                        },
                    )
                }}</DialogDescription>
                <form class="flex flex-col gap-3" @submit.prevent="unlock">
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium">{{ t('Grund') }}</span>
                        <input
                            v-model="unlockReason"
                            v-focus
                            type="text"
                            maxlength="500"
                            :placeholder="
                                t('z. B. Tippfehler im Kundennamen korrigieren')
                            "
                            class="h-9 rounded-md border bg-background px-2"
                        />
                        <InputError :message="unlockError ?? undefined" />
                    </label>
                    <DialogFooter class="items-center gap-2">
                        <HelpLink anchor="schreibschutz" class="sm:mr-auto" />
                        <Button
                            type="button"
                            variant="outline"
                            @click="unlockOpen = false"
                            >{{ t('Abbrechen') }}</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="unlockReason.trim().length < 3"
                            >{{ t('Für 15 Minuten freigeben') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <CommandPalette
            v-model:open="paletteOpen"
            :workspace="workspace"
            @shortcuts="shortcutsOpen = true"
        />
        <Toaster position="bottom-right" />
    </div>
</template>
