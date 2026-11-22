<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { usd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface Money {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
}

interface Day extends Money {
    date: string;
    reported_generations: number | null;
    reported_cost: string | null;
    matches: boolean | null;
    users: (Money & { name: string; email: string })[];
}

defineProps<{
    days: Day[];
    totals: Money;
    usageError: string | null;
    range: { start: string; end: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: "Operators' report", href: '/admin/report' }];
</script>

<template>
    <Head title="Operators' report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex min-w-0 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Spend, cost and profit</h1>
                <p class="text-sm text-neutral-500">
                    {{ range.start }} to {{ range.end }}, by the UTC day each generation completed, which is the day Pixelforge bills it. Each day is compared
                    with Pixelforge's own usage report for this API key.
                </p>
            </div>

            <p v-if="usageError" class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                Pixelforge's usage report could not be read just now ({{ usageError }}), so the comparison is missing.
            </p>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <dt class="text-xs text-neutral-500">Paid generations</dt>
                    <dd class="text-2xl font-semibold">{{ totals.generations }}</dd>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <dt class="text-xs text-neutral-500">Users were charged</dt>
                    <dd class="text-2xl font-semibold">{{ usd(totals.charged) }}</dd>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <dt class="text-xs text-neutral-500">Pixelforge charged us</dt>
                    <dd class="text-2xl font-semibold">{{ usd(totals.provider_cost) }}</dd>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <dt class="text-xs text-neutral-500">Profit</dt>
                    <dd class="text-2xl font-semibold">{{ usd(totals.profit) }}</dd>
                </div>
            </dl>

            <p v-if="days.length === 0" class="rounded-xl border border-dashed border-sidebar-border p-10 text-center text-sm text-neutral-500">
                No finished generations in this period.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr>
                            <th class="px-4 py-3">Day (UTC) / user</th>
                            <th class="px-4 py-3 text-right">Generations</th>
                            <th class="px-4 py-3 text-right">Charged to users</th>
                            <th class="px-4 py-3 text-right">Provider cost</th>
                            <th class="px-4 py-3 text-right">Profit</th>
                            <th class="px-4 py-3 text-right">Pixelforge reports</th>
                        </tr>
                    </thead>
                    <tbody v-for="day in days" :key="day.date" class="border-t border-sidebar-border/70 dark:border-sidebar-border">
                        <tr class="font-medium">
                            <td class="px-4 py-3">{{ day.date }}</td>
                            <td class="px-4 py-3 text-right">{{ day.generations }}</td>
                            <td class="px-4 py-3 text-right">{{ usd(day.charged) }}</td>
                            <td class="px-4 py-3 text-right">{{ usd(day.provider_cost) }}</td>
                            <td class="px-4 py-3 text-right">{{ usd(day.profit) }}</td>
                            <td class="px-4 py-3 text-right">
                                <template v-if="day.matches === null">not available</template>
                                <template v-else>
                                    {{ usd(day.reported_cost) }} for {{ day.reported_generations }}
                                    <span
                                        :class="[
                                            'ml-2 rounded-full px-2 py-0.5 text-xs',
                                            day.matches
                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
                                        ]"
                                    >
                                        {{ day.matches ? 'matches' : 'differs' }}
                                    </span>
                                </template>
                            </td>
                        </tr>
                        <tr v-for="user in day.users" :key="user.email" class="text-neutral-600 dark:text-neutral-400">
                            <td class="px-4 py-2 pl-8">{{ user.name }} <span class="text-xs">({{ user.email }})</span></td>
                            <td class="px-4 py-2 text-right">{{ user.generations }}</td>
                            <td class="px-4 py-2 text-right">{{ usd(user.charged) }}</td>
                            <td class="px-4 py-2 text-right">{{ usd(user.provider_cost) }}</td>
                            <td class="px-4 py-2 text-right">{{ usd(user.profit) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
