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

        if ('geo.dailymotion.com' === url.hostname) {
            return /^\/player\/[A-Za-z0-9_-]{1,32}\.html$/.test(url.pathname)
                && /^\?video=[A-Za-z0-9]{1,32}$/.test(url.search);
        }

        if ('www.facebook.com' === url.hostname && '/video/embed' === url.pathname) {
            const parameters = Array.from(url.searchParams.entries());

            return 1 === parameters.length
                && 'video_id' === parameters[0][0]
                && /^[0-9]{1,32}$/.test(parameters[0][1]);
        }

        if ('drive.google.com' === url.hostname && /^\/file\/d\/[A-Za-z0-9_-]{10,128}\/preview$/.test(url.pathname)) {
            const parameters = Array.from(url.searchParams.entries());

            return 0 === parameters.length
                || (1 === parameters.length
                    && 'resourcekey' === parameters[0][0]
                    && /^[A-Za-z0-9_-]{1,128}$/.test(parameters[0][1]));
        }

        if ('rutube.ru' === url.hostname) {
            return /^\/play\/embed\/(?:[a-f0-9]{32}|[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})$/.test(url.pathname)
                && '' === url.search;
        }

        if ('vk.com' === url.hostname && '/video_ext.php' === url.pathname) {
            const parameters = Array.from(url.searchParams.entries());
            const validOwner = parameters[0]?.[0] === 'oid' && /^-?[0-9]{1,20}$/.test(parameters[0][1]);
            const validId = parameters[1]?.[0] === 'id' && /^[0-9]{1,20}$/.test(parameters[1][1]);
            const validHash = 2 === parameters.length
                || (3 === parameters.length
                    && 'hash' === parameters[2][0]
                    && /^[A-Za-z0-9_-]{1,128}$/.test(parameters[2][1]));

            return validOwner && validId && validHash;
        }

        return 'player.youku.com' === url.hostname
            && /^\/embed\/[A-Za-z0-9_-]{6,64}={0,2}$/.test(url.pathname)
            && '' === url.search;
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
