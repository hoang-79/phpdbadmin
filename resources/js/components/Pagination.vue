<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';

/**
 * Seiten wählen: zurück, Seitenzahlen (erste, letzte und die Nachbarn der aktuellen, dazwischen «…»), weiter.
 */
const props = defineProps<{
    pageCount: number;
}>();

const page = defineModel<number>('page', { required: true });

/** Seitenzahlen mit Lücken (null = «…»). */
const items = computed<(number | null)[]>(() => {
    const count = props.pageCount;
    const current = page.value;
    const shown = new Set(
        [1, count, current - 1, current, current + 1].filter(
            (number) => number >= 1 && number <= count,
        ),
    );
    const sorted = [...shown].sort((a, b) => a - b);
    const result: (number | null)[] = [];

    sorted.forEach((number, index) => {
        const previous = sorted[index - 1];

        if (previous !== undefined && number - previous > 1) {
            result.push(number - previous === 2 ? previous + 1 : null);
        }

        result.push(number);
    });

    return result;
});

function go(number: number): void {
    page.value = Math.min(Math.max(number, 1), props.pageCount);
}
</script>

<template>
    <nav class="flex items-center gap-1" :aria-label="t('Seiten')">
        <button
            type="button"
            class="inline-grid size-8 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40 disabled:hover:bg-transparent"
            :disabled="page <= 1"
            :aria-label="t('Vorherige Seite')"
            @click="go(page - 1)"
        >
            <ChevronLeft class="size-4" />
        </button>
        <template v-for="(number, index) in items" :key="index">
            <span
                v-if="number === null"
                class="px-1 text-text-secondary"
                aria-hidden="true"
                >…</span
            >
            <button
                v-else
                type="button"
                :class="[
                    'inline-grid h-8 min-w-8 place-items-center rounded-md px-2 text-[13px] tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    number === page
                        ? 'bg-primary font-semibold text-primary-foreground'
                        : 'hover:bg-muted',
                ]"
                :aria-current="number === page ? 'page' : undefined"
                :aria-label="t('Seite :page', { page: number })"
                @click="go(number)"
            >
                {{ number }}
            </button>
        </template>
        <button
            type="button"
            class="inline-grid size-8 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40 disabled:hover:bg-transparent"
            :disabled="page >= pageCount"
            :aria-label="t('Nächste Seite')"
            @click="go(page + 1)"
        >
            <ChevronRight class="size-4" />
        </button>
    </nav>
</template>
