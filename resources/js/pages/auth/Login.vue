<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound, Mail } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { passkeyMessage } from '@/lib/passkeyMessage';
import { home } from '@/routes';
import { code } from '@/routes/login';
import { login as passkeyLogin, loginOptions } from '@/routes/passkey';

/** Wireframe 01: Passkey zuerst, sonst Code per Mail. Keine Registrierung, kein Passwort. */
defineProps<{
    email: string | null;
}>();

setLayoutProps({ back: null });

const routes = { options: loginOptions.url(), submit: passkeyLogin.url() };
const onSuccess = (response: { redirect?: string }) =>
    router.visit(response.redirect ?? home.url());

// Knopf «Mit Passkey anmelden» und, unabhängig davon, die Vorschläge des Browsers im Adressfeld.
const button = usePasskeyVerify({ routes, onSuccess });
const autofill = usePasskeyVerify({ routes, onSuccess, autofill: true });

const passkeyError = computed(
    () =>
        passkeyMessage(button.errorInstance.value) ??
        passkeyMessage(autofill.errorInstance.value),
);
</script>

<template>
    <Head title="Anmelden" />

    <div class="flex flex-col gap-1">
        <h1 class="text-xl font-semibold">Anmelden</h1>
        <p class="text-text-secondary">
            Mit Ihrem Passkey oder mit einem Code, den wir Ihnen per Mail
            senden.
        </p>
    </div>

    <div v-if="button.isSupported.value" class="flex flex-col gap-2">
        <Button
            type="button"
            size="lg"
            class="w-full text-[15px]"
            :disabled="button.isLoading.value"
            @click="button.verify"
        >
            <Spinner v-if="button.isLoading.value" />
            <KeyRound v-else />
            Mit Passkey anmelden
        </Button>
        <InputError :message="passkeyError" />
    </div>

    <div
        v-if="button.isSupported.value"
        class="flex items-center gap-3 text-[13px] text-muted-foreground before:h-px before:flex-1 before:bg-border after:h-px after:flex-1 after:bg-border"
    >
        oder mit Code per Mail
    </div>

    <Form
        v-bind="code.form()"
        class="flex flex-col gap-5"
        #default="{ errors, processing }"
    >
        <div class="flex flex-col gap-1.5">
            <Label for="email" class="text-[13px]">E-Mail-Adresse</Label>
            <Input
                id="email"
                type="email"
                name="email"
                :default-value="email ?? ''"
                required
                autofocus
                autocomplete="username webauthn"
                :aria-invalid="errors.email ? true : undefined"
                aria-describedby="email-hint"
            />
            <InputError :message="errors.email" />
            <p id="email-hint" class="text-xs text-muted-foreground">
                Ihr Browser schlägt hier auch gespeicherte Passkeys vor.
            </p>
        </div>

        <Button
            type="submit"
            size="lg"
            :variant="button.isSupported.value ? 'outline' : 'default'"
            class="w-full text-[15px]"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            <Mail v-else />
            Code senden
        </Button>
    </Form>

    <p class="text-xs text-muted-foreground">
        Neue Konten entstehen nur per Einladung durch eine Administratorin oder
        einen Administrator.
    </p>
</template>
