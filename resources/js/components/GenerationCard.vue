<script setup lang="ts">
import { usd } from '@/lib/money';
import type { Generation, GenerationStatus } from '@/types/generation';
import { computed } from 'vue';

const props = defineProps<{ generation: Generation }>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Waiting to be sent',
    queued: 'Queued',
    processing: 'Generating…',
    succeeded: 'Done',
    failed: 'Failed',
};

const colours: Record<GenerationStatus, string> = {
    pending: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

const running = computed(() => !['succeeded', 'failed'].includes(props.generation.status));
const created = computed(() => new Date(props.generation.created_at).toLocaleString());
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-950">
        <div class="flex aspect-[4/3] items-center justify-center bg-neutral-100 dark:bg-neutral-900">
            <img v-if="generation.image_url" :src="generation.image_url" :alt="generation.prompt" class="h-full w-full object-contain" />
            <p v-else-if="generation.image_expired" class="px-6 text-center text-sm text-neutral-500">
                The image link has expired. Images are kept by the image service for 24 hours.
            </p>
            <p v-else-if="generation.status === 'failed'" class="px-6 text-center text-sm text-red-700 dark:text-red-400">
                {{ generation.error_message }}
            </p>
            <div v-else class="flex flex-col items-center gap-3 text-sm text-neutral-500">
                <span class="size-6 animate-spin rounded-full border-2 border-neutral-300 border-t-neutral-600"></span>
                {{ labels[generation.status] }}
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-3">
                <p class="line-clamp-3 text-sm">{{ generation.prompt }}</p>
                <span :class="['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium', colours[generation.status]]" data-test="status">
                    {{ labels[generation.status] }}
                </span>
            </div>

            <p class="text-xs text-neutral-500">
                {{ generation.size }} · {{ generation.quality }} quality
                <template v-if="generation.reference_images.length"> · {{ generation.reference_images.length }} reference image(s)</template>
                · {{ created }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t border-sidebar-border/70 pt-3 text-sm dark:border-sidebar-border">
                <div>
                    <dt class="text-xs text-neutral-500">Estimated</dt>
                    <dd>{{ usd(generation.estimate.lowest) }} – {{ usd(generation.estimate.highest) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">You paid</dt>
                    <dd v-if="generation.status === 'succeeded'" class="font-semibold">{{ usd(generation.charged) }}</dd>
                    <dd v-else-if="generation.status === 'failed'">$0.00 <span class="text-xs text-neutral-500">(not charged)</span></dd>
                    <dd v-else-if="running" class="text-neutral-500">known when it finishes</dd>
                </div>
            </dl>
        </div>
    </article>
</template>
