/**
 * Copying the one-time Masar credential (Masar CONTRACT §13.28.10).
 *
 * The previous implementation was a bare
 * `x-on:click="navigator.clipboard.writeText(...)"`. That has three defects, and
 * together they produce exactly the reported symptom — a button that appears to do
 * nothing:
 *
 *   1. `writeText` returns a promise whose result was never observed, so a success
 *      and a failure looked identical from the operator's side. There was no
 *      feedback of any kind, which also made the real cause undiagnosable.
 *   2. A rejected promise (`NotAllowedError` when the document is not focused, or
 *      permission is refused) was swallowed as an unhandled rejection.
 *   3. There was no second path. Anywhere `navigator.clipboard` is absent or
 *      blocked — a non-secure origin, a Permissions-Policy or CSP restriction, or
 *      an embedded/in-app webview, all of them ordinary on a phone — the copy had
 *      nowhere else to go.
 *
 * So this keeps the async API, observes its result, and falls back to a selection
 * copy when it fails for any reason.
 *
 * **The secret is read from the DOM node that already displays it** rather than
 * being passed in as an argument. That is deliberate: the password is already
 * rendered in this response's markup and nowhere else, so reading `textContent`
 * adds no second copy of it. Putting it in an Alpine attribute, an action argument
 * or a JS variable captured at render time would.
 *
 * Nothing here persists anything: no `localStorage`, no `sessionStorage`, no
 * IndexedDB, no cookie, no Livewire property, no server request. The confirmation
 * is a local flag that expires on a timer, so even the "it worked" signal leaves
 * nothing behind.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('masarCredentialCopy', () => ({
        /** Which field was last copied — 'login' | 'password' | null. Never the value. */
        copied: null,

        /** Pending reset of the confirmation, so repeated taps do not stack timers. */
        copiedTimeout: null,

        /**
         * Copy one field, and only ever from an explicit tap.
         *
         * `which` names the field for the confirmation text; it is never the secret.
         */
        async copyFrom(ref, which) {
            const source = this.$refs[ref];

            if (! source) {
                return;
            }

            // `textContent` and not `innerText`: the value is rendered inside a
            // <code> element and must be copied exactly as issued, with no
            // collapsing of whitespace that a password could legitimately contain.
            const value = source.textContent;

            if (! value) {
                return;
            }

            const copied = (await this.viaClipboardApi(value)) || this.viaSelection(value);

            this.announce(copied ? which : null);
        },

        /**
         * The modern path. Returns false rather than throwing, so the caller can
         * fall through to the selection copy on any refusal.
         */
        async viaClipboardApi(value) {
            if (! navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                return false;
            }

            try {
                await navigator.clipboard.writeText(value);

                return true;
            } catch {
                // NotAllowedError, a blocked permission, or an insecure context.
                // Deliberately not logged: the thrown object can carry the attempted
                // value in some engines, and this secret must not reach the console.
                return false;
            }
        },

        /**
         * The fallback: a detached textarea, selected and copied inside the same
         * click handler, then removed immediately.
         *
         * `readOnly` plus a non-zero font size keeps iOS from zooming to and showing
         * a keyboard for an element the operator never sees, and `setSelectionRange`
         * is what actually selects the text there — `select()` alone does not.
         */
        viaSelection(value) {
            const field = document.createElement('textarea');

            field.value = value;
            field.setAttribute('readonly', 'readonly');
            field.setAttribute('aria-hidden', 'true');
            field.style.position = 'fixed';
            field.style.top = '0';
            field.style.left = '-9999px';
            field.style.fontSize = '16px';
            field.style.opacity = '0';

            document.body.appendChild(field);

            try {
                field.focus();
                field.select();
                field.setSelectionRange(0, value.length);

                return document.execCommand('copy');
            } catch {
                return false;
            } finally {
                // Immediately, and in a finally so a throw above cannot leave a node
                // holding the password in the document.
                field.value = '';
                field.remove();
            }
        },

        /** Local, transient confirmation. Nothing is sent and nothing is stored. */
        announce(which) {
            this.copied = which;

            if (this.copiedTimeout) {
                clearTimeout(this.copiedTimeout);
            }

            this.copiedTimeout = setTimeout(() => {
                this.copied = null;
                this.copiedTimeout = null;
            }, 2000);
        },
    }));
});
