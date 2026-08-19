<script setup>
import { computed, ref, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const show = ref(true);
const style = ref('success');
const message = ref('');

watchEffect(async () => {
    style.value = page.flash?.banner?.style || 'success';
    message.value = page.flash?.banner?.message || '';
    show.value = true;
});

// Written out rather than composed from the style name: Tailwind only emits
// classes it can see as whole strings in the source, so `bg-${colour}-500`
// would compile to nothing at all.
//
// The bar shades are picked to clear 4.5:1 against the white text below, which
// is what rules out the obvious blue-500 at 3.8:1 and yellow-500 at 1.9:1.
// Contrast, not the palette's own numbering, is why these four are not a row.
const palette = {
    success: { bar: 'bg-indigo-500', badge: 'bg-indigo-600', button: 'hover:bg-indigo-600 focus:bg-indigo-600', icon: 'check' },
    info: { bar: 'bg-blue-600', badge: 'bg-blue-700', button: 'hover:bg-blue-700 focus:bg-blue-700', icon: 'info' },
    warning: { bar: 'bg-yellow-700', badge: 'bg-yellow-800', button: 'hover:bg-yellow-800 focus:bg-yellow-800', icon: 'alert' },
    danger: { bar: 'bg-red-700', badge: 'bg-red-600', button: 'hover:bg-red-600 focus:bg-red-600', icon: 'alert' },
};

// An unknown style renders as success rather than unstyled: a banner with no
// colour reads as a broken page, and the message still deserves to be seen.
const banner = computed(() => palette[style.value] ?? palette.success);
</script>

<template>
    <div>
        <div v-if="show && message" :class="banner.bar">
            <div class="max-w-screen-xl mx-auto py-2 px-3 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between flex-wrap">
                    <div class="w-0 flex-1 flex items-center min-w-0">
                        <span class="flex p-2 rounded-lg" :class="banner.badge">
                            <svg v-if="banner.icon === 'check'" class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>

                            <svg v-if="banner.icon === 'info'" class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>

                            <svg v-if="banner.icon === 'alert'" class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </span>

                        <p class="ms-3 font-medium text-sm text-white truncate">
                            {{ message }}
                        </p>
                    </div>

                    <div class="shrink-0 sm:ms-3">
                        <button
                            type="button"
                            class="-me-1 flex p-2 rounded-md focus:outline-none sm:-me-2 transition"
                            :class="banner.button"
                            aria-label="Dismiss"
                            @click.prevent="show = false"
                        >
                            <svg class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
