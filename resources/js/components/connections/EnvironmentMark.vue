<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import type { ConnectionEnvironment } from '@/types';

/** Umgebung (G-03): Symbol und Wort, nie Farbe allein — ○ lokal, ◐ Test, ● Produktion. */
defineProps<{
    environment: ConnectionEnvironment;
}>();

const words = computed<Record<ConnectionEnvironment, string>>(() => ({
    local: t('lokal'),
    test: t('Test'),
    production: t('Produktion'),
}));

const colors: Record<ConnectionEnvironment, string> = {
    local: 'text-env-local',
    test: 'text-env-test',
    production: 'text-env-production',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 font-medium whitespace-nowrap',
            colors[environment],
        ]"
    >
        <svg viewBox="0 0 10 10" class="size-2.5 shrink-0" aria-hidden="true">
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
        {{ words[environment] }}
    </span>
</template>
