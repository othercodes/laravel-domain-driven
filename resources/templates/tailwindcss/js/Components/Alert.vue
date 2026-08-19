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

/**
 * Builds the body of the validation dialog as elements rather than as markup,
 * so a message containing angle brackets stays a message.
 */
const listOf = (errors) => {
    const list = document.createElement('ul');
    list.className = 'text-start';

    errors.forEach(([field, message]) => {
        const item = document.createElement('li');
        item.textContent = `${field}: ${message}`;
        list.append(item);
    });

    return list;
};

const showErrors = (errors) => {
    const entries = Object.entries(errors);

    // Errors keyed into a bag belong to a form, and the form prints them
    // beside the field they came from. Interrupting with a dialog as well
    // would say the same thing twice, further from where it can be fixed.
    if (entries.some(([, message]) => typeof message === 'object')) {
        return;
    }

    dialog(entries.length === 1
        ? { icon: 'error', titleText: entries[0][0], text: entries[0][1] }
        : { icon: 'error', titleText: 'Please check the form', html: listOf(entries) });
};

watchEffect(() => {
    const alert = page.props.context?.flash?.alert;

    // Only one dialog can be open: firing a second destroys the first. A
    // message somebody wrote by hand outranks one assembled from field names,
    // and the flash carrying it is spent either way.
    if (alert) {
        showAlert(alert);

        return;
    }

    if (Object.keys(page.props.errors ?? {}).length > 0) {
        showErrors(page.props.errors);
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
