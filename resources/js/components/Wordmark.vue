<script setup lang="ts">
import { BRAND_MARK } from '@/lib/brand';

/**
 * Logo (G-06): Bildmarke — die «db»-Ligatur auf Akzentfläche — und der Schriftzug «phpdbadmin» in JetBrains Mono,
 * «db» im Akzent. Die Farben kommen aus dem Stylesheet, damit hell und dunkel zum Logo-Kit passen.
 */
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <svg
            :viewBox="`0 0 ${BRAND_MARK.size} ${BRAND_MARK.size}`"
            class="size-[22px] shrink-0"
            aria-hidden="true"
        >
            <rect
                :width="BRAND_MARK.size"
                :height="BRAND_MARK.size"
                :rx="BRAND_MARK.radius"
                class="fill-primary"
            />
            <g
                :transform="BRAND_MARK.transform"
                class="fill-primary-foreground"
            >
                <path v-for="path in BRAND_MARK.paths" :key="path" :d="path" />
            </g>
        </svg>
        <span
            class="font-mono text-[15px] font-semibold tracking-[-0.2px] whitespace-nowrap text-foreground"
            >php<b class="font-semibold text-primary">db</b>admin</span
        >
    </span>
</template>
