<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Servicios {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}

		if (isset($_POST['mdr_servicio_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_servicio_nonce'])), 'mdr_save_servicio')) {
			$this->save_servicio();
		}
	}

	private function save_servicio(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";

		$id           = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$nombre       = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
		$slug         = sanitize_title($nombre ?: uniqid('servicio', true));
		$icono_media  = isset($_POST['icono_media_id']) ? absint($_POST['icono_media_id']) : null;
		$icono_svg    = isset($_POST['icono_svg']) ? wp_kses_post(wp_unslash($_POST['icono_svg'])) : '';
		$icono_clase  = isset($_POST['icono_clase']) ? sanitize_text_field(wp_unslash($_POST['icono_clase'])) : '';
		$marker_style = isset($_POST['marker_style']) ? wp_kses_post(wp_unslash($_POST['marker_style'])) : '';

		if ('' === $nombre) {
			add_settings_error('mdr_servicios', 'nombre_required', __('El nombre es obligatorio.', 'mapa-de-recursos'), 'error');
			return;
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s AND id != %d LIMIT 1", $nombre, $id)
		);
		if ($existing_id) {
			add_settings_error('mdr_servicios', 'nombre_unique', __('Ya existe un servicio con ese nombre.', 'mapa-de-recursos'), 'error');
			return;
		}

		$data = [
			'nombre'         => $nombre,
			'slug'           => $slug,
			'icono_media_id' => $icono_media ?: null,
			'icono_svg'      => $icono_svg,
			'icono_clase'    => $icono_clase,
			'marker_style'   => $marker_style,
		];

		$format = ['%s','%s','%d','%s','%s','%s'];

		if ($id > 0) {
			$wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
			$this->logger->log('update_servicio', 'servicio', ['id' => $id, 'nombre' => $nombre], 'servicio');
		} else {
			$wpdb->insert($table, $data, $format);
			$id = (int) $wpdb->insert_id;
			$this->logger->log('create_servicio', 'servicio', ['id' => $id, 'nombre' => $nombre], 'servicio');
		}

		Cache::flush_all();

		if (! headers_sent()) {
			wp_safe_redirect(add_query_arg(['page' => 'mdr_servicios', 'updated' => 'true'], admin_url('admin.php')));
			exit;
		}

		add_settings_error('mdr_servicios', 'saved', __('Servicio guardado.', 'mapa-de-recursos'), 'updated');
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_servicios');

		$editing = null;
		if (! empty($_GET['action']) && 'edit' === $_GET['action'] && ! empty($_GET['id'])) {
			$editing = $this->get_servicio(absint($_GET['id']));
		}

		$servicios = $this->get_servicios();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Servicios / Iconos', 'mapa-de-recursos'); ?></h1>
			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Servicio guardado.', 'mapa-de-recursos'); ?></p></div>
			<?php endif; ?>
			<div class="mdr-admin-grid">
				<div class="mdr-admin-col">
					<h2><?php echo $editing ? esc_html__('Editar servicio', 'mapa-de-recursos') : esc_html__('Nuevo servicio', 'mapa-de-recursos'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('mdr_save_servicio', 'mdr_servicio_nonce'); ?>
						<input type="hidden" name="id" value="<?php echo $editing ? esc_attr((string) $editing->id) : ''; ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="nombre"><?php esc_html_e('Nombre', 'mapa-de-recursos'); ?></label></th>
								<td><input type="text" required name="nombre" id="nombre" class="regular-text" value="<?php echo $editing ? esc_attr($editing->nombre) : ''; ?>"></td>
							</tr>
							<tr>
								<th><label for="icono_media_id"><?php esc_html_e('Icono', 'mapa-de-recursos'); ?></label></th>
								<td>
									<div class="mdr-logo-field">
										<input type="hidden" name="icono_media_id" id="icono_media_id" value="<?php echo $editing ? esc_attr((string) $editing->icono_media_id) : ''; ?>">
										<button type="button" class="button" id="mdr-upload-icono"><?php esc_html_e('Subir/Seleccionar icono', 'mapa-de-recursos'); ?></button>
										<small><?php esc_html_e('SVG o imagen. También puedes arrastrar al selector.', 'mapa-de-recursos'); ?></small>
										<div class="mdr-icono-preview">
											<?php if ($editing && $editing->icono_media_id) : ?>
												<?php $url = wp_get_attachment_url((int) $editing->icono_media_id); ?>
												<?php if ($url) : ?>
													<img src="<?php echo esc_url($url); ?>" alt="" />
												<?php endif; ?>
											<?php endif; ?>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="icono_svg"><?php esc_html_e('Icono SVG (inline)', 'mapa-de-recursos'); ?></label></th>
								<td><textarea name="icono_svg" id="icono_svg" rows="3" class="large-text" placeholder="<?php esc_attr_e('<svg>...</svg>', 'mapa-de-recursos'); ?>"><?php echo $editing ? esc_textarea($editing->icono_svg) : ''; ?></textarea></td>
							</tr>
							<tr>
								<th><label for="icono_clase"><?php esc_html_e('Clase Font Awesome', 'mapa-de-recursos'); ?></label></th>
								<td>
									<input type="text" name="icono_clase" id="icono_clase" class="regular-text" placeholder="fa-solid fa-briefcase" value="<?php echo $editing ? esc_attr($editing->icono_clase) : ''; ?>">
									<div class="mdr-fa-preview" aria-live="polite"></div>
									<p class="description"><?php esc_html_e('Usa el kit configurado (clasic solid/regular/brands). Ej: fa-solid fa-hands-helping', 'mapa-de-recursos'); ?></p>
									<div class="mdr-fa-picker-actions">
										<button type="button" class="button" id="mdr-fa-open"><?php esc_html_e('Elegir icono', 'mapa-de-recursos'); ?></button>
										<div class="mdr-fa-suggestions">
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-briefcase" title="Briefcase"><i class="fa-solid fa-briefcase"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-hands-helping" title="Hands Helping"><i class="fa-solid fa-hands-helping"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-graduation-cap" title="Graduation Cap"><i class="fa-solid fa-graduation-cap"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-users" title="Users"><i class="fa-solid fa-users"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-leaf" title="Leaf"><i class="fa-solid fa-leaf"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-house" title="House"><i class="fa-solid fa-house"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-heart" title="Heart"><i class="fa-solid fa-heart"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-stethoscope" title="Stethoscope"><i class="fa-solid fa-stethoscope"></i></span>
											<span class="mdr-fa-suggestion" data-class="fa-solid fa-child-reaching" title="Child"><i class="fa-solid fa-child-reaching"></i></span>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="marker_style"><?php esc_html_e('Estilo marcador (JSON)', 'mapa-de-recursos'); ?></label></th>
								<td><textarea name="marker_style" id="marker_style" rows="2" class="large-text" placeholder='{"color":"#ff0000"}'><?php echo $editing ? esc_textarea($editing->marker_style) : ''; ?></textarea></td>
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
							<?php if (! empty($servicios)) : ?>
								<?php foreach ($servicios as $serv) : ?>
									<tr>
										<td><?php echo esc_html((string) $serv->id); ?></td>
										<td><?php echo esc_html($serv->nombre); ?></td>
										<td class="mdr-actions">
											<a class="button button-primary button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mdr_servicios', 'action' => 'edit', 'id' => $serv->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mapa-de-recursos'); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr><td colspan="3"><?php esc_html_e('Sin servicios todavía.', 'mapa-de-recursos'); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_servicio(int $id) {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	private function get_servicios(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";
		return (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY nombre ASC");
	}
}
