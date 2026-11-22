<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Generation } from '@/types/generation';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps<{
    generations: Generation[];
    pagination: { current: number; last: number; total: number };
    running: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/history' }];

// While anything is still running, ask the server again every three seconds.
// Once everything has finished, the asking stops.
const { start, stop } = usePoll(3000, { only: ['generations', 'running', 'credit'] }, { autoStart: false });

watch(
    () => props.running,
    (running) => (running ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Your images</h1>
                    <p class="text-sm text-neutral-500">
                        {{ pagination.total }} in total<template v-if="running"> · updating live</template>
                    </p>
                </div>
                <Button as-child><Link :href="route('generations.create')">New image</Link></Button>
            </div>

            <p v-if="generations.length === 0" class="rounded-xl border border-dashed border-sidebar-border p-10 text-center text-sm text-neutral-500">
                Nothing here yet. Generate your first image.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard v-for="generation in generations" :key="generation.id" :generation="generation" />
            </div>

            <nav v-if="pagination.last > 1" class="flex items-center justify-center gap-4 text-sm">
                <Link v-if="pagination.current > 1" :href="route('generations.index', { page: pagination.current - 1 })" class="underline underline-offset-4">Newer</Link>
                <span class="text-neutral-500">Page {{ pagination.current }} of {{ pagination.last }}</span>
                <Link v-if="pagination.current < pagination.last" :href="route('generations.index', { page: pagination.current + 1 })" class="underline underline-offset-4">Older</Link>
            </nav>
        </div>
    </AppLayout>
</template>
