(() => {
    for (const form of document.querySelectorAll('[data-video-source-metadata]')) {
        const button = form.querySelector('[data-video-metadata-fetch]');
        const status = form.querySelector('[data-video-metadata-status]');
        const sourceInput = form.querySelector('[name="source_url"]');
        const titleInput = form.querySelector('[name="title"]');
        const descriptionInput = form.querySelector('[name="description"]');
        const durationMinutesInput = form.querySelector('[name="duration_minutes"]');
        const durationSecondsInput = form.querySelector('[name="duration_seconds_part"]');
        const sourceTypeInput = form.querySelector('[data-video-source-type]');
        const sourceUrlField = form.querySelector('[data-video-source-url-field]');
        const sourceUrlLabel = form.querySelector('[data-video-source-url-label]');
        const sourceFileField = form.querySelector('[data-video-source-file-field]');
        const sourceFileInput = form.querySelector('[data-video-source-file]');
        const sourceHelp = form.querySelectorAll('[data-video-source-help]');

        if (!(button instanceof HTMLButtonElement)
            || !(status instanceof HTMLElement)
            || !(sourceInput instanceof HTMLInputElement)
            || !(titleInput instanceof HTMLInputElement)
            || !(descriptionInput instanceof HTMLTextAreaElement)
            || !(durationMinutesInput instanceof HTMLInputElement)
            || !(durationSecondsInput instanceof HTMLInputElement)
            || !(sourceTypeInput instanceof HTMLSelectElement)
            || !(sourceUrlField instanceof HTMLElement)
            || !(sourceUrlLabel instanceof HTMLElement)
            || !(sourceFileField instanceof HTMLElement)
            || !(sourceFileInput instanceof HTMLInputElement)
        ) {
            continue;
        }

        const updateSourceFields = () => {
            const sourceType = sourceTypeInput.value;
            const isFile = 'file' === sourceType;
            sourceUrlField.hidden = isFile;
            sourceInput.required = !isFile;
            sourceFileField.hidden = !isFile;
            sourceFileInput.required = isFile && '1' !== form.dataset.videoEdit;
            if (!isFile) {
                sourceFileInput.value = '';
            }
            button.hidden = 'embed' !== sourceType;

            if ('embed' === sourceType) {
                sourceUrlLabel.textContent = form.dataset.providerUrlLabel || '';
            } else if ('stream' === sourceType) {
                sourceUrlLabel.textContent = form.dataset.streamUrlLabel || '';
            } else {
                sourceUrlLabel.textContent = form.dataset.directUrlLabel || '';
            }

            for (const help of sourceHelp) {
                help.hidden = help.dataset.videoSourceHelp !== sourceType;
            }
        };

        sourceTypeInput.addEventListener('change', updateSourceFields);
        updateSourceFields();

        button.addEventListener('click', async () => {
            const endpoint = form.dataset.metadataEndpoint || '';
            const csrfToken = form.dataset.metadataCsrfToken || '';
            const sourceUrl = sourceInput.value.trim();
            if ('' === endpoint || '' === csrfToken || '' === sourceUrl) {
                status.textContent = form.dataset.metadataError || '';
                return;
            }

            button.disabled = true;
            status.textContent = form.dataset.metadataLoading || '';
            try {
                const payload = new URLSearchParams({
                    _token: csrfToken,
                    source_url: sourceUrl,
                });
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    referrerPolicy: 'no-referrer',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: payload,
                });
                const metadata = await response.json();
                if (!response.ok
                    || 'string' !== typeof metadata.title
                    || 'string' !== typeof metadata.description
                    || !Number.isInteger(metadata.duration_seconds) && null !== metadata.duration_seconds
                    || metadata.title.length < 1
                    || metadata.title.length > 180
                    || metadata.description.length > 20000
                    || (null !== metadata.duration_seconds && (metadata.duration_seconds < 0 || metadata.duration_seconds > 2147483647))
                ) {
                    throw new Error('provider-metadata-rejected');
                }

                titleInput.value = metadata.title;
                descriptionInput.value = metadata.description;
                if (null !== metadata.duration_seconds) {
                    durationMinutesInput.value = String(Math.floor(metadata.duration_seconds / 60));
                    durationSecondsInput.value = String(metadata.duration_seconds % 60);
                }
                status.textContent = form.dataset.metadataLoaded || '';
            } catch {
                status.textContent = form.dataset.metadataError || '';
            } finally {
                button.disabled = false;
            }
        });
    }
})();
