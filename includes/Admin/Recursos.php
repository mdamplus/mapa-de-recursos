<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Recursos {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}

		if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && 'delete' === $_GET['action'] && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mdr_delete_recurso')) {
			$this->delete_recurso(absint($_GET['id']));
		}
		if (isset($_POST['mdr_recurso_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_recurso_nonce'])), 'mdr_save_recurso')) {
			$this->save_recurso();
		}
		if (current_user_can('manage_options') && isset($_POST['mdr_recursos_bulk_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_recursos_bulk_nonce'])), 'mdr_recursos_bulk_delete')) {
			$this->bulk_delete();
		}
	}

	private function save_recurso(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_recursos";

		$id            = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$entidad_id    = isset($_POST['entidad_id']) ? absint($_POST['entidad_id']) : 0;
		$recurso_prog  = isset($_POST['recurso_programa']) ? sanitize_text_field(wp_unslash($_POST['recurso_programa'])) : '';
		$descripcion   = isset($_POST['descripcion']) ? wp_kses_post(wp_unslash($_POST['descripcion'])) : '';
		$objetivo      = isset($_POST['objetivo']) ? sanitize_text_field(wp_unslash($_POST['objetivo'])) : '';
		$destinatarios = isset($_POST['destinatarios']) ? sanitize_text_field(wp_unslash($_POST['destinatarios'])) : '';
		$periodo       = isset($_POST['periodo_ejecucion']) ? sanitize_text_field(wp_unslash($_POST['periodo_ejecucion'])) : '';
		$periodo_inicio = isset($_POST['periodo_inicio']) ? sanitize_text_field(wp_unslash($_POST['periodo_inicio'])) : '';
		$periodo_fin    = isset($_POST['periodo_fin']) ? sanitize_text_field(wp_unslash($_POST['periodo_fin'])) : '';
		$ent_gestora   = isset($_POST['entidad_gestora']) ? sanitize_text_field(wp_unslash($_POST['entidad_gestora'])) : '';
		$ent_gestora_id = isset($_POST['entidad_gestora_id']) ? absint($_POST['entidad_gestora_id']) : null;
		$financiacion  = isset($_POST['financiacion']) ? sanitize_text_field(wp_unslash($_POST['financiacion'])) : '';
		$financiacion_id = isset($_POST['financiacion_id']) ? absint($_POST['financiacion_id']) : null;
		$contactos_data = $this->collect_contactos_from_request();
		if (isset($contactos_data['error'])) {
			add_settings_error('mdr_recursos', 'contacto_invalid', $contactos_data['error'], 'error');
			return;
		}
		$contactos = $contactos_data['items'];
		$servicio_id   = isset($_POST['servicio_id']) ? absint($_POST['servicio_id']) : null;
		$ambito_id     = isset($_POST['ambito_id']) ? absint($_POST['ambito_id']) : null;
		$subcat_id     = isset($_POST['subcategoria_id']) ? absint($_POST['subcategoria_id']) : null;
		$activo        = isset($_POST['activo']) ? 1 : 0;

		if (! $entidad_id || '' === $recurso_prog) {
			add_settings_error('mdr_recursos', 'fields_required', __('Entidad y nombre del recurso son obligatorios.', 'mapa-de-recursos'), 'error');
			return;
		}

		$data = [
			'entidad_id'       => $entidad_id,
			'ambito_id'        => $ambito_id ?: null,
			'subcategoria_id'  => $subcat_id ?: null,
			'recurso_programa' => $recurso_prog,
			'descripcion'      => $descripcion,
			'objetivo'         => $objetivo,
			'destinatarios'    => $destinatarios,
			'periodo_ejecucion'=> $periodo,
			'periodo_inicio'   => $periodo_inicio ?: null,
			'periodo_fin'      => $periodo_fin ?: null,
			'entidad_gestora'  => $ent_gestora,
			'entidad_gestora_id' => $ent_gestora_id ?: null,
			'financiacion'     => $financiacion,
			'financiacion_id'  => $financiacion_id ?: null,
			'contacto'         => $contactos ? wp_json_encode($contactos) : '',
			'servicio_id'      => $servicio_id ?: null,
			'activo'           => $activo,
		];

		$format = ['%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d'];

		if ($id > 0) {
			$wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
			$this->logger->log('update_recurso', 'recurso', ['id' => $id, 'recurso' => $recurso_prog], 'recurso');
		} else {
			$wpdb->insert($table, $data, $format);
			$id = (int) $wpdb->insert_id;
			$this->logger->log('create_recurso', 'recurso', ['id' => $id, 'recurso' => $recurso_prog], 'recurso');
		}

		Cache::flush_all();

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_recursos', 'updated' => 'true'], admin_url('admin.php')));
			exit;
		}

		add_settings_error('mdr_recursos', 'saved', __('Recurso guardado.', 'mapa-de-recursos'), 'updated');
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_recursos');

		$editing = null;
		if (! empty($_GET['action']) && 'edit' === $_GET['action'] && ! empty($_GET['id'])) {
			$editing = $this->get_recurso(absint($_GET['id']));
		}

		$entidades = $this->get_entidades();
		$ambitos = $this->get_ambitos();
		$subcategorias = $this->get_subcategorias();
		$servicios = $this->get_servicios();
		$gestoras = $this->get_entidades();
		$financiaciones = $this->get_financiaciones();
		$recursos = $this->get_recursos();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Recursos', 'mapa-de-recursos'); ?></h1>
			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Recurso guardado.', 'mapa-de-recursos'); ?></p></div>
			<?php endif; ?>
			<div class="mdr-admin-grid">
				<div class="mdr-admin-col">
					<h2><?php echo $editing ? esc_html__('Editar recurso', 'mapa-de-recursos') : esc_html__('Nuevo recurso', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_save_recurso', 'mdr_recurso_nonce'); ?>
						<input type="hidden" name="id" value="<?php echo $editing ? esc_attr((string) $editing->id) : ''; ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="entidad_id"><?php esc_html_e('Entidad', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select required name="entidad_id" id="entidad_id">
										<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
										<?php foreach ($entidades as $ent) : ?>
											<option value="<?php echo esc_attr((string) $ent->id); ?>" <?php selected($editing ? $editing->entidad_id : '', $ent->id); ?>>
												<?php echo esc_html($ent->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="recurso_programa"><?php esc_html_e('Recurso/Programa', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" required name="recurso_programa" id="recurso_programa" class="regular-text" value="<?php echo $editing ? esc_attr($editing->recurso_programa) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="descripcion"><?php esc_html_e('Descripción', 'mapa-de-recursos'); ?></label></th>
								<td><textarea name="descripcion" id="descripcion" rows="3" class="large-text"><?php echo $editing ? esc_textarea($editing->descripcion) : ''; ?></textarea></td>
							</tr>
							<tr>
								<th><label for="objetivo"><?php esc_html_e('Objetivo', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="objetivo" id="objetivo" class="regular-text" value="<?php echo $editing ? esc_attr($editing->objetivo) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="destinatarios"><?php esc_html_e('Destinatarios', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" name="destinatarios" id="destinatarios" class="regular-text" value="<?php echo $editing ? esc_attr($editing->destinatarios) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="periodo_ejecucion"><?php esc_html_e('Periodo de ejecución', 'mapa-de-recursos'); ?></label></th>
								<td>
									<input type="text" name="periodo_ejecucion" id="periodo_ejecucion" class="regular-text" value="<?php echo $editing ? esc_attr($editing->periodo_ejecucion) : ''; ?>" placeholder="<?php esc_attr_e('Texto opcional', 'mapa-de-recursos'); ?>">
									<div style="margin-top:6px;">
										<input type="date" name="periodo_inicio" value="<?php echo $editing ? esc_attr((string) $editing->periodo_inicio) : ''; ?>"> -
										<input type="date" name="periodo_fin" value="<?php echo $editing ? esc_attr((string) $editing->periodo_fin) : ''; ?>">
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="entidad_gestora"><?php esc_html_e('Entidad gestora', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="entidad_gestora_id" id="entidad_gestora_id">
										<option value=""><?php esc_html_e('Seleccionar entidad gestora', 'mapa-de-recursos'); ?></option>
										<?php foreach ($gestoras as $ent) : ?>
											<option value="<?php echo esc_attr((string) $ent->id); ?>" <?php selected($editing ? $editing->entidad_gestora_id : '', $ent->id); ?>>
												<?php echo esc_html($ent->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<div style="margin-top:6px;">
										<input type="text" name="entidad_gestora" id="entidad_gestora" class="regular-text" value="<?php echo $editing ? esc_attr($editing->entidad_gestora) : ''; ?>" placeholder="<?php esc_attr_e('Texto opcional', 'mapa-de-recursos'); ?>">
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="financiacion"><?php esc_html_e('Financiación', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="financiacion_id" id="financiacion_id">
										<option value=""><?php esc_html_e('Seleccionar financiador', 'mapa-de-recursos'); ?></option>
										<?php foreach ($financiaciones as $fin) : ?>
											<option value="<?php echo esc_attr((string) $fin->id); ?>" <?php selected($editing ? $editing->financiacion_id : '', $fin->id); ?>>
												<?php echo esc_html($fin->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<div style="margin-top:6px;">
										<input type="text" name="financiacion" id="financiacion" class="regular-text" value="<?php echo $editing ? esc_attr($editing->financiacion) : ''; ?>" placeholder="<?php esc_attr_e('Texto opcional', 'mapa-de-recursos'); ?>">
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="contacto"><?php esc_html_e('Contacto', 'mapa-de-recursos'); ?></label></th>
								<td>
									<div id="mdr-contactos-wrap">
										<?php
										$contactos = $this->decode_contactos($editing->contacto ?? '');
										if (empty($contactos)) {
											$contactos = [['nombre' => '', 'email' => '', 'telefono' => '']];
										}
										foreach ($contactos as $index => $c) :
										?>
											<div class="mdr-contacto-row">
												<input type="text" name="contacto_nombre[]" placeholder="<?php esc_attr_e('Nombre/Depto', 'mapa-de-recursos'); ?>" value="<?php echo esc_attr($c['nombre'] ?? ''); ?>">
												<input type="email" name="contacto_email[]" placeholder="<?php esc_attr_e('Email', 'mapa-de-recursos'); ?>" value="<?php echo esc_attr($c['email'] ?? ''); ?>">
												<input type="text" name="contacto_tel[]" placeholder="<?php esc_attr_e('Teléfono', 'mapa-de-recursos'); ?>" value="<?php echo esc_attr($c['telefono'] ?? ''); ?>">
												<button type="button" class="button mdr-contacto-remove"><?php esc_html_e('Quitar', 'mapa-de-recursos'); ?></button>
											</div>
										<?php endforeach; ?>
									</div>
									<button type="button" class="button" id="mdr-contacto-add"><?php esc_html_e('Añadir contacto', 'mapa-de-recursos'); ?></button>
									<small><?php esc_html_e('Todos los campos son opcionales.', 'mapa-de-recursos'); ?></small>
								</td>
							</tr>
							<tr>
								<th><label for="servicio_id"><?php esc_html_e('Servicio', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="servicio_id" id="servicio_id">
										<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
										<?php foreach ($servicios as $serv) : ?>
											<option value="<?php echo esc_attr((string) $serv->id); ?>" <?php selected($editing ? $editing->servicio_id : '', $serv->id); ?>>
												<?php echo esc_html($serv->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="ambito_id"><?php esc_html_e('Ámbito', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="ambito_id" id="ambito_id">
										<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
										<?php foreach ($ambitos as $amb) : ?>
											<option value="<?php echo esc_attr((string) $amb->id); ?>" <?php selected($editing ? $editing->ambito_id : '', $amb->id); ?>>
												<?php echo esc_html($amb->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="subcategoria_id"><?php esc_html_e('Subcategoría', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select name="subcategoria_id" id="subcategoria_id">
										<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
										<?php foreach ($subcategorias as $sub) : ?>
											<option value="<?php echo esc_attr((string) $sub->id); ?>" <?php selected($editing ? $editing->subcategoria_id : '', $sub->id); ?>>
												<?php echo esc_html($sub->nombre); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="activo"><?php esc_html_e('Activo', 'mapa-de-recursos'); ?></label></th>
								<td><label><input type="checkbox" name="activo" id="activo" value="1" <?php checked($editing ? (bool) $editing->activo : true, true); ?>> <?php esc_html_e('Visible en el mapa', 'mapa-de-recursos'); ?></label></td>
							</tr>
						</table>
						<?php submit_button($editing ? __('Actualizar', 'mapa-de-recursos') : __('Crear', 'mapa-de-recursos')); ?>
					</form>
				</div>
				<div class="mdr-admin-col">
					<h2><?php esc_html_e('Listado', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_recursos_bulk_delete', 'mdr_recursos_bulk_nonce'); ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><input type="checkbox" class="mdr-select-all" data-target="mdr_recurso_ids[]"></th>
									<th><?php esc_html_e('ID', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Recurso', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Entidad', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Activo', 'mapa-de-recursos'); ?></th>
									<th><?php esc_html_e('Acciones', 'mapa-de-recursos'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if (! empty($recursos)) : ?>
									<?php foreach ($recursos as $rec) : ?>
										<tr>
											<td><input type="checkbox" name="mdr_recurso_ids[]" value="<?php echo esc_attr((string) $rec->id); ?>"></td>
											<td><?php echo esc_html((string) $rec->id); ?></td>
											<td><?php echo esc_html($rec->recurso_programa); ?></td>
											<td><?php echo esc_html($rec->entidad_nombre); ?></td>
											<td><?php echo $rec->activo ? '✓' : '—'; ?></td>
											<td>
												<a href="<?php echo esc_url(add_query_arg(['page' => 'mdr_recursos', 'action' => 'edit', 'id' => $rec->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mapa-de-recursos'); ?></a>
												|
												<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'mdr_recursos', 'action' => 'delete', 'id' => $rec->id], admin_url('admin.php')), 'mdr_delete_recurso')); ?>" onclick="return confirm('<?php esc_attr_e('¿Eliminar este recurso?', 'mapa-de-recursos'); ?>');"><?php esc_html_e('Eliminar', 'mapa-de-recursos'); ?></a>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr><td colspan="6"><?php esc_html_e('Sin recursos todavía.', 'mapa-de-recursos'); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>
						<button type="submit" class="button button-secondary"><?php esc_html_e('Eliminar seleccionados', 'mapa-de-recursos'); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_recurso(int $id) {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_recursos";
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	private function get_recursos(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_recursos";
		$ent   = "{$wpdb->prefix}mdr_entidades";
		return (array) $wpdb->get_results(
			"SELECT r.*, e.nombre as entidad_nombre
			FROM {$table} r
			LEFT JOIN {$ent} e ON e.id = r.entidad_id
			ORDER BY r.updated_at DESC
			LIMIT 200"
		);
	}

	private function get_entidades(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function get_ambitos(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_ambitos";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function get_subcategorias(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function get_servicios(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function get_financiaciones(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_financiaciones";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function bulk_delete(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		if (empty($_POST['mdr_recursos_bulk_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_recursos_bulk_nonce'])), 'mdr_recursos_bulk_delete')) {
			return;
		}
		if (empty($_POST['mdr_recurso_ids']) || ! is_array($_POST['mdr_recurso_ids'])) {
			return;
		}
		global $wpdb;
		foreach ($_POST['mdr_recurso_ids'] as $id) {
			$this->delete_recurso(absint($id), true);
		}
		Cache::flush_all();
		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_recursos', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}

	private function delete_recurso(int $id, bool $suppress_redirect = false): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_recursos";
		$wpdb->delete($table, ['id' => $id], ['%d']);
		Cache::flush_all();
		$this->logger->log('delete_recurso', 'recurso', ['id' => $id], 'recurso');

		if (! $suppress_redirect && ! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_recursos', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}

	private function decode_contactos(string $raw): array {
		if (empty($raw)) {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			return array_map(
				static function ($c) {
					return [
						'nombre'   => $c['nombre'] ?? '',
						'email'    => $c['email'] ?? '',
						'telefono' => $c['telefono'] ?? '',
					];
				},
				$decoded
			);
		}
		return [];
	}

	private function collect_contactos_from_request(): array {
		$names = isset($_POST['contacto_nombre']) && is_array($_POST['contacto_nombre']) ? array_map('sanitize_text_field', wp_unslash($_POST['contacto_nombre'])) : [];
		$emails = isset($_POST['contacto_email']) && is_array($_POST['contacto_email']) ? array_map('sanitize_text_field', wp_unslash($_POST['contacto_email'])) : [];
		$tels = isset($_POST['contacto_tel']) && is_array($_POST['contacto_tel']) ? array_map('sanitize_text_field', wp_unslash($_POST['contacto_tel'])) : [];

		$max = max(count($names), count($emails), count($tels));
		$result = [];
		for ($i = 0; $i < $max; $i++) {
			$nombre = $names[$i] ?? '';
			$email = $emails[$i] ?? '';
			$tel = $tels[$i] ?? '';
			if ($nombre === '' && $email === '' && $tel === '') {
				continue;
			}
			if ($email !== '' && ! is_email($email)) {
				return ['items' => [], 'error' => __('El email de contacto no es válido.', 'mapa-de-recursos')];
			}
			if ($tel !== '' && ! $this->is_valid_phone($tel)) {
				return ['items' => [], 'error' => __('El teléfono de contacto no es válido.', 'mapa-de-recursos')];
			}
			$result[] = [
				'nombre'   => $nombre,
				'email'    => $email,
				'telefono' => $tel,
			];
		}
		return ['items' => $result];
	}

	private function is_valid_phone(string $phone): bool {
		$clean = trim($phone);
		return (bool) preg_match('/^[0-9+\-\s()]{6,}$/', $clean);
	}
}
