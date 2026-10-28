<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { ChevronRight, KeyRound, Mail, Rocket } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { passkeyMessage } from '@/lib/passkeyMessage';
import { home, setup } from '@/routes';
import { code } from '@/routes/login';
import { login as passkeyLogin, loginOptions } from '@/routes/passkey';

/**
 * Wireframe 01: Passkey zuerst, sonst Code per Mail. Keine Registrierung, kein Passwort. Ohne jedes Konto (frische
 * Installation) erscheint statt der Anmeldung der Weg zur Ersteinrichtung.
 */
defineProps<{
    email: string | null;
    needsSetup: boolean;
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
    <template v-if="needsSetup">
        <Head title="Ersteinrichtung" />

        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">Willkommen bei phpdbadmin</h1>
            <p class="text-text-secondary">
                Diese Installation hat noch kein Konto. Legen Sie das erste an —
                es wird Administrator und meldet sich mit einem Passkey an.
            </p>
        </div>

        <Button size="lg" class="w-full text-[15px]" as-child>
            <Link :href="setup()"><Rocket />Einrichtung starten</Link>
        </Button>

        <details class="group text-sm">
            <summary
                class="flex cursor-pointer list-none items-center gap-1 text-text-secondary hover:text-foreground"
            >
                <ChevronRight
                    class="size-3.5 transition-transform group-open:rotate-90"
                />Lieber im Terminal?
            </summary>
            <pre
                class="mt-2 rounded-md border bg-muted px-3 py-2.5 font-mono text-[13px] whitespace-pre-wrap"
            ><span class="text-muted-foreground select-none">$ </span>php artisan phpdbadmin:install</pre>
            <p class="mt-1.5 text-xs text-muted-foreground">
                Der Befehl gibt einen Link zur Ersteinrichtung aus.
            </p>
        </details>

        <p class="text-xs text-muted-foreground">
            Danach laden Sie weitere Personen ein; offene Registrierung gibt es
            nicht.
        </p>
    </template>

    <template v-else>
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
            Neue Konten entstehen nur per Einladung durch eine Administratorin
            oder einen Administrator.
        </p>
    </template>
</template>
