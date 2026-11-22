<script setup lang="ts">
import { usd } from '@/lib/money';
import type { Estimate } from '@/types/generation';
import { computed } from 'vue';

const props = defineProps<{ estimate: Estimate; charged?: string | null }>();

/**
 * Where a price sits between the lowest and the highest, as a percentage.
 * Only for drawing: turning the strings into numbers here positions a dot
 * on a bar, it is never used to work out what anyone pays.
 */
function position(amount: string): number {
    const lowest = Number(props.estimate.lowest);
    const highest = Number(props.estimate.highest);

    if (highest === lowest) {
        return 50;
    }

    return Math.min(100, Math.max(0, ((Number(amount) - lowest) / (highest - lowest)) * 100));
}

const marker = computed(() => position(props.charged ?? props.estimate.expected));
</script>

<template>
    <div>
        <div class="relative h-2 rounded-full bg-gradient-to-r from-emerald-400 via-amber-400 to-rose-400 opacity-80">
            <span
                class="absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-neutral-900 shadow transition-all duration-500 dark:border-neutral-900 dark:bg-white"
                :style="{ left: `${marker}%` }"
            ></span>
        </div>
        <div class="mt-2 flex justify-between text-xs text-muted-foreground">
            <span>at least {{ usd(estimate.lowest) }}</span>
            <span>at most {{ usd(estimate.highest) }}</span>
        </div>
    </div>
</template>
