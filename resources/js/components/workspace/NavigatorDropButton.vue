<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { t } from '@/lib/i18n';

/**
 * Papierkorb am Ende einer Zeile im Navigator (S-05): erscheint beim Überfahren der Zeile und mit dem Tastaturfokus,
 * auf Geräten ohne Mauszeiger immer. Gesperrt (Schreibschutz) bleibt er sichtbar, nennt den Grund und tut nichts.
 */
const props = defineProps<{
    name: string;
    blocked: string | null;
}>();

const emit = defineEmits<{ drop: [] }>();

function onClick(): void {
    if (!props.blocked) {
        emit('drop');
    }
}
</script>

<template>
    <button
        type="button"
        :class="[
            'mr-1 hidden size-6 shrink-0 place-items-center rounded-md text-text-secondary outline-none group-focus-within:inline-grid group-hover:inline-grid focus-visible:ring-2 focus-visible:ring-ring [@media(hover:none)]:inline-grid',
            blocked
                ? 'cursor-not-allowed opacity-50'
                : 'hover:bg-destructive-soft hover:text-destructive',
        ]"
        :aria-label="t(':name löschen …', { name })"
        :aria-disabled="blocked ? 'true' : undefined"
        :title="blocked ?? t(':name löschen …', { name })"
        @click="onClick"
    >
        <Trash2 class="size-3.5" />
    </button>
</template>
