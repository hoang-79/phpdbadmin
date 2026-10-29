<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AccountMenu from '@/components/AccountMenu.vue';
import SkipLink from '@/components/SkipLink.vue';
import Wordmark from '@/components/Wordmark.vue';
import { Toaster } from '@/components/ui/sonner';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <SkipLink />
        <header
            class="sticky top-0 z-20 flex h-[52px] shrink-0 items-center gap-3 border-b bg-background px-4"
        >
            <Link
                :href="home()"
                class="rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('Verbindungen')"
            >
                <Wordmark />
            </Link>
            <div class="flex-1" />
            <AccountMenu />
        </header>
        <main id="inhalt" tabindex="-1" class="flex-1 outline-none">
            <slot />
        </main>
        <Toaster position="bottom-right" />
    </div>
</template>
