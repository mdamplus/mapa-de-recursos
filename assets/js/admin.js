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
		initEntityMap();
		initFaPicker();
		initMarkerUploader();
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

	function initMarkerUploader() {
		const $btn = $('#mdr-upload-marker');
		if (!$btn.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}
		const $input = $('#entity_marker_url');
		let frame;

		$btn.on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: 'Seleccionar icono marcador',
				button: { text: 'Usar icono' },
				multiple: false,
				library: { type: ['image', 'image/svg+xml', 'image/svg'] },
			});
			frame.on('select', function () {
				const attachment = frame.state().get('selection').first().toJSON();
				$input.val(attachment.url || '');
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
							const latNum = parseFloat(item.lat);
							const lngNum = parseFloat(item.lon);
							$lat.val(latNum.toFixed(6));
							$lng.val(lngNum.toFixed(6));
							setEntityMarker(latNum, lngNum);
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
						let items = '';
						if (Array.isArray(data) && data.length) {
							items = data.map((item) => {
								return `<div class="mdr-addr-item" data-lat="${item.lat}" data-lng="${item.lon}" data-display="${escapeHtml(item.display_name)}">${escapeHtml(item.display_name)}</div>`;
							}).join('');
						}
						// Siempre ofrecer usar la dirección escrita, aunque no haya sugerencias.
						items += `<div class="mdr-addr-item mdr-addr-free" data-display="${escapeHtml(query)}">${escapeHtml(query)} (${escapeHtml('usar tal cual')})</div>`;
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
				const latNum = parseFloat(lat);
				const lngNum = parseFloat(lng);
				$('#lat').val(latNum.toFixed(6));
				$('#lng').val(lngNum.toFixed(6));
				setEntityMarker(latNum, lngNum);
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

	let entityMap = null;
	let entityMarker = null;

	function initEntityMap() {
		const mapEl = document.getElementById('mdr-entity-map');
		const latInput = document.getElementById('lat');
		const lngInput = document.getElementById('lng');
		if (!mapEl || !latInput || !lngInput || typeof L === 'undefined') {
			return;
		}
		const parseVal = (input, fallback) => {
			const v = parseFloat(input.value);
			return isNaN(v) ? fallback : v;
		};
		const fallback = (window.mdrAdmin && mdrAdmin.fallbackCenter) ? mdrAdmin.fallbackCenter : { lat: 36.7213, lng: -4.4214 };
		const startLat = parseVal(latInput, fallback.lat);
		const startLng = parseVal(lngInput, fallback.lng);

		entityMap = L.map(mapEl).setView([startLat, startLng], 14);
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap',
			maxZoom: 19,
		}).addTo(entityMap);

		entityMarker = L.marker([startLat, startLng], { draggable: true }).addTo(entityMap);
		entityMarker.on('dragend', function (e) {
			const { lat, lng } = e.target.getLatLng();
			latInput.value = lat.toFixed(6);
			lngInput.value = lng.toFixed(6);
		});

		$(latInput).on('change', function () {
			const lat = parseFloat(this.value);
			const lng = parseFloat(lngInput.value);
			if (isNaN(lat) || isNaN(lng)) return;
			setEntityMarker(lat, lng, false);
		});
		$(lngInput).on('change', function () {
			const lat = parseFloat(latInput.value);
			const lng = parseFloat(this.value);
			if (isNaN(lat) || isNaN(lng)) return;
			setEntityMarker(lat, lng, false);
		});
	}

	function setEntityMarker(lat, lng, recenter = true) {
		if (!entityMap || !entityMarker || typeof L === 'undefined') {
			return;
		}
		entityMarker.setLatLng([lat, lng]);
		if (recenter) {
			entityMap.setView([lat, lng], entityMap.getZoom());
		}
		const latInput = document.getElementById('lat');
		const lngInput = document.getElementById('lng');
		if (latInput && lngInput) {
			latInput.value = parseFloat(lat).toFixed(6);
			lngInput.value = parseFloat(lng).toFixed(6);
		}
	}

	function initFaPicker() {
		const input = document.getElementById('icono_clase');
		const preview = document.querySelector('.mdr-fa-preview');
		const suggestions = document.querySelectorAll('.mdr-fa-suggestion');
		const openBtn = document.getElementById('mdr-fa-open');
		let modal = null;
		let grid = null;
		let search = null;
		let closeBtn = null;
		if (!input || !preview) {
			return;
		}
		const renderPreview = () => {
			const cls = input.value.trim();
			if (!cls) {
				preview.innerHTML = '';
				return;
			}
			preview.innerHTML = '<i class="' + cls + '"></i>';
		};
		input.addEventListener('input', renderPreview);
		suggestions.forEach((item) => {
			item.addEventListener('click', () => {
				const cls = item.getAttribute('data-class') || '';
				input.value = cls;
				renderPreview();
			});
		});
		renderPreview();

		// Modal picker
		let iconsLoaded = false;
		let icons = [
			{ c: 'fa-solid fa-briefcase', n: 'Briefcase' },
			{ c: 'fa-solid fa-briefcase-clock', n: 'Briefcase Clock' },
			{ c: 'fa-solid fa-hands-helping', n: 'Hands Helping' },
			{ c: 'fa-solid fa-hand-holding-heart', n: 'Support' },
			{ c: 'fa-solid fa-hands-holding-child', n: 'Hands Holding Child' },
			{ c: 'fa-solid fa-heart', n: 'Heart' },
			{ c: 'fa-solid fa-heart-pulse', n: 'Heart Pulse' },
			{ c: 'fa-solid fa-hands', n: 'Hands' },
			{ c: 'fa-solid fa-hands-bubbles', n: 'Hands Bubbles' },
			{ c: 'fa-solid fa-handshake', n: 'Handshake' },
			{ c: 'fa-solid fa-handshake-angle', n: 'Handshake Angle' },
			{ c: 'fa-solid fa-hand-holding-medical', n: 'Hand Medical' },
			{ c: 'fa-solid fa-hands-holding', n: 'Hands Holding' },
			{ c: 'fa-solid fa-hands-praying', n: 'Hands Praying' },
			{ c: 'fa-solid fa-hands-bound', n: 'Hands Bound' },
			{ c: 'fa-solid fa-people-roof', n: 'Family' },
			{ c: 'fa-solid fa-people-group', n: 'Community' },
			{ c: 'fa-solid fa-people-arrows', n: 'Inclusión' },
			{ c: 'fa-solid fa-users', n: 'Users' },
			{ c: 'fa-solid fa-user-friends', n: 'Friends' },
			{ c: 'fa-solid fa-user-large', n: 'User Large' },
			{ c: 'fa-solid fa-user-graduate', n: 'User Graduate' },
			{ c: 'fa-solid fa-user-nurse', n: 'Nurse' },
			{ c: 'fa-solid fa-user-doctor', n: 'Doctor' },
			{ c: 'fa-solid fa-user-gear', n: 'User Gear' },
			{ c: 'fa-solid fa-user-shield', n: 'User Shield' },
			{ c: 'fa-solid fa-user-tie', n: 'User Tie' },
			{ c: 'fa-solid fa-child', n: 'Child' },
			{ c: 'fa-solid fa-child-reaching', n: 'Child Reaching' },
			{ c: 'fa-solid fa-children', n: 'Children' },
			{ c: 'fa-solid fa-person-cane', n: 'Mayores' },
			{ c: 'fa-solid fa-person-walking-with-cane', n: 'Cane Walk' },
			{ c: 'fa-solid fa-wheelchair', n: 'Wheelchair' },
			{ c: 'fa-solid fa-brain', n: 'Brain' },
			{ c: 'fa-solid fa-scale-balanced', n: 'Justice' },
			{ c: 'fa-solid fa-gavel', n: 'Gavel' },
			{ c: 'fa-solid fa-shield', n: 'Shield' },
			{ c: 'fa-solid fa-shield-heart', n: 'Shield Heart' },
			{ c: 'fa-solid fa-shield-dog', n: 'Shield Dog' },
			{ c: 'fa-solid fa-passport', n: 'Passport' },
			{ c: 'fa-solid fa-earth-europe', n: 'Earth Europe' },
			{ c: 'fa-solid fa-earth-americas', n: 'Earth Americas' },
			{ c: 'fa-solid fa-globe', n: 'Globe' },
			{ c: 'fa-solid fa-flag', n: 'Flag' },
			{ c: 'fa-solid fa-language', n: 'Language' },
			{ c: 'fa-solid fa-bullhorn', n: 'Bullhorn' },
			{ c: 'fa-solid fa-chart-line', n: 'Chart Line' },
			{ c: 'fa-solid fa-clipboard-list', n: 'Clipboard' },
			{ c: 'fa-solid fa-book', n: 'Book' },
			{ c: 'fa-solid fa-book-open', n: 'Book Open' },
			{ c: 'fa-solid fa-bookmark', n: 'Bookmark' },
			{ c: 'fa-solid fa-school', n: 'School' },
			{ c: 'fa-solid fa-chalkboard-teacher', n: 'Teacher' },
			{ c: 'fa-solid fa-graduation-cap', n: 'Graduation Cap' },
			{ c: 'fa-solid fa-person-chalkboard', n: 'Person Chalkboard' },
			{ c: 'fa-solid fa-computer', n: 'Computer' },
			{ c: 'fa-solid fa-laptop', n: 'Laptop' },
			{ c: 'fa-solid fa-mobile-screen', n: 'Mobile' },
			{ c: 'fa-solid fa-phone', n: 'Phone' },
			{ c: 'fa-solid fa-envelope', n: 'Envelope' },
			{ c: 'fa-solid fa-circle-info', n: 'Info' },
			{ c: 'fa-solid fa-circle-check', n: 'Check' },
			{ c: 'fa-solid fa-circle-xmark', n: 'X Mark' },
			{ c: 'fa-solid fa-house', n: 'House' },
			{ c: 'fa-solid fa-house-user', n: 'House User' },
			{ c: 'fa-solid fa-house-chimney', n: 'House Chimney' },
			{ c: 'fa-solid fa-people-line', n: 'People Line' },
			{ c: 'fa-solid fa-person-shelter', n: 'Shelter' },
			{ c: 'fa-solid fa-building', n: 'Building' },
			{ c: 'fa-solid fa-city', n: 'City' },
			{ c: 'fa-solid fa-warehouse', n: 'Warehouse' },
			{ c: 'fa-solid fa-tree-city', n: 'Tree City' },
			{ c: 'fa-solid fa-seedling', n: 'Seedling' },
			{ c: 'fa-solid fa-leaf', n: 'Leaf' },
			{ c: 'fa-solid fa-tree', n: 'Tree' },
			{ c: 'fa-solid fa-recycle', n: 'Recycle' },
			{ c: 'fa-solid fa-water', n: 'Water' },
			{ c: 'fa-solid fa-droplet', n: 'Droplet' },
			{ c: 'fa-solid fa-sun', n: 'Sun' },
			{ c: 'fa-solid fa-cloud', n: 'Cloud' },
			{ c: 'fa-solid fa-car', n: 'Car' },
			{ c: 'fa-solid fa-bus', n: 'Bus' },
			{ c: 'fa-solid fa-bus-simple', n: 'Bus Simple' },
			{ c: 'fa-solid fa-train', n: 'Train' },
			{ c: 'fa-solid fa-bicycle', n: 'Bicycle' },
			{ c: 'fa-solid fa-motorcycle', n: 'Motorcycle' },
			{ c: 'fa-solid fa-plane', n: 'Plane' },
			{ c: 'fa-solid fa-plane-departure', n: 'Plane Departure' },
			{ c: 'fa-solid fa-person-running', n: 'Running' },
			{ c: 'fa-solid fa-dumbbell', n: 'Dumbbell' },
			{ c: 'fa-solid fa-mountain', n: 'Mountain' },
			{ c: 'fa-solid fa-futbol', n: 'Futbol' },
			{ c: 'fa-solid fa-music', n: 'Music' },
			{ c: 'fa-solid fa-guitar', n: 'Guitar' },
			{ c: 'fa-solid fa-theater-masks', n: 'Theater Masks' },
			{ c: 'fa-solid fa-masks-theater', n: 'Masks Theater' },
			{ c: 'fa-solid fa-people-carry', n: 'Carry' },
			{ c: 'fa-solid fa-stethoscope', n: 'Stethoscope' },
			{ c: 'fa-solid fa-notes-medical', n: 'Medical Notes' },
			{ c: 'fa-solid fa-briefcase-medical', n: 'Medical Briefcase' },
			{ c: 'fa-solid fa-syringe', n: 'Syringe' },
			{ c: 'fa-solid fa-pills', n: 'Pills' },
			{ c: 'fa-solid fa-hospital', n: 'Hospital' },
			{ c: 'fa-solid fa-band-aid', n: 'Band Aid' },
			{ c: 'fa-solid fa-bottle-droplet', n: 'Bottle Droplet' },
			{ c: 'fa-solid fa-lungs', n: 'Lungs' },
			{ c: 'fa-solid fa-people-pulling', n: 'People Pulling' },
			{ c: 'fa-solid fa-clipboard', n: 'Clipboard' },
			{ c: 'fa-solid fa-toolbox', n: 'Toolbox' },
			{ c: 'fa-solid fa-wrench', n: 'Wrench' },
			{ c: 'fa-solid fa-hammer', n: 'Hammer' },
			{ c: 'fa-solid fa-seedling', n: 'Seedling' },
			{ c: 'fa-solid fa-chart-pie', n: 'Chart Pie' },
			{ c: 'fa-solid fa-chart-bar', n: 'Chart Bar' },
			{ c: 'fa-solid fa-clipboard-check', n: 'Clipboard Check' },
			{ c: 'fa-solid fa-sack-dollar', n: 'Sack Dollar' },
			{ c: 'fa-solid fa-piggy-bank', n: 'Piggy Bank' },
			{ c: 'fa-regular fa-handshake', n: 'Handshake Regular' },
			{ c: 'fa-regular fa-heart', n: 'Heart Regular' },
			{ c: 'fa-regular fa-hospital', n: 'Hospital Regular' },
			{ c: 'fa-regular fa-user', n: 'User Regular' },
			{ c: 'fa-regular fa-circle', n: 'Circle Regular' },
			{ c: 'fa-regular fa-square', n: 'Square Regular' },
			{ c: 'fa-regular fa-folder-open', n: 'Folder Open Regular' },
			{ c: 'fa-brands fa-hands-helping', n: 'Hands Brands' },
			{ c: 'fa-brands fa-leanpub', n: 'Leanpub' },
			{ c: 'fa-brands fa-readme', n: 'Readme' },
			{ c: 'fa-brands fa-people-carry-box', n: 'Carry Box Brands' },
		];

		const loadIconsJson = () => {
			if (iconsLoaded || !window.mdrAdmin || !mdrAdmin.faJson) {
				iconsLoaded = true;
				return Promise.resolve();
			}
			return fetch(mdrAdmin.faJson)
				.then((res) => res.ok ? res.json() : [])
				.then((data) => {
					if (Array.isArray(data)) {
						const map = new Map();
						icons.forEach((i) => map.set(i.c, i));
						data.forEach((i) => {
							if (i && i.c && !map.has(i.c)) {
								map.set(i.c, i);
							}
						});
						icons = Array.from(map.values());
					}
					iconsLoaded = true;
				})
				.catch(() => {
					iconsLoaded = true;
				});
		};

		function ensureModal() {
			if (modal) return;
			modal = document.createElement('div');
			modal.className = 'mdr-fa-picker-modal';
			modal.innerHTML = `
				<div class="mdr-fa-picker-panel">
					<div class="mdr-fa-picker-head">
						<input type="search" id="mdr-fa-search" placeholder="Filtrar por nombre o clase...">
						<button type="button" class="button" id="mdr-fa-close">&times;</button>
					</div>
					<div class="mdr-fa-grid"></div>
				</div>
			`;
			document.body.appendChild(modal);
			grid = modal.querySelector('.mdr-fa-grid');
			search = modal.querySelector('#mdr-fa-search');
			closeBtn = modal.querySelector('#mdr-fa-close');

			const renderGrid = (filter = '') => {
				const f = filter.toLowerCase();
				const filtered = icons.filter((icon) => icon.c.toLowerCase().includes(f) || icon.n.toLowerCase().includes(f));
				const items = filtered
					.map((icon) => `<div class="mdr-fa-card" data-class="${icon.c}">
						<i class="${icon.c}"></i>
						<small>${escapeHtml(icon.n)}</small>
					</div>`)
					.join('');
				if (items) {
					grid.innerHTML = items;
				} else if (filter.trim().length > 0) {
					grid.innerHTML = `<div class="mdr-fa-card" data-class="${escapeHtml(filter.trim())}">
						<i class="${escapeHtml(filter.trim())}"></i>
						<small>${escapeHtml(filter.trim())}</small>
					</div>`;
				} else {
					grid.innerHTML = '<p style="color:#bbb;text-align:center;">No hay iconos</p>';
				}
			};

			renderGrid();

			grid.addEventListener('click', (e) => {
				const card = e.target.closest('.mdr-fa-card');
				if (!card) return;
				const cls = card.getAttribute('data-class');
				if (cls) {
					input.value = cls;
					renderPreview();
					closeModal();
				}
			});

			search.addEventListener('input', () => renderGrid(search.value));
			closeBtn.addEventListener('click', closeModal);
			modal.addEventListener('click', (e) => {
				if (e.target === modal) {
					closeModal();
				}
			});

			function closeModal() {
				modal.classList.remove('is-active');
			}

			modal.closeModal = closeModal;
		}

		function openModal() {
			ensureModal();
			if (modal) {
				modal.classList.add('is-active');
				if (search) {
					search.value = '';
					search.focus();
					const event = new Event('input');
					search.dispatchEvent(event);
				}
			}
		}

		function closeModal() {
			if (modal && modal.classList.contains('is-active')) {
				modal.classList.remove('is-active');
			}
		}

		if (openBtn) {
			openBtn.addEventListener('click', () => {
				loadIconsJson().finally(openModal);
			});
		}
		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				closeModal();
			}
		});
		renderPreview();
	}
})(jQuery);
