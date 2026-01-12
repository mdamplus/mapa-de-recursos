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
		$has_more = count($events) >= (int) $atts['per_page'];
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
		$params = [
			'per_page' => min(50, max(1, (int) $atts['per_page'])),
			'page'     => max(1, (int) $atts['page']),
			'orderby'  => sanitize_key($atts['orderby']),
			'order'    => in_array(strtolower((string) $atts['order']), ['asc', 'desc'], true) ? strtolower((string) $atts['order']) : 'desc',
			'_embed'   => '1',
		];
		if (! empty($atts['search'])) {
			$params['search'] = sanitize_text_field($atts['search']);
		}

		$url = $this->api_url . '?' . http_build_query($params, '', '&');
		$cache_key = 'mdr_agenda_' . md5($url);
		$cached = get_transient($cache_key);
		if ($cached !== false) {
			$has_more = count($cached) >= $params['per_page'];
			return $return_html
				? ['events' => $cached, 'html' => $this->render_cards_html($cached), 'has_more' => $has_more]
				: ['events' => $cached, 'has_more' => $has_more];
		}

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

		set_transient($cache_key, $events, HOUR_IN_SECONDS);
		if ($return_html) {
			return [
				'events' => $events,
				'html' => $this->render_cards_html($events),
				'has_more' => count($events) >= $params['per_page'],
			];
		}

		return [
			'events' => $events,
			'has_more' => count($events) >= $params['per_page'],
		];
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

		$placeholder_bg = $is_placeholder ? $this->placeholder_colors[array_rand($this->placeholder_colors)] : '';

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
			$placeholder_bg = $this->placeholder_colors[array_rand($this->placeholder_colors)];
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
						<span class="mdr-agenda-badge"><?php echo esc_html($term['name']); ?></span>
					<?php endforeach; ?>
					<?php foreach ($event['terms']['ubicaciones'] as $term) : ?>
						<span class="mdr-agenda-badge mdr-agenda-badge-ghost"><?php echo esc_html($term['name']); ?></span>
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
