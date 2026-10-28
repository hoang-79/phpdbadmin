<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { Mail, RefreshCw } from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCountdown, formatTime } from '@/lib/format';
import { login } from '@/routes';
import { resend, verify } from '@/routes/login/code';

/** Wireframe 02: Code aus der Mail eingeben. Dieselbe Seite für bekannte und unbekannte Adressen. */
const props = defineProps<{
    email: string;
    validMinutes: number;
    expiresAt: string;
    resendInSeconds: number;
}>();

setLayoutProps({ back: { href: login.url(), label: 'Zurück zur Anmeldung' } });

const waitSeconds = ref(props.resendInSeconds);
useIntervalFn(() => {
    waitSeconds.value = Math.max(0, waitSeconds.value - 1);
}, 1000);
</script>

<template>
    <Head title="Code eingeben" />

    <h1 class="text-xl font-semibold">Code eingeben</h1>

    <Notice :icon="Mail">
        Wir haben einen Code an
        <b class="font-semibold wrap-break-word">{{ email }}</b> gesendet,
        sofern es dazu ein Konto gibt. Er ist {{ validMinutes }} Minuten gültig,
        bis {{ formatTime(expiresAt) }} Uhr.
    </Notice>

    <Form
        v-bind="verify.form()"
        class="flex flex-col gap-5"
        #default="{ errors, processing }"
    >
        <div class="flex flex-col gap-1.5">
            <Label for="code" class="text-[13px]">Code aus der Mail</Label>
            <Input
                id="code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="9"
                required
                autofocus
                class="h-13 font-mono text-2xl tracking-[0.35em] md:text-2xl"
                :aria-invalid="errors.code ? true : undefined"
                aria-describedby="code-hint"
            />
            <InputError :message="errors.code" />
            <p id="code-hint" class="text-xs text-muted-foreground">
                Sechs Ziffern. Sie können den Code auch aus der Mail einfügen —
                Ihr Gerät schlägt ihn oft schon vor.
            </p>
        </div>

        <Button
            type="submit"
            size="lg"
            class="w-full text-[15px]"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            Anmelden
        </Button>
    </Form>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <Form
            v-bind="resend.form()"
            #default="{ processing }"
            @success="waitSeconds = resendInSeconds"
        >
            <button
                type="submit"
                class="inline-flex items-center gap-1.5 text-primary underline underline-offset-2 disabled:text-muted-foreground disabled:no-underline"
                :disabled="processing || waitSeconds > 0"
            >
                <RefreshCw class="size-3.5" />
                Code erneut senden
                <span v-if="waitSeconds > 0" class="tabular-nums"
                    >(in {{ formatCountdown(waitSeconds) }})</span
                >
            </button>
        </Form>
        <Link
            :href="login()"
            class="inline-flex items-center gap-1.5 text-primary underline underline-offset-2"
        >
            <Mail class="size-3.5" />Andere Adresse verwenden
        </Link>
    </div>
</template>
