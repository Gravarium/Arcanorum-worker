(() => {
    const selectors = document.querySelectorAll('select[data-video-quality-selector]');

    for (const selector of selectors) {
        const video = document.getElementById(selector.dataset.videoPlayerId);
        if (!(video instanceof HTMLVideoElement)) {
            continue;
        }

        const status = selector.parentElement?.querySelector('[data-video-quality-status]');
        const setStatus = (message) => {
            if (status && message) {
                status.textContent = message;
            }
        };

        selector.addEventListener('change', () => {
            const selected = selector.selectedOptions.item(0);
            if (!selected || !selected.value) {
                setStatus(selector.dataset.qualityMessageError);
                return;
            }

            let source;
            try {
                source = new URL(selected.value, document.baseURI);
            } catch {
                setStatus(selector.dataset.qualityMessageError);
                selector.selectedIndex = 0;
                return;
            }

            if (source.username || source.password || source.hash
                || (source.origin !== window.location.origin && 'https:' !== source.protocol)
            ) {
                setStatus(selector.dataset.qualityMessageError);
                selector.selectedIndex = 0;
                return;
            }

            const mimeType = selected.dataset.mimeType;
            if (!mimeType || !['video/mp4', 'video/webm', 'video/ogg'].includes(mimeType)) {
                setStatus(selector.dataset.qualityMessageError);
                selector.selectedIndex = 0;
                return;
            }

            const player = video.__arcanorumVideoJsPlayer;
            const currentTime = player ? player.currentTime() : video.currentTime;
            const wasPlaying = player ? !player.paused() : !video.paused;
            const restorePlayback = () => {
                if (Number.isFinite(currentTime) && currentTime >= 0) {
                    const duration = player ? player.duration() : video.duration;
                    if (!Number.isFinite(duration) || currentTime < duration) {
                        if (player) {
                            player.currentTime(currentTime);
                        } else {
                            video.currentTime = currentTime;
                        }
                    }
                }
                if (wasPlaying) {
                    const playback = player ? player.play() : video.play();
                    if (playback && 'function' === typeof playback.catch) {
                        playback.catch(() => {});
                    }
                }
            };

            if (player) {
                player.one('loadedmetadata', restorePlayback);
                player.src({ src: source.href, type: mimeType });
            } else {
                video.addEventListener('loadedmetadata', restorePlayback, { once: true });
                video.src = source.href;
                video.load();
            }

            setStatus(selector.dataset.qualityMessageChanged);
        });
    }
})();
