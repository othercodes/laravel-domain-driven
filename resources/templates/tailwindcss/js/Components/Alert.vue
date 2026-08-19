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
const dialog = (options) => Swal.fire({
    buttonsStyling: true,
    ...options,
});

// Passed as `text`, never as `html`. SweetAlert2 does not sanitise `html`, and
// a flashed message is free to carry whatever a controller interpolated into
// it, an account name or a filename that came from a form.
const showAlert = (alert) => dialog({
    icon: alert.style,
    title: alert.title || undefined,
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
        ? { icon: 'error', title: entries[0][0], text: entries[0][1] }
        : { icon: 'error', title: 'Please check the form', html: listOf(entries) });
};

watchEffect(() => {
    const alert = page.props.context?.flash?.alert;

    if (alert) {
        showAlert(alert);
    }

    if (Object.keys(page.props.errors ?? {}).length > 0) {
        showErrors(page.props.errors);
    }
});
</script>

<template>
    <div />
</template>
