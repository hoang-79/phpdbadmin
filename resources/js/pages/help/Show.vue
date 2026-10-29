<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

/**
 * Kurze Hilfe (N-05): Inhaltsverzeichnis und Text aus `resources/help/{locale}.md`. Der Text ist mitgeliefert, nicht
 * aus einer Datenbank — deshalb darf er als HTML erscheinen (der Server entfernt HTML im Quelltext trotzdem).
 */
defineProps<{
    html: string;
    sections: { id: string; title: string }[];
}>();
</script>

<template>
    <Head :title="t('Hilfe')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ t('Hilfe') }}</h1>
            <p class="text-text-secondary">
                {{
                    t(
                        'Das Wichtigste in Kürze. Alle Tastenkürzel zeigt «?» im Arbeitsbildschirm.',
                    )
                }}
            </p>
        </div>

        <div
            class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start"
        >
            <nav
                :aria-label="t('Inhalt')"
                class="rounded-lg border bg-background p-3 lg:sticky lg:top-5"
            >
                <ol class="flex flex-col gap-0.5 text-sm">
                    <li v-for="section in sections" :key="section.id">
                        <a
                            :href="`#${section.id}`"
                            class="block rounded-md px-2 py-1.5 text-text-secondary hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >{{ section.title }}</a
                        >
                    </li>
                </ol>
            </nav>

            <!-- eslint-disable-next-line vue/no-v-html -- mitgelieferter Hilfetext, siehe oben -->
            <article class="help-text max-w-3xl" v-html="html" />
        </div>
    </div>
</template>

<style scoped>
.help-text :deep(h2) {
    scroll-margin-top: 1.25rem;
    margin: 2rem 0 0.75rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border);
    font-size: 1.125rem;
    font-weight: 600;
}

.help-text :deep(h2:first-child) {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}

.help-text :deep(p),
.help-text :deep(ul),
.help-text :deep(ol) {
    margin: 0.75rem 0;
    line-height: 1.6;
}

.help-text :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.help-text :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.help-text :deep(li + li) {
    margin-top: 0.35rem;
}

.help-text :deep(code) {
    border-radius: 0.25rem;
    background: var(--muted);
    padding: 0.05rem 0.3rem;
    font-family: var(--font-mono);
    font-size: 0.875em;
}

.help-text :deep(h2:target) {
    color: var(--primary);
}
</style>
