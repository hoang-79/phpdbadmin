<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import Wordmark from '@/components/Wordmark.vue';
import { Toaster } from '@/components/ui/sonner';

/**
 * Anmeldung, Code, Bestätigung, Einladung (Wireframes 01, 02): Karte auf Fläche, Wortmarke oben, optional ein Weg
 * zurück unter der Karte (Seiten setzen ihn mit setLayoutProps).
 */
withDefaults(
    defineProps<{
        back?: { href: string; label: string } | null;
    }>(),
    { back: null },
);
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-4"
    >
        <main
            class="flex w-full max-w-[400px] flex-col gap-5 rounded-lg border bg-background px-5 py-6 sm:p-8"
        >
            <Wordmark />
            <slot />
        </main>
        <Link
            v-if="back"
            :href="back.href"
            class="inline-flex items-center gap-1 text-sm text-text-secondary hover:text-foreground"
        >
            <ArrowLeft class="size-3.5" />{{ back.label }}
        </Link>
        <Toaster position="top-center" />
    </div>
</template>
