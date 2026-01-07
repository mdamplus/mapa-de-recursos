<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class LogsPage {
	public function render(): void {
		if (! current_user_can('mdr_view_logs')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		global $wpdb;
		$table = "{$wpdb->prefix}mdr_logs";

		$logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200");
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Logs', 'mapa-de-recursos'); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Fecha', 'mapa-de-recursos'); ?></th>
						<th><?php esc_html_e('Usuario', 'mapa-de-recursos'); ?></th>
						<th><?php esc_html_e('Acción', 'mapa-de-recursos'); ?></th>
						<th><?php esc_html_e('Objeto', 'mapa-de-recursos'); ?></th>
						<th><?php esc_html_e('Detalles', 'mapa-de-recursos'); ?></th>
						<th><?php esc_html_e('IP', 'mapa-de-recursos'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ($logs) : foreach ($logs as $log) : ?>
						<tr>
							<td><?php echo esc_html($log->created_at); ?></td>
							<td>
								<?php
								if ($log->user_name) {
									echo esc_html($log->user_name);
									echo $log->user_id ? ' (' . esc_html((string) $log->user_id) . ')' : '';
								} elseif ($log->user_id) {
									echo esc_html((string) $log->user_id);
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html($log->accion); ?></td>
							<td><?php echo esc_html($log->objeto); ?></td>
							<td><code><?php echo esc_html($log->detalles); ?></code></td>
							<td><?php echo esc_html($log->ip); ?></td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="6"><?php esc_html_e('Sin logs.', 'mapa-de-recursos'); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
