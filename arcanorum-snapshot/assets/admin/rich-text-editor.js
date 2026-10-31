(() => {
    'use strict';

    document.querySelectorAll('[data-rich-text-editor]').forEach((widget) => {
        const surface = widget.querySelector('[data-rich-text-surface]');
        const textarea = widget.querySelector('textarea[name="body"]');
        const toolbar = widget.querySelector('[role="toolbar"]');
        const form = widget.closest('form');

        if (!(surface instanceof HTMLElement)
            || !(textarea instanceof HTMLTextAreaElement)
            || !(toolbar instanceof HTMLElement)
            || !(form instanceof HTMLFormElement)
        ) {
            return;
        }

        const syncToTextarea = () => {
            textarea.value = surface.innerHTML;
        };

        surface.addEventListener('paste', (event) => {
            event.preventDefault();
            const plainText = event.clipboardData?.getData('text/plain') ?? '';
            document.execCommand('insertText', false, plainText);
            syncToTextarea();
        });
        surface.addEventListener('drop', (event) => event.preventDefault());
        surface.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof Element && target.closest('a')) {
                event.preventDefault();
            }
        });

        surface.hidden = false;
        textarea.hidden = true;
        textarea.required = false;
        surface.setAttribute('aria-required', 'true');
        toolbar.hidden = false;
        surface.addEventListener('input', syncToTextarea);
        form.addEventListener('submit', syncToTextarea);

        toolbar.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const button = target.closest('button[data-rich-text-command]');
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            surface.focus();
            const command = button.dataset.richTextCommand;
            if ('createLink' === command) {
                const url = window.prompt('Linkadresse eingeben');
                if (null === url || '' === url.trim()) {
                    return;
                }
                const destination = url.trim();
                let protocol;
                try {
                    protocol = new URL(destination, window.location.href).protocol;
                } catch {
                    return;
                }
                if (!['http:', 'https:', 'mailto:'].includes(protocol)) {
                    return;
                }
                document.execCommand('createLink', false, destination);
            } else if ('formatBlock' === command) {
                const element = button.dataset.richTextValue;
                if ('h2' === element || 'blockquote' === element) {
                    document.execCommand(command, false, element);
                }
            } else if (['bold', 'italic', 'insertUnorderedList', 'insertOrderedList'].includes(command)) {
                document.execCommand(command, false);
            }

            syncToTextarea();
        });
    });
})();
