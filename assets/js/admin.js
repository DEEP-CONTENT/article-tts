/* global jQuery, articleTTS */
(function ($) {
	'use strict';

	function feedback($box, msg, isError) {
		var $fb = $box.find('.article-tts-feedback');
		$fb.text(msg);
		$fb.toggleClass('article-tts-error', !!isError);
		$fb.toggleClass('article-tts-success', !isError);
	}

	function reloadAfter(ms) {
		setTimeout(function () {
			window.location.reload();
		}, ms || 800);
	}

	$(document).on('click', '#article-tts-generate', function (e) {
		e.preventDefault();
		var $box = $(this).closest('.article-tts-metabox');
		var postId = $box.data('post-id');
		var $btn = $(this);
		var voiceOverride = $('#article-tts-voice-override').val() || '';

		$btn.prop('disabled', true);
		feedback($box, articleTTS.i18n.generating, false);

		$.post(articleTTS.ajaxUrl, {
			action: 'article_tts_generate',
			nonce: articleTTS.nonce,
			post_id: postId,
			voice_override: voiceOverride
		})
			.done(function (res) {
				if (res && res.success) {
					var msg = res.data.skipped ? articleTTS.i18n.skipped : articleTTS.i18n.success;
					feedback($box, msg, false);
					reloadAfter(1200);
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
					reloadAfter(500);
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
