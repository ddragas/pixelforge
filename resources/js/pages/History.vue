<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Generation } from '@/types/generation';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Images, Sparkles } from 'lucide-vue-next';
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
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 lg:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Your images</h1>
                    <p class="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
                        {{ pagination.total }} in total
                        <span v-if="running" class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-950 dark:text-violet-300">
                            <span class="relative flex size-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-60"></span>
                                <span class="relative inline-flex size-1.5 rounded-full bg-current"></span>
                            </span>
                            updating live
                        </span>
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('generations.create')"><Sparkles class="size-4" /> New image</Link>
                </Button>
            </div>

            <div v-if="generations.length === 0" class="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-border px-6 py-16 text-center">
                <span class="flex size-12 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300">
                    <Images class="size-6" />
                </span>
                <div>
                    <p class="font-medium">No images yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">Everything you generate appears here, with what it cost.</p>
                </div>
                <Button as-child>
                    <Link :href="route('generations.create')">Create your first image</Link>
                </Button>
            </div>

            <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard v-for="generation in generations" :key="generation.id" :generation="generation" />
            </div>

            <nav v-if="pagination.last > 1" class="flex items-center justify-center gap-4 text-sm">
                <Link v-if="pagination.current > 1" :href="route('generations.index', { page: pagination.current - 1 })" class="font-medium text-violet-700 hover:underline dark:text-violet-300">← Newer</Link>
                <span class="text-muted-foreground">Page {{ pagination.current }} of {{ pagination.last }}</span>
                <Link v-if="pagination.current < pagination.last" :href="route('generations.index', { page: pagination.current + 1 })" class="font-medium text-violet-700 hover:underline dark:text-violet-300">Older →</Link>
            </nav>
        </div>
    </AppLayout>
</template>
