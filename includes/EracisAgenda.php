<?php

declare(strict_types=1);

namespace MapaDeRecursos;

use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

class EracisAgenda {
	private string $api_url;
	private string $placeholder;
	private array $legacy_placeholders = [
		'https://eracis.asociacionarrabal.org/wp-content/uploads/2026/01/eracis.svg',
	];
	private array $placeholder_colors = [
		'#ff9500',
		'#da3ab3',
		'#da1800',
		'#00b3e3',
		'#ff6b00',
		'#00299f',
		'#ff4338',
	];

	public function __construct(string $api_url) {
		$this->api_url = untrailingslashit($api_url);
		$this->placeholder = 'https://eracis.asociacionarrabal.org/wp-content/uploads/eracis-plus-blanco.svg';
	}

	public function render(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'per_page' => 9,
				'page'     => 1,
				'orderby'  => 'date',
				'order'    => 'desc',
				'search'   => '',
				'mode'     => 'all', // all|upcoming|past
			],
			$atts,
			'eracis_agenda'
		);

		$result = $this->get_events($atts, false);
		if (is_wp_error($result)) {
			return '<div class="mdr-agenda mdr-agenda-error">' . esc_html__('No se pudo cargar la agenda en este momento.', 'mapa-de-recursos') . '</div>';
		}
		$events = $result['events'];
		if (is_wp_error($events)) {
			return '<div class="mdr-agenda mdr-agenda-error">' . esc_html__('No se pudo cargar la agenda en este momento.', 'mapa-de-recursos') . '</div>';
		}

		// Filtro opcional por modo (upcoming/past) basado en fecha de inicio parseada.
		$events = $this->filter_by_mode($events, $atts['mode']);

		if (! $events) {
			return '<div class="mdr-agenda mdr-agenda-empty">' . esc_html__('No hay eventos para mostrar.', 'mapa-de-recursos') . '</div>';
		}

		$this->enqueue_assets();

		ob_start();
		$has_more = ! empty($result['has_more']);
		$attrs = [
			'data-per-page' => (int) $atts['per_page'],
			'data-page' => (int) $atts['page'],
			'data-order' => esc_attr($atts['order']),
			'data-orderby' => esc_attr($atts['orderby']),
			'data-search' => esc_attr($atts['search']),
			'data-mode' => esc_attr($atts['mode']),
			'data-has-more' => $has_more ? '1' : '0',
		];
		$attrs_str = '';
		foreach ($attrs as $k => $v) {
			$attrs_str .= sprintf(' %s="%s"', esc_attr($k), esc_attr((string) $v));
		}
		echo '<div class="mdr-agenda-grid" ' . $attrs_str . '>';
		foreach ($events as $event) {
			$this->render_card($event);
		}
		echo '</div>';
		if ($has_more) {
			echo '<div class="mdr-agenda-sentinel"></div>';
		}
		return (string) ob_get_clean();
	}

	private function enqueue_assets(): void {
		$css_handle = 'mdr-agenda';
		if (! wp_style_is($css_handle, 'registered')) {
			wp_register_style(
				$css_handle,
				MAPA_DE_RECURSOS_URL . 'assets/css/agenda.css',
				[],
				MAPA_DE_RECURSOS_VERSION
			);
		}
		wp_enqueue_style($css_handle);

		$js_handle = 'mdr-agenda-js';
		if (! wp_script_is($js_handle, 'registered')) {
			wp_register_script(
				$js_handle,
				MAPA_DE_RECURSOS_URL . 'assets/js/agenda.js',
				[],
				MAPA_DE_RECURSOS_VERSION,
				true
			);
		}
		wp_enqueue_script($js_handle);
		wp_localize_script(
			$js_handle,
			'mdrAgenda',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('mdr_agenda_load'),
			]
		);
	}

	public function get_events(array $atts, bool $return_html = false) {
		$per_page = min(50, max(1, (int) ($atts['per_page'] ?? 9)));
		$page     = max(1, (int) ($atts['page'] ?? 1));
		$fetch_count = 100; // trae el máximo permitido para tener margen de paginación
		$orderby  = sanitize_key($atts['orderby'] ?? 'date');
		$order    = in_array(strtolower((string) ($atts['order'] ?? 'desc')), ['asc', 'desc'], true) ? strtolower((string) $atts['order']) : 'desc';
		$search   = ! empty($atts['search']) ? sanitize_text_field($atts['search']) : '';

		$params = [
			'per_page' => $fetch_count,
			'page'     => 1,
			'orderby'  => $orderby,
			'order'    => $order,
			'_embed'   => '1',
		];
		if ($search !== '') {
			$params['search'] = $search;
		}

		$agenda_url = $this->api_url . '?' . http_build_query($params, '', '&');
		$cache_key = 'mdr_agenda_v4_' . md5($agenda_url . '|posts|per_page:' . $per_page . '|page:' . $page . '|order:' . $order . '|search:' . $search);
		$cached = get_transient($cache_key);
		if ($cached !== false) {
			return $this->build_events_response($cached, $per_page, $page, $return_html);
		}

		$events_result = $this->fetch_agenda_events($agenda_url);
		if (is_wp_error($events_result)) {
			return $events_result;
		}

		$posts_result = $this->fetch_agenda_posts($fetch_count, $order, $search, $orderby);

		$combined_items = array_merge($events_result['items'], $posts_result['items']);
		$combined_items = $this->sort_items($combined_items, $order, $orderby);
		$payload = [
			'items' => $combined_items,
			'total' => (int) $events_result['total'] + (int) $posts_result['total'],
		];

		set_transient($cache_key, $payload, HOUR_IN_SECONDS);
		return $this->build_events_response($payload, $per_page, $page, $return_html);
	}

	private function build_events_response(array $data, int $per_page, int $page, bool $return_html) {
		$items = $data['items'] ?? [];
		$total = (int) ($data['total'] ?? count($items));
		$offset = ($page - 1) * $per_page;
		$page_events = array_slice($items, $offset, $per_page);
		$has_more = $total > ($offset + $per_page);

		return $return_html
			? ['events' => $page_events, 'html' => $this->render_cards_html($page_events), 'has_more' => $has_more]
			: ['events' => $page_events, 'has_more' => $has_more];
	}

	private function fetch_agenda_events(string $url) {
		$response = wp_remote_get($url, [
			'timeout' => 10,
		]);
		if (is_wp_error($response)) {
			$this->log_error($response->get_error_message());
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($code !== 200 || empty($body)) {
			$this->log_error('Agenda HTTP error ' . $code);
			return new WP_Error('agenda_http', __('No se pudo obtener la agenda.', 'mapa-de-recursos'));
		}

		$data = json_decode($body, true);
		if (! is_array($data)) {
			$this->log_error('Agenda JSON decode failed');
			return new WP_Error('agenda_json', __('Datos de agenda inválidos.', 'mapa-de-recursos'));
		}

		$events = [];
		foreach ($data as $item) {
			$events[] = $this->map_event($item);
		}

		$total = (int) wp_remote_retrieve_header($response, 'x-wp-total');
		if ($total <= 0) {
			$total = count($events);
		}

		return [
			'items' => $events,
			'total' => $total,
		];
	}

	private function fetch_agenda_posts(int $per_page, string $order, string $search, string $orderby): array {
		$posts_url = $this->build_posts_api_url();
		if ($posts_url === '') {
			return ['items' => [], 'total' => 0];
		}

		$params = [
			'per_page'   => $per_page,
			'page'       => 1,
			'orderby'    => $orderby,
			'order'      => $order,
			'categories' => 14,
			'status'     => 'publish',
			'_embed'     => '1',
		];
		if ($search !== '') {
			$params['search'] = $search;
		}

		$url = $posts_url . '?' . http_build_query($params, '', '&');
		$response = wp_remote_get($url, ['timeout' => 10]);
		if (is_wp_error($response)) {
			$this->log_error('Agenda posts: ' . $response->get_error_message());
			return ['items' => [], 'total' => 0];
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($code !== 200 || empty($body)) {
			$this->log_error('Agenda posts HTTP error ' . $code);
			return ['items' => [], 'total' => 0];
		}

		$data = json_decode($body, true);
		if (! is_array($data)) {
			$this->log_error('Agenda posts JSON decode failed');
			return ['items' => [], 'total' => 0];
		}

		$posts = [];
		foreach ($data as $item) {
			$posts[] = $this->map_post_as_event($item);
		}

		$total = (int) wp_remote_retrieve_header($response, 'x-wp-total');
		if ($total <= 0) {
			$total = count($posts);
		}

		return [
			'items' => $posts,
			'total' => $total,
		];
	}

	private function build_posts_api_url(): string {
		// Usamos el sitio actual para que la categoría "agenda" (ID 14) se lea desde esta web.
		return trailingslashit(home_url()) . 'wp-json/wp/v2/posts';
	}

	private function map_post_as_event(array $item): array {
		$title = isset($item['title']['rendered']) ? wp_strip_all_tags((string) $item['title']['rendered']) : '';
		$link  = isset($item['link']) ? esc_url_raw($item['link']) : '';

		$featured = $this->placeholder;
		$is_placeholder = true;
		if (! empty($item['_embedded']['wp:featuredmedia'][0]['source_url'])) {
			$featured = esc_url_raw($item['_embedded']['wp:featuredmedia'][0]['source_url']);
			$is_placeholder = false;
		}

		$placeholder_bg = $is_placeholder ? $this->get_palette_color() : '';

		$excerpt = '';
		if (! empty($item['excerpt']['rendered'])) {
			$excerpt = wp_trim_words(wp_strip_all_tags($item['excerpt']['rendered']), 30);
		} elseif (! empty($item['content']['rendered'])) {
			$excerpt = wp_trim_words(wp_strip_all_tags($item['content']['rendered']), 30);
		}

		$meta = [
			'inicio'    => '',
			'fin'       => '',
			'lugar'     => '',
			'organiza'  => '',
			'inicio_ts' => $this->parse_post_timestamp($item),
		];
		if (! empty($meta['inicio_ts'])) {
			$meta['inicio'] = wp_date(get_option('date_format'), (int) $meta['inicio_ts'], wp_timezone());
		}

		$terms = $this->parse_terms($item['_embedded']['wp:term'] ?? []);

		return [
			'title'           => $title,
			'link'            => $link,
			'featured'        => $featured,
			'excerpt'         => $excerpt,
			'meta'            => $meta,
			'terms'           => $terms,
			'inicio_ts'       => $meta['inicio_ts'],
			'estado'          => '',
			'is_placeholder'  => $is_placeholder,
			'placeholder_bg'  => $placeholder_bg,
		];
	}

	private function parse_post_timestamp(array $item) {
		$date_string = $item['date'] ?? '';
		if ($date_string === '') {
			return null;
		}
		$dt = date_create($date_string, wp_timezone());
		return $dt ? $dt->getTimestamp() : null;
	}

	private function sort_items(array $items, string $order, string $orderby = 'date'): array {
		$direction = strtolower($order) === 'asc' ? 1 : -1;
		$orderby = $orderby ?: 'date';
		usort(
			$items,
			static function (array $a, array $b) use ($direction, $orderby): int {
				if ($orderby === 'title') {
					$a_title = strtolower((string) ($a['title'] ?? ''));
					$b_title = strtolower((string) ($b['title'] ?? ''));
					return strcmp($a_title, $b_title) * $direction;
				}

				$a_ts = (int) ($a['inicio_ts'] ?? 0);
				$b_ts = (int) ($b['inicio_ts'] ?? 0);
				if ($a_ts === $b_ts) {
					return 0;
				}
				return ($a_ts < $b_ts ? -1 : 1) * $direction;
			}
		);
		return $items;
	}

	private function get_palette_color(): string {
		return $this->placeholder_colors[array_rand($this->placeholder_colors)];
	}

	private function map_event(array $item): array {
		$title = isset($item['title']['rendered']) ? wp_strip_all_tags((string) $item['title']['rendered']) : '';
		$link  = isset($item['link']) ? esc_url_raw($item['link']) : '';

		$meta = $this->parse_meta_from_content($item['content']['rendered'] ?? '');

		$featured = $this->placeholder;
		$is_placeholder = true;
		if (! empty($item['_embedded']['wp:featuredmedia'][0]['source_url'])) {
			$featured = esc_url_raw($item['_embedded']['wp:featuredmedia'][0]['source_url']);
			$is_placeholder = false;
		}

		$placeholder_bg = $is_placeholder ? $this->get_palette_color() : '';

		$excerpt = '';
		if (! empty($item['excerpt']['rendered'])) {
			$excerpt = wp_trim_words(wp_strip_all_tags($item['excerpt']['rendered']), 30);
		} elseif (! empty($item['content']['rendered'])) {
			$excerpt = wp_trim_words(wp_strip_all_tags($item['content']['rendered']), 30);
		}

		$terms = $this->parse_terms($item['_embedded']['wp:term'] ?? []);
		$estado = '';
		if (! empty($item['content']['rendered'])) {
			if (stripos($item['content']['rendered'], 'FINALIZADO') !== false) {
				$estado = __('Finalizado', 'mapa-de-recursos');
			}
		}

		return [
			'title'      => $title,
			'link'       => $link,
			'featured'   => $featured,
			'excerpt'    => $excerpt,
			'meta'       => $meta,
			'terms'      => $terms,
			'inicio_ts'  => $meta['inicio_ts'] ?? null,
			'estado'     => $estado,
			'is_placeholder' => $is_placeholder,
			'placeholder_bg' => $placeholder_bg,
		];
	}

	private function parse_terms(array $embedded_terms): array {
		$out = [
			'categorias'  => [],
			'ubicaciones' => [],
		];
		foreach ($embedded_terms as $group) {
			foreach ((array) $group as $term) {
				if (empty($term['taxonomy'])) {
					continue;
				}
				if ($term['taxonomy'] === 'categorias-eventos') {
					$out['categorias'][] = [
						'name' => $term['name'] ?? '',
						'link' => $term['link'] ?? '',
					];
				} elseif ($term['taxonomy'] === 'ubicaciones-eventos') {
					$out['ubicaciones'][] = [
						'name' => $term['name'] ?? '',
						'link' => $term['link'] ?? '',
					];
				} elseif ($term['taxonomy'] === 'category') {
					$out['categorias'][] = [
						'name' => $term['name'] ?? '',
						'link' => $term['link'] ?? '',
					];
				}
			}
		}
		return $out;
	}

	private function parse_meta_from_content(string $html): array {
		$meta = [
			'inicio' => '',
			'fin'    => '',
			'lugar'  => '',
			'organiza' => '',
		];

		if ($html === '') {
			return $meta;
		}

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		if (! $loaded) {
			libxml_clear_errors();
			return $meta;
		}
		$xpath = new \DOMXPath($dom);
		$nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' jet-listing-dynamic-field__content ')]");
		foreach ($nodes as $node) {
			$text = trim(preg_replace('/\s+/', ' ', $node->textContent));
			if ($text === '') {
				continue;
			}
			$upper = strtoupper($text);
			if (strpos($upper, 'INICIO:') === 0) {
				$meta['inicio'] = trim(substr($text, strlen('INICIO:')));
				$meta['inicio_ts'] = $this->parse_spanish_date($meta['inicio']);
			} elseif (strpos($upper, 'FINALIZACIÓN:') === 0 || strpos($upper, 'FINALIZACION:') === 0) {
				$meta['fin'] = trim(substr($text, strpos($upper, 'FINAL') === 0 ? strlen(explode(':', $text)[0] . ':') : strlen('FINALIZACIÓN:')));
			} elseif (strpos($upper, 'LUGAR:') === 0) {
				$meta['lugar'] = trim(substr($text, strlen('LUGAR:')));
			} elseif (strpos($upper, 'ORGANIZA:') === 0) {
				$meta['organiza'] = trim(substr($text, strlen('ORGANIZA:')));
			}
		}
		libxml_clear_errors();
		return $meta;
	}

	private function parse_spanish_date(string $text) {
		if ($text === '') {
			return null;
		}
		$meses = [
			'enero' => 'January', 'febrero' => 'February', 'marzo' => 'March',
			'abril' => 'April', 'mayo' => 'May', 'junio' => 'June',
			'julio' => 'July', 'agosto' => 'August', 'septiembre' => 'September',
			'setiembre' => 'September', 'octubre' => 'October', 'noviembre' => 'November',
			'diciembre' => 'December',
		];
		$clean = strtolower($text);
		foreach ($meses as $es => $en) {
			$clean = str_replace($es, $en, $clean);
		}
		$clean = preg_replace('/\s+h\.?/i', '', $clean);
		$dt = \DateTime::createFromFormat('d F Y H:i', $clean, wp_timezone());
		if (! $dt) {
			$dt = \DateTime::createFromFormat('d F Y', $clean, wp_timezone());
		}
		return $dt ? $dt->getTimestamp() : null;
	}

	private function render_card(array $event): void {
		$meta = $event['meta'] ?? [];
		$featured = $event['featured'] ?? $this->placeholder;
		$known_placeholders = array_merge([$this->placeholder], $this->legacy_placeholders);
		$is_placeholder = array_key_exists('is_placeholder', $event)
			? (bool) $event['is_placeholder']
			: in_array($featured, $known_placeholders, true);
		if ($is_placeholder) {
			$featured = $this->placeholder;
		}
		$placeholder_bg = $event['placeholder_bg'] ?? '';
		if ($is_placeholder && $placeholder_bg === '') {
			$placeholder_bg = $this->get_palette_color();
		}
		$thumb_classes = ['mdr-agenda-thumb'];
		if ($is_placeholder) {
			$thumb_classes[] = 'mdr-agenda-thumb--placeholder';
		}
		$thumb_style = $is_placeholder && $placeholder_bg !== ''
			? ' style="background-color: ' . esc_attr($placeholder_bg) . ';"'
			: '';
		$img_classes = [];
		if ($is_placeholder) {
			$img_classes[] = 'mdr-agenda-thumb-img--placeholder';
		}
		$img_class_attr = $img_classes ? ' class="' . esc_attr(implode(' ', $img_classes)) . '"' : '';
		?>
		<div class="mdr-agenda-card">
			<div class="<?php echo esc_attr(implode(' ', $thumb_classes)); ?>"<?php echo $thumb_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<img<?php echo $img_class_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> src="<?php echo esc_url($featured); ?>" alt="" loading="lazy" />
			</div>
			<div class="mdr-agenda-body">
				<div class="mdr-agenda-terms">
					<?php foreach ($event['terms']['categorias'] as $term) : ?>
						<?php
						$badge_color = $this->get_palette_color();
						$badge_style = ' style="--mdr-badge-bg: ' . esc_attr($badge_color) . ';"';
						?>
						<span class="mdr-agenda-badge"<?php echo $badge_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($term['name']); ?></span>
					<?php endforeach; ?>
					<?php foreach ($event['terms']['ubicaciones'] as $term) : ?>
						<?php
						$badge_color = $this->get_palette_color();
						$badge_style = ' style="--mdr-badge-bg: ' . esc_attr($badge_color) . ';"';
						?>
						<span class="mdr-agenda-badge mdr-agenda-badge-ghost"<?php echo $badge_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($term['name']); ?></span>
					<?php endforeach; ?>
				</div>
				<h3 class="mdr-agenda-title"><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($event['link']); ?>"><?php echo esc_html($event['title']); ?></a></h3>
				<?php if ($event['estado']) : ?>
					<div class="mdr-agenda-estado"><?php echo esc_html($event['estado']); ?></div>
				<?php endif; ?>
				<p class="mdr-agenda-excerpt"><?php echo esc_html($event['excerpt']); ?></p>
				<div class="mdr-agenda-meta">
					<?php if (! empty($meta['inicio'])) : ?>
						<div><strong><?php esc_html_e('Inicio', 'mapa-de-recursos'); ?>:</strong> <?php echo esc_html($meta['inicio']); ?></div>
					<?php endif; ?>
					<?php if (! empty($meta['fin'])) : ?>
						<div><strong><?php esc_html_e('Finalización', 'mapa-de-recursos'); ?>:</strong> <?php echo esc_html($meta['fin']); ?></div>
					<?php endif; ?>
					<?php if (! empty($meta['lugar'])) : ?>
						<div><strong><?php esc_html_e('Lugar', 'mapa-de-recursos'); ?>:</strong> <?php echo esc_html($meta['lugar']); ?></div>
					<?php endif; ?>
					<?php if (! empty($meta['organiza'])) : ?>
						<div><strong><?php esc_html_e('Organiza', 'mapa-de-recursos'); ?>:</strong> <?php echo esc_html($meta['organiza']); ?></div>
					<?php endif; ?>
				</div>
				<div class="mdr-agenda-actions">
					<a class="button mdr-entities-btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($event['link']); ?>"><?php esc_html_e('Ver más', 'mapa-de-recursos'); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_cards_html(array $events): string {
		ob_start();
		foreach ($events as $event) {
			$this->render_card($event);
		}
		return (string) ob_get_clean();
	}

	public function filter_by_mode(array $events, string $mode): array {
		if ($mode === 'all') {
			return $events;
		}
		$filtered = [];
		$now = current_time('timestamp');
		foreach ($events as $event) {
			$ts = $event['inicio_ts'] ?? null;
			if (! $ts) {
				$filtered[] = $event; // mantener si no hay fecha.
				continue;
			}
			if ($mode === 'upcoming' && $ts >= $now) {
				$filtered[] = $event;
			} elseif ($mode === 'past' && $ts < $now) {
				$filtered[] = $event;
			}
		}
		return $filtered;
	}

	private function log_error(string $message): void {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[mdr_agenda] ' . $message);
		}
	}
}
