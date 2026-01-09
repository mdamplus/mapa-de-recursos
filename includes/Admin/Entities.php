<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Entities {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}

		if (isset($_POST['mdr_entity_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_entity_nonce'])), 'mdr_save_entity')) {
			$this->save_entity();
		}
		if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mdr_delete_entidad')) {
			$this->delete_entity(absint($_GET['id']));
		}
		if (current_user_can('manage_options') && isset($_POST['mdr_entities_bulk_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_entities_bulk_nonce'])), 'mdr_entities_bulk_delete')) {
			$this->bulk_delete();
		}
	}

	private function save_entity(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";

		$id          = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$nombre      = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
		$slug        = sanitize_title($nombre ?: uniqid('entidad', true));
		$telefono    = isset($_POST['telefono']) ? sanitize_text_field(wp_unslash($_POST['telefono'])) : '';
		$email       = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		$logo_url    = isset($_POST['logo_url']) ? esc_url_raw(wp_unslash($_POST['logo_url'])) : '';
		$logo_media  = isset($_POST['logo_media_id']) ? absint($_POST['logo_media_id']) : null;
		$lat         = isset($_POST['lat']) && $_POST['lat'] !== '' ? floatval(wp_unslash($_POST['lat'])) : null;
		$lng         = isset($_POST['lng']) && $_POST['lng'] !== '' ? floatval(wp_unslash($_POST['lng'])) : null;
		$zona_id     = isset($_POST['zona_id']) ? absint($_POST['zona_id']) : null;
		$direccion   = isset($_POST['direccion_linea1']) ? sanitize_text_field(wp_unslash($_POST['direccion_linea1'])) : '';
		$cp          = isset($_POST['cp']) ? sanitize_text_field(wp_unslash($_POST['cp'])) : '';
		$ciudad      = isset($_POST['ciudad']) ? sanitize_text_field(wp_unslash($_POST['ciudad'])) : '';
		$provincia   = isset($_POST['provincia']) ? sanitize_text_field(wp_unslash($_POST['provincia'])) : '';
		$pais        = isset($_POST['pais']) ? sanitize_text_field(wp_unslash($_POST['pais'])) : '';
		$web         = isset($_POST['web']) ? esc_url_raw(wp_unslash($_POST['web'])) : '';

		if ('' === $nombre) {
			add_settings_error('mdr_entidades', 'nombre_required', __('El nombre es obligatorio.', 'mapa-de-recursos'), 'error');
			return;
		}

		if ($email && ! is_email($email)) {
			add_settings_error('mdr_entidades', 'email_invalid', __('El email no es válido.', 'mapa-de-recursos'), 'error');
			return;
		}

		if ($telefono && ! $this->is_valid_phone($telefono)) {
			add_settings_error('mdr_entidades', 'phone_invalid', __('El teléfono no es válido.', 'mapa-de-recursos'), 'error');
			return;
		}

		// Validar unicidad de nombre.
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s AND id != %d LIMIT 1", $nombre, $id)
		);
		if ($existing_id) {
			add_settings_error('mdr_entidades', 'nombre_unique', __('Ya existe una entidad con ese nombre.', 'mapa-de-recursos'), 'error');
			return;
		}

		$data = [
			'nombre'            => $nombre,
			'slug'              => $slug,
			'telefono'          => $telefono,
			'email'             => $email,
			'logo_url'          => $logo_url,
			'logo_media_id'     => $logo_media ?: null,
			'lat'               => $lat,
			'lng'               => $lng,
			'zona_id'           => $zona_id ?: null,
			'direccion_linea1'  => $direccion,
			'cp'                => $cp,
			'ciudad'            => $ciudad,
			'provincia'         => $provincia,
			'pais'              => $pais,
			'web'               => $web,
		];

		$format = ['%s','%s','%s','%s','%s','%d','%f','%f','%d','%s','%s','%s','%s','%s'];

		if ($id > 0) {
			$wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
			$this->logger->log('update_entidad', 'entidad', ['id' => $id, 'nombre' => $nombre], 'entidad');
		} else {
			$wpdb->insert($table, $data, $format);
			$id = (int) $wpdb->insert_id;
			$this->logger->log('create_entidad', 'entidad', ['id' => $id, 'nombre' => $nombre], 'entidad');
		}

		Cache::flush_all();

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_entidades', 'updated' => 'true'], admin_url('admin.php')));
			exit;
		}

		add_settings_error('mdr_entidades', 'saved', __('Entidad guardada.', 'mapa-de-recursos'), 'updated');
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_entidades');

		$editing = null;
		if (! empty($_GET['action']) && 'edit' === $_GET['action'] && ! empty($_GET['id'])) {
			$editing = $this->get_entity(absint($_GET['id']));
		}

		$zonas = $this->get_zonas();
		$order_param = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'id_desc';
		$entities = $this->get_entities($order_param);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Entidades', 'mapa-de-recursos'); ?></h1>
			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Entidad guardada.', 'mapa-de-recursos'); ?></p></div>
			<?php endif; ?>
			<div class="mdr-admin-grid">
				<div class="mdr-admin-col">
					<h2><?php echo $editing ? esc_html__('Editar entidad', 'mapa-de-recursos') : esc_html__('Nueva entidad', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_save_entity', 'mdr_entity_nonce'); ?>
						<input type="hidden" name="id" value="<?php echo $editing ? esc_attr((string) $editing->id) : ''; ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="nombre"><?php esc_html_e('Nombre', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" required name="nombre" id="nombre" class="regular-text" value="<?php echo $editing ? esc_attr($editing->nombre) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="telefono"><?php esc_html_e('Teléfono', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="telefono" id="telefono" class="regular-text" value="<?php echo $editing ? esc_attr($editing->telefono) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="email"><?php esc_html_e('Email', 'mapa-de-recursos'); ?></label></th>
								<td><input type="email" name="email" id="email" class="regular-text" value="<?php echo $editing ? esc_attr($editing->email) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="web"><?php esc_html_e('Web', 'mapa-de-recursos'); ?></label></th>
								<td><input type="url" name="web" id="web" class="regular-text" value="<?php echo $editing ? esc_attr($editing->web) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="logo_url"><?php esc_html_e('Logo', 'mapa-de-recursos'); ?></label></th>
								<td>
									<div class="mdr-logo-field">
										<input type="hidden" name="logo_media_id" id="logo_media_id" value="<?php echo $editing ? esc_attr((string) $editing->logo_media_id) : ''; ?>">
										<input type="hidden" name="logo_url" id="logo_url" value="<?php echo $editing ? esc_attr($editing->logo_url) : ''; ?>">
										<button type="button" class="button" id="mdr-upload-logo"><?php esc_html_e('Subir/Seleccionar logo', 'mapa-de-recursos'); ?></button>
										<small><?php esc_html_e('SVG o imagen. También puedes arrastrar al selector.', 'mapa-de-recursos'); ?></small>
										<div class="mdr-logo-preview">
											<?php if ($editing && $editing->logo_url) : ?>
												<img src="<?php echo esc_url($editing->logo_url); ?>" alt="" />
											<?php endif; ?>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="zona_id"><?php esc_html_e('Zona', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="zona_id" id="zona_id">
										<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
										<?php foreach ($zonas as $zona) : ?>
											<option value="<?php echo esc_attr((string) $zona->id); ?>" <?php selected($editing ? $editing->zona_id : '', $zona->id); ?>>
												<?php echo esc_html($zona->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="lat"><?php esc_html_e('Latitud', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="lat" id="lat" value="<?php echo $editing ? esc_attr((string) $editing->lat) : ''; ?>" placeholder="36.xxxxxx"></td>
							</tr>
							<tr>
								<th><label for="lng"><?php esc_html_e('Longitud', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="lng" id="lng" value="<?php echo $editing ? esc_attr((string) $editing->lng) : ''; ?>" placeholder="-4.xxxxxx"></td>
							</tr>
							<tr>
								<th><label for="direccion_linea1"><?php esc_html_e('Dirección', 'mapa-de-recursos'); ?></label></th>
								<td>
									<input type="text" name="direccion_linea1" id="direccion_linea1" class="regular-text" value="<?php echo $editing ? esc_attr($editing->direccion_linea1) : ''; ?>" autocomplete="off">
									<div id="mdr-addr-suggestions" class="mdr-addr-suggestions" aria-live="polite"></div>
								</td>
							</tr>
							<tr>
								<th><label for="cp"><?php esc_html_e('CP', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="cp" id="cp" value="<?php echo $editing ? esc_attr($editing->cp) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="ciudad"><?php esc_html_e('Ciudad', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="ciudad" id="ciudad" value="<?php echo $editing ? esc_attr($editing->ciudad) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="provincia"><?php esc_html_e('Provincia', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="provincia" id="provincia" value="<?php echo $editing ? esc_attr($editing->provincia) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="pais"><?php esc_html_e('País', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="pais" id="pais" value="<?php echo $editing ? esc_attr($editing->pais) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label><?php esc_html_e('Geolocalizar', 'mapa-de-recursos'); ?></label></th>
								<td>
									<button type="button" class="button mdr-geocode-btn" data-prefix=""><?php esc_html_e('Obtener lat/lng desde dirección', 'mapa-de-recursos'); ?></button>
									<small><?php esc_html_e('Usa la dirección, CP, ciudad, provincia y país para buscar.', 'mapa-de-recursos'); ?></small>
								</td>
							</tr>
							<tr>
								<th><label><?php esc_html_e('Mapa', 'mapa-de-recursos'); ?></label></th>
								<td>
									<div id="mdr-entity-map" style="height:260px;"></div>
									<small><?php esc_html_e('Arrastra el marcador para ajustar latitud y longitud.', 'mapa-de-recursos'); ?></small>
								</td>
							</tr>
						</table>
						<?php submit_button($editing ? __('Actualizar', 'mapa-de-recursos') : __('Crear', 'mapa-de-recursos')); ?>
					</form>
				</div>
				<div class="mdr-admin-col">
					<h2><?php esc_html_e('Listado', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_entities_bulk_delete', 'mdr_entities_bulk_nonce'); ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><input type="checkbox" class="mdr-select-all" data-target="mdr_entity_ids[]"></th>
									<th>
										<?php esc_html_e('ID', 'mapa-de-recursos'); ?>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'id_asc'])); ?>">↑</a>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'id_desc'])); ?>">↓</a>
									</th>
									<th>
										<?php esc_html_e('Nombre', 'mapa-de-recursos'); ?>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'name_asc'])); ?>">↑</a>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'name_desc'])); ?>">↓</a>
									</th>
									<th>
										<?php esc_html_e('Zona', 'mapa-de-recursos'); ?>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'zona_asc'])); ?>">↑</a>
										<a href="<?php echo esc_url(add_query_arg(['order' => 'zona_desc'])); ?>">↓</a>
									</th>
									<th><?php esc_html_e('Lat', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Lng', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Acciones', 'mapa-de-recursos'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if (! empty($entities)) : ?>
									<?php foreach ($entities as $ent) : ?>
										<tr>
											<td><input type="checkbox" name="mdr_entity_ids[]" value="<?php echo esc_attr((string) $ent->id); ?>"></td>
											<td><?php echo esc_html((string) $ent->id); ?></td>
											<td><?php echo esc_html($ent->nombre); ?></td>
											<td><?php echo esc_html($ent->zona_nombre ?: ''); ?></td>
											<td><?php echo esc_html($ent->lat); ?></td>
											<td><?php echo esc_html($ent->lng); ?></td>
											<td>
												<a class="button button-primary button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mdr_entidades', 'action' => 'edit', 'id' => $ent->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mapa-de-recursos'); ?></a>
												<a class="button button-secondary button-small is-danger" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'mdr_entidades', 'action' => 'delete', 'id' => $ent->id], admin_url('admin.php')), 'mdr_delete_entidad')); ?>" onclick="return confirm('<?php esc_attr_e('¿Eliminar esta entidad y sus recursos?', 'mapa-de-recursos'); ?>');"><?php esc_html_e('Eliminar', 'mapa-de-recursos'); ?></a>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr><td colspan="7"><?php esc_html_e('Sin entidades todavía.', 'mapa-de-recursos'); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>
						<?php submit_button(__('Eliminar seleccionadas', 'mapa-de-recursos'), 'delete'); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_entity(int $id) {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	private function get_entities(string $order_param = 'id_desc'): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";
		$zonas = "{$wpdb->prefix}mdr_zonas";
		$order = 'e.id DESC';
		if ($order_param === 'id_asc') {
			$order = 'e.id ASC';
		} elseif ($order_param === 'name_asc') {
			$order = 'e.nombre ASC';
		} elseif ($order_param === 'name_desc') {
			$order = 'e.nombre DESC';
		} elseif ($order_param === 'zona_asc') {
			$order = 'z.nombre ASC';
		} elseif ($order_param === 'zona_desc') {
			$order = 'z.nombre DESC';
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, z.nombre as zona_nombre FROM {$table} e
				LEFT JOIN {$zonas} z ON z.id = e.zona_id
				ORDER BY {$order}
				LIMIT %d",
				200
			)
		);
	}

	private function get_zonas(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function is_valid_phone(string $phone): bool {
		$clean = trim($phone);
		return (bool) preg_match('/^[0-9+\-\s()]{6,}$/', $clean);
	}

	private function delete_entity(int $id, bool $suppress_redirect = false): void {
		global $wpdb;
		$entities_table = "{$wpdb->prefix}mdr_entidades";
		$recursos_table = "{$wpdb->prefix}mdr_recursos";

		$wpdb->delete($recursos_table, ['entidad_id' => $id], ['%d']);
		$wpdb->delete($entities_table, ['id' => $id], ['%d']);

		Cache::flush_all();
		$this->logger->log('delete_entidad', 'entidad', ['id' => $id], 'entidad');

		if (! $suppress_redirect && ! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_entidades', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}

	private function bulk_delete(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		if (empty($_POST['mdr_entities_bulk_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_entities_bulk_nonce'])), 'mdr_entities_bulk_delete')) {
			return;
		}
		if (empty($_POST['mdr_entity_ids']) || ! is_array($_POST['mdr_entity_ids'])) {
			return;
		}
		foreach ($_POST['mdr_entity_ids'] as $id) {
			$this->delete_entity(absint($id), true);
		}
		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_entidades', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}
}
