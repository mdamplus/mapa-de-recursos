<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Subcategorias {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}
		if (isset($_POST['mdr_subcat_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_subcat_nonce'])), 'mdr_save_subcat')) {
			$this->save();
		}
		if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && 'delete' === $_GET['action'] && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mdr_delete_subcat')) {
			$this->delete(absint($_GET['id']));
		}
		if (current_user_can('manage_options') && isset($_POST['mdr_subcats_bulk_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_subcats_bulk_nonce'])), 'mdr_subcats_bulk_delete')) {
			$this->bulk_delete();
		}
	}

	private function save(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";

		$id        = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$ambito_id = isset($_POST['ambito_id']) ? absint($_POST['ambito_id']) : 0;
		$nombre    = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
		$slug      = sanitize_title($nombre ?: uniqid('subcat', true));

		if (! $ambito_id || '' === $nombre) {
			add_settings_error('mdr_subcat', 'fields_required', __('Ámbito y nombre son obligatorios.', 'mapa-de-recursos'), 'error');
			return;
		}

		$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE ambito_id = %d AND nombre = %s AND id != %d LIMIT 1", $ambito_id, $nombre, $id));
		if ($exists) {
			add_settings_error('mdr_subcat', 'nombre_unique', __('Ya existe una subcategoría con ese nombre en el ámbito.', 'mapa-de-recursos'), 'error');
			return;
		}

		$data = [
			'ambito_id' => $ambito_id,
			'nombre'    => $nombre,
			'slug'      => $slug,
		];

		if ($id) {
			$wpdb->update($table, $data, ['id' => $id], ['%d','%s','%s'], ['%d']);
			$this->logger->log('update_subcategoria', 'subcategoria', ['id' => $id, 'nombre' => $nombre], 'subcategoria');
		} else {
			$wpdb->insert($table, $data, ['%d','%s','%s']);
			$id = (int) $wpdb->insert_id;
			$this->logger->log('create_subcategoria', 'subcategoria', ['id' => $id, 'nombre' => $nombre], 'subcategoria');
		}

		Cache::flush_all();

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_subcategorias', 'updated' => 'true'], admin_url('admin.php')));
			exit;
		}

		add_settings_error('mdr_subcat', 'saved', __('Subcategoría guardada.', 'mapa-de-recursos'), 'updated');
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_subcat');

		$editing = null;
		if (! empty($_GET['action']) && 'edit' === $_GET['action'] && ! empty($_GET['id'])) {
			$editing = $this->get(absint($_GET['id']));
		}

		$list = $this->get_all();
		$ambitos = $this->get_ambitos();
		$can_bulk = current_user_can('manage_options');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Subcategorías', 'mapa-de-recursos'); ?></h1>
			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Subcategoría guardada.', 'mapa-de-recursos'); ?></p></div>
			<?php endif; ?>
			<div class="mdr-admin-grid">
				<div class="mdr-admin-col">
					<h2><?php echo $editing ? esc_html__('Editar subcategoría', 'mapa-de-recursos') : esc_html__('Nueva subcategoría', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_save_subcat', 'mdr_subcat_nonce'); ?>
						<input type="hidden" name="id" value="<?php echo $editing ? esc_attr((string) $editing->id) : ''; ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="ambito_id"><?php esc_html_e('Ámbito', 'mapa-de-recursos'); ?></label></th>
								<td>
									<select required name="ambito_id" id="ambito_id">
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
								<th><label for="nombre"><?php esc_html_e('Nombre', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" required name="nombre" id="nombre" class="regular-text" value="<?php echo $editing ? esc_attr($editing->nombre) : ''; ?>"></td>
							</tr>
						</table>
						<?php submit_button($editing ? __('Actualizar', 'mapa-de-recursos') : __('Crear', 'mapa-de-recursos')); ?>
					</form>
				</div>
				<div class="mdr-admin-col">
					<h2><?php esc_html_e('Listado', 'mapa-de-recursos'); ?></h2>
				<form method="post">
					<?php if ($can_bulk) : ?>
						<?php wp_nonce_field('mdr_subcats_bulk_delete', 'mdr_subcats_bulk_nonce'); ?>
					<?php endif; ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<?php if ($can_bulk) : ?>
									<th><input type="checkbox" class="mdr-select-all" data-target="mdr_subcat_ids[]"></th>
								<?php endif; ?>
								<th><?php esc_html_e('ID', 'mapa-de-recursos'); ?></th>
								<th><?php esc_html_e('Ámbito', 'mapa-de-recursos'); ?></th>
								<th><?php esc_html_e('Nombre', 'mapa-de-recursos'); ?></th>
								<th><?php esc_html_e('Acciones', 'mapa-de-recursos'); ?></th>
							</tr>
						</thead>
						<tbody>
					<?php if ($list) : foreach ($list as $item) : ?>
						<tr>
							<?php if ($can_bulk) : ?>
								<td><input type="checkbox" name="mdr_subcat_ids[]" value="<?php echo esc_attr((string) $item->id); ?>"></td>
							<?php endif; ?>
							<td><?php echo esc_html((string) $item->id); ?></td>
							<td><?php echo esc_html($item->ambito_nombre); ?></td>
							<td><?php echo esc_html($item->nombre); ?></td>
							<td class="mdr-actions">
								<a class="button button-primary button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mdr_subcategorias', 'action' => 'edit', 'id' => $item->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mapa-de-recursos'); ?></a>
								<a class="button button-secondary button-small is-danger" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'mdr_subcategorias', 'action' => 'delete', 'id' => $item->id], admin_url('admin.php')), 'mdr_delete_subcat')); ?>" onclick="return confirm('<?php esc_attr_e('¿Eliminar esta subcategoría?', 'mapa-de-recursos'); ?>');"><?php esc_html_e('Eliminar', 'mapa-de-recursos'); ?></a>
							</td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="<?php echo $can_bulk ? 5 : 4; ?>"><?php esc_html_e('Sin subcategorías todavía.', 'mapa-de-recursos'); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<?php if ($can_bulk) : ?>
					<button type="submit" class="button button-secondary is-danger"><?php esc_html_e('Eliminar seleccionadas', 'mapa-de-recursos'); ?></button>
				<?php endif; ?>
				</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_all(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";
		$amb   = "{$wpdb->prefix}mdr_ambitos";
		return (array) $wpdb->get_results("
			SELECT s.*, a.nombre as ambito_nombre
			FROM {$table} s
			LEFT JOIN {$amb} a ON a.id = s.ambito_id
			ORDER BY a.nombre ASC, s.nombre ASC
		");
	}

	private function get(int $id) {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	private function get_ambitos(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_ambitos";
		return (array) $wpdb->get_results("SELECT id, nombre FROM {$table} ORDER BY nombre ASC");
	}

	private function delete(int $id, bool $suppress_redirect = false): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";
		$wpdb->delete($table, ['id' => $id], ['%d']);
		Cache::flush_all();
		$this->logger->log('delete_subcategoria', 'subcategoria', ['id' => $id], 'subcategoria');

		if (! $suppress_redirect && ! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_subcategorias', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}

	private function bulk_delete(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		if (empty($_POST['mdr_subcat_ids']) || ! is_array($_POST['mdr_subcat_ids'])) {
			return;
		}
		foreach ($_POST['mdr_subcat_ids'] as $id) {
			$this->delete(absint($id), true);
		}
		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_subcategorias', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}
}
