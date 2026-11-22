<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <!-- A spark struck from a square of pixels. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" :class="className" v-bind="$attrs" aria-hidden="true">
        <rect x="3" y="15" width="6" height="6" rx="1.5" opacity=".45" />
        <rect x="3" y="23" width="6" height="6" rx="1.5" opacity=".7" />
        <rect x="11" y="23" width="6" height="6" rx="1.5" />
        <path d="M21 2c.7 6.2 2.800 8.300 9 9-6.200.7-8.300 2.800-9 9-.7-6.200-2.800-8.300-9-9 6.200-.7 8.300-2.800 9-9Z" />
    </svg>
</template>
