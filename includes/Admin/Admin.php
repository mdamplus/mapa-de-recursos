<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use MapaDeRecursos\Pdf\PdfExporter;
use MapaDeRecursos\Admin\Importer;
use MapaDeRecursos\Admin\KnowledgeBase;

if (! defined('ABSPATH')) {
	exit;
}

class Admin {
	private Logger $logger;
	private Entities $entities;
	private Recursos $recursos;
	private Servicios $servicios;
	private Reports $reports;
	private Zonas $zonas;
	private Ambitos $ambitos;
	private Subcategorias $subcategorias;
	private LogsPage $logs;
	private Financiaciones $financiaciones;
	private Importer $importer;
	private KnowledgeBase $knowledge;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
		$this->entities = new Entities($logger);
		$this->recursos = new Recursos($logger);
		$this->servicios = new Servicios($logger);
		$this->reports  = new Reports($logger, new PdfExporter($logger));
		$this->zonas    = new Zonas($logger);
		$this->ambitos  = new Ambitos($logger);
		$this->subcategorias = new Subcategorias($logger);
		$this->logs     = new LogsPage();
		$this->financiaciones = new Financiaciones($logger);
		$this->importer = new Importer($logger);
		$this->knowledge = new KnowledgeBase();
	}

	public function register(): void {
		add_menu_page(
			__('Mapa de recursos', 'mapa-de-recursos'),
			__('Mapa de recursos', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_dashboard',
			[$this, 'render_dashboard'],
			'dashicons-location',
			26
		);

		// Orden del submenú según especificación.
		add_submenu_page(
			'mdr_dashboard',
			__('Recursos', 'mapa-de-recursos'),
			__('Recursos', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_recursos',
			[$this, 'render_recursos']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Entidades', 'mapa-de-recursos'),
			__('Entidades', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_entidades',
			[$this, 'render_entidades']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Zonas', 'mapa-de-recursos'),
			__('Zonas', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_zonas',
			[$this, 'render_zonas']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Ámbitos', 'mapa-de-recursos'),
			__('Ámbitos', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_ambitos',
			[$this, 'render_ambitos']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Subcategorías', 'mapa-de-recursos'),
			__('Subcategorías', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_subcategorias',
			[$this, 'render_subcategorias']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Servicios / Iconos', 'mapa-de-recursos'),
			__('Servicios / Iconos', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_servicios',
			[$this, 'render_servicios']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Financiación', 'mapa-de-recursos'),
			__('Financiación', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_financiaciones',
			[$this, 'render_financiaciones']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Importar', 'mapa-de-recursos'),
			__('Importar', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_import',
			[$this, 'render_import']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Knowledge Base', 'mapa-de-recursos'),
			__('Knowledge Base', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_knowledge',
			[$this, 'render_knowledge']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Ajustes', 'mapa-de-recursos'),
			__('Ajustes', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_settings',
			[$this, 'render_settings']
		);
		add_submenu_page(
			'mdr_dashboard',
			__('Informes / PDF', 'mapa-de-recursos'),
			__('Informes / PDF', 'mapa-de-recursos'),
			'mdr_manage',
			'mdr_pdf',
			[$this, 'render_pdf']
		);

		add_submenu_page(
			'mdr_dashboard',
			__('Logs', 'mapa-de-recursos'),
			__('Logs', 'mapa-de-recursos'),
			'mdr_view_logs',
			'mdr_logs',
			[$this, 'render_logs']
		);
	}

	public function render_dashboard(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Mapa de recursos', 'mapa-de-recursos'); ?></h1>
			<p><?php esc_html_e('Panel inicial del plugin. Próximamente se añadirán listados y formularios.', 'mapa-de-recursos'); ?></p>
		</div>
		<?php
	}

	public function render_settings(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		if (isset($_POST['mdr_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_settings_nonce'])), 'mdr_save_settings')) {
			$settings = [
				'map_provider'      => isset($_POST['map_provider']) ? sanitize_text_field(wp_unslash($_POST['map_provider'])) : 'osm',
				'mapbox_token'      => isset($_POST['mapbox_token']) ? sanitize_text_field(wp_unslash($_POST['mapbox_token'])) : '',
				'default_radius_km' => isset($_POST['default_radius_km']) ? floatval(wp_unslash($_POST['default_radius_km'])) : 5,
				'fallback_center'   => [
					'lat' => isset($_POST['fallback_lat']) ? floatval(wp_unslash($_POST['fallback_lat'])) : 36.7213,
					'lng' => isset($_POST['fallback_lng']) ? floatval(wp_unslash($_POST['fallback_lng'])) : -4.4214,
				],
				'default_zona'      => isset($_POST['default_zona']) ? absint($_POST['default_zona']) : '',
				'fa_kit'            => isset($_POST['fa_kit']) ? sanitize_text_field(wp_unslash($_POST['fa_kit'])) : 'f2eb5a66e3',
				'load_bulma_front'  => isset($_POST['load_bulma_front']) ? 1 : 0,
				'entity_marker_url' => isset($_POST['entity_marker_url']) ? esc_url_raw(wp_unslash($_POST['entity_marker_url'])) : '',
			];
			update_option('mapa_de_recursos_settings', $settings);
			$this->logger->log('update_settings', 'settings', ['provider' => $settings['map_provider']], 'plugin');
			?>
			<div class="notice notice-success"><p><?php esc_html_e('Ajustes guardados.', 'mapa-de-recursos'); ?></p></div>
			<?php
		}

		if (isset($_POST['mdr_geocode_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_geocode_nonce'])), 'mdr_geocode_all')) {
			$this->geocode_all_entities();
		}

		$settings = get_option('mapa_de_recursos_settings', []);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Ajustes del mapa', 'mapa-de-recursos'); ?></h1>
			<form method="post">
				<?php wp_nonce_field('mdr_save_settings', 'mdr_settings_nonce'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Proveedor de mapa', 'mapa-de-recursos'); ?></th>
						<td>
							<select name="map_provider">
								<option value="osm" <?php selected($settings['map_provider'] ?? 'osm', 'osm'); ?>><?php esc_html_e('OpenStreetMap (Leaflet)', 'mapa-de-recursos'); ?></option>
								<option value="mapbox" <?php selected($settings['map_provider'] ?? 'osm', 'mapbox'); ?>><?php esc_html_e('Mapbox', 'mapa-de-recursos'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Mapbox token', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="text" name="mapbox_token" value="<?php echo isset($settings['mapbox_token']) ? esc_attr($settings['mapbox_token']) : ''; ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Radio por defecto (km)', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="number" step="0.1" name="default_radius_km" value="<?php echo isset($settings['default_radius_km']) ? esc_attr($settings['default_radius_km']) : '5'; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Centro por defecto (lat,lng)', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="text" name="fallback_lat" value="<?php echo isset($settings['fallback_center']['lat']) ? esc_attr($settings['fallback_center']['lat']) : '36.7213'; ?>" size="10" />
							<input type="text" name="fallback_lng" value="<?php echo isset($settings['fallback_center']['lng']) ? esc_attr($settings['fallback_center']['lng']) : '-4.4214'; ?>" size="10" />
							<p class="description"><?php esc_html_e('Selecciona en el mapa o ajusta manualmente.', 'mapa-de-recursos'); ?></p>
							<div id="mdr-settings-map" style="max-width:520px; height:320px; margin-top:10px; border:1px solid #e5e5e5; border-radius:6px;"></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Zona por defecto', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="number" name="default_zona" value="<?php echo isset($settings['default_zona']) ? esc_attr((string) $settings['default_zona']) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Font Awesome Kit ID', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="text" name="fa_kit" value="<?php echo isset($settings['fa_kit']) ? esc_attr((string) $settings['fa_kit']) : 'f2eb5a66e3'; ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Se cargará desde kit.fontawesome.com/{kit}.js (clásico solid/regular/brands).', 'mapa-de-recursos'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Cargar Bulma en frontend', 'mapa-de-recursos'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="load_bulma_front" value="1" <?php checked($settings['load_bulma_front'] ?? 0, 1); ?> />
								<?php esc_html_e('Incluir Bulma CSS en las páginas con el shortcode', 'mapa-de-recursos'); ?>
							</label>
							<p class="description"><?php esc_html_e('Desmarca si tu tema ya carga Bulma o prefieres no añadirlo.', 'mapa-de-recursos'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Icono de marcador (entidades)', 'mapa-de-recursos'); ?></th>
						<td>
							<input type="text" name="entity_marker_url" id="entity_marker_url" value="<?php echo isset($settings['entity_marker_url']) ? esc_attr((string) $settings['entity_marker_url']) : ''; ?>" class="regular-text" placeholder="<?php esc_attr_e('URL de PNG/SVG', 'mapa-de-recursos'); ?>" />
							<button type="button" class="button" id="mdr-upload-marker"><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></button>
							<p class="description"><?php esc_html_e('Icono opcional para los marcadores del mapa de entidades.', 'mapa-de-recursos'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Guardar ajustes', 'mapa-de-recursos')); ?>
			</form>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (typeof L === 'undefined') { return; }
				const latInput = document.querySelector('input[name="fallback_lat"]');
				const lngInput = document.querySelector('input[name="fallback_lng"]');
				const mapEl = document.getElementById('mdr-settings-map');
				if (!latInput || !lngInput || !mapEl) { return; }

				const lat = parseFloat(latInput.value) || 36.7213;
				const lng = parseFloat(lngInput.value) || -4.4214;
				const map = L.map(mapEl).setView([lat, lng], 12);
				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: '&copy; OpenStreetMap contributors'
				}).addTo(map);

				const marker = L.marker([lat, lng], { draggable: true }).addTo(map);

				function updateInputs(latLng) {
					latInput.value = latLng.lat.toFixed(6);
					lngInput.value = latLng.lng.toFixed(6);
				}

				marker.on('dragend', function (e) {
					updateInputs(e.target.getLatLng());
				});

				map.on('click', function (e) {
					marker.setLatLng(e.latlng);
					updateInputs(e.latlng);
				});

				function recenter() {
					const newLat = parseFloat(latInput.value);
					const newLng = parseFloat(lngInput.value);
					if (!isNaN(newLat) && !isNaN(newLng)) {
						const ll = L.latLng(newLat, newLng);
						marker.setLatLng(ll);
						map.setView(ll);
					}
				}

				latInput.addEventListener('change', recenter);
				lngInput.addEventListener('change', recenter);
			});
			</script>
			<hr />
			<h2><?php esc_html_e('Utilidades', 'mapa-de-recursos'); ?></h2>
			<form method="post">
				<?php wp_nonce_field('mdr_geocode_all', 'mdr_geocode_nonce'); ?>
				<p><?php esc_html_e('Actualizar latitud/longitud de todas las entidades usando su dirección (Nominatim). Solo se procesan las que no tengan lat/lng o estén en 0.0000000.', 'mapa-de-recursos'); ?></p>
				<?php submit_button(__('Actualizar lat/lng', 'mapa-de-recursos'), 'secondary'); ?>
			</form>
		</div>
		<?php
	}

	public function render_entidades(): void {
		$this->entities->handle_actions();
		$this->entities->render();
	}

	public function render_recursos(): void {
		$this->recursos->handle_actions();
		$this->recursos->render();
	}

	public function render_servicios(): void {
		$this->servicios->handle_actions();
		$this->servicios->render();
	}

	public function render_financiaciones(): void {
		$this->financiaciones->handle_actions();
		$this->financiaciones->render();
	}

	public function render_import(): void {
		$this->importer->handle_actions();
		$this->importer->render();
	}

	public function render_knowledge(): void {
		$this->knowledge->render();
	}

	public function render_zonas(): void {
		$this->zonas->handle_actions();
		$this->zonas->render();
	}

	public function render_ambitos(): void {
		$this->ambitos->handle_actions();
		$this->ambitos->render();
	}

	public function render_subcategorias(): void {
		// La gestión se realiza desde Ámbitos (creación rápida). Mantenemos la página para compatibilidad pero la ocultamos del menú.
		$this->subcategorias->handle_actions();
		$this->subcategorias->render();
	}

	public function render_pdf(): void {
		$this->reports->handle_actions();
		$this->reports->render();
	}

	public function render_logs(): void {
		$this->logs->render();
	}

	private function geocode_all_entities(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";

		$entities = $wpdb->get_results("SELECT * FROM {$table} WHERE ((lat IS NULL OR lng IS NULL) OR (lat = 0 OR lng = 0) OR (lat = 69.1665290 AND lng = 17.8786200)) AND (direccion_linea1 <> '' OR ciudad <> '' OR provincia <> '' OR pais <> '' OR cp <> '') LIMIT 200");
		if (! $entities) {
			echo '<div class="notice notice-info"><p>' . esc_html__('No hay entidades pendientes de geolocalizar.', 'mapa-de-recursos') . '</p></div>';
			return;
		}

		$updated = 0;
		$failed  = 0;
		foreach ($entities as $ent) {
			$address_parts = array_filter([
				$ent->direccion_linea1,
				$ent->cp,
				$ent->ciudad,
				$ent->provincia,
				$ent->pais,
			]);
			$address = implode(', ', $address_parts);
			if ($address === '') {
				$failed++;
				continue;
			}

			$coords = $this->geocode_address($address);
			if (! $coords) {
				$failed++;
				continue;
			}

			$wpdb->update(
				$table,
				[
					'lat' => $coords['lat'],
					'lng' => $coords['lng'],
				],
				['id' => $ent->id],
				['%f','%f'],
				['%d']
			);
			$updated++;
		}

		$this->logger->log('geocode_entities', 'entidad', ['updated' => $updated, 'failed' => $failed], 'geocode');

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(sprintf(__('Geocodificación completada. Actualizadas: %d. Fallidas: %d.', 'mapa-de-recursos'), $updated, $failed))
		);
	}

	private function geocode_address(string $address): ?array {
		$url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($address);
		$response = wp_remote_get($url, [
			'timeout' => 12,
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'mapa-de-recursos/1.0',
			],
		]);

		if (is_wp_error($response)) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (! is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
			return null;
		}

		return [
			'lat' => (float) $data[0]['lat'],
			'lng' => (float) $data[0]['lon'],
		];
	}
}
