(function ($) {
	'use strict';

	$(document).ready(function () {
		initMediaUploader();
		initGeocode();
		initServiceUploader();
		initContactos();
		initKbAccordion();
		initAddressAutocomplete();
		initSelectAll();
	});

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function initMediaUploader() {
		const $btn = $('#mdr-upload-logo');
		if (!$btn.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}
		const $inputId = $('#logo_media_id');
		const $inputUrl = $('#logo_url');
		const $preview = $('.mdr-logo-preview');
		let frame;

		$btn.on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: 'Seleccionar logo',
				button: { text: 'Usar logo' },
				multiple: false,
				library: { type: ['image', 'image/svg+xml', 'image/svg'] },
			});
			frame.on('select', function () {
				const attachment = frame.state().get('selection').first().toJSON();
				$inputId.val(attachment.id || '');
				$inputUrl.val(attachment.url || '');
				if ($preview.length) {
					$preview.html('<img src="' + (attachment.url || '') + '" alt="" />');
				}
			});
			frame.open();
		});
	}

	function initGeocode() {
		$('.mdr-geocode-btn').each(function () {
			const $btn = $(this);
			$btn.on('click', function (e) {
				e.preventDefault();
				const prefix = $btn.data('prefix') || '';
				const $lat = $('#' + prefix + 'lat');
				const $lng = $('#' + prefix + 'lng');
				const prefixed = (id) => {
					const $field = $('#' + prefix + id);
					return $field.length ? $field.val() : '';
				};
				const address = [
					prefixed('direccion_linea1') || $('#direccion_linea1').val(),
					prefixed('cp') || $('#cp').val(),
					prefixed('ciudad') || $('#ciudad').val(),
					prefixed('provincia') || $('#provincia').val(),
					prefixed('pais') || $('#pais').val(),
				].filter(Boolean).join(', ');
				if (!address) {
					alert((window.mdrAdmin && mdrAdmin.i18n && mdrAdmin.i18n.geocode_need_address) || 'Introduce una dirección para geocodificar.');
					return;
				}
				$btn.prop('disabled', true).text((window.mdrAdmin && mdrAdmin.i18n && mdrAdmin.i18n.geocode_searching) || 'Buscando...');
				fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(address), {
					headers: {
						'Accept': 'application/json',
						'User-Agent': 'mdr-plugin/1.0',
					},
				})
					.then((res) => res.json())
					.then((data) => {
						if (Array.isArray(data) && data.length) {
							const item = data[0];
							$lat.val(parseFloat(item.lat).toFixed(6));
							$lng.val(parseFloat(item.lon).toFixed(6));
						} else {
							alert((window.mdrAdmin && mdrAdmin.i18n && mdrAdmin.i18n.geocode_not_found) || 'No se encontraron coordenadas para esa dirección.');
						}
					})
					.catch(() => {
						alert((window.mdrAdmin && mdrAdmin.i18n && mdrAdmin.i18n.geocode_error) || 'Error obteniendo coordenadas.');
					})
					.finally(() => {
						$btn.prop('disabled', false).text('Obtener lat/lng desde dirección');
					});
			});
		});
	}

	function initServiceUploader() {
		const $btn = $('#mdr-upload-icono');
		if (!$btn.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}
		const $inputId = $('#icono_media_id');
		const $preview = $('.mdr-icono-preview');
		let frame;

		$btn.on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: 'Seleccionar icono',
				button: { text: 'Usar icono' },
				multiple: false,
				library: { type: ['image', 'image/svg+xml', 'image/svg'] },
			});
			frame.on('select', function () {
				const attachment = frame.state().get('selection').first().toJSON();
				$inputId.val(attachment.id || '');
				if ($preview.length) {
					$preview.html('<img src="' + (attachment.url || '') + '" alt="" />');
				}
			});
			frame.open();
		});
	}

	function initContactos() {
		const $wrap = $('#mdr-contactos-wrap');
		if (!$wrap.length) {
			return;
		}
		const tpl = () => {
			return `<div class="mdr-contacto-row">
				<input type="text" name="contacto_nombre[]" placeholder="Nombre/Depto">
				<input type="email" name="contacto_email[]" placeholder="Email">
				<input type="text" name="contacto_tel[]" placeholder="Teléfono">
				<button type="button" class="button mdr-contacto-remove">Quitar</button>
			</div>`;
		};
		$('#mdr-contacto-add').on('click', function (e) {
			e.preventDefault();
			$wrap.append(tpl());
		});
		$wrap.on('click', '.mdr-contacto-remove', function (e) {
			e.preventDefault();
			$(this).closest('.mdr-contacto-row').remove();
		});
	}

	function initKbAccordion() {
		const cards = document.querySelectorAll('.mdr-kb-card');
		if (!cards.length) {
			return;
		}
		cards.forEach((card) => {
			const toggle = card.querySelector('.mdr-kb-toggle');
			const content = card.querySelector('.mdr-kb-content');
			if (!toggle || !content) return;
			toggle.addEventListener('click', () => {
				const open = card.classList.toggle('is-open');
				const icon = toggle.querySelector('.dashicons');
				if (icon) {
					icon.classList.toggle('dashicons-arrow-up-alt2', open);
					icon.classList.toggle('dashicons-arrow-down-alt2', !open);
				}
			});
		});
	}

	function initAddressAutocomplete() {
		const $input = $('#direccion_linea1');
		const $list = $('#mdr-addr-suggestions');
		if (!$input.length || !$list.length) {
			return;
		}
		let timer = null;
		$input.on('input', function () {
			clearTimeout(timer);
			const q = $input.val();
			if (!q || q.length < 3) {
				$list.empty().hide();
				return;
			}
			timer = setTimeout(() => {
				const extra = [
					$('#cp').val(),
					$('#ciudad').val(),
					$('#provincia').val(),
					$('#pais').val(),
				].filter(Boolean).join(', ');
				const query = q + (extra ? ', ' + extra : '');
				fetch('https://nominatim.openstreetmap.org/search?format=json&limit=5&q=' + encodeURIComponent(query), {
					headers: {
						'Accept': 'application/json',
						'User-Agent': 'mdr-plugin/1.0',
					},
				})
					.then((res) => res.json())
					.then((data) => {
						if (!Array.isArray(data) || !data.length) {
							$list.empty().hide();
							return;
						}
						const items = data.map((item) => {
							return `<div class="mdr-addr-item" data-lat="${item.lat}" data-lng="${item.lon}" data-display="${escapeHtml(item.display_name)}">${escapeHtml(item.display_name)}</div>`;
						}).join('');
						$list.html(items).show();
					})
					.catch(() => {
						$list.empty().hide();
					});
			}, 400);
		});

		$list.on('click', '.mdr-addr-item', function () {
			const lat = $(this).data('lat');
			const lng = $(this).data('lng');
			const display = $(this).data('display');
			if (display) {
				$input.val(display);
			}
			if (lat && lng) {
				$('#lat').val(parseFloat(lat).toFixed(6));
				$('#lng').val(parseFloat(lng).toFixed(6));
			}
			$list.empty().hide();
		});
	}

	function initSelectAll() {
		document.addEventListener('change', function (e) {
			const master = e.target;
			if (!master.classList || !master.classList.contains('mdr-select-all')) {
				return;
			}
			const targetName = master.getAttribute('data-target');
			const form = master.closest('form');
			const scope = form || master.closest('table') || document;
			let selector = 'input[type="checkbox"]';
			if (targetName) {
				selector = 'input[type="checkbox"][name="' + targetName + '"]';
			}
			scope.querySelectorAll(selector).forEach((cb) => {
				cb.checked = master.checked;
			});
		});
	}
})(jQuery);
