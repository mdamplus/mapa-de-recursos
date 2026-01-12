(function () {
	if (typeof mdrAgenda === 'undefined') {
		return;
	}
	const grids = document.querySelectorAll('.mdr-agenda-grid[data-has-more]');
	if (!grids.length) {
		return;
	}
	const observer = new IntersectionObserver(onIntersect, { rootMargin: '200px' });
	document.querySelectorAll('.mdr-agenda-sentinel').forEach((s) => observer.observe(s));

	function onIntersect(entries) {
		entries.forEach((entry) => {
			if (entry.isIntersecting) {
				const grid = entry.target.previousElementSibling;
				if (!grid || grid.dataset.loading === '1') return;
				if (grid.dataset.hasMore !== '1') return;
				loadMore(grid, entry.target);
			}
		});
	}

	function loadMore(grid, sentinel) {
		grid.dataset.loading = '1';
		const page = parseInt(grid.dataset.page || '1', 10) + 1;
		const perPage = parseInt(grid.dataset.perPage || '9', 10);
		const form = new FormData();
		form.append('action', 'mdr_agenda_load');
		form.append('nonce', mdrAgenda.nonce);
		form.append('page', page);
		form.append('per_page', perPage);
		form.append('orderby', grid.dataset.orderby || 'date');
		form.append('order', grid.dataset.order || 'desc');
		form.append('search', grid.dataset.search || '');
		form.append('mode', grid.dataset.mode || 'all');

		fetch(mdrAgenda.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form,
		})
			.then((r) => r.json())
			.then((data) => {
				if (!data || !data.success || !data.data) {
					grid.dataset.hasMore = '0';
					return;
				}
				const html = data.data.html || '';
				if (html) {
					const temp = document.createElement('div');
					temp.innerHTML = html;
					while (temp.firstChild) {
						grid.appendChild(temp.firstChild);
					}
					grid.dataset.page = String(page);
				}
				if (!data.data.has_more) {
					grid.dataset.hasMore = '0';
					sentinel.remove();
					observer.unobserve(sentinel);
				}
			})
			.catch(() => {
				grid.dataset.hasMore = '0';
			})
			.finally(() => {
				grid.dataset.loading = '0';
			});
	}
})();
