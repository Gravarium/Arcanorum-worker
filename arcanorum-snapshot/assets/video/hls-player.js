(() => {
    const playlistType = 'application/vnd.apple.mpegurl';

    for (const video of document.querySelectorAll('video[data-hls-url]')) {
        const status = video.closest('.video-stage')?.querySelector('[data-hls-status]');
        const setStatus = (message) => {
            if (status && message) {
                status.textContent = message;
            }
        };
        let playlist;

        try {
            playlist = new URL(video.dataset.hlsUrl, document.baseURI);
        } catch {
            video.hidden = true;
            setStatus(video.dataset.hlsMessageInvalidUrl);
            continue;
        }

        if ('https:' !== playlist.protocol || playlist.username || playlist.password || playlist.hash) {
            video.hidden = true;
            setStatus(video.dataset.hlsMessageInvalidUrl);
            continue;
        }

        const showFailure = () => setStatus(video.dataset.hlsMessageError);

        video.addEventListener('loadedmetadata', () => {
            setStatus(video.dataset.hlsMessageReady);
        }, { once: true });
        video.addEventListener('error', showFailure, { once: true });

        if (video.canPlayType(playlistType)) {
            video.src = playlist.href;
            continue;
        }

        const HlsPlayer = window.Hls;
        if (!HlsPlayer || 'function' !== typeof HlsPlayer.isSupported || !HlsPlayer.isSupported()) {
            video.hidden = true;
            setStatus(video.dataset.hlsMessageUnsupported);
            continue;
        }

        let player;
        try {
            video.crossOrigin = 'anonymous';
            player = new HlsPlayer({ enableWorker: true });
            player.on(HlsPlayer.Events.MANIFEST_PARSED, () => {
                setStatus(video.dataset.hlsMessageReady);
            });
            player.on(HlsPlayer.Events.ERROR, (_event, data) => {
                if (data.fatal) {
                    player.destroy();
                    showFailure();
                }
            });
            player.loadSource(playlist.href);
            player.attachMedia(video);
        } catch {
            if (player) {
                player.destroy();
            }
            showFailure();
        }
    }
})();
