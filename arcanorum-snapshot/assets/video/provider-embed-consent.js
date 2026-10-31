(() => {
    const isApprovedEmbedUrl = (value) => {
        let url;

        try {
            url = new URL(value);
        } catch {
            return false;
        }

        if ('https:' !== url.protocol || url.username || url.password || url.port || url.hash) {
            return false;
        }

        if ('www.youtube-nocookie.com' === url.hostname) {
            return '' === url.search && /^\/embed\/[A-Za-z0-9_-]{11}\/?$/.test(url.pathname);
        }

        if ('player.vimeo.com' === url.hostname) {
            return /^\/video\/\d{1,12}\/?$/.test(url.pathname)
                && ('' === url.search || /^\?h=[A-Za-z0-9]{1,64}$/.test(url.search));
        }

        return 'geo.dailymotion.com' === url.hostname
            && /^\/player\/[A-Za-z0-9_-]{1,32}\.html$/.test(url.pathname)
            && /^\?video=[A-Za-z0-9]{1,32}$/.test(url.search);
    };

    for (const container of document.querySelectorAll('[data-provider-embed]')) {
        const button = container.querySelector('[data-provider-embed-load]');
        const status = container.querySelector('[data-provider-embed-status]');
        const url = container.dataset.embedUrl;

        if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement)) {
            continue;
        }

        button.addEventListener('click', () => {
            if (!isApprovedEmbedUrl(url)) {
                status.textContent = container.dataset.errorLabel || '';
                button.disabled = true;
                return;
            }

            const frame = document.createElement('iframe');
            frame.dataset.testid = 'video-embed-provider';
            frame.src = url;
            frame.title = container.dataset.embedTitle || '';
            frame.loading = 'lazy';
            frame.referrerPolicy = 'no-referrer';
            frame.sandbox.add('allow-scripts', 'allow-same-origin', 'allow-presentation');
            frame.allow = 'fullscreen; picture-in-picture';
            frame.allowFullscreen = true;
            container.append(frame);
            button.remove();
            status.textContent = container.dataset.loadLabel || '';
        }, { once: true });
    }
})();
