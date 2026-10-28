<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

defineProps<{
    message?: string | null;
}>();
</script>

<template>
    <p
        v-if="message"
        class="flex items-start gap-1.5 text-[13px] leading-[18px] text-destructive"
    >
        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
        <span>{{ message }}</span>
    </p>
</template>
