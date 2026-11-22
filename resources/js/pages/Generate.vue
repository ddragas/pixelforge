<script setup lang="ts">
import CreditPanel from '@/components/CreditPanel.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { usd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { Estimate, Generation } from '@/types/generation';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    maxReferenceImages: number;
    maxPromptLength: number;
    creditPacks: number[];
    markupPercent: number;
    recent: Generation[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: 'medium',
    reference_images: [] as string[],
});

const estimate = ref<(Estimate & { affordable: boolean }) | null>(null);
const estimating = ref(false);
const estimateFailed = ref(false);

const references = computed(() => form.reference_images.filter((url) => url.trim() !== ''));
const canSubmit = computed(() => form.prompt.trim() !== '' && estimate.value?.affordable === true && !form.processing);

let timer: ReturnType<typeof setTimeout> | undefined;
let latest = 0;

/** The price comes from the server, from the same rules that will be used to charge. */
async function refreshEstimate() {
    if (form.prompt.trim() === '') {
        estimate.value = null;
        return;
    }

    const request = ++latest;
    estimating.value = true;

    try {
        const response = await fetch(route('generations.estimate'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ ...form.data(), reference_images: references.value }),
        });

        // A slower, older answer must not overwrite a newer one.
        if (request !== latest) {
            return;
        }

        estimate.value = response.ok ? await response.json() : null;
        estimateFailed.value = !response.ok && response.status !== 422;
    } catch {
        estimate.value = null;
        estimateFailed.value = true;
    } finally {
        if (request === latest) {
            estimating.value = false;
        }
    }
}

watch(
    () => [form.prompt, form.size, form.quality, references.value.join('|')],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refreshEstimate, 300);
    },
);

onMounted(refreshEstimate);

// Buying credits changes whether the image can be afforded, so the server is asked again.
watch(() => usePage().props.credit?.available, refreshEstimate);

function submit() {
    form.transform((data) => ({ ...data, reference_images: references.value })).post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <form class="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="prompt">What should the image show?</Label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        :maxlength="maxPromptLength"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    ></textarea>
                    <div class="flex justify-between text-xs text-neutral-500">
                        <InputError :message="form.errors.prompt" />
                        <span class="ml-auto">{{ form.prompt.length }} / {{ maxPromptLength }}</span>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Size</legend>
                        <label v-for="size in sizes" :key="size" class="flex items-center gap-2 text-sm">
                            <input v-model="form.size" type="radio" name="size" :value="size" />
                            {{ size }}
                        </label>
                        <InputError :message="form.errors.size" />
                    </fieldset>

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Quality</legend>
                        <label v-for="quality in qualities" :key="quality" class="flex items-center gap-2 text-sm capitalize">
                            <input v-model="form.quality" type="radio" name="quality" :value="quality" />
                            {{ quality }}
                        </label>
                        <InputError :message="form.errors.quality" />
                    </fieldset>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images <span class="font-normal text-neutral-500">(optional, up to {{ maxReferenceImages }})</span></Label>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                        <Button type="button" variant="ghost" @click="form.reference_images.splice(index, 1)">Remove</Button>
                    </div>
                    <InputError v-for="(_, index) in form.reference_images" :key="`error-${index}`" :message="(form.errors as Record<string, string>)[`reference_images.${index}`]" />
                    <div>
                        <Button
                            v-if="form.reference_images.length < maxReferenceImages"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="form.reference_images.push('')"
                        >
                            Add a reference image
                        </Button>
                    </div>
                </div>

                <section class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-900" aria-live="polite" data-test="estimate">
                    <h2 class="text-sm font-medium">What this will cost</h2>

                    <p v-if="!estimate && !estimateFailed" class="mt-1 text-sm text-neutral-500">Write a prompt to see the price.</p>
                    <p v-else-if="estimateFailed" class="mt-1 text-sm text-red-700 dark:text-red-400">
                        The price could not be worked out just now. Please try again in a moment.
                    </p>
                    <template v-else-if="estimate">
                        <p class="mt-1 text-2xl font-semibold" :class="{ 'opacity-50': estimating }">
                            {{ usd(estimate.lowest) }} – {{ usd(estimate.highest) }}
                        </p>
                        <p class="mt-1 text-sm text-neutral-500">
                            Most likely about {{ usd(estimate.expected) }}. The exact price depends on how much work the image takes and is only known when it
                            is finished. You are charged the real cost, never more than {{ usd(estimate.highest) }}, and nothing if it fails.
                        </p>
                        <p v-if="!estimate.affordable" class="mt-2 text-sm font-medium text-red-700 dark:text-red-400">
                            You need {{ usd(estimate.highest) }} of free credit for this image. Buy credits to continue.
                        </p>
                    </template>
                </section>

                <InputError :message="(form.errors as Record<string, string>).credit" />

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="!canSubmit">{{ form.processing ? 'Sending…' : 'Generate image' }}</Button>
                    <p class="text-xs text-neutral-500">Price is the image service's cost plus {{ markupPercent }}%.</p>
                </div>
            </form>

            <div class="flex flex-col gap-6">
                <CreditPanel :packs="creditPacks" />

                <section v-if="recent.length" class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-medium text-neutral-500">Latest</h2>
                        <Link :href="route('generations.index')" class="text-sm underline underline-offset-4">All history</Link>
                    </div>
                    <GenerationCard v-for="generation in recent.slice(0, 1)" :key="generation.id" :generation="generation" />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
