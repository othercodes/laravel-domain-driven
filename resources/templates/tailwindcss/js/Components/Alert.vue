<script setup>
import { watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Swal from 'sweetalert2';

const page = usePage();

// A banner is part of the page; an alert interrupts it. This component renders
// nothing of its own because SweetAlert2 mounts its dialog on the body, so the
// only reason it exists is to keep that decision out of the layout, next to
// Banner.vue rather than buried in it.

// The style token is SweetAlert2's own icon vocabulary, which is why the error
// macro flashes `error` here while its banner counterpart flashes `danger`.
// Modal.vue opens a native <dialog> with showModal(), which puts it in the top
// layer and makes everything behind it inert. A dialog mounted on the body then
// renders underneath it, invisible and unclickable, and leaves its scroll lock
// behind. So it is fired into whichever dialog is open, if one is.
const dialog = (options) => Swal.fire({
    target: document.querySelector('dialog[open]') ?? 'body',
    buttonsStyling: true,
    ...options,
});

// `titleText` and `text`, never `title` or `html`. Both of the ones not used
// here are parsed as markup and neither is sanitised, and a flashed alert is
// free to carry whatever a controller interpolated into it: an account name, or
// a filename that arrived from a form.
const showAlert = (alert) => dialog({
    icon: alert.style,
    titleText: alert.title || undefined,
    text: alert.message,
});

// Only what was flashed, never `page.props.errors`. Every form on every page
// prints its errors in red under the field they belong to, so a dialog listing
// them says the same thing twice, further from where it can be fixed. Worse,
// errors are an ordinary prop: the browser keeps them in the history entry and
// a partial reload merges them back, so the dialog reopened on the Back button
// and on every scoped router.reload(). A flash cannot do that. It is gone once
// read, which is the whole reason a one-off interruption travels as one.
watchEffect(() => {
    const alert = page.flash?.alert;

    if (alert) {
        showAlert(alert);
    }
// Runs after the DOM settles, not before it. The default flush would fire the
// dialog while the outgoing page is still mounted, which both measures the
// wrong document for scrollbar compensation and, since the target above is
// resolved here, can mount it into a <dialog> that is on its way out.
}, { flush: 'post' });
</script>

<template>
    <div />
</template>
