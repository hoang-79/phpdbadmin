<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';
import { store } from '@/routes/workspace/password';
import type { ConnectionEnvironment } from '@/types';

/** Passwort für eine Verbindung, die keines speichert (V-03): einmal je Sitzung. */
defineProps<{
    connection: {
        id: string;
        name: string;
        username: string | null;
        location: string;
        environment: ConnectionEnvironment;
    };
}>();

setLayoutProps({ back: { href: home.url(), label: 'Alle Verbindungen' } });
</script>

<template>
    <Head :title="`Passwort · ${connection.name}`" />

    <div class="flex flex-col gap-1">
        <EnvironmentMark
            :environment="connection.environment"
            class="text-sm"
        />
        <h1 class="font-mono text-xl font-semibold">{{ connection.name }}</h1>
        <p class="text-text-secondary">
            Konto <code class="font-mono">{{ connection.username }}</code> auf
            <code class="font-mono">{{ connection.location }}</code>
        </p>
    </div>

    <Notice>
        Für diese Verbindung ist kein Passwort gespeichert. Es gilt nur für
        diese Sitzung und liegt nie in der App-Datenbank.
    </Notice>

    <Form
        v-bind="store.form(connection.id)"
        class="flex flex-col gap-5"
        #default="{ errors, processing }"
    >
        <div class="flex flex-col gap-1.5">
            <Label for="password" class="text-[13px]">Passwort</Label>
            <Input
                id="password"
                name="password"
                type="password"
                autocomplete="off"
                required
                autofocus
                :aria-invalid="errors.password ? true : undefined"
            />
            <InputError :message="errors.password" />
        </div>
        <Button
            type="submit"
            size="lg"
            class="w-full text-[15px]"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            <KeyRound v-else />
            Verbinden
        </Button>
    </Form>
</template>
