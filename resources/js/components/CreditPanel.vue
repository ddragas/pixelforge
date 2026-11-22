<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usd } from '@/lib/money';
import type { Credit } from '@/types/generation';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{ packs: number[] }>();

const credit = computed(() => usePage().props.credit as Credit);
const buying = ref<number | null>(null);

function buy(dollars: number) {
    buying.value = dollars;

    router.post(route('credits.store'), { dollars }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
        <h2 class="text-sm font-medium text-neutral-500">Your credit</h2>
        <p class="mt-1 text-3xl font-semibold" data-test="balance">{{ usd(credit.balance) }}</p>
        <p v-if="credit.available !== credit.balance" class="mt-1 text-sm text-neutral-500">
            {{ usd(credit.available) }} free to spend. The rest is set aside for images still being generated, and anything not used comes back when they finish.
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            <Button v-for="pack in packs" :key="pack" variant="outline" size="sm" :disabled="buying !== null" @click="buy(pack)">
                Buy ${{ pack }}
            </Button>
        </div>
        <p class="mt-2 text-xs text-neutral-500">Demo purchase: credit is added, no payment is taken.</p>
    </section>
</template>
