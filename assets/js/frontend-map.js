(function () {
	'use strict';

	if (typeof window.mdrData === 'undefined' || typeof L === 'undefined') {
		return;
	}

	const restBase = (window.mdrData.restUrl || '').replace(/\/$/, '');
	const radiusKmDefault = parseFloat(window.mdrData.defaultRadiusKm) || 5;
	const fallbackCenter = window.mdrData.fallbackCenter || { lat: 36.7213, lng: -4.4214 };

	const state = {
		map: null,
		cluster: null,
		center: fallbackCenter,
		radiusKm: radiusKmDefault,
		filters: {
			zona: '',
			ambito: '',
			subcategoria: '',
			servicio: '',
			q: '',
		},
	};

	const els = {
		map: document.getElementById('mdr-map'),
		list: document.getElementById('mdr-list'),
		status: document.getElementById('mdr-status'),
		filterZona: document.getElementById('mdr-filter-zona'),
		filterAmbito: document.getElementById('mdr-filter-ambito'),
		filterSubcategoria: document.getElementById('mdr-filter-subcategoria'),
		filterServicio: document.getElementById('mdr-filter-servicio'),
		filterQ: document.getElementById('mdr-filter-q'),
	};

	function setStatus(text) {
		if (!els.status) {
			return;
		}
		els.status.textContent = text || '';
	}

	function haversineKm(lat1, lon1, lat2, lon2) {
		const R = 6371;
		const dLat = toRad(lat2 - lat1);
		const dLon = toRad(lon2 - lon1);
		const a =
			Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
			Math.sin(dLon / 2) * Math.sin(dLon / 2);
		const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
		return R * c;
	}

	function toRad(deg) {
		return deg * Math.PI / 180;
	}

	function init() {
		if (!els.map) {
			return;
		}

		initMap(fallbackCenter);
		loadFilters();
		requestGeolocation();
		bindFilterEvents();
		loadEntities();
	}

	function initMap(center) {
		state.map = L.map(els.map).setView([center.lat, center.lng], 13);

		const provider = window.mdrData.provider || 'osm';
		if (provider === 'mapbox' && window.mdrData.mapboxToken) {
			L.tileLayer('https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}', {
				attribution: '© Mapbox © OpenStreetMap',
				tileSize: 512,
				zoomOffset: -1,
				id: 'mapbox/streets-v11',
				accessToken: window.mdrData.mapboxToken,
			}).addTo(state.map);
		} else {
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: '&copy; OpenStreetMap contributors',
			}).addTo(state.map);
		}

		state.cluster = L.markerClusterGroup();
		state.map.addLayer(state.cluster);

		state.map.on('moveend', debounce(loadEntities, 400));
		state.map.on('popupopen', handlePopupOpen);
	}

	function requestGeolocation() {
		if (!navigator.geolocation) {
			setStatus(window.mdrData.i18n.denyGeolocation);
			return;
		}
		navigator.geolocation.getCurrentPosition(
			(pos) => {
				state.center = { lat: pos.coords.latitude, lng: pos.coords.longitude };
				state.map.setView([state.center.lat, state.center.lng], 14);
				L.circle([state.center.lat, state.center.lng], { radius: state.radiusKm * 1000, color: '#3b82f6', fillOpacity: 0.05 }).addTo(state.map);
				loadEntities();
			},
			() => {
				setStatus(window.mdrData.i18n.denyGeolocation);
			},
			{
				enableHighAccuracy: true,
				timeout: 7000,
				maximumAge: 30000,
			}
		);
	}

	async function loadFilters() {
		try {
			const res = await fetch(restBase + '/filtros');
			if (!res.ok) {
				return;
			}
			const data = await res.json();
			populateSelect(els.filterZona, data.zonas || []);
			populateSelect(els.filterAmbito, data.ambitos || []);
			populateSelect(els.filterServicio, data.servicios || []);
			if (Array.isArray(data.subcategorias)) {
				populateSelect(els.filterSubcategoria, data.subcategorias);
			}
			if (window.mdrData.defaultZona) {
				els.filterZona.value = window.mdrData.defaultZona;
				state.filters.zona = window.mdrData.defaultZona;
			}
		} catch (e) {
			console.error('mdr filtros', e);
		}
	}

	function populateSelect(selectEl, items) {
		if (!selectEl || !Array.isArray(items)) {
			return;
		}
		items.forEach((item) => {
			const opt = document.createElement('option');
			opt.value = item.id || '';
			opt.textContent = item.nombre || '';
			selectEl.appendChild(opt);
		});
	}

	function bindFilterEvents() {
		if (els.filterZona) {
			els.filterZona.addEventListener('change', () => {
				state.filters.zona = els.filterZona.value;
				loadEntities();
			});
		}
		if (els.filterAmbito) {
			els.filterAmbito.addEventListener('change', () => {
				state.filters.ambito = els.filterAmbito.value;
				loadEntities();
			});
		}
		if (els.filterSubcategoria) {
			els.filterSubcategoria.addEventListener('change', () => {
				state.filters.subcategoria = els.filterSubcategoria.value;
				loadEntities();
			});
		}
		if (els.filterServicio) {
			els.filterServicio.addEventListener('change', () => {
				state.filters.servicio = els.filterServicio.value;
				loadEntities();
			});
		}
		if (els.filterQ) {
			els.filterQ.addEventListener('input', debounce(() => {
				state.filters.q = els.filterQ.value;
				loadEntities();
			}, 400));
		}
	}

	function buildBbox() {
		if (!state.map) {
			return null;
		}
		const bounds = state.map.getBounds();
		return [
			bounds.getWest().toFixed(6),
			bounds.getSouth().toFixed(6),
			bounds.getEast().toFixed(6),
			bounds.getNorth().toFixed(6),
		].join(',');
	}

	async function loadEntities() {
		if (!state.map) {
			return;
		}
		setStatus(window.mdrData.i18n.loading);

		const params = new URLSearchParams();
		const bbox = buildBbox();
		if (bbox) params.append('bbox', bbox);
		if (state.filters.zona) params.append('zona', state.filters.zona);
		if (state.filters.ambito) params.append('ambito', state.filters.ambito);
		if (state.filters.subcategoria) params.append('subcategoria', state.filters.subcategoria);
		if (state.filters.servicio) params.append('servicio', state.filters.servicio);
		if (state.filters.q) params.append('q', state.filters.q);

		try {
			const res = await fetch(restBase + '/entidades?' + params.toString(), {
				headers: {
					'X-WP-Nonce': window.mdrData.nonce,
				},
			});
			if (!res.ok) {
				throw new Error('error entidades');
			}
			const data = await res.json();
			const filtered = (data || []).filter((item) => {
				if (!item.lat || !item.lng) {
					return false;
				}
				const dist = haversineKm(state.center.lat, state.center.lng, item.lat, item.lng);
				return dist <= state.radiusKm;
			});
			renderEntities(filtered);
			setStatus(filtered.length ? '' : window.mdrData.i18n.noResults);
		} catch (e) {
			console.error('mdr entidades', e);
			setStatus(window.mdrData.i18n.noResults);
		}
	}

	function renderEntities(entities) {
		state.cluster.clearLayers();
		if (!Array.isArray(entities)) {
			return;
		}
		const listItems = [];
		entities.forEach((item) => {
			const marker = L.marker([item.lat, item.lng]);
			const popupHtml = `
				<div class="mdr-popup">
					<strong>${escapeHtml(item.nombre || '')}</strong><br />
					${item.email ? `<a href="mailto:${escapeHtml(item.email)}">${escapeHtml(item.email)}</a><br />` : ''}
					${item.telefono ? `<a href="tel:${escapeHtml(item.telefono)}">${escapeHtml(item.telefono)}</a><br />` : ''}
					<button class="mdr-view-resources" data-entidad="${item.id}">${window.mdrData.i18n.viewResources}</button>
				</div>
			`;
			marker.bindPopup(popupHtml);
			state.cluster.addLayer(marker);

			listItems.push(`<li data-entidad="${item.id}">${escapeHtml(item.nombre || '')}</li>`);
		});

		if (els.list) {
			els.list.innerHTML = listItems.length ? `<ul>${listItems.join('')}</ul>` : '';
			if (listItems.length) {
				els.list.querySelectorAll('li').forEach((li) => {
					li.addEventListener('click', () => {
						const id = li.getAttribute('data-entidad');
						loadRecursos(id, li.textContent || '');
					});
				});
			}
		}
	}

	async function loadRecursos(entidadId, entidadNombre) {
		if (!entidadId) {
			return;
		}
		setStatus(window.mdrData.i18n.loading);
		try {
			const res = await fetch(restBase + '/recursos?entidad_id=' + encodeURIComponent(entidadId), {
				headers: {
					'X-WP-Nonce': window.mdrData.nonce,
				},
			});
			if (!res.ok) {
				throw new Error('error recursos');
			}
			const data = await res.json();
			renderRecursosList(data || [], entidadNombre);
			setStatus('');
		} catch (e) {
			console.error('mdr recursos', e);
			setStatus(window.mdrData.i18n.noResults);
		}
	}

	function renderRecursosList(items, entidadNombre) {
		if (!els.list) {
			return;
		}
		if (!Array.isArray(items) || !items.length) {
			els.list.innerHTML = `<p>${window.mdrData.i18n.noResults}</p>`;
			return;
		}
		const htmlItems = items.map((item) => {
			return `
				<li>
					<strong>${escapeHtml(item.recurso_programa || '')}</strong><br/>
					${item.descripcion ? `<small>${escapeHtml(item.descripcion)}</small><br/>` : ''}
					${item.contacto ? `<span>${escapeHtml(item.contacto)}</span>` : ''}
				</li>
			`;
		});
		els.list.innerHTML = `
			<h3>${escapeHtml(entidadNombre || '')}</h3>
			<ul>${htmlItems.join('')}</ul>
		`;
	}

	function handlePopupOpen(e) {
		const popupEl = e.popup.getElement();
		if (!popupEl) {
			return;
		}
		const btn = popupEl.querySelector('.mdr-view-resources');
		if (btn) {
			btn.addEventListener('click', () => {
				const entidadId = btn.getAttribute('data-entidad');
				loadRecursos(entidadId, popupEl.querySelector('strong')?.textContent || '');
			});
		}
	}

	function escapeHtml(str) {
		return (str || '').replace(/[&<>"']/g, function (m) {
			return ({
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			})[m];
		});
	}

	function debounce(fn, wait) {
		let t;
		return function () {
			const args = arguments;
			clearTimeout(t);
			t = setTimeout(() => {
				fn.apply(null, args);
			}, wait);
		};
	}

	document.addEventListener('DOMContentLoaded', init);
})();
