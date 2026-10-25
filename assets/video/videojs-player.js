(() => {
    const playerElements = document.querySelectorAll('video[data-video-player-engine="videojs"]');

    for (const video of playerElements) {
        const status = video.closest('.video-stage')?.querySelector('[data-video-player-status]');
        const setStatus = (message) => {
            if (status && message) {
                status.textContent = message;
            }
        };

        if (video.dataset.hlsUrl) {
            let playlist;
            try {
                playlist = new URL(video.dataset.hlsUrl, document.baseURI);
            } catch {
                video.hidden = true;
                setStatus(video.dataset.hlsMessageInvalidUrl || video.dataset.playerMessageError);
                continue;
            }

            if ('https:' !== playlist.protocol || playlist.username || playlist.password || playlist.hash) {
                video.hidden = true;
                setStatus(video.dataset.hlsMessageInvalidUrl || video.dataset.playerMessageError);
                continue;
            }
            video.crossOrigin = 'anonymous';
        }

        if ('function' !== typeof window.videojs) {
            setStatus(video.dataset.playerMessageError);
            continue;
        }

        try {
            const player = window.videojs(video, {
                controls: true,
                fluid: true,
                responsive: true,
                preload: video.getAttribute('preload') || 'metadata',
                autoplay: video.hasAttribute('autoplay') ? 'muted' : false,
                muted: video.hasAttribute('muted'),
            });

            player.on('loadedmetadata', () => {
                setStatus(video.dataset.playerMessageReady);
            });
            player.on('error', () => {
                setStatus(video.dataset.playerMessageError);
            });
        } catch {
            setStatus(video.dataset.playerMessageError);
        }
    }
})();
