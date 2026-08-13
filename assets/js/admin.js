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
		// Ohne Adresse KEINEN Player einsetzen. Ein <audio> ohne brauchbare
		// Quelle rendert als ausgegrauter Knopf mit 0:00 und sieht damit exakt
		// aus wie ein kaputtes Audio — der Hinweis, dass etwas fehlt, wäre
		// unsichtbar.
		if (!url) {
			feedback($box, articleTTS.i18n.failed + ': keine Audio-Adresse erhalten', true);
			$box.find('#article-tts-generate').prop('disabled', false);
			return;
		}

		// IMMER ein frisches Element, nie das vorhandene weiterverwenden.
		//
		// Ein <audio> traegt seinen Zustand mit sich: eine fehlgeschlagene
		// Quelle, gepufferte Bereiche, ein networkState im Fehler. Genau dieser
		// Fall tritt hier regelmaessig ein, denn vor der gelungenen Vertonung
		// stehen oft misslungene — und dann wird ein Element wiederbelebt, das
		// bereits an derselben Adresse gescheitert ist. Ein neues Element hat
		// nichts davon.
		//
		// Die Quelle bekommt einen eindeutigen Parameter: der Dateiname enthaelt
		// den Inhalts-Hash, dieselbe Adresse wurde also womoeglich schon
		// angefragt, bevor die Datei existierte. Der Zusatz gilt NUR fuer dieses
		// Abspielen im Editor — in den Post-Meta und damit im Player der Website
		// steht weiter die saubere Adresse.
		var src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
		var $alt = $box.find('audio');

		var $player = $('<audio controls preload="metadata" style="width:100%;"></audio>');
		// Die ungeschminkte Adresse mitfuehren, damit spaeter erkennbar ist,
		// welche Datei hier eigentlich haengt. Die src ist danach eine
		// blob:-Adresse und taugt dafuer nicht.
		$player.attr('data-source-url', url);

		if ($alt.length) {
			// Das alte erst leeren und entladen, sonst laedt es im Hintergrund
			// weiter, waehrend es aus dem Dokument verschwindet.
			$alt[0].removeAttribute('src');
			$alt[0].load();
			$player.insertBefore($alt);
			$alt.remove();
		} else {
			$player.insertBefore($box.find('.article-tts-actions'));
		}

		// Die Datei per fetch holen und aus dem Speicher abspielen, statt sie
		// dem <audio>-Element zu ueberlassen.
		//
		// WARUM DIESER UMWEG. Ein Medienelement fordert seine Quelle mit einem
		// Range-Header an — es will ja springen koennen. Auf einer Installation
		// beantwortet die Schicht vor WordPress genau diese Anfrage mit 412,
		// waehrend dieselbe Adresse direkt im Browser geoeffnet einwandfrei
		// ausliefert. Der Player blieb dadurch grau und ohne Laufzeit, und nichts
		// daran war im Plugin falsch.
		//
		// fetch() schickt keinen Range-Header und stellt dieselbe Anfrage, die
		// beim direkten Oeffnen funktioniert. Die Datei kommt vollstaendig, wird
		// zu einer lokalen Adresse und der Player laedt gar nicht mehr uebers
		// Netz. Ein Editor, der eben eine Vertonung angestossen hat, will sie
		// ohnehin ganz hoeren.
		//
		// Schlaegt der Weg fehl, wird die normale Adresse gesetzt: schlechter
		// als heute wird es dadurch nie.
		if (window.fetch && window.URL && window.URL.createObjectURL) {
			window.fetch(src, { credentials: 'same-origin' })
				.then(function (res) {
					if (!res.ok) {
						throw new Error('HTTP ' + res.status);
					}
					return res.blob();
				})
				.then(function (blob) {
					if ($player.data('objectUrl')) {
						window.URL.revokeObjectURL($player.data('objectUrl'));
					}
					var objectUrl = window.URL.createObjectURL(blob);
					$player.data('objectUrl', objectUrl);
					$player.attr('src', objectUrl);
					$player[0].load();
				})
				.catch(function (e) {
					// Nicht still bleiben: ein abgelehnter Abruf sieht sonst
					// genauso aus wie ein noch ladender — grauer Knopf, 0:00.
					feedback(
						$box,
						articleTTS.i18n.audioBlocked.replace('%s', e.message || '?'),
						true
					);
					$player.attr('src', src);
					$player[0].load();
				});
		} else {
			$player.attr('src', src);
			$player[0].load();
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

	// Der Knopf „Jetzt von Heise I/O holen".
	//
	// Fragt den Posteingang sofort ab, statt auf den Cron zu warten. Vier Lagen,
	// und sie auseinanderzuhalten ist der ganze Sinn: „nichts da" und „wird noch
	// zusammengefügt" sehen für den Wartenden gleich aus, verlangen aber
	// Verschiedenes — einmal die Adresse prüfen, einmal nur Geduld.
	$(document).on('click', '#article-tts-fetch', function (e) {
		e.preventDefault();

		var $box = $(this).closest('.article-tts-metabox');
		var $btn = $(this);

		$btn.prop('disabled', true);
		feedback($box, articleTTS.i18n.fetching, false);

		$.post(articleTTS.ajaxUrl, {
			action: 'article_tts_fetch_delivery',
			nonce: articleTTS.nonce,
			post_id: $box.data('post-id')
		})
			.done(function (res) {
				$btn.prop('disabled', false);

				if (!res || !res.success) {
					feedback($box, articleTTS.i18n.failed, true);
					return;
				}

				var d = res.data;
				setStatus($box, d.statusHtml);

				// Der Player wird IMMER nachgezogen, nicht nur bei 'delivered'.
				//
				// Holt der Cron die Zustellung ab, waehrend der Editor offen
				// steht, meldet dieser Knopf danach voellig zu Recht 'none' —
				// die Box zeigte dann aber weiter die alte Datei, und der
				// Redakteur liest "nichts bereitliegend", waehrend die neue
				// Fassung laengst am Beitrag haengt.
				var gezeigt = $box.find('audio').attr('data-source-url') || '';

				if (d.url && d.url !== gezeigt) {
					showAudio($box, d.url);
				}

				switch (d.state) {
					case 'delivered':
						feedback($box, articleTTS.i18n.fetchTaken, false);
						break;
					case 'composing':
						feedback($box, articleTTS.i18n.fetchComposing, false);
						break;
					case 'deferred':
						feedback($box, articleTTS.i18n.fetchDeferred, false);
						break;
					case 'none':
						feedback($box, articleTTS.i18n.fetchNone, false);
						break;
					default:
						// rejected oder failed: der Grund steht in DC-IO, hier
						// waere jede Vermutung schlechter als die Wahrheit.
						feedback($box, articleTTS.i18n.failed, true);
				}
			})
			.fail(function (xhr) {
				$btn.prop('disabled', false);
				var m = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: (xhr.statusText || 'Request failed');
				feedback($box, m, true);
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
