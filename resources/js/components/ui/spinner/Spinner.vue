<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Loader2Icon } from "@lucide/vue"
import { t } from "@/lib/i18n"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <Loader2Icon
    role="status"
    :aria-label="t('Wird geladen')"
    :class="cn('size-4 animate-spin', props.class)"
  />
</template>
