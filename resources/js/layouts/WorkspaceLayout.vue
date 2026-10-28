<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { PanelLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import Wordmark from '@/components/Wordmark.vue';
import ConnectionChip from '@/components/workspace/ConnectionChip.vue';
import Navigator from '@/components/workspace/Navigator.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
import type { Workspace } from '@/types';

/**
 * Arbeitsbildschirm (Wireframe 05): Kopf mit Verbindung und Umgebung, Navigator links; auf dem Telefon als Schublade.
 * Produktion trägt oben einen roten Streifen (V-04, G-03). Das gewählte Objekt kommt als Seiten-Prop `selected`.
 */
const page = usePage<{ workspace?: Workspace; selected?: string | null }>();
const workspace = computed(() => page.props.workspace ?? null);
const selected = computed(() => page.props.selected ?? null);
const navigatorOpen = ref(false);

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
                <ConnectionChip :connection="workspace.connection" compact />
            </div>
            <div v-if="workspace" class="hidden min-w-0 sm:block">
                <ConnectionChip :connection="workspace.connection" />
            </div>
            <div class="flex-1" />
            <AccountMenu />
        </header>

        <div class="flex min-h-0 flex-1">
            <aside
                v-if="workspace"
                class="hidden w-64 shrink-0 flex-col border-r bg-muted lg:flex"
            >
                <Navigator :workspace="workspace" :selected="selected" />
            </aside>
            <main class="min-w-0 flex-1 overflow-auto">
                <slot />
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

        <Toaster position="bottom-right" />
    </div>
</template>
