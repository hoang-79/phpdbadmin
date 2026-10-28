<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Database } from '@lucide/vue';

/** Startseite = Verbindungsübersicht (A-04, Wireframe 03). Verbindungen anlegen folgt mit Scheibe 3. */
defineProps<{
    connections: unknown[];
}>();
</script>

<template>
    <Head title="Verbindungen" />

    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">Verbindungen</h1>
            <p class="text-text-secondary">
                Ihre Datenbanken auf einen Blick — ein Klick öffnet die
                Verbindung im Arbeitsbildschirm.
            </p>
        </div>

        <section
            class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center"
        >
            <span
                class="inline-grid size-12 place-items-center rounded-lg bg-muted text-text-secondary"
            >
                <Database class="size-6" />
            </span>
            <h2 class="font-semibold">Noch keine Verbindung</h2>
            <p class="max-w-md text-sm text-text-secondary">
                Hier erscheinen Ihre Datenbanken — MariaDB, MySQL, PostgreSQL
                und SQLite — gruppiert und mit ihrer Umgebung.
            </p>
        </section>
    </div>
</template>
