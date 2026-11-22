<script setup lang="ts">
import CreditPanel from '@/components/CreditPanel.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PriceRange from '@/components/PriceRange.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { usd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { Estimate, Generation } from '@/types/generation';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Sparkles, X } from 'lucide-vue-next';
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

const sizeNames: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };
const qualityNotes: Record<string, string> = {
    low: 'Quick drafts and ideas',
    medium: 'Good for most images',
    high: 'Finest detail, costs most',
};
const ideas = ['A lighthouse on a cliff at dawn, oil painting', 'Isometric cutaway of a tiny bakery, soft light', 'A red fox in fresh snow, 35mm photo'];

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

function shape(size: string) {
    const [width, height] = size.split('x').map(Number);

    return { aspectRatio: `${width} / ${height}`, [width >= height ? 'width' : 'height']: '2.25rem' };
}

function submit() {
    form.transform((data) => ({ ...data, reference_images: references.value })).post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-6xl gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:p-6">
            <form id="generate" class="flex flex-col gap-8" @submit.prevent="submit">
                <header>
                    <h1 class="text-2xl font-semibold tracking-tight">Create an image</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Describe it, pick a shape and a quality. You see what it costs before anything is sent.</p>
                </header>

                <section class="grid gap-2">
                    <label for="prompt" class="text-sm font-medium">What should the image show?</label>
                    <div class="rounded-2xl border border-input bg-card shadow-sm transition focus-within:border-violet-400 focus-within:ring-4 focus-within:ring-violet-500/10">
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            :maxlength="maxPromptLength"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="block w-full resize-y rounded-2xl border-0 bg-transparent px-4 py-3 text-base leading-relaxed placeholder:text-muted-foreground/70 focus:outline-none focus:ring-0"
                        ></textarea>
                        <div class="flex flex-wrap items-center gap-2 border-t border-border px-3 py-2">
                            <template v-if="form.prompt === ''">
                                <span class="text-xs text-muted-foreground">Try:</span>
                                <button
                                    v-for="idea in ideas"
                                    :key="idea"
                                    type="button"
                                    class="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground transition hover:bg-violet-100 hover:text-violet-800 dark:hover:bg-violet-950 dark:hover:text-violet-200"
                                    @click="form.prompt = idea"
                                >
                                    {{ idea }}
                                </button>
                            </template>
                            <span class="ml-auto text-xs tabular-nums text-muted-foreground">{{ form.prompt.length }} / {{ maxPromptLength }}</span>
                        </div>
                    </div>
                    <InputError :message="form.errors.prompt" />
                </section>

                <section class="grid gap-6 sm:grid-cols-2">
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">Shape</legend>
                        <div class="grid grid-cols-3 gap-2">
                            <label
                                v-for="size in sizes"
                                :key="size"
                                :class="[
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-xl border p-3 text-center transition',
                                    form.size === size
                                        ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-500/20 dark:bg-violet-950/40'
                                        : 'border-border bg-card hover:border-violet-300',
                                ]"
                            >
                                <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                                <span class="flex h-10 items-center justify-center">
                                    <span
                                        :class="['rounded-[3px] border-2', form.size === size ? 'border-violet-500 bg-violet-200/60' : 'border-muted-foreground/40']"
                                        :style="shape(size)"
                                    ></span>
                                </span>
                                <span class="text-sm font-medium leading-none">{{ sizeNames[size] ?? size }}</span>
                                <span class="text-[11px] leading-none text-muted-foreground">{{ size.replace('x', ' × ') }}</span>
                            </label>
                        </div>
                        <InputError :message="form.errors.size" class="mt-2" />
                    </fieldset>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">Quality</legend>
                        <div class="grid gap-2">
                            <label
                                v-for="quality in qualities"
                                :key="quality"
                                :class="[
                                    'flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-3 py-2.5 transition',
                                    form.quality === quality
                                        ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-500/20 dark:bg-violet-950/40'
                                        : 'border-border bg-card hover:border-violet-300',
                                ]"
                            >
                                <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                                <span class="text-sm font-medium capitalize">{{ quality }}</span>
                                <span class="text-xs text-muted-foreground">{{ qualityNotes[quality] }}</span>
                            </label>
                        </div>
                        <InputError :message="form.errors.quality" class="mt-2" />
                    </fieldset>
                </section>

                <section class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">
                            Reference images <span class="font-normal text-muted-foreground">· optional, up to {{ maxReferenceImages }}</span>
                        </span>
                        <Button
                            v-if="form.reference_images.length < maxReferenceImages"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="form.reference_images.push('')"
                        >
                            <ImagePlus class="size-4" />
                            Add
                        </Button>
                    </div>
                    <p v-if="form.reference_images.length === 0" class="text-xs text-muted-foreground">
                        Links to images whose style should guide the result. Each one adds a little to the price.
                    </p>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="grid gap-1">
                        <div class="flex gap-2">
                            <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                            <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${index + 1}`" @click="form.reference_images.splice(index, 1)">
                                <X class="size-4" />
                            </Button>
                        </div>
                        <InputError :message="(form.errors as Record<string, string>)[`reference_images.${index}`]" />
                    </div>
                </section>
            </form>

            <aside class="flex flex-col gap-6 lg:sticky lg:top-6 lg:self-start">
                <section class="rounded-2xl border border-border bg-card p-5 shadow-sm" aria-live="polite" data-test="estimate">
                    <h2 class="text-sm font-medium text-muted-foreground">Price</h2>

                    <div v-if="!estimate && estimating" class="mt-3 space-y-3" aria-label="Working out the price">
                        <div class="h-9 w-32 animate-pulse rounded-lg bg-muted"></div>
                        <div class="h-2 animate-pulse rounded-full bg-muted"></div>
                    </div>

                    <div v-else-if="!estimate && !estimateFailed" class="mt-3 text-sm text-muted-foreground">Write a prompt and the price appears here.</div>

                    <div v-else-if="estimateFailed" class="mt-3 text-sm text-rose-700 dark:text-rose-400">
                        The price could not be worked out just now. Please try again in a moment.
                    </div>

                    <template v-else-if="estimate">
                        <p class="mt-2 text-xs uppercase tracking-wide text-muted-foreground">Most likely</p>
                        <p class="text-4xl font-semibold tracking-tight transition-opacity" :class="{ 'opacity-40': estimating }">
                            <Money :amount="estimate.expected" />
                        </p>

                        <PriceRange class="mt-4" :estimate="estimate" />

                        <ul class="mt-4 space-y-1.5 text-sm text-muted-foreground">
                            <li>The exact price is known when the image is finished.</li>
                            <li>
                                You pay the real cost, never more than <span class="font-medium text-foreground">{{ usd(estimate.highest) }}</span>.
                            </li>
                            <li>If it fails, you pay nothing.</li>
                        </ul>

                        <p v-if="!estimate.affordable" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm font-medium text-rose-800 dark:bg-rose-950/50 dark:text-rose-300">
                            You need {{ usd(estimate.highest) }} of free credit for this image. Add credit below to continue.
                        </p>
                    </template>

                    <InputError :message="(form.errors as Record<string, string>).credit" class="mt-3" />

                    <Button type="submit" form="generate" size="lg" class="mt-5 w-full" :disabled="!canSubmit">
                        <Sparkles class="size-4" />
                        {{ form.processing ? 'Sending…' : 'Generate image' }}
                    </Button>
                    <p class="mt-2 text-center text-xs text-muted-foreground">The image service's cost plus {{ markupPercent }}%.</p>
                </section>

                <CreditPanel :packs="creditPacks" />

                <section v-if="recent.length" class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-medium text-muted-foreground">Your latest</h2>
                        <Link :href="route('generations.index')" class="text-sm font-medium text-violet-700 hover:underline dark:text-violet-300">All images</Link>
                    </div>
                    <GenerationCard :generation="recent[0]" />
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
