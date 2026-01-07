<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Zonas {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}
		if (isset($_POST['mdr_zona_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_zona_nonce'])), 'mdr_save_zona')) {
			$this->save();
		}
		if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mdr_delete_zona')) {
			$this->delete(absint($_GET['id']));
		}
	}

	private function save(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";

		$id     = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$nombre = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
		$slug   = sanitize_title($nombre ?: uniqid('zona', true));

		if ('' === $nombre) {
			add_settings_error('mdr_zonas', 'nombre_required', __('El nombre es obligatorio.', 'mapa-de-recursos'), 'error');
			return;
		}

		$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s AND id != %d LIMIT 1", $nombre, $id));
		if ($exists) {
			add_settings_error('mdr_zonas', 'nombre_unique', __('Ya existe una zona con ese nombre.', 'mapa-de-recursos'), 'error');
			return;
		}

		$data = [
			'nombre' => $nombre,
			'slug'   => $slug,
		];

		if ($id) {
			$wpdb->update($table, $data, ['id' => $id], ['%s','%s'], ['%d']);
			$this->logger->log('update_zona', 'zona', ['id' => $id, 'nombre' => $nombre], 'zona');
		} else {
			$wpdb->insert($table, $data, ['%s','%s']);
			$id = (int) $wpdb->insert_id;
			$this->logger->log('create_zona', 'zona', ['id' => $id, 'nombre' => $nombre], 'zona');
		}

		Cache::flush_all();

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_zonas', 'updated' => 'true'], admin_url('admin.php')));
			exit;
		}

		add_settings_error('mdr_zonas', 'saved', __('Zona guardada.', 'mapa-de-recursos'), 'updated');
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_zonas');

		$editing = null;
		if (! empty($_GET['action']) && 'edit' === $_GET['action'] && ! empty($_GET['id'])) {
			$editing = $this->get(absint($_GET['id']));
		}

		$list = $this->get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Zonas', 'mapa-de-recursos'); ?></h1>
			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Zona guardada.', 'mapa-de-recursos'); ?></p></div>
			<?php endif; ?>
			<div class="mdr-admin-grid">
				<div class="mdr-admin-col">
					<h2><?php echo $editing ? esc_html__('Editar zona', 'mapa-de-recursos') : esc_html__('Nueva zona', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_save_zona', 'mdr_zona_nonce'); ?>
						<input type="hidden" name="id" value="<?php echo $editing ? esc_attr((string) $editing->id) : ''; ?>" />
						<table class="form-table" role="presentation">
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
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('ID', 'mapa-de-recursos'); ?></th>
								<th><?php esc_html_e('Nombre', 'mapa-de-recursos'); ?></th>
								<th><?php esc_html_e('Acciones', 'mapa-de-recursos'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ($list) : foreach ($list as $item) : ?>
								<tr>
									<td><?php echo esc_html((string) $item->id); ?></td>
									<td><?php echo esc_html($item->nombre); ?></td>
									<td>
										<a href="<?php echo esc_url(add_query_arg(['page' => 'mdr_zonas', 'action' => 'edit', 'id' => $item->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mapa-de-recursos'); ?></a>
										|
										<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'mdr_zonas', 'action' => 'delete', 'id' => $item->id], admin_url('admin.php')), 'mdr_delete_zona')); ?>" onclick="return confirm('<?php esc_attr_e('¿Eliminar esta zona?', 'mapa-de-recursos'); ?>');"><?php esc_html_e('Eliminar', 'mapa-de-recursos'); ?></a>
									</td>
								</tr>
							<?php endforeach; else : ?>
								<tr><td colspan="3"><?php esc_html_e('Sin zonas todavía.', 'mapa-de-recursos'); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_all(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";
		return (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY nombre ASC");
	}

	private function get(int $id) {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	private function delete(int $id): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";
		$wpdb->delete($table, ['id' => $id], ['%d']);
		Cache::flush_all();
		$this->logger->log('delete_zona', 'zona', ['id' => $id], 'zona');

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_zonas', 'deleted' => 'true'], admin_url('admin.php')));
			exit;
		}
	}
}
