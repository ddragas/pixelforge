<script setup lang="ts">
import Money from '@/components/Money.vue';
import PriceRange from '@/components/PriceRange.vue';
import { usd } from '@/lib/money';
import type { Generation, GenerationStatus } from '@/types/generation';
import { CircleAlert, Clock } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ generation: Generation }>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Waiting to be sent',
    queued: 'In the queue',
    processing: 'Generating',
    succeeded: 'Done',
    failed: 'Failed',
};

const colours: Record<GenerationStatus, string> = {
    pending: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    processing: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const explanations: Record<string, string> = {
    pending: 'Sending your request to the image service…',
    queued: 'Accepted. Waiting for a free slot.',
    processing: 'The image is being made. This usually takes under half a minute.',
};

const running = computed(() => !['succeeded', 'failed'].includes(props.generation.status));
const created = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }),
);
const aspect = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);

    return `${width} / ${height}`;
});
</script>

<template>
    <article
        class="group flex flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
    >
        <div class="relative flex items-center justify-center overflow-hidden bg-muted" :style="{ aspectRatio: aspect }">
            <img v-if="generation.image_url" :src="generation.image_url" :alt="generation.prompt" class="h-full w-full object-cover" loading="lazy" />

            <div v-else-if="generation.image_expired" class="flex flex-col items-center gap-2 px-6 text-center text-sm text-muted-foreground">
                <Clock class="size-5" />
                The image link has expired. The image service keeps images for 24 hours.
            </div>

            <div v-else-if="generation.status === 'failed'" class="flex flex-col items-center gap-2 px-6 text-center text-sm text-rose-700 dark:text-rose-400">
                <CircleAlert class="size-5" />
                {{ generation.error_message }}
            </div>

            <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center text-sm text-muted-foreground">
                <div class="shimmer absolute inset-0"></div>
                <span class="relative size-7 animate-spin rounded-full border-2 border-violet-200 border-t-violet-600"></span>
                <span class="relative">{{ explanations[generation.status] }}</span>
            </div>

            <span
                :class="['absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium shadow-sm', colours[generation.status]]"
                data-test="status"
            >
                <span v-if="running" class="relative flex size-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-60"></span>
                    <span class="relative inline-flex size-1.5 rounded-full bg-current"></span>
                </span>
                {{ labels[generation.status] }}
            </span>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <p class="line-clamp-2 text-sm font-medium leading-snug" :title="generation.prompt">{{ generation.prompt }}</p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size.replace('x', ' × ') }} · {{ generation.quality }} quality
                <template v-if="generation.reference_images.length"> · {{ generation.reference_images.length }} reference</template>
                · {{ created }}
            </p>

            <div class="mt-auto border-t border-border pt-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-xs text-muted-foreground">
                        {{ generation.status === 'succeeded' ? 'You paid' : generation.status === 'failed' ? 'Not charged' : 'Will cost' }}
                    </span>
                    <span v-if="generation.status === 'succeeded'" class="text-lg font-semibold"><Money :amount="generation.charged" /></span>
                    <span v-else-if="generation.status === 'failed'" class="text-lg font-semibold tabular-nums text-muted-foreground">$0.00</span>
                    <span v-else class="text-sm tabular-nums text-muted-foreground">{{ usd(generation.estimate.lowest) }} – {{ usd(generation.estimate.highest) }}</span>
                </div>

                <PriceRange v-if="generation.status !== 'failed'" class="mt-3" :estimate="generation.estimate" :charged="generation.charged" />
            </div>
        </div>
    </article>
</template>

<style scoped>
.shimmer {
    background: linear-gradient(100deg, transparent 30%, rgb(139 92 246 / 0.12) 50%, transparent 70%);
    background-size: 200% 100%;
    animation: shimmer 1.6s linear infinite;
}

@keyframes shimmer {
    from {
        background-position: 200% 0;
    }
    to {
        background-position: -200% 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .shimmer {
        animation: none;
    }
}
</style>
