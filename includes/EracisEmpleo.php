<?php

declare(strict_types=1);

namespace MapaDeRecursos;

if (! defined('ABSPATH')) {
	exit;
}

class EracisEmpleo {
	private string $feed_url;
	private string $placeholder = 'https://eracis.asociacionarrabal.org/wp-content/uploads/eracis-plus-blanco.svg';
	private array $placeholder_colors = [
		'#ff9500',
		'#da3ab3',
		'#da1800',
		'#00b3e3',
		'#ff6b00',
		'#00299f',
		'#ff4338',
	];

	public function __construct(string $feed_url) {
		$this->feed_url = $feed_url;
	}

	public function render(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'per_page' => 9,
			],
			$atts,
			'eracis_empleo'
		);

		$result = $this->get_jobs((int) $atts['per_page']);
		if (is_wp_error($result)) {
			return '<div class="mdr-agenda mdr-agenda-error">' . esc_html__('No se pudo cargar las ofertas en este momento.', 'mapa-de-recursos') . '</div>';
		}
		$jobs = $result['items'];
		if (! $jobs) {
			return '<div class="mdr-agenda mdr-agenda-empty">' . esc_html__('No hay ofertas disponibles ahora mismo.', 'mapa-de-recursos') . '</div>';
		}

		$this->enqueue_assets();

		ob_start();
		echo '<div class="mdr-agenda-grid">';
		foreach ($jobs as $job) {
			$this->render_card($job);
		}
		echo '</div>';

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
	}

	private function get_jobs(int $per_page): array|\WP_Error {
		$per_page = min(50, max(1, $per_page));
		$cache_key = 'mdr_empleo_' . md5($this->feed_url . '|per_page:' . $per_page);
		$cached = get_transient($cache_key);
		if ($cached !== false) {
			return $cached;
		}

		$response = wp_remote_get($this->feed_url, ['timeout' => 10]);
		if (is_wp_error($response)) {
			return $response;
		}
		$body = wp_remote_retrieve_body($response);
		if (! $body) {
			return new \WP_Error('empleo_empty', __('El feed de empleo está vacío.', 'mapa-de-recursos'));
		}

		$xml = simplexml_load_string($body);
		if ($xml === false || empty($xml->channel->item)) {
			return new \WP_Error('empleo_parse', __('No se pudo leer el feed de empleo.', 'mapa-de-recursos'));
		}

		$items = [];
		foreach ($xml->channel->item as $item) {
			$items[] = $this->map_item($item);
			if (count($items) >= $per_page) {
				break;
			}
		}

		$data = [
			'items' => $items,
			'total' => count($items),
		];

		set_transient($cache_key, $data, HOUR_IN_SECONDS);
		return $data;
	}

	private function map_item(\SimpleXMLElement $item): array {
		$title_raw = isset($item->title) ? (string) $item->title : '';
		$title_raw = wp_strip_all_tags($title_raw);
		$parts = array_map('trim', explode('|', $title_raw, 2));
		$title = $parts[0] ?? '';
		$location = $parts[1] ?? '';

		$link = isset($item->link) ? esc_url_raw((string) $item->link) : '';

		$pub_date = isset($item->pubDate) ? (string) $item->pubDate : '';
		$ts = $pub_date !== '' ? strtotime($pub_date) : null;
		$date_display = $ts ? wp_date(get_option('date_format'), $ts, wp_timezone()) : '';

		$description = isset($item->description) ? (string) $item->description : '';
		$description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5);
		$excerpt = wp_trim_words(wp_strip_all_tags($description), 40);

		return [
			'title' => $title !== '' ? $title : $title_raw,
			'location' => $location,
			'link' => $link,
			'date' => $date_display,
			'date_ts' => $ts,
			'excerpt' => $excerpt,
			'placeholder_bg' => $this->get_palette_color(),
		];
	}

	private function render_card(array $job): void {
		$thumb_style = ' style="background-color: ' . esc_attr($job['placeholder_bg']) . ';"';
		?>
		<div class="mdr-agenda-card mdr-job-card">
			<div class="mdr-agenda-thumb mdr-agenda-thumb--placeholder"<?php echo $thumb_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<img class="mdr-agenda-thumb-img--placeholder" src="<?php echo esc_url($this->placeholder); ?>" alt="" loading="lazy" />
			</div>
			<div class="mdr-agenda-body">
				<div class="mdr-agenda-terms">
					<?php if (! empty($job['location'])) : ?>
						<span class="mdr-agenda-badge"><?php echo esc_html($job['location']); ?></span>
					<?php endif; ?>
					<span class="mdr-agenda-badge mdr-agenda-badge-ghost"><?php esc_html_e('Oferta', 'mapa-de-recursos'); ?></span>
				</div>
				<h3 class="mdr-agenda-title"><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($job['link']); ?>"><?php echo esc_html($job['title']); ?></a></h3>
				<?php if (! empty($job['date'])) : ?>
					<div class="mdr-agenda-estado"><?php echo esc_html(sprintf(__('Publicado el %s', 'mapa-de-recursos'), $job['date'])); ?></div>
				<?php endif; ?>
				<p class="mdr-agenda-excerpt"><?php echo esc_html($job['excerpt']); ?></p>
				<div class="mdr-agenda-actions">
					<a class="button mdr-entities-btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($job['link']); ?>"><?php esc_html_e('Ver oferta', 'mapa-de-recursos'); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_palette_color(): string {
		return $this->placeholder_colors[array_rand($this->placeholder_colors)];
	}
}
