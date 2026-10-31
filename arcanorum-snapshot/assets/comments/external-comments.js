const widget = document.querySelector('[data-external-comments-provider]');

if (widget instanceof HTMLElement) {
    const provider = widget.dataset.externalCommentsProvider;
    const status = widget.querySelector('[data-external-comments-status]');
    const reportFailure = () => {
        if (status instanceof HTMLElement) {
            status.textContent = widget.dataset.loadError ?? '';
        }
    };

    if (provider === 'facebook') {
        const appId = widget.dataset.facebookAppId ?? '';
        const locale = widget.dataset.facebookLocale ?? '';
        if (/^[0-9]{5,32}$/.test(appId) && ['de_DE', 'en_GB'].includes(locale)) {
            const script = document.createElement('script');
            script.async = true;
            script.crossOrigin = 'anonymous';
            script.referrerPolicy = 'strict-origin-when-cross-origin';
            script.src = `https://connect.facebook.net/${locale}/sdk.js#xfbml=1&version=v23.0&appId=${appId}`;
            script.addEventListener('error', reportFailure, {once: true});
            document.head.append(script);
        } else {
            reportFailure();
        }
    } else if (provider === 'disqus') {
        const shortname = widget.dataset.disqusShortname ?? '';
        const identifier = widget.dataset.disqusIdentifier ?? '';
        const pageUrl = widget.dataset.disqusPageUrl ?? '';
        let canonicalUrl = null;
        try {
            canonicalUrl = new URL(pageUrl);
        } catch {
            canonicalUrl = null;
        }
        if (/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(shortname)
            && identifier !== ''
            && canonicalUrl instanceof URL
            && canonicalUrl.protocol === 'https:') {
            window.disqus_config = function () {
                this.page.url = canonicalUrl.href;
                this.page.identifier = identifier;
            };
            const script = document.createElement('script');
            script.async = true;
            script.referrerPolicy = 'strict-origin-when-cross-origin';
            script.src = `https://${shortname}.disqus.com/embed.js`;
            script.addEventListener('error', reportFailure, {once: true});
            document.head.append(script);
        } else {
            reportFailure();
        }
    }
}
