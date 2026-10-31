(() => {
    const container = document.querySelector('[data-video-preroll-container]');
    const advertisement = container?.querySelector('[data-video-preroll-ad]');
    const contentVideo = document.querySelector('video[data-video-preroll-content]');
    const status = container?.querySelector('[data-video-preroll-status]');

    if (!advertisement || !contentVideo || !status) {
        return;
    }

    let released = false;
    let playedThrough = 0;
    contentVideo.removeAttribute('autoplay');
    contentVideo.setAttribute('data-video-preroll-locked', '');
    contentVideo.removeAttribute('controls');
    contentVideo.pause();
    const videoJsPlayer = contentVideo.__arcanorumVideoJsPlayer;
    if (videoJsPlayer && 'function' === typeof videoJsPlayer.controls) {
        videoJsPlayer.controls(false);
    } else {
        contentVideo.controls = false;
    }
    status.hidden = false;
    status.textContent = status.dataset.startedMessage || '';

    const releaseContent = (message) => {
        if (released) {
            return;
        }

        released = true;
        contentVideo.removeAttribute('data-video-preroll-locked');
        const videoJsPlayer = contentVideo.__arcanorumVideoJsPlayer;
        if (videoJsPlayer && 'function' === typeof videoJsPlayer.controls) {
            videoJsPlayer.controls(true);
        } else {
            contentVideo.controls = true;
        }
        advertisement.hidden = true;
        status.textContent = message || '';
    };

    contentVideo.addEventListener('play', () => {
        if (!released) {
            contentVideo.pause();
        }
    });
    advertisement.addEventListener('timeupdate', () => {
        const currentTime = Number(advertisement.currentTime);
        if (!Number.isFinite(currentTime)) {
            return;
        }

        if (currentTime <= playedThrough + 1.5) {
            playedThrough = Math.max(playedThrough, currentTime);
        } else {
            advertisement.currentTime = playedThrough;
        }
    });
    advertisement.addEventListener('seeking', () => {
        const currentTime = Number(advertisement.currentTime);
        if (Number.isFinite(currentTime) && currentTime > playedThrough + 0.25) {
            advertisement.currentTime = playedThrough;
        }
    });
    advertisement.addEventListener('ratechange', () => {
        if (Number(advertisement.playbackRate) > 1) {
            advertisement.playbackRate = 1;
        }
    });
    advertisement.addEventListener('ended', () => {
        releaseContent(status.dataset.completedMessage);
    }, {once: true});
    advertisement.addEventListener('error', () => {
        releaseContent(status.dataset.errorMessage);
    }, {once: true});
})();
