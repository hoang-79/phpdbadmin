<script setup lang="ts">
import { t } from '@/lib/i18n';

/** Erster Tab-Halt jeder Seite (WCAG 2.4.1): springt an Kopf und Navigator vorbei zum Inhalt (`#inhalt`). */
</script>

<template>
    <a
        href="#inhalt"
        class="sr-only z-50 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >{{ t('Zum Inhalt springen') }}</a
    >
</template>
