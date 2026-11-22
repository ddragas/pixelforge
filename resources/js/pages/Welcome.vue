<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, Gauge, ReceiptText } from 'lucide-vue-next';

const promises = [
    {
        icon: ReceiptText,
        title: 'The price, before you press the button',
        text: 'Every image shows what it will most likely cost and the most it can cost. You are charged what it really took, to the last fraction of a cent.',
    },
    {
        icon: Gauge,
        title: 'Watch it happen',
        text: 'Queued, generating, done: the status of every image updates on its own. No refreshing, no guessing.',
    },
    {
        icon: BadgeCheck,
        title: 'Pay only for images you get',
        text: 'If an image fails, it costs nothing, and the credit set aside for it comes straight back.',
    },
];
</script>

<template>
    <Head title="Images from a sentence, at a price you see first" />

    <div class="relative min-h-screen overflow-hidden bg-neutral-950 text-white">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-40 -top-40 size-[36rem] rounded-full bg-violet-600/30 blur-3xl"></div>
            <div class="absolute -right-40 top-40 size-[32rem] rounded-full bg-fuchsia-600/20 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/3 size-[28rem] rounded-full bg-sky-500/10 blur-3xl"></div>
        </div>

        <div class="relative mx-auto flex min-h-screen max-w-6xl flex-col px-6">
            <header class="flex items-center justify-between py-6">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500">
                        <AppLogoIcon class="size-5 fill-current" />
                    </span>
                    <span class="font-semibold tracking-tight">Pixelforge Studio</span>
                </div>
                <nav class="flex items-center gap-2 text-sm">
                    <Link v-if="$page.props.auth.user" :href="route('generations.create')" class="rounded-lg bg-white px-4 py-2 font-medium text-neutral-950 transition hover:bg-neutral-200">
                        Open the studio
                    </Link>
                    <template v-else>
                        <Link :href="route('login')" class="rounded-lg px-4 py-2 font-medium text-white/80 transition hover:text-white">Log in</Link>
                        <Link :href="route('register')" class="rounded-lg bg-white px-4 py-2 font-medium text-neutral-950 transition hover:bg-neutral-200">Create account</Link>
                    </template>
                </nav>
            </header>

            <main class="grid flex-1 items-center gap-12 py-12 lg:grid-cols-2">
                <div>
                    <h1 class="text-4xl font-semibold leading-[1.05] tracking-tight sm:text-6xl">
                        Turn a sentence into an image.
                        <span class="bg-gradient-to-r from-violet-300 to-fuchsia-300 bg-clip-text text-transparent">Know the price first.</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/70">
                        Describe what you want, choose a shape and a quality, and see what it costs before anything is sent. You pay for the images you get, and
                        nothing for the ones that fail.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <Link
                            :href="$page.props.auth.user ? route('generations.create') : route('register')"
                            class="rounded-xl bg-gradient-to-r from-violet-500 to-fuchsia-500 px-6 py-3 font-medium shadow-lg shadow-violet-500/30 transition hover:brightness-110"
                        >
                            Start creating
                        </Link>
                        <span class="text-sm text-white/50">An image costs from about six cents.</span>
                    </div>
                </div>

                <!-- A picture of the product itself: one finished image and what it cost. -->
                <div class="relative mx-auto w-full max-w-md" aria-hidden="true">
                    <div class="rounded-3xl border border-white/10 bg-white/5 p-3 shadow-2xl backdrop-blur">
                        <div class="relative aspect-[4/3] overflow-hidden rounded-2xl bg-gradient-to-br from-amber-300 via-rose-400 to-violet-600">
                            <div class="absolute -bottom-10 -left-6 size-48 rounded-full bg-sky-900/50 blur-xl"></div>
                            <div class="absolute bottom-0 right-10 h-40 w-10 rounded-t-md bg-neutral-900/80"></div>
                            <div class="absolute bottom-40 right-[2.4rem] size-12 rounded-full bg-amber-100/90 blur-md"></div>
                            <span class="absolute left-3 top-3 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800">Done</span>
                        </div>
                        <div class="p-3">
                            <p class="text-sm font-medium">A lighthouse on a cliff at dawn, oil painting</p>
                            <div class="mt-3 flex items-baseline justify-between">
                                <span class="text-xs text-white/50">You paid</span>
                                <span class="text-lg font-semibold tabular-nums">$0.072071875</span>
                            </div>
                            <div class="relative mt-3 h-2 rounded-full bg-gradient-to-r from-emerald-400 via-amber-400 to-rose-400 opacity-80">
                                <span class="absolute left-[49%] top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-neutral-900 bg-white"></span>
                            </div>
                            <div class="mt-2 flex justify-between text-xs text-white/50">
                                <span>at least $0.05819375</span>
                                <span>at most $0.08635625</span>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <section class="grid gap-4 pb-16 sm:grid-cols-3">
                <div v-for="promise in promises" :key="promise.title" class="rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                    <component :is="promise.icon" class="size-5 text-violet-300" />
                    <h2 class="mt-3 font-medium">{{ promise.title }}</h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-white/60">{{ promise.text }}</p>
                </div>
            </section>
        </div>
    </div>
</template>
