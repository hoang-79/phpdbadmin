<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { FileKey, UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/setup';

/**
 * Ersteinrichtung im Browser (A-06): erstes Konto anlegen, abgesichert über den Einrichtungsschlüssel, den
 * phpdbadmin als Datei auf dem Server abgelegt hat. Danach folgt das Onboarding mit Passkey.
 */
defineProps<{
    keyPath: string;
}>();

setLayoutProps({ back: { href: login.url(), label: 'Zurück' } });
</script>

<template>
    <Head title="Ersteinrichtung" />

    <div class="flex flex-col gap-1">
        <h1 class="text-xl font-semibold">Ersteinrichtung</h1>
        <p class="text-text-secondary">
            Das erste Konto wird Administrator. Als Nächstes legen Sie Ihren
            Passkey an.
        </p>
    </div>

    <Form
        v-bind="store.form()"
        class="flex flex-col gap-5"
        #default="{ errors, processing }"
    >
        <div class="flex flex-col gap-1.5">
            <Label for="name" class="text-[13px]">Ihr Name</Label>
            <Input
                id="name"
                name="name"
                autocomplete="name"
                required
                autofocus
                :aria-invalid="errors.name ? true : undefined"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="email" class="text-[13px]">E-Mail-Adresse</Label>
            <Input
                id="email"
                type="email"
                name="email"
                autocomplete="email"
                required
                :aria-invalid="errors.email ? true : undefined"
                aria-describedby="email-hint"
            />
            <InputError :message="errors.email" />
            <p id="email-hint" class="text-xs text-muted-foreground">
                Ohne Passkey melden Sie sich mit einem Code an diese Adresse an
                — bitte genau prüfen.
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="key" class="text-[13px]">Einrichtungsschlüssel</Label>
            <Input
                id="key"
                name="key"
                autocomplete="off"
                spellcheck="false"
                required
                placeholder="XXXX-XXXX-XXXX"
                class="font-mono uppercase"
                :aria-invalid="errors.key ? true : undefined"
                aria-describedby="key-hint"
            />
            <InputError :message="errors.key" />
        </div>

        <Notice id="key-hint" :icon="FileKey">
            phpdbadmin hat den Schlüssel eben auf dem Server abgelegt, in der
            Datei <code class="font-mono wrap-break-word">{{ keyPath }}</code>
            im Ordner der Installation. Öffnen Sie sie über den Dateimanager
            Ihres Hostings, per FTP oder im Editor — so zeigen Sie, dass Sie
            Zugriff auf den Server haben.
        </Notice>

        <Button
            type="submit"
            size="lg"
            class="w-full text-[15px]"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            <UserPlus v-else />
            Konto anlegen
        </Button>
    </Form>
</template>
