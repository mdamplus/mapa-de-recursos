<?php

declare(strict_types=1);

namespace MapaDeRecursos;

use MapaDeRecursos\Admin\Admin;
use MapaDeRecursos\Database\Installer;
use MapaDeRecursos\Logs\Logger;
use MapaDeRecursos\Pdf\PdfExporter;
use MapaDeRecursos\Rest\Entities_Controller;
use MapaDeRecursos\Rest\Filters_Controller;
use MapaDeRecursos\Rest\Recursos_Controller;
use MapaDeRecursos\Updater\GitHubUpdater;
use WP_REST_Controller;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Core plugin bootstrapper.
 */
class Plugin {
	private static ?self $instance = null;
	private ?Logger $logger        = null;
	private ?Admin $admin          = null;
	private ?PdfExporter $pdf      = null;
	/** @var WP_REST_Controller[] */
	private array $rest_controllers = [];
	private ?GitHubUpdater $updater = null;

	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function run(): void {
		add_action('init', [$this, 'load_textdomain']);
		add_action('init', [$this, 'register_shortcodes']);
		add_action('rest_api_init', [$this, 'register_rest_routes']);
		add_action('admin_menu', [$this, 'register_admin']);
		add_action('wp_ajax_mdr_agenda_load', [$this, 'ajax_agenda_load']);
		add_action('wp_ajax_nopriv_mdr_agenda_load', [$this, 'ajax_agenda_load']);
		add_action('init', [$this, 'add_entity_rewrite']);
		add_filter('query_vars', [$this, 'add_entity_query_var']);
		add_action('template_redirect', [$this, 'maybe_render_entity_route']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_filter('plugin_action_links_' . plugin_basename(MAPA_DE_RECURSOS_FILE), [$this, 'plugin_action_links']);
		add_action('plugins_loaded', [$this, 'init_updater']);
		add_action('plugins_loaded', [$this, 'register_logger_hooks']);
		add_action('plugins_loaded', [$this, 'ensure_caps_runtime']);
		add_action('plugins_loaded', [Installer::class, 'maybe_upgrade']);
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'mapa-de-recursos',
			false,
			dirname(plugin_basename(MAPA_DE_RECURSOS_FILE)) . '/languages/'
		);
	}

	public function register_shortcodes(): void {
		add_shortcode('mapa_de_recursos', [$this, 'render_shortcode']);
		add_shortcode('mdr_entidades_mapa', [$this, 'render_entities_map_shortcode']);
		add_shortcode('mdr_entidad', [$this, 'render_entity_detail_shortcode']);
		add_shortcode('eracis_agenda', [$this, 'render_agenda_shortcode']);
		add_shortcode('eracis_empleo', [$this, 'render_empleo_shortcode']);
	}

	public function register_rest_routes(): void {
		$this->rest_controllers = [
			new Entities_Controller(),
			new Recursos_Controller(),
			new Filters_Controller(),
		];

		foreach ($this->rest_controllers as $controller) {
			$controller->register_routes();
		}
	}

	public function register_admin(): void {
		$this->admin = new Admin($this->get_logger());
		$this->admin->register();
	}

	public function init_updater(): void {
		$this->updater = new GitHubUpdater(
			'mapa-de-recursos',
			'mdamplus',
			'mapa-de-recursos',
			MAPA_DE_RECURSOS_VERSION,
			$this->get_logger()
		);
		$this->updater->register();
	}

	public function enqueue_frontend_assets(): void {
		$settings = $this->get_settings();
		$fa_kit = ! empty($settings['fa_kit']) ? sanitize_text_field($settings['fa_kit']) : 'f2eb5a66e3';
		if ($fa_kit) {
			wp_enqueue_script(
				'mdr-fa-kit',
				'https://kit.fontawesome.com/' . rawurlencode($fa_kit) . '.js',
				[],
				null,
				true
			);
			wp_script_add_data('mdr-fa-kit', 'crossorigin', 'anonymous');
			// Fallback CSS por si el kit no carga.
			wp_enqueue_style(
				'mdr-fa-fallback',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
				[],
				'6.5.1'
			);
		}

		wp_register_style(
			'mdr-leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			[],
			'1.9.4'
		);

		wp_register_script(
			'mdr-leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			[],
			'1.9.4',
			true
		);

		wp_register_style(
			'mdr-leaflet-cluster',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
			['mdr-leaflet'],
			'1.5.3'
		);

		wp_register_style(
			'mdr-leaflet-cluster-default',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
			['mdr-leaflet-cluster'],
			'1.5.3'
		);

		wp_register_script(
			'mdr-leaflet-cluster',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',
			['mdr-leaflet'],
			'1.5.3',
			true
		);

		wp_register_style(
			'mdr-frontend',
			MAPA_DE_RECURSOS_URL . 'assets/css/frontend.css',
			['mdr-leaflet', 'mdr-leaflet-cluster', 'mdr-leaflet-cluster-default'],
			MAPA_DE_RECURSOS_VERSION
		);

		wp_register_script(
			'mdr-frontend',
			MAPA_DE_RECURSOS_URL . 'assets/js/frontend-map.js',
			['mdr-leaflet', 'mdr-leaflet-cluster'],
			MAPA_DE_RECURSOS_VERSION,
			true
		);
		wp_register_script(
			'mdr-entities-map',
			MAPA_DE_RECURSOS_URL . 'assets/js/entities-map.js',
			['mdr-leaflet', 'mdr-leaflet-cluster'],
			MAPA_DE_RECURSOS_VERSION,
			true
		);

		if (! is_singular() && ! is_front_page()) {
			return;
		}

		if (! empty($settings['load_bulma_front'])) {
			wp_enqueue_style(
				'mdr-bulma-front',
				'https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css',
				[],
				'0.9.4'
			);
		}

		wp_enqueue_style('mdr-frontend');
		wp_enqueue_script('mdr-frontend');

		wp_localize_script(
			'mdr-frontend',
			'mdrData',
			[
				'nonce'           => wp_create_nonce('wp_rest'),
				'restUrl'         => esc_url_raw(rest_url('mdr/v1')),
				'provider'        => $settings['map_provider'],
				'mapboxToken'     => $settings['mapbox_token'],
				'defaultRadiusKm' => (float) $settings['default_radius_km'],
				'fallbackCenter'  => $settings['fallback_center'],
				'defaultZona'     => $settings['default_zona'],
				'markerIcons'     => [
					'entidad' => $settings['entity_marker_url'] ?? '',
					'servicio' => $settings['service_marker_url'] ?? '',
					'recurso' => $settings['recurso_marker_url'] ?? '',
				],
				'i18n'            => [
					'loading'     => __('Cargando recursos...', 'mapa-de-recursos'),
					'noResults'   => __('No hay recursos en este radio.', 'mapa-de-recursos'),
					'viewResources' => __('Ver recursos', 'mapa-de-recursos'),
					'denyGeolocation' => __('No se pudo obtener tu ubicación. Usando la ubicación por defecto.', 'mapa-de-recursos'),
				],
			]
		);
	}

	public function enqueue_admin_assets(string $hook): void {
		if (strpos($hook, 'mapa-de-recursos') === false && strpos($hook, 'mdr_') === false) {
			return;
		}

		$settings = $this->get_settings();
		$fa_kit = ! empty($settings['fa_kit']) ? sanitize_text_field($settings['fa_kit']) : 'f2eb5a66e3';
		if ($fa_kit) {
			wp_enqueue_script(
				'mdr-fa-kit',
				'https://kit.fontawesome.com/' . rawurlencode($fa_kit) . '.js',
				[],
				null,
				true
			);
			wp_script_add_data('mdr-fa-kit', 'crossorigin', 'anonymous');
			wp_enqueue_style(
				'mdr-fa-fallback',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
				[],
				'6.5.1'
			);
		}

		wp_enqueue_media();

		$admin_js_path  = MAPA_DE_RECURSOS_PATH . 'assets/js/admin.js';
		$admin_css_path = MAPA_DE_RECURSOS_PATH . 'assets/css/admin.css';
		$asset_version  = MAPA_DE_RECURSOS_VERSION;
		if (file_exists($admin_js_path)) {
			$asset_version .= '.' . filemtime($admin_js_path);
		}

		// Leaflet para mapa en entidades (lo cargamos en todas las pantallas del plugin para evitar fallos por hook).
		wp_enqueue_style(
			'mdr-leaflet-admin',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			[],
			'1.9.4'
		);
		wp_enqueue_script(
			'mdr-leaflet-admin',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			[],
			'1.9.4',
			true
		);

		wp_enqueue_style(
			'mdr-admin',
			MAPA_DE_RECURSOS_URL . 'assets/css/admin.css',
			[],
			file_exists($admin_css_path) ? $asset_version : MAPA_DE_RECURSOS_VERSION
		);
		wp_enqueue_style(
			'mdr-bulma',
			'https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css',
			[],
			'0.9.4'
		);
		$admin_deps = ['jquery'];
		if (wp_script_is('mdr-leaflet-admin', 'registered') || wp_script_is('mdr-leaflet-admin', 'enqueued')) {
			$admin_deps[] = 'mdr-leaflet-admin';
		}

		wp_enqueue_script(
			'mdr-admin',
			MAPA_DE_RECURSOS_URL . 'assets/js/admin.js',
			$admin_deps,
			$asset_version,
			true
		);

		wp_localize_script(
			'mdr-admin',
			'mdrAdmin',
			[
				'i18n' => [
					'geocode_searching' => __('Buscando...', 'mapa-de-recursos'),
					'geocode_error'     => __('Error obteniendo coordenadas.', 'mapa-de-recursos'),
					'geocode_not_found' => __('No se encontraron coordenadas para esa dirección.', 'mapa-de-recursos'),
					'geocode_need_address' => __('Introduce una dirección para geocodificar.', 'mapa-de-recursos'),
				],
				'fallbackCenter' => [
					'lat' => (float) ($settings['fallback_center']['lat'] ?? 36.7213),
					'lng' => (float) ($settings['fallback_center']['lng'] ?? -4.4214),
				],
				'faJson' => MAPA_DE_RECURSOS_URL . 'assets/icons/fa-free.json',
			]
		);
	}

	public function render_shortcode(array $atts = []): string {
		if (! wp_script_is('mdr-frontend', 'enqueued')) {
			$this->enqueue_frontend_assets();
		}

		ob_start();
		?>
		<div id="mdr-app" class="mdr-app">
			<div class="mdr-controls">
				<div class="mdr-filters">
					<label>
						<?php esc_html_e('Zona', 'mapa-de-recursos'); ?>
						<select id="mdr-filter-zona"><option value=""><?php esc_html_e('Todas', 'mapa-de-recursos'); ?></option></select>
					</label>
					<label>
						<?php esc_html_e('Ámbito', 'mapa-de-recursos'); ?>
						<select id="mdr-filter-ambito"><option value=""><?php esc_html_e('Todos', 'mapa-de-recursos'); ?></option></select>
					</label>
					<label>
						<?php esc_html_e('Subcategoría', 'mapa-de-recursos'); ?>
						<select id="mdr-filter-subcategoria"><option value=""><?php esc_html_e('Todas', 'mapa-de-recursos'); ?></option></select>
					</label>
					<label>
						<?php esc_html_e('Servicio', 'mapa-de-recursos'); ?>
						<select id="mdr-filter-servicio"><option value=""><?php esc_html_e('Todos', 'mapa-de-recursos'); ?></option></select>
					</label>
					<label>
						<?php esc_html_e('Buscar', 'mapa-de-recursos'); ?>
						<input type="search" id="mdr-filter-q" placeholder="<?php esc_attr_e('Nombre o palabra clave', 'mapa-de-recursos'); ?>" />
					</label>
				</div>
				<div id="mdr-status" class="mdr-status" role="status" aria-live="polite"></div>
			</div>
			<div id="mdr-map" class="mdr-map"></div>
			<div id="mdr-list" class="mdr-list" aria-live="polite"></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	public function render_entities_map_shortcode(array $atts = []): string {
		$settings = $this->get_settings();
		$atts = shortcode_atts([
			'width' => '100%',
			'height' => '500px',
		], $atts, 'mdr_entidades_mapa');
		if (! wp_script_is('mdr-entities-map', 'registered')) {
			$this->enqueue_frontend_assets();
		}
		wp_enqueue_style('mdr-frontend');
		wp_enqueue_script('mdr-entities-map');
		wp_localize_script(
			'mdr-entities-map',
			'mdrEntities',
			[
				'restUrl'     => esc_url_raw(rest_url('mdr/v1/entidades')),
				'nonce'       => wp_create_nonce('wp_rest'),
				'entityUrlBase' => trailingslashit(home_url('/entidades/')),
				'fallbackCenter' => $settings['fallback_center'],
				'markerIcon'   => $settings['entity_marker_url'] ?? '',
				'strings' => [
					'loading' => __('Cargando entidades...', 'mapa-de-recursos'),
					'noResults' => __('No hay entidades para mostrar.', 'mapa-de-recursos'),
					'viewServices' => __('Ver servicios', 'mapa-de-recursos'),
				],
			]
		);

		ob_start();
		?>
		<div id="mdr-entities-map" class="mdr-entities-map-wrap" style="width: <?php echo esc_attr($atts['width']); ?>;">
			<div id="mdr-entities-map-status" class="mdr-status"></div>
			<div id="mdr-entities-map-canvas" class="mdr-map" style="height: <?php echo esc_attr($atts['height']); ?>;"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function render_entity_detail_shortcode(array $atts = []): string {
		global $wpdb;
		$atts = shortcode_atts(['slug' => ''], $atts, 'mdr_entidad');
		$slug = sanitize_title($atts['slug']);
		if ($slug === '') {
			return '';
		}
		$table_ent = "{$wpdb->prefix}mdr_entidades";
		$table_rec = "{$wpdb->prefix}mdr_recursos";
		$table_zonas = "{$wpdb->prefix}mdr_zonas";
		$entity = $wpdb->get_row($wpdb->prepare("SELECT e.*, z.nombre AS zona_nombre FROM {$table_ent} e LEFT JOIN {$table_zonas} z ON z.id = e.zona_id WHERE e.slug = %s", $slug));
		if (! $entity) {
			return '';
		}
		$recursos = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.recurso_programa, r.descripcion, r.objetivo, r.destinatarios, r.periodo_ejecucion, r.servicio_id, s.icono_clase, s.nombre AS servicio_nombre, a.nombre AS ambito_nombre
				FROM {$table_rec} r
				LEFT JOIN {$wpdb->prefix}mdr_servicios s ON s.id = r.servicio_id
				LEFT JOIN {$wpdb->prefix}mdr_ambitos a ON a.id = r.ambito_id
				WHERE r.entidad_id = %d AND r.activo = 1
				ORDER BY r.id DESC
				LIMIT 200",
				$entity->id
			)
		);
		$entity_url = trailingslashit(home_url('/entidades/' . $slug));
		$gmaps_link = '';
		if (! empty($entity->lat) && ! empty($entity->lng)) {
			$gmaps_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($entity->lat . ',' . $entity->lng);
		} elseif (! empty($entity->direccion_linea1)) {
			$gmaps_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($entity->direccion_linea1);
		}
		$whatsapp_text_parts = [
			$entity->nombre,
			$entity->direccion_linea1 ?: '',
			$entity->email ?: '',
			$entity->telefono ?: '',
			$entity_url,
		];
		$whatsapp_text = rawurlencode(implode(' | ', array_filter($whatsapp_text_parts)));
		$whatsapp_link = 'https://wa.me/?text=' . $whatsapp_text;

		// Posts recientes (3).
		$posts = get_posts([
			'numberposts' => 3,
			'post_status' => 'publish',
		]);

		if (! wp_script_is('mdr-leaflet', 'enqueued')) {
			$this->enqueue_frontend_assets();
		}
		wp_enqueue_style('mdr-frontend');
		wp_enqueue_script('mdr-leaflet');

		ob_start();
		$logo = $entity->logo_url ?: ($entity->logo_media_id ? wp_get_attachment_url((int) $entity->logo_media_id) : '');
		?>
		<div class="mdr-entity-detail">
			<div class="mdr-entity-hero">
				<div class="mdr-entity-map" id="mdr-entity-map" data-lat="<?php echo esc_attr((string) $entity->lat); ?>" data-lng="<?php echo esc_attr((string) $entity->lng); ?>" data-name="<?php echo esc_attr($entity->nombre); ?>"></div>
				<?php if ($gmaps_link || $whatsapp_link) : ?>
					<div class="mdr-map-actions">
						<?php if ($gmaps_link) : ?>
							<a class="mdr-map-action-btn" href="<?php echo esc_url($gmaps_link); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Enviar a Google Maps', 'mapa-de-recursos'); ?>">
								<i class="fa-solid fa-location-arrow"></i>
								<span class="mdr-map-tooltip"><?php esc_html_e('Enviar a Google Maps', 'mapa-de-recursos'); ?></span>
							</a>
						<?php endif; ?>
						<a class="mdr-map-action-btn" href="<?php echo esc_url($whatsapp_link); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Compartir por WhatsApp', 'mapa-de-recursos'); ?>">
							<i class="fa-brands fa-whatsapp"></i>
							<span class="mdr-map-tooltip"><?php esc_html_e('Compartir por WhatsApp', 'mapa-de-recursos'); ?></span>
						</a>
					</div>
				<?php endif; ?>
			</div>
			<div class="mdr-entity-banner">
				<div class="mdr-entity-banner-inner">
					<h2 class="mdr-entity-banner-title"><?php esc_html_e('Entidad Eracis +', 'mapa-de-recursos'); ?></h2>
					<h1 class="mdr-entity-banner-name"><?php echo esc_html($entity->nombre); ?></h1>
				</div>
			</div>
			<div class="mdr-entity-container">
				<div class="mdr-entity-columns">
					<div class="mdr-entity-col">
						<?php if ($logo) : ?>
							<div class="mdr-entity-logo"><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($entity->nombre); ?>" /></div>
						<?php endif; ?>
						<div class="mdr-entity-info">
							<?php if ($entity->direccion_linea1) : ?>
								<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-location-dot"></i></span><span><?php echo esc_html($entity->direccion_linea1); ?></span></div>
								<div class="mdr-popup-row">
									<a class="button mdr-entities-btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($entity->direccion_linea1)); ?>"><?php esc_html_e('Enviar a Google Maps', 'mapa-de-recursos'); ?></a>
								</div>
							<?php endif; ?>
							<?php if ($entity->telefono) : ?>
								<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-phone"></i></span><a href="tel:<?php echo esc_attr($entity->telefono); ?>"><?php echo esc_html($entity->telefono); ?></a></div>
							<?php endif; ?>
							<?php if ($entity->email) : ?>
								<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-envelope"></i></span><a href="mailto:<?php echo esc_attr($entity->email); ?>"><?php echo esc_html($entity->email); ?></a></div>
							<?php endif; ?>
							<?php if ($entity->zona_nombre) : ?>
								<div class="mdr-popup-row"><span class="mdr-popup-icon"><i class="fa-solid fa-map"></i></span><span><?php echo esc_html($entity->zona_nombre); ?></span></div>
							<?php endif; ?>
						</div>
						<?php if (! empty($entity->descripcion)) : ?>
							<div class="mdr-entity-desc"><?php echo wp_kses_post($entity->descripcion); ?></div>
						<?php endif; ?>
					</div>
					<div class="mdr-entity-col">
						<h3><?php esc_html_e('Sobre esta entidad', 'mapa-de-recursos'); ?></h3>
						<?php if (! empty($entity->descripcion)) : ?>
							<div class="mdr-entity-desc"><?php echo wp_kses_post($entity->descripcion); ?></div>
						<?php else : ?>
							<p><?php esc_html_e('Sin descripción disponible.', 'mapa-de-recursos'); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="mdr-entity-services-table">
					<h3><?php esc_html_e('Servicios activos', 'mapa-de-recursos'); ?></h3>
					<?php if ($recursos) : ?>
						<div class="mdr-service-list">
							<?php foreach ($recursos as $rec) : ?>
								<div class="mdr-service-row">
									<div class="mdr-service-icon">
										<?php if (! empty($rec->icono_clase)) : ?>
											<i class="<?php echo esc_attr($rec->icono_clase); ?>"></i>
										<?php else : ?>
											<i class="fa-solid fa-circle-info"></i>
										<?php endif; ?>
									</div>
									<div class="mdr-service-main">
										<div class="mdr-service-title"><?php echo esc_html($rec->recurso_programa); ?></div>
										<div class="mdr-service-meta">
											<?php if (! empty($rec->objetivo)) : ?>
												<div><strong><?php esc_html_e('Objetivo:', 'mapa-de-recursos'); ?></strong> <?php echo esc_html($rec->objetivo); ?></div>
											<?php endif; ?>
											<?php if (! empty($rec->destinatarios)) : ?>
												<div><strong><?php esc_html_e('Destinatarios:', 'mapa-de-recursos'); ?></strong> <?php echo esc_html($rec->destinatarios); ?></div>
											<?php endif; ?>
											<?php if (! empty($rec->periodo_ejecucion)) : ?>
												<div><strong><?php esc_html_e('Periodo:', 'mapa-de-recursos'); ?></strong> <?php echo esc_html($rec->periodo_ejecucion); ?></div>
											<?php endif; ?>
											<?php if (! empty($rec->ambito_nombre)) : ?>
												<div><strong><?php esc_html_e('Ámbito:', 'mapa-de-recursos'); ?></strong> <?php echo esc_html($rec->ambito_nombre); ?></div>
											<?php endif; ?>
										</div>
									</div>
									<div class="mdr-service-actions">
										<a class="button mdr-entities-btn" href="<?php echo esc_url(trailingslashit(home_url('/recursos/' . sanitize_title($rec->recurso_programa) . '-' . $entity->slug))); ?>"><?php esc_html_e('Descubrir', 'mapa-de-recursos'); ?></a>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><?php esc_html_e('Sin servicios activos.', 'mapa-de-recursos'); ?></p>
					<?php endif; ?>
				</div>
				<?php if ($posts) : ?>
					<div class="mdr-entity-posts">
						<h3><?php esc_html_e('Publicaciones recientes', 'mapa-de-recursos'); ?></h3>
						<div class="mdr-post-grid">
							<?php foreach ($posts as $post) : ?>
								<a class="mdr-post-card" href="<?php echo esc_url(get_permalink($post)); ?>">
									<?php if (has_post_thumbnail($post)) : ?>
										<div class="mdr-post-thumb"><?php echo get_the_post_thumbnail($post, 'medium'); ?></div>
									<?php endif; ?>
									<div class="mdr-post-title"><?php echo esc_html(get_the_title($post)); ?></div>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function(){
			if (typeof L === 'undefined') return;
			var mapEl = document.getElementById('mdr-entity-map');
			if (!mapEl) return;
			var lat = parseFloat(mapEl.dataset.lat);
			var lng = parseFloat(mapEl.dataset.lng);
			if (isNaN(lat) || isNaN(lng)) return;
			var map = L.map(mapEl).setView([lat, lng], 14);
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: '&copy; OpenStreetMap contributors'
			}).addTo(map);
			L.marker([lat, lng]).addTo(map).bindPopup(mapEl.dataset.name || '');
		});
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public function render_agenda_shortcode(array $atts = []): string {
		$settings = $this->get_settings();
		$api = ! empty($settings['agenda_api_url']) ? $settings['agenda_api_url'] : 'https://asociacionarrabal.org/wp-json/wp/v2/agenda';
		$agenda = new EracisAgenda($api);
		return $agenda->render($atts);
	}

	public function render_empleo_shortcode(array $atts = []): string {
		$feed_url = $atts['feed_url'] ?? 'https://arrabalempleo.agenciascolocacion.com/rss';
		$jobs = new EracisEmpleo($feed_url);
		return $jobs->render($atts);
	}

	public function ajax_agenda_load(): void {
		check_ajax_referer('mdr_agenda_load', 'nonce');
		$settings = $this->get_settings();
		$api = ! empty($settings['agenda_api_url']) ? $settings['agenda_api_url'] : 'https://asociacionarrabal.org/wp-json/wp/v2/agenda';
		$agenda = new EracisAgenda($api);
		$page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
		$per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 9;
		$atts = [
			'per_page' => $per_page,
			'page' => $page,
			'orderby' => sanitize_key($_POST['orderby'] ?? 'date'),
			'order' => sanitize_text_field($_POST['order'] ?? 'desc'),
			'search' => sanitize_text_field($_POST['search'] ?? ''),
			'mode' => sanitize_text_field($_POST['mode'] ?? 'all'),
		];
		$result = $agenda->get_events($atts, false);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => __('No se pudo cargar más eventos.', 'mapa-de-recursos')]);
		}
		if (is_wp_error($result['events'])) {
			wp_send_json_error(['message' => __('No se pudo cargar más eventos.', 'mapa-de-recursos')]);
		}
		$events = $agenda->filter_by_mode($result['events'], $atts['mode']);
		$html = $agenda->render_cards_html($events);
		$has_more = ! empty($result['has_more']);
		wp_send_json_success([
			'html' => $html,
			'has_more' => $has_more,
		]);
	}

	public function plugin_action_links(array $links): array {
		$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=mdr_settings')) . '">' . esc_html__('Ajustes', 'mapa-de-recursos') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	public function add_entity_rewrite(): void {
		add_rewrite_tag('%mdr_entidad_slug%', '([^&]+)');
		add_rewrite_rule('^entidades/([^/]+)/?$', 'index.php?mdr_entidad_slug=$matches[1]', 'top');
	}

	public function add_entity_query_var(array $vars): array {
		$vars[] = 'mdr_entidad_slug';
		return $vars;
	}

	public function maybe_render_entity_route(): void {
		$slug = get_query_var('mdr_entidad_slug');
		if (! $slug) {
			return;
		}
		global $wpdb;
		$table_ent = "{$wpdb->prefix}mdr_entidades";
		$entity_name = (string) $wpdb->get_var($wpdb->prepare("SELECT nombre FROM {$table_ent} WHERE slug = %s", $slug));
		if (! $entity_name) {
			return;
		}
		add_filter('document_title_parts', function ($parts) use ($entity_name) {
			$parts['title'] = sprintf('%s 👉 Eracis +', $entity_name);
			return $parts;
		});
		status_header(200);
		nocache_headers();
		$content = $this->render_entity_detail_shortcode(['slug' => $slug]);
		get_header();
		echo '<main class="mdr-entity-page">' . $content . '</main>';
		get_footer();
		exit;
	}

	public static function activate(): void {
		$installer = new Installer();
		$installer->install();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public function get_settings(): array {
		$defaults = [
			'map_provider'      => 'osm',
			'mapbox_token'      => '',
			'default_radius_km' => 5,
			'fallback_center'   => [
				'lat' => 36.7213,
				'lng' => -4.4214,
			],
			'default_zona'      => '',
			'fa_kit'            => 'f2eb5a66e3',
			'load_bulma_front'  => 1,
			'entity_marker_url' => '',
			'service_marker_url' => '',
			'recurso_marker_url' => '',
			'agenda_api_url'    => 'https://asociacionarrabal.org/wp-json/wp/v2/agenda',
		];

		$saved = get_option('mapa_de_recursos_settings', []);

		return wp_parse_args($saved, $defaults);
	}

	private function get_logger(): Logger {
		if (null === $this->logger) {
			$this->logger = new Logger();
		}

		return $this->logger;
	}

	public function register_logger_hooks(): void {
		$this->get_logger()->register_hooks();
	}

	/**
	 * Garantiza que el usuario vea el menú aunque la activación previa no añadiera las caps.
	 */
	public function ensure_caps_runtime(): void {
		Installer::ensure_caps();
	}

	public function pdf(): PdfExporter {
		if (null === $this->pdf) {
			$this->pdf = new PdfExporter($this->get_logger());
		}

		return $this->pdf;
	}
}
