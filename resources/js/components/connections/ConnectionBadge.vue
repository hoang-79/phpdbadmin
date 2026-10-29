<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import type { ConnectionEnvironment } from '@/types';

/**
 * Verbindung als Chip (Wireframe 14): Umgebung mit Symbol und Wort, Name, Schloss bei Schreibschutz — nie Farbe
 * allein (G-03).
 */
const props = defineProps<{
    name: string;
    environment: ConnectionEnvironment;
    readOnly?: boolean;
}>();

const word = computed(
    () =>
        ({
            local: t('lokal'),
            test: t('Test'),
            production: t('Produktion'),
        })[props.environment],
);
</script>

<template>
    <span
        :class="[
            'inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-full border px-2 py-px text-xs font-medium',
            environment === 'production'
                ? 'border-env-production bg-destructive-soft text-env-production'
                : environment === 'test'
                  ? 'border-env-test text-env-test'
                  : 'text-foreground',
        ]"
    >
        <svg viewBox="0 0 10 10" class="size-2 shrink-0" aria-hidden="true">
            <circle
                v-if="environment === 'production'"
                cx="5"
                cy="5"
                r="4.7"
                fill="currentColor"
            />
            <template v-else>
                <circle
                    cx="5"
                    cy="5"
                    r="3.9"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                />
                <path
                    v-if="environment === 'test'"
                    d="M5 1.1A3.9 3.9 0 0 0 5 8.9Z"
                    fill="currentColor"
                />
            </template>
        </svg>
        <span class="truncate">{{ word }} · {{ name }}</span>
        <Lock
            v-if="readOnly"
            class="size-3 shrink-0"
            :aria-label="t('schreibgeschützt')"
        />
    </span>
</template>
