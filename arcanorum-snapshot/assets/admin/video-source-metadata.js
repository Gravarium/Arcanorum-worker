(() => {
    for (const form of document.querySelectorAll('[data-video-source-metadata]')) {
        const button = form.querySelector('[data-video-metadata-fetch]');
        const status = form.querySelector('[data-video-metadata-status]');
        const sourceInput = form.querySelector('[name="source_url"]');
        const titleInput = form.querySelector('[name="title"]');
        const descriptionInput = form.querySelector('[name="description"]');
        const durationInput = form.querySelector('[name="duration_seconds"]');

        if (!(button instanceof HTMLButtonElement)
            || !(status instanceof HTMLElement)
            || !(sourceInput instanceof HTMLInputElement)
            || !(titleInput instanceof HTMLInputElement)
            || !(descriptionInput instanceof HTMLTextAreaElement)
            || !(durationInput instanceof HTMLInputElement)
        ) {
            continue;
        }

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
                    durationInput.value = String(metadata.duration_seconds);
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
