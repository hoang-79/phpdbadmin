<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { ChevronDown, CircleX, Pencil, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { edit } from '@/routes/connections';
import type { ConnectionProblemView } from '@/types';

/** Verbindung scheitert beim Öffnen: Fehler in Klartext statt Fehlerseite (V-02, O-09). */
defineProps<{
    connection: { id: string; name: string; canManage: boolean };
    problem: ConnectionProblemView;
}>();

setLayoutProps({ back: { href: home.url(), label: 'Alle Verbindungen' } });
</script>

<template>
    <Head :title="`Nicht erreichbar · ${connection.name}`" />

    <div class="flex flex-col gap-1">
        <h1 class="font-mono text-xl font-semibold">{{ connection.name }}</h1>
        <p class="text-text-secondary">
            Die Verbindung lässt sich nicht öffnen.
        </p>
    </div>

    <div
        class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
        role="alert"
    >
        <div class="flex items-center gap-2 font-semibold text-destructive">
            <CircleX class="size-4 shrink-0" />{{ problem.title }}
        </div>
        <p class="text-[13px] leading-[18px]">{{ problem.explanation }}</p>
        <p
            v-if="problem.detail"
            class="font-mono text-xs wrap-break-word text-text-secondary"
        >
            {{ problem.detail }}
        </p>
        <details
            v-if="problem.serverMessage"
            class="group border-t border-destructive/30 pt-2"
        >
            <summary
                class="flex cursor-pointer list-none items-center gap-1 text-xs text-text-secondary"
            >
                <ChevronDown
                    class="size-3.5 transition-transform group-open:rotate-180"
                />Meldung des Servers
            </summary>
            <pre
                class="mt-1.5 rounded-md border bg-background px-2.5 py-2 font-mono text-xs leading-[18px] wrap-break-word whitespace-pre-wrap"
                >{{ problem.serverMessage }}</pre>
        </details>
    </div>

    <div class="flex flex-col gap-2">
        <Button size="lg" class="w-full text-[15px]" @click="router.reload()">
            <RefreshCw />Erneut versuchen
        </Button>
        <Button
            v-if="connection.canManage"
            size="lg"
            variant="outline"
            class="w-full text-[15px]"
            as-child
        >
            <Link :href="edit(connection.id)"
                ><Pencil />Verbindung bearbeiten</Link
            >
        </Button>
    </div>
</template>
