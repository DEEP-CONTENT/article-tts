/* global jQuery, articleTTS */
(function ($) {
	'use strict';

	/**
	 * Poll cadence.
	 *
	 * Tight at the start because a short article is done in seconds, then slower:
	 * a long one takes minutes, and asking every five seconds for ten minutes is
	 * a hundred and twenty requests for one article.
	 */
	var FIRST_INTERVAL = 5000;
	var LATER_INTERVAL = 15000;
	var SLOW_DOWN_AFTER = 60000;

	/**
	 * When the browser stops asking. NOT when the rendition stops: the cron keeps
	 * going and the audio appears on the article either way. This only ends the
	 * live display.
	 */
	var GIVE_UP_AFTER = 1200000;

	function feedback($box, msg, isError) {
		var $fb = $box.find('.article-tts-feedback');
		$fb.text(msg);
		$fb.toggleClass('article-tts-error', !!isError);
		$fb.toggleClass('article-tts-success', !isError);
	}

	/**
	 * Show the finished audio without navigating.
	 *
	 * NEVER window.location.reload(). On post-new.php that URL creates a FRESH
	 * draft, so a reload after a successful rendition drops the editor into an
	 * empty article — and on any screen it would discard whatever is unsaved.
	 * The metabox has everything it needs to update itself.
	 */
	/**
	 * The status line comes from the server, rendered by the same PHP the metabox
	 * itself uses. Without this the box kept saying "Noch keine Audio-Version
	 * generiert" underneath a player that was already playing one.
	 */
	function setStatus($box, html) {
		if (html) {
			$box.find('.article-tts-status').html(html);
		}
	}

	function showAudio($box, url) {
		var $player = $box.find('audio');

		if ($player.length) {
			$player.attr('src', url);
			$player[0].load();
		} else {
			$('<audio controls preload="metadata" style="width:100%;"></audio>')
				.attr('src', url)
				.insertBefore($box.find('.article-tts-actions'));
		}

		// A running rendition is over; whatever said so has to go.
		$box.find('.article-tts-running, .article-tts-warning').remove();
		$box.attr('data-job-pending', '0');

		var $btn = $box.find('#article-tts-generate');
		$btn.prop('disabled', false).text(articleTTS.i18n.regenerate);
	}

	function progressText(data) {
		if (data.total > 1 && data.done >= 0) {
			return articleTTS.i18n.progress
				.replace('%1$s', data.done)
				.replace('%2$s', data.total);
		}

		return articleTTS.i18n.generating;
	}

	function poll($box, postId, startedAt) {
		// A hidden tab gets no useful timers anyway and the work continues
		// server-side; asking again when it comes back is enough.
		if (document.hidden) {
			setTimeout(function () {
				poll($box, postId, startedAt);
			}, LATER_INTERVAL);
			return;
		}

		$.post(articleTTS.ajaxUrl, {
			action: 'article_tts_status',
			nonce: articleTTS.nonce,
			post_id: postId
		})
			.done(function (res) {
				if (!res || !res.success) {
					feedback($box, articleTTS.i18n.failed, true);
					$box.find('#article-tts-generate').prop('disabled', false);
					return;
				}

				var data = res.data;

				if (data.job_status === 'completed' && data.url) {
					feedback($box, articleTTS.i18n.success, false);
					setStatus($box, data.statusHtml);
					showAudio($box, data.url);
					return;
				}

				if (data.job_status === 'failed' || data.job_status === 'expired') {
					feedback($box, articleTTS.i18n.failed + ': ' + (data.error || ''), true);
					$box.find('#article-tts-generate').prop('disabled', false);
					return;
				}

				var elapsed = Date.now() - startedAt;

				if (elapsed > GIVE_UP_AFTER) {
					// Deliberately not an error: nothing broke, we simply stop
					// watching. Saying so is what keeps the editor from clicking
					// again and paying for a second rendition.
					feedback($box, articleTTS.i18n.background, false);
					return;
				}

				feedback($box, progressText(data), false);

				setTimeout(function () {
					poll($box, postId, startedAt);
				}, elapsed > SLOW_DOWN_AFTER ? LATER_INTERVAL : FIRST_INTERVAL);
			})
			.fail(function () {
				// One failed poll is not a failed rendition — the connection may
				// simply have hiccupped. Keep asking; the deadline ends it.
				setTimeout(function () {
					poll($box, postId, startedAt);
				}, LATER_INTERVAL);
			});
	}

	$(document).on('click', '#article-tts-generate', function (e) {
		e.preventDefault();
		var $box = $(this).closest('.article-tts-metabox');
		var postId = $box.data('post-id');
		var $btn = $(this);
		var voiceOverride = $('#article-tts-voice-override').val() || '';

		$btn.prop('disabled', true);
		feedback($box, articleTTS.i18n.submitting, false);

		$.post(articleTTS.ajaxUrl, {
			action: 'article_tts_generate',
			nonce: articleTTS.nonce,
			post_id: postId,
			voice_override: voiceOverride
		})
			.done(function (res) {
				if (res && res.success) {
					feedback($box, articleTTS.i18n.generating, false);
					poll($box, postId, Date.now());
				} else {
					var m = (res && res.data && res.data.message) ? res.data.message : articleTTS.i18n.failed;
					feedback($box, articleTTS.i18n.failed + ': ' + m, true);
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				var m = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (xhr.statusText || 'Request failed');
				feedback($box, articleTTS.i18n.failed + ': ' + m, true);
				$btn.prop('disabled', false);
			});
	});

	// A rendition that was already running when the editor was opened: pick the
	// display back up rather than showing a stale "queued" until the next reload.
	$(function () {
		$('.article-tts-metabox[data-job-pending="1"]').each(function () {
			var $box = $(this);
			poll($box, $box.data('post-id'), Date.now());
		});
	});

	$(document).on('click', '#article-tts-delete', function (e) {
		e.preventDefault();
		if (!window.confirm(articleTTS.i18n.confirmDelete)) {
			return;
		}
		var $box = $(this).closest('.article-tts-metabox');
		var postId = $box.data('post-id');
		var $btn = $(this);
		$btn.prop('disabled', true);
		feedback($box, articleTTS.i18n.deleting, false);

		$.post(articleTTS.ajaxUrl, {
			action: 'article_tts_delete',
			nonce: articleTTS.nonce,
			post_id: postId
		})
			.done(function (res) {
				if (res && res.success) {
					$box.find('audio').remove();
					$box.find('.article-tts-running, .article-tts-warning').remove();
					$box.attr('data-job-pending', '0');
					setStatus($box, res.data && res.data.statusHtml);
					$btn.remove();
					$box.find('#article-tts-generate')
						.prop('disabled', false)
						.text(articleTTS.i18n.generate);
					feedback($box, articleTTS.i18n.deleted, false);
				} else {
					feedback($box, articleTTS.i18n.failed, true);
					$btn.prop('disabled', false);
				}
			})
			.fail(function () {
				feedback($box, articleTTS.i18n.failed, true);
				$btn.prop('disabled', false);
			});
	});

})(jQuery);
