<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import type { CollationChoice } from '@/types';

/**
 * Sortierung wählen, nach Zeichensatz gruppiert (S-10, S-12) — die Vorgabe jedes Zeichensatzes ist markiert. Die Liste
 * kommt vom Server; MySQL kennt rund 280, MariaDB über 300.
 */
const props = defineProps<{
    id: string;
    collations: CollationChoice[];
    invalid?: boolean;
}>();

const value = defineModel<string>({ required: true });

const groups = computed(() => {
    const byCharset = new Map<string, CollationChoice[]>();

    for (const collation of props.collations) {
        byCharset.set(collation.charset, [
            ...(byCharset.get(collation.charset) ?? []),
            collation,
        ]);
    }

    return [...byCharset.entries()].map(([charset, collations]) => ({
        charset,
        collations,
    }));
});
</script>

<template>
    <select
        :id="id"
        v-model="value"
        class="h-9 rounded-md border bg-background px-2 font-mono text-sm"
        :aria-invalid="invalid ? true : undefined"
    >
        <optgroup
            v-for="group in groups"
            :key="group.charset"
            :label="group.charset"
        >
            <option
                v-for="collation in group.collations"
                :key="collation.name"
                :value="collation.name"
            >
                {{
                    collation.default
                        ? t(':name (Vorgabe)', { name: collation.name })
                        : collation.name
                }}
            </option>
        </optgroup>
    </select>
</template>
