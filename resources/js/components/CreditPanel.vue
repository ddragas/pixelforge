<script setup lang="ts">
import Money from '@/components/Money.vue';
import { usd } from '@/lib/money';
import type { Credit } from '@/types/generation';
import { router, usePage } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';
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
    <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-600 via-violet-600 to-fuchsia-600 p-5 text-white shadow-lg shadow-violet-500/20">
        <div class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-white/10 blur-2xl"></div>

        <div class="flex items-center gap-2 text-sm font-medium text-white/80">
            <Wallet class="size-4" />
            Your credit
        </div>
        <p class="mt-2 text-4xl font-semibold tracking-tight" data-test="balance"><Money :amount="credit.balance" /></p>
        <p v-if="credit.available !== credit.balance" class="mt-2 text-sm leading-snug text-white/80">
            <span class="font-medium text-white">{{ usd(credit.available) }}</span> free to spend. The rest is set aside for images still being made; whatever they
            do not use comes back when they finish.
        </p>

        <div class="mt-5 grid grid-cols-3 gap-2">
            <button
                v-for="pack in packs"
                :key="pack"
                type="button"
                :data-test="`buy-${pack}`"
                :disabled="buying !== null"
                class="rounded-lg bg-white/15 px-3 py-2 text-sm font-medium backdrop-blur transition hover:bg-white/25 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white disabled:opacity-50"
                @click="buy(pack)"
            >
                {{ buying === pack ? 'Adding…' : `+ $${pack}` }}
            </button>
        </div>
        <p class="mt-3 text-xs text-white/70">Demo purchase: credit is added, no payment is taken.</p>
    </section>
</template>
