<script setup lang="ts">
import { computed } from 'vue';

/**
 * Shows an exact amount so that it reads at a glance: dollars and cents at
 * full size, any finer digits smaller beside them. Nothing is rounded away.
 */
const props = defineProps<{ amount: string | null | undefined }>();

const parts = computed(() => {
    if (props.amount === null || props.amount === undefined) {
        return null;
    }

    const [whole, fraction = ''] = props.amount.replace('-', '').split('.');
    const padded = fraction.padEnd(2, '0');

    return {
        sign: props.amount.startsWith('-') ? '-' : '',
        main: `${whole}.${padded.slice(0, 2)}`,
        rest: padded.slice(2).replace(/0+$/, ''),
    };
});
</script>

<template>
    <span v-if="parts" class="tabular-nums" :title="`$${amount}`"
        >{{ parts.sign }}${{ parts.main }}<span v-if="parts.rest" class="text-[0.55em] font-medium opacity-60">{{ parts.rest }}</span></span
    >
    <span v-else>—</span>
</template>
