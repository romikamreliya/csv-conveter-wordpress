/* global jQuery, WEBPC */
(function ($) {
	'use strict';

	function post(action, data) {
		return $.post(WEBPC.ajax, $.extend({ action: 'webpc_' + action, nonce: WEBPC.nonce }, data || {}));
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	/**
	 * Sequential runner: converts IDs one by one (one AJAX request each – no timeouts on big images).
	 */
	function Runner($progress, ids, onItem, onDone) {
		var self = this, total = ids.length, i = 0, ok = 0, fail = 0, skip = 0;
		self.stopped = false;

		var $bar = $progress.find('.webpc-bar span'),
			$text = $progress.find('.webpc-progress-text'),
			$log = $progress.find('.webpc-log');

		$progress.show();

		function render() {
			var pct = total ? Math.round((i / total) * 100) : 100;
			$bar.css('width', pct + '%');
			$text.text(i + ' / ' + total + ' processed — ' + ok + ' converted, ' + fail + ' failed' + (skip ? ', ' + skip + ' skipped' : '') + (self.stopped && i < total ? ' (paused)' : ''));
		}

		function log(cls, html) {
			$log.prepend('<li class="' + cls + '">' + html + '</li>');
		}

		function next() {
			render();
			if (self.stopped) { return; }
			if (i >= total) {
				// Refresh page caches (shop, archives, home) once at the end of a run.
				var finish = ok ? post('finish') : $.Deferred().resolve();
				finish.always(function () {
					if (onDone) { onDone({ ok: ok, fail: fail }); }
				});
				return;
			}
			var id = ids[i];
			post('convert', { id: id }).done(function (r) {
				var d = r && r.data ? r.data : {};
				if (r && r.success) {
					ok++;
					log('ok', '✔ <strong>#' + id + ' ' + esc(d.title) + '</strong> — ' + esc(d.message));
				} else if (d.skipped) {
					skip++;
				} else {
					fail++;
					log('err', '✖ <strong>#' + id + ' ' + esc(d.title) + '</strong> — ' + esc(d.message || 'Error'));
				}
				if (onItem) { onItem(id, r); }
			}).fail(function (x) {
				fail++;
				log('err', '✖ #' + id + ' — HTTP ' + x.status + ' ' + esc(x.statusText));
			}).always(function () {
				i++;
				next();
			});
		}

		self.start = function () { self.stopped = false; next(); };
		self.stop = function () { self.stopped = true; render(); };
		self.remaining = function () { return ids.slice(i); };
	}

	function refreshStats() {
		post('stats').done(function (r) {
			if (!r || !r.success) { return; }
			$.each(r.data, function (k, v) {
				$('[data-stat="' + k + '"]').text(k === 'percent' ? v + '%' : v);
			});
		});
	}

	/* ---------------- Bulk tab ---------------- */
	var bulk = null;
	$('#webpc-bulk-start').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this), $stop = $('#webpc-bulk-stop');

		if (bulk && bulk.stopped && bulk.remaining().length) {
			$btn.hide(); $stop.show();
			bulk.start();
			return;
		}

		$btn.prop('disabled', true).text('Preparing…');
		post('queue', { scope: $('input[name=webpc_scope]:checked').val() }).done(function (r) {
			var ids = r && r.success ? r.data.ids : [];
			$btn.prop('disabled', false).text('Start Conversion');
			if (!ids.length) {
				$('#webpc-progress-bulk').show().find('.webpc-progress-text').text('Nothing to convert – all images are already WebP. 🎉');
				return;
			}
			$btn.hide(); $stop.show();
			bulk = new Runner($('#webpc-progress-bulk').find('.webpc-log').empty().end(), ids, function () {}, function () {
				$stop.hide(); $btn.show().text('Start Conversion');
				refreshStats();
			});
			bulk.start();
		});
	});

	// Queue handed over from the Products list bulk action.
	var preQueue = $('#webpc-bulk-start').data('queue');
	if ($.isArray(preQueue) && preQueue.length) {
		$('#webpc-bulk-start').hide();
		$('#webpc-bulk-stop').show();
		bulk = new Runner($('#webpc-progress-bulk'), preQueue, null, function () {
			$('#webpc-bulk-stop').hide();
			$('#webpc-bulk-start').show().text('Start Conversion');
			refreshStats();
		});
		bulk.start();
	}

	$('#webpc-bulk-stop').on('click', function (e) {
		e.preventDefault();
		if (bulk) { bulk.stop(); }
		$(this).hide();
		$('#webpc-bulk-start').show().text('Resume');
		refreshStats();
	});

	/* ---------------- Single product tab ---------------- */
	var $product = $('#webpc-product'), $grid = $('#webpc-product-images'), $pBtn = $('#webpc-product-convert');

	function loadProduct(autostart) {
		var pid = $product.val();
		$grid.empty();
		$pBtn.prop('disabled', true);
		$('#webpc-progress-product').hide().find('.webpc-log').empty();
		if (!pid) { return; }
		$grid.html('<p>Loading images…</p>');
		post('product_images', { product_id: pid }).done(function (r) {
			var imgs = r && r.success ? r.data.images : [];
			$grid.empty();
			if (!imgs.length) {
				$grid.html('<p>No images found for this product.</p>');
				return;
			}
			var pending = 0;
			$.each(imgs, function (_, im) {
				if (im.convert) { pending++; }
				$grid.append(
					'<div class="webpc-item" data-id="' + im.id + '">' +
					'<img src="' + esc(im.thumb) + '" alt="">' +
					'<div class="webpc-item-meta"><strong>#' + im.id + '</strong> ' + esc(im.title) + '<br><small>' + esc(im.mime) + ' · ' + esc(im.size) + '</small></div>' +
					im.html + '</div>'
				);
			});
			$pBtn.prop('disabled', !pending).text(pending ? 'Convert ' + pending + ' image(s) of this product' : 'All images are WebP ✔');
			if (autostart && pending) { $pBtn.trigger('click'); }
		});
	}

	if ($product.length) {
		$(document.body).trigger('wc-enhanced-select-init');
		$product.on('change', function () { loadProduct(false); });
		if ($product.val()) { loadProduct($grid.data('autostart') === 1 || $grid.data('autostart') === '1'); }
	}

	$pBtn.on('click', function (e) {
		e.preventDefault();
		var ids = [];
		$grid.find('.webpc-convert').each(function () { ids.push($(this).data('id')); });
		if (!ids.length) { return; }
		$pBtn.prop('disabled', true).text('Converting…');
		new Runner($('#webpc-progress-product'), ids, function (id, r) {
			if (r && r.success) {
				var $it = $grid.find('.webpc-item[data-id="' + id + '"]');
				$it.find('.webpc-status').replaceWith(r.data.html);
				if (r.data.thumb) { $it.find('img').attr('src', r.data.thumb + '?v=' + Date.now()); }
				$it.find('small').first().text('image/webp');
			}
		}, function (res) {
			$pBtn.text('Done – ' + res.ok + ' converted' + (res.fail ? ', ' + res.fail + ' failed' : ''));
		}).start();
	});

	/* ---------------- Product edit metabox ---------------- */
	$(document).on('click', '.webpc-product-box-btn', function (e) {
		e.preventDefault();
		var $btn = $(this), ids = String($btn.data('ids')).split(',').filter(Boolean);
		$btn.prop('disabled', true).text('Converting…');
		new Runner($btn.closest('.inside').find('.webpc-progress'), ids, null, function (res) {
			$btn.text('Done – ' + res.ok + ' converted');
			// Gallery/featured thumbnails in the editor still point to old URLs – reload to refresh them.
			setTimeout(function () { window.location.reload(); }, 1200);
		}).start();
	});

	// Media modal: reload the attachment so "File type / File size / URL" show the WebP values.
	function refreshMediaModel(id) {
		if (window.wp && wp.media && wp.media.attachment) {
			var m = wp.media.attachment(id);
			if (!m) { return; }
			m.fetch().done(function () {
				// WP doesn't re-render the details panel on its own, so do it here.
				$.each([wp.media.frames && wp.media.frames.edit, wp.media.frame], function (_, f) {
					var v = f && f.content && f.content.get && f.content.get();
					if (!v) { return; }
					if (v.model && v.model.id === m.id && v.render) {
						v.render(); // Media Library grid: "Attachment details" modal.
					} else if (v.sidebar && v.sidebar.get && v.sidebar.get('details')) {
						v.sidebar.get('details').render(); // Insert-media modal on edit screens.
					}
				});
			});
		}
	}

	/* ---------------- Media library: single convert / restore ---------------- */
	$(document).on('click', '.webpc-convert', function (e) {
		if ($(this).closest('#webpc-product-images').length) { e.preventDefault(); $pBtn.trigger('click'); return; }
		e.preventDefault();
		var $a = $(this), $box = $a.closest('.webpc-status');
		$a.addClass('disabled').text('Converting…');
		post('convert', { id: $a.data('id') }).done(function (r) {
			if (r && r.success) {
				$box.replaceWith(r.data.html);
				refreshMediaModel($a.data('id'));
			} else {
				$a.removeClass('disabled').text('Convert to WebP');
				window.alert((r && r.data && r.data.message) || 'Conversion failed');
			}
		});
	});

	$(document).on('click', '.webpc-restore', function (e) {
		e.preventDefault();
		if (!window.confirm('Restore the original JPG/PNG and switch all paths back?')) { return; }
		var $a = $(this), $box = $a.closest('.webpc-status');
		$a.text('Restoring…');
		post('restore', { id: $a.data('id') }).done(function (r) {
			if (r && r.success) {
				$box.replaceWith(r.data.html);
				refreshMediaModel($a.data('id'));
			} else {
				$a.text('Restore original');
				window.alert((r && r.data && r.data.message) || 'Restore failed');
			}
		});
	});

})(jQuery);
