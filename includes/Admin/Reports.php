<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Pdf\PdfExporter;
use MapaDeRecursos\Logs\Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Reports {
	private Logger $logger;
	private PdfExporter $pdf;

	public function __construct(Logger $logger, PdfExporter $pdf) {
		$this->logger = $logger;
		$this->pdf    = $pdf;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_export_pdf')) {
			return;
		}

		if (isset($_POST['mdr_pdf_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_pdf_nonce'])), 'mdr_generate_pdf')) {
			$this->process_pdf();
		}
	}

	private function process_pdf(): void {
		$from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
		$to   = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
		$last24 = isset($_POST['last24']) ? true : false;

		$result = $this->pdf->generate($from ?: null, $to ?: null, $last24);

		if (is_wp_error($result)) {
			add_settings_error('mdr_pdf', 'pdf_error', $result->get_error_message(), 'error');
			return;
		}

		add_settings_error(
			'mdr_pdf',
			'pdf_success',
			sprintf(__('PDF generado: <a href="%s" target="_blank">descargar</a>', 'mapa-de-recursos'), esc_url($result['url'])),
			'success'
		);
	}

	public function render(): void {
		if (! current_user_can('mdr_export_pdf')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_pdf');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Informes / PDF', 'mapa-de-recursos'); ?></h1>
			<form method="post">
				<?php wp_nonce_field('mdr_generate_pdf', 'mdr_pdf_nonce'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Rango de fechas (updated_at)', 'mapa-de-recursos'); ?></th>
						<td>
							<label><?php esc_html_e('Desde', 'mapa-de-recursos'); ?> <input type="date" name="date_from" /></label>
							<label><?php esc_html_e('Hasta', 'mapa-de-recursos'); ?> <input type="date" name="date_to" /></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Últimas 24h', 'mapa-de-recursos'); ?></th>
						<td><label><input type="checkbox" name="last24" value="1"> <?php esc_html_e('Ignora fechas y usa últimas 24 horas', 'mapa-de-recursos'); ?></label></td>
					</tr>
				</table>
				<?php submit_button(__('Generar PDF', 'mapa-de-recursos')); ?>
			</form>
		</div>
		<?php
	}
}
