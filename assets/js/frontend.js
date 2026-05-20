(function () {
	'use strict';

	function formatTime(seconds) {
		if (!isFinite(seconds) || seconds < 0) {
			return '0:00';
		}
		var m = Math.floor(seconds / 60);
		var s = Math.floor(seconds % 60);
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function initPlayer(root) {
		var audio = root.querySelector('audio');
		var playBtn = root.querySelector('.article-tts-player__play');
		var progress = root.querySelector('.article-tts-player__progress');
		var fill = root.querySelector('.article-tts-player__progress-fill');
		var timeEl = root.querySelector('.article-tts-player__time');

		if (!audio || !playBtn || !progress || !fill || !timeEl) {
			return;
		}

		var playLabel = playBtn.getAttribute('data-play-label') || 'Play';
		var pauseLabel = playBtn.getAttribute('data-pause-label') || 'Pause';

		function updateProgress() {
			var cur = audio.currentTime || 0;
			var dur = audio.duration || 0;
			var pct = dur > 0 ? (cur / dur) * 100 : 0;
			fill.style.width = pct + '%';
			progress.setAttribute('aria-valuenow', Math.round(pct));
			timeEl.textContent = formatTime(cur) + ' / ' + formatTime(dur);
		}

		function seekFromEvent(e) {
			var rect = progress.getBoundingClientRect();
			var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
			var pct = Math.max(0, Math.min(1, x / rect.width));
			if (audio.duration) {
				audio.currentTime = audio.duration * pct;
			}
		}

		playBtn.addEventListener('click', function () {
			if (audio.paused) {
				audio.play();
			} else {
				audio.pause();
			}
		});

		audio.addEventListener('play', function () {
			root.classList.add('is-playing');
			playBtn.setAttribute('aria-label', pauseLabel);
		});
		audio.addEventListener('pause', function () {
			root.classList.remove('is-playing');
			playBtn.setAttribute('aria-label', playLabel);
		});
		audio.addEventListener('ended', function () {
			root.classList.remove('is-playing');
			playBtn.setAttribute('aria-label', playLabel);
		});
		audio.addEventListener('timeupdate', updateProgress);
		audio.addEventListener('loadedmetadata', updateProgress);
		audio.addEventListener('durationchange', updateProgress);

		progress.addEventListener('click', seekFromEvent);
		progress.addEventListener('keydown', function (e) {
			if (!audio.duration) return;
			if (e.key === 'ArrowRight') {
				audio.currentTime = Math.min(audio.duration, audio.currentTime + 5);
				e.preventDefault();
			} else if (e.key === 'ArrowLeft') {
				audio.currentTime = Math.max(0, audio.currentTime - 5);
				e.preventDefault();
			}
		});

		updateProgress();
	}

	function init() {
		document.querySelectorAll('[data-tts-player]').forEach(initPlayer);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
