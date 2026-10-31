(() => {
    'use strict';

    document.querySelectorAll('[data-rich-text-editor]').forEach((widget) => {
        const surface = widget.querySelector('[data-rich-text-surface]');
        const textarea = widget.querySelector('textarea[data-rich-text-source]');
        const toolbar = widget.querySelector('[role="toolbar"]');
        const imageInput = widget.querySelector('[data-rich-text-image-input]');
        const imageError = widget.querySelector('[data-rich-text-image-error]');
        const form = widget.closest('form');

        if (!(surface instanceof HTMLElement)
            || !(textarea instanceof HTMLTextAreaElement)
            || !(toolbar instanceof HTMLElement)
            || !(form instanceof HTMLFormElement)
        ) {
            return;
        }

        const syncToTextarea = () => {
            const serializableSurface = surface.cloneNode(true);
            serializableSurface.querySelectorAll('img[data-rich-text-persist-src]').forEach((image) => {
                const storedSource = image.getAttribute('data-rich-text-persist-src');
                if (storedSource && /^\/content-inline-images\/[a-f0-9]{64}$/.test(storedSource)) {
                    image.setAttribute('src', storedSource);
                }
                image.removeAttribute('data-rich-text-persist-src');
            });
            textarea.value = serializableSurface.innerHTML;
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

        const sourceRequired = textarea.required;
        surface.hidden = false;
        textarea.hidden = true;
        textarea.required = false;
        surface.setAttribute('aria-required', sourceRequired ? 'true' : 'false');
        toolbar.hidden = false;
        surface.addEventListener('input', syncToTextarea);
        form.addEventListener('submit', syncToTextarea);

        let savedRange = null;
        let pendingAlt = '';

        const showImageError = (message) => {
            if (!(imageError instanceof HTMLElement)) {
                return;
            }

            imageError.textContent = message;
            imageError.hidden = '' === message;
        };

        const rememberSelection = () => {
            const selection = window.getSelection();
            if (!selection || 0 === selection.rangeCount) {
                savedRange = null;

                return;
            }

            const range = selection.getRangeAt(0);
            savedRange = surface.contains(range.commonAncestorContainer) ? range.cloneRange() : null;
        };

        const insertUploadedImage = (url, previewUrl) => {
            const image = document.createElement('img');
            image.src = previewUrl;
            if (previewUrl !== url) {
                image.dataset.richTextPersistSrc = url;
            }
            image.alt = pendingAlt;

            const selection = window.getSelection();
            if (savedRange && surface.contains(savedRange.commonAncestorContainer) && selection) {
                selection.removeAllRanges();
                selection.addRange(savedRange);
                savedRange.insertNode(image);
                savedRange.setStartAfter(image);
                savedRange.collapse(true);
                selection.removeAllRanges();
                selection.addRange(savedRange);
            } else {
                surface.append(image);
            }

            syncToTextarea();
        };

        if (imageInput instanceof HTMLInputElement) {
            imageInput.addEventListener('change', async () => {
                const file = imageInput.files?.[0];
                imageInput.value = '';
                if (!file) {
                    return;
                }

                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size < 1 || file.size > 4_194_304) {
                    showImageError('Wähle ein JPEG-, PNG- oder WebP-Bild mit höchstens 4 MiB.');

                    return;
                }

                const uploadUrl = widget.dataset.richTextImageUploadUrl ?? '';
                const token = widget.dataset.richTextImageUploadToken ?? '';
                if ('' === uploadUrl || '' === token) {
                    showImageError('Speichere den Inhalt zuerst, bevor du Bilder hochlädst.');

                    return;
                }

                const payload = new FormData();
                payload.append('_token', token);
                payload.append('image', file, file.name);

                try {
                    const response = await window.fetch(uploadUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        body: payload,
                    });
                    if (!response.ok) {
                        throw new Error('Der Bild-Upload wurde abgelehnt. Prüfe Dateityp, Größe und Berechtigung.');
                    }

                    const result = await response.json();
                    const imageUrl = new URL(result.url, window.location.href);
                    if (imageUrl.origin !== window.location.origin
                        || !/^\/content-inline-images\/[a-f0-9]{64}$/.test(imageUrl.pathname)
                        || '' !== imageUrl.search
                        || '' !== imageUrl.hash
                    ) {
                        throw new Error('Der Server hat keine sichere lokale Bildadresse zurückgegeben.');
                    }

                    let previewPath = imageUrl.pathname;
                    if (undefined !== result.preview_url) {
                        const previewUrl = new URL(result.preview_url, window.location.href);
                        const uploadPath = new URL(uploadUrl, window.location.href).pathname;
                        const categoryUpload = uploadPath.match(/^\/admin\/content-inline-images\/(news-category|video-category)\/([a-f0-9]{32})$/);
                        const expectedPreviewPath = categoryUpload
                            ? `/admin/content-inline-images/${categoryUpload[1]}/${categoryUpload[2]}/${imageUrl.pathname.slice('/content-inline-images/'.length)}`
                            : '';
                        if (previewUrl.origin !== window.location.origin
                            || '' === expectedPreviewPath
                            || previewUrl.pathname !== expectedPreviewPath
                            || '' !== previewUrl.search
                            || '' !== previewUrl.hash
                        ) {
                            throw new Error('Der Server hat keine sichere private Bildvorschau zurückgegeben.');
                        }
                        previewPath = previewUrl.pathname;
                    }

                    showImageError('');
                    insertUploadedImage(imageUrl.pathname, previewPath);
                } catch (error) {
                    showImageError(error instanceof Error ? error.message : 'Das Bild konnte nicht hochgeladen werden.');
                }
            });
        }

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
            if ('insertImage' === command) {
                if (!(imageInput instanceof HTMLInputElement) || button.disabled) {
                    showImageError('Speichere den Inhalt zuerst, bevor du Bilder hochlädst.');

                    return;
                }

                const alt = window.prompt('Alternativtext für das Bild (leer lassen, wenn es dekorativ ist)');
                if (null === alt) {
                    return;
                }
                if (alt.length > 500) {
                    showImageError('Der Alternativtext darf höchstens 500 Zeichen lang sein.');

                    return;
                }

                pendingAlt = alt;
                showImageError('');
                rememberSelection();
                imageInput.click();

                return;
            }
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
