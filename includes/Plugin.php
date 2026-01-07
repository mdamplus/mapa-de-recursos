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
		add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_filter('plugin_action_links_' . plugin_basename(MAPA_DE_RECURSOS_FILE), [$this, 'plugin_action_links']);
		add_action('plugins_loaded', [$this, 'init_updater']);
		add_action('plugins_loaded', [$this, 'register_logger_hooks']);
		add_action('plugins_loaded', [$this, 'ensure_caps_runtime']);
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

		if (! is_singular() && ! is_front_page()) {
			return;
		}

		wp_enqueue_style('mdr-frontend');
		wp_enqueue_script('mdr-frontend');

		$settings = $this->get_settings();

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

		wp_enqueue_media();
		wp_enqueue_style(
			'mdr-admin',
			MAPA_DE_RECURSOS_URL . 'assets/css/admin.css',
			[],
			MAPA_DE_RECURSOS_VERSION
		);
		wp_enqueue_style(
			'mdr-bulma',
			'https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css',
			[],
			'0.9.4'
		);
		wp_enqueue_script(
			'mdr-admin',
			MAPA_DE_RECURSOS_URL . 'assets/js/admin.js',
			['jquery'],
			MAPA_DE_RECURSOS_VERSION,
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

	public function plugin_action_links(array $links): array {
		$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=mdr_settings')) . '">' . esc_html__('Ajustes', 'mapa-de-recursos') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
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
