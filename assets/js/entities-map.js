/* global mdrEntities, L */
(function () {
	if (typeof L === 'undefined' || typeof mdrEntities === 'undefined') {
		return;
	}

	const mapEl = document.getElementById('mdr-entities-map-canvas');
	const statusEl = document.getElementById('mdr-entities-map-status');
	if (!mapEl) {
		return;
	}

	function setStatus(msg) {
		if (statusEl) {
			statusEl.textContent = msg || '';
		}
	}

	function fetchEntities() {
		setStatus(mdrEntities.strings.loading);
		const url = new URL(mdrEntities.restUrl);
		url.searchParams.set('all', '1');
		url.searchParams.set('include_empty', '1');
		return fetch(url.toString(), {
			headers: {
				'X-WP-Nonce': mdrEntities.nonce
			}
		}).then((r) => r.json());
	}

	const map = L.map(mapEl).setView(
		[mdrEntities.fallbackCenter.lat || 36.7213, mdrEntities.fallbackCenter.lng || -4.4214],
		12
	);

	L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution: '&copy; OpenStreetMap contributors'
	}).addTo(map);

	let customIcon = null;
	if (mdrEntities.markerIcon) {
		customIcon = L.icon({
			iconUrl: mdrEntities.markerIcon,
			iconSize: [38, 38],
			iconAnchor: [19, 38],
			popupAnchor: [0, -26]
		});
	}

	const cluster = L.markerClusterGroup();

	fetchEntities().then((items) => {
		if (!items || !items.length) {
			setStatus(mdrEntities.strings.noResults);
			return;
		}
		setStatus('');
		items.forEach((item) => {
			if (!item.lat || !item.lng) {
				return;
			}
			const marker = L.marker([item.lat, item.lng], customIcon ? { icon: customIcon } : undefined);
			const logo = item.logo_url || '';
			const phone = item.telefono || '';
			const mail = item.email || '';
			const addr = item.direccion || '';
			const btn = '<a class="button is-small mdr-entities-btn" href="' + mdrEntities.entityUrlBase + encodeURIComponent(item.slug) + '">' + mdrEntities.strings.viewServices + '</a>';
			const popup = `
				<div class="mdr-popup">
					${logo ? '<div class="mdr-popup-logo"><img src="' + logo + '" alt="' + item.nombre + '"></div>' : ''}
					<div class="mdr-popup-body">
						<strong class="mdr-popup-title">${item.nombre}</strong>
						${phone ? '<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-phone"></i></span><a href="tel:' + phone + '">' + phone + '</a></div>' : ''}
						${mail ? '<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-envelope"></i></span><a href="mailto:' + mail + '">' + mail + '</a></div>' : ''}
						${addr ? '<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-location-dot"></i></span><span>' + addr + '</span></div>' : ''}
						<div class="mdr-popup-row" style="margin-top:6px;">${btn}</div>
					</div>
				</div>
			`;
			marker.bindPopup(popup);
			cluster.addLayer(marker);
		});
		map.addLayer(cluster);
		if (cluster.getLayers().length > 0) {
			map.fitBounds(cluster.getBounds(), { padding: [30, 30] });
		}
	}).catch(() => {
		setStatus(mdrEntities.strings.noResults);
	});
})();
