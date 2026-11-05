(() => {
    const providerHosts = new Set([
        'youtu.be', 'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'vimeo.com', 'www.vimeo.com', 'player.vimeo.com',
        'dailymotion.com', 'www.dailymotion.com',
        'facebook.com', 'www.facebook.com', 'drive.google.com',
        'rutube.ru', 'www.rutube.ru',
        'vk.com', 'www.vk.com', 'vk.ru', 'www.vk.ru', 'vkvideo.ru', 'www.vkvideo.ru',
        'youku.com', 'www.youku.com', 'v.youku.com', 'player.youku.com',
    ]);

    const isSafeProviderThumbnailUrl = value => {
        if ('string' !== typeof value || value.length < 1 || value.length > 2048) {
            return false;
        }

        try {
            const url = new URL(value);
            const allowedHost = ['i.ytimg.com', 'img.youtube.com', 'i.vimeocdn.com'].includes(url.hostname)
                || /^s[1-5]\.dmcdn\.net$/.test(url.hostname);

            return 'https:' === url.protocol
                && '' === url.username
                && '' === url.password
                && '' === url.port
                && '' === url.hash
                && allowedHost;
        } catch {
            return false;
        }
    };

    for (const form of document.querySelectorAll('[data-video-source-metadata]')) {
        const button = form.querySelector('[data-video-metadata-fetch]');
        const status = form.querySelector('[data-video-metadata-status]');
        const sourceInput = form.querySelector('[name="source_url"]');
        const titleInput = form.querySelector('[name="title"]');
        const descriptionInput = form.querySelector('[name="description"]');
        const durationMinutesInput = form.querySelector('[name="duration_minutes"]');
        const durationSecondsInput = form.querySelector('[name="duration_seconds_part"]');
        const thumbnailUrlInput = form.querySelector('[name="thumbnail_url"]');
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
        sourceInput.addEventListener('input', () => {
            if ('file' === sourceTypeInput.value || '' === sourceInput.value.trim()) {
                return;
            }

            let inferredSourceType = null;
            try {
                const url = new URL(sourceInput.value.trim());
                if ('https:' !== url.protocol || '' !== url.username || '' !== url.password) {
                    return;
                }
                if (/\.m3u8$/i.test(url.pathname)) {
                    inferredSourceType = 'stream';
                } else if (/\.(?:mp4|m4v|webm|ogv|ogg|mp3)$/i.test(url.pathname)) {
                    inferredSourceType = 'url';
                } else if (providerHosts.has(url.hostname.toLowerCase())) {
                    inferredSourceType = 'embed';
                }
            } catch {
                return;
            }

            if (null !== inferredSourceType && sourceTypeInput.value !== inferredSourceType) {
                sourceTypeInput.value = inferredSourceType;
                updateSourceFields();
            }
        });
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
                    || (Object.hasOwn(metadata, 'thumbnail_url') && !isSafeProviderThumbnailUrl(metadata.thumbnail_url))
                ) {
                    throw new Error('provider-metadata-rejected');
                }

                titleInput.value = metadata.title;
                descriptionInput.value = metadata.description;
                if (null !== metadata.duration_seconds) {
                    durationMinutesInput.value = String(Math.floor(metadata.duration_seconds / 60));
                    durationSecondsInput.value = String(metadata.duration_seconds % 60);
                }
                if (thumbnailUrlInput instanceof HTMLInputElement
                    && '' === thumbnailUrlInput.value.trim()
                    && 'string' === typeof metadata.thumbnail_url
                ) {
                    thumbnailUrlInput.value = metadata.thumbnail_url;
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
