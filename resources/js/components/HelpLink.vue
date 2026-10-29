<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { t } from '@/lib/i18n';
import { help } from '@/routes';

/**
 * Verweis auf einen Abschnitt der Hilfe (N-05). Die Anker stehen in `resources/help/*.md`; ein Test prüft, dass es
 * jeden hier verwendeten Anker in jeder Sprache gibt.
 */
defineProps<{ anchor: string }>();
</script>

<template>
    <Link
        :href="`${help.url()}#${anchor}`"
        class="inline-flex items-center gap-1 rounded-sm text-[12px] text-text-secondary underline-offset-2 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        ><CircleHelp class="size-3.5" aria-hidden="true" />{{
            t('Hilfe')
        }}</Link
    >
</template>
