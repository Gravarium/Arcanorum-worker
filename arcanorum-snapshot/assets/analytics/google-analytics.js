(() => {
    const element = document.currentScript;
    const measurementId = element?.dataset?.measurementId;
    const pageType = element?.dataset?.pageType;
    const allowedPageTypes = new Set([
        'home',
        'search',
        'video_categories',
        'video_list',
        'video_category',
        'popular_videos',
        'video',
        'news_list',
        'news_category',
        'news_article',
        'series_list',
        'series',
        'episode',
        'pages',
        'page',
    ]);

    if (!/^G-[A-Z0-9]{10}$/.test(measurementId ?? '') || !allowedPageTypes.has(pageType ?? '')) {
        return;
    }

    const pageLocation = new URL(`/_analytics/${pageType}`, window.location.origin).href;
    const providerScript = document.createElement('script');
    providerScript.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
    providerScript.async = true;
    providerScript.referrerPolicy = 'no-referrer';

    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
        window.dataLayer.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', measurementId, {
        send_page_view: false,
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
        page_location: pageLocation,
        page_referrer: '',
        page_title: pageType,
    });
    window.gtag('event', 'page_view', {
        page_location: pageLocation,
        page_referrer: '',
        page_title: pageType,
    });

    document.head.appendChild(providerScript);
})();
