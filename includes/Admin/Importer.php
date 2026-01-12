<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Logs\Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Importer {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	public function handle_actions(): void {
		if (! current_user_can('mdr_manage')) {
			return;
		}

		if (isset($_POST['mdr_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mdr_import_nonce'])), 'mdr_import_csv')) {
			if (! empty($_POST['mdr_csv_raw'])) {
				$this->process_import();
			}
		}
	}

	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		settings_errors('mdr_import');

		$preview_data = [];
		$preview_type = '';
		if (! empty($_FILES['mdr_csv_file']['tmp_name']) && isset($_POST['tipo'])) {
			$preview_type = sanitize_text_field(wp_unslash($_POST['tipo']));
			$preview_data = $this->parse_uploaded_file($_FILES['mdr_csv_file']);
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e('Importar', 'mapa-de-recursos'); ?></h1>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field('mdr_import_csv', 'mdr_import_nonce'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tipo"><?php esc_html_e('Qué importar', 'mapa-de-recursos'); ?></label></th>
						<td>
							<select name="tipo" id="tipo" required>
								<option value=""><?php esc_html_e('Seleccionar', 'mapa-de-recursos'); ?></option>
								<option value="financiaciones"><?php esc_html_e('Financiadores', 'mapa-de-recursos'); ?></option>
								<option value="servicios"><?php esc_html_e('Servicios', 'mapa-de-recursos'); ?></option>
								<option value="ambitos"><?php esc_html_e('Ámbitos', 'mapa-de-recursos'); ?></option>
								<option value="subcategorias"><?php esc_html_e('Subcategorías', 'mapa-de-recursos'); ?></option>
								<option value="zonas"><?php esc_html_e('Zonas', 'mapa-de-recursos'); ?></option>
								<option value="entidades"><?php esc_html_e('Entidades', 'mapa-de-recursos'); ?></option>
								<option value="recursos"><?php esc_html_e('Recursos', 'mapa-de-recursos'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="mdr_csv_file"><?php esc_html_e('Archivo CSV o XLSX', 'mapa-de-recursos'); ?></label></th>
						<td><input type="file" name="mdr_csv_file" id="mdr_csv_file" accept=".csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></td>
					</tr>
				</table>
				<?php submit_button(__('Previsualizar', 'mapa-de-recursos')); ?>
			</form>

			<?php if ($preview_data) : ?>
				<hr />
				<h2><?php esc_html_e('Previsualización (editable)', 'mapa-de-recursos'); ?></h2>
				<p><?php esc_html_e('Edita el contenido y pulsa Importar.', 'mapa-de-recursos'); ?></p>
				<form method="post">
					<?php wp_nonce_field('mdr_import_csv', 'mdr_import_nonce'); ?>
					<input type="hidden" name="tipo" value="<?php echo esc_attr($preview_type); ?>">
					<textarea name="mdr_csv_raw" rows="12" class="large-text code"><?php echo esc_textarea($this->csv_to_string($preview_data)); ?></textarea>
					<?php submit_button(__('Importar', 'mapa-de-recursos')); ?>
				</form>
				<h3><?php esc_html_e('Vista tabla', 'mapa-de-recursos'); ?></h3>
				<?php
				$max_cols = 0;
				foreach ($preview_data as $r) {
					$max_cols = max($max_cols, count($r));
				}
				?>
				<div style="overflow:auto; max-height:480px; border:1px solid #e5e5e5; border-radius:4px;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>#</th>
								<?php for ($c = 1; $c <= $max_cols; $c++) : ?>
									<th><?php echo esc_html($c); ?></th>
								<?php endfor; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($preview_data as $idx => $row) : ?>
								<tr>
									<th><?php echo esc_html((string) ($idx + 1)); ?></th>
									<?php
									for ($c = 0; $c < $max_cols; $c++) {
										$cell = $row[$c] ?? '';
										echo '<td>' . esc_html($cell) . '</td>';
									}
									?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function parse_csv(string $raw): array {
		$rows = [];
		$lines = preg_split("/\r\n|\n|\r/", trim($raw));
		foreach ($lines as $line) {
			if ($line === '') {
				continue;
			}
			$rows[] = str_getcsv($line, ';');
			if (count(end($rows)) === 1) {
				$rows[array_key_last($rows)] = str_getcsv($line, ',');
			}
		}
		return $rows;
	}

	private function parse_xlsx(string $path): array {
		if (! is_readable($path)) {
			return [];
		}
		$data = [];
		if (! class_exists('ZipArchive')) {
			return [];
		}
		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			return [];
		}
		// Simple parser: read sharedStrings and first sheet sheet1.xml
		$shared = [];
		if (($index = $zip->locateName('xl/sharedStrings.xml')) !== false) {
			$xml = simplexml_load_string($zip->getFromIndex($index));
			foreach ($xml->si as $si) {
				$shared[] = (string) $si->t;
			}
		}
		if (($sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml')) === false) {
			$zip->close();
			return [];
		}
		$sheet = simplexml_load_string($zip->getFromIndex($sheetIndex));
		$sheet->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
		foreach ($sheet->sheetData->row as $row) {
			$current = [];
			foreach ($row->c as $c) {
				$type = (string) $c['t'];
				$v = (string) $c->v;
				if ($type === 's') {
					$current[] = $shared[(int) $v] ?? '';
				} else {
					$current[] = $v;
				}
			}
			if ($current) {
				$data[] = $current;
			}
		}
		$zip->close();
		return $data;
	}

	private function parse_uploaded_file(array $file): array {
		$type = $file['type'] ?? '';
		$tmp = $file['tmp_name'] ?? '';
		if (! $tmp || ! is_readable($tmp)) {
			return [];
		}
		$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
		if ($ext === 'xlsx' || $type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
			return $this->parse_xlsx($tmp);
		}
		return $this->parse_csv(file_get_contents($tmp));
	}

	private function csv_to_string(array $rows): string {
		$out = [];
		foreach ($rows as $row) {
			$out[] = implode(',', $row);
		}
		return implode("\n", $out);
	}

	private function process_import(): void {
		$tipo = isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : '';
		$rows = $this->parse_csv(sanitize_textarea_field(wp_unslash($_POST['mdr_csv_raw'])));
		if (! $tipo || empty($rows)) {
			add_settings_error('mdr_import', 'empty', __('No hay datos para importar.', 'mapa-de-recursos'), 'error');
			return;
		}

		$inserted = 0;
		switch ($tipo) {
			case 'financiaciones':
				$inserted = $this->import_financiaciones($rows);
				break;
			case 'servicios':
				$inserted = $this->import_servicios($rows);
				break;
			case 'ambitos':
				$inserted = $this->import_ambitos($rows);
				break;
			case 'subcategorias':
				$inserted = $this->import_subcategorias($rows);
				break;
			case 'zonas':
				$inserted = $this->import_zonas($rows);
				break;
			case 'entidades':
				$inserted = $this->import_entidades($rows);
				break;
			case 'recursos':
				$inserted = $this->import_recursos($rows);
				break;
			default:
				add_settings_error('mdr_import', 'tipo', __('Tipo no soportado.', 'mapa-de-recursos'), 'error');
				return;
		}

		Cache::flush_all();
		$this->logger->log('import_' . $tipo, 'import', ['inserted' => $inserted], 'import');

		add_settings_error('mdr_import', 'success', sprintf(__('Importación completada. Insertados: %d', 'mapa-de-recursos'), $inserted), 'updated');
	}

	private function import_financiaciones(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_financiaciones";
		$count = 0;
		foreach ($rows as $row) {
			$nombre = sanitize_text_field($row[0] ?? '');
			if ($nombre === '') {
				continue;
			}
			$slug = $this->ensure_unique_slug($table, sanitize_title($nombre));
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s LIMIT 1", $nombre));
			if ($exists) {
				continue;
			}
			$wpdb->insert($table, [
				'nombre' => $nombre,
				'slug' => $slug,
				'descripcion' => sanitize_text_field($row[1] ?? ''),
				'logo_media_id' => null,
			], ['%s','%s','%s','%d']);
			$count++;
		}
		return $count;
	}

	private function import_servicios(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";
		$count = 0;
		foreach ($rows as $row) {
			$nombre = sanitize_text_field($row[0] ?? '');
			if ($nombre === '') {
				continue;
			}
			$slug = sanitize_title($nombre);
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s LIMIT 1", $nombre));
			if ($exists) {
				continue;
			}
			$marker = sanitize_text_field($row[2] ?? '');
			$wpdb->insert($table, [
				'nombre' => $nombre,
				'slug' => $slug,
				'icono_media_id' => null,
				'icono_svg' => '',
				'marker_style' => $marker,
			], ['%s','%s','%d','%s','%s']);
			$count++;
		}
		return $count;
	}

	private function import_ambitos(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_ambitos";
		$count = 0;
		foreach ($rows as $row) {
			$nombre = sanitize_text_field($row[0] ?? '');
			if ($nombre === '') {
				continue;
			}
			$slug = sanitize_title($nombre);
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s LIMIT 1", $nombre));
			if ($exists) {
				continue;
			}
			$wpdb->insert($table, ['nombre' => $nombre, 'slug' => $slug], ['%s','%s']);
			$count++;
		}
		return $count;
	}

	private function import_subcategorias(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_subcategorias";
		$amb_table = "{$wpdb->prefix}mdr_ambitos";
		$count = 0;
		foreach ($rows as $row) {
			$ambito_nombre = sanitize_text_field($row[0] ?? '');
			$nombre = sanitize_text_field($row[1] ?? '');
			if ($ambito_nombre === '' || $nombre === '') {
				continue;
			}
			$ambito_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$amb_table} WHERE nombre = %s LIMIT 1", $ambito_nombre));
			if (! $ambito_id) {
				continue;
			}
			$slug = sanitize_title($nombre);
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE ambito_id = %d AND nombre = %s LIMIT 1", $ambito_id, $nombre));
			if ($exists) {
				continue;
			}
			$wpdb->insert($table, [
				'ambito_id' => $ambito_id,
				'nombre' => $nombre,
				'slug' => $slug,
			], ['%d','%s','%s']);
			$count++;
		}
		return $count;
	}

	private function import_zonas(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_zonas";
		$count = 0;
		foreach ($rows as $row) {
			$nombre = sanitize_text_field($row[0] ?? '');
			if ($nombre === '') {
				continue;
			}
			$slug = sanitize_title($nombre);
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s LIMIT 1", $nombre));
			if ($exists) {
				continue;
			}
			$wpdb->insert($table, ['nombre' => $nombre, 'slug' => $slug], ['%s','%s']);
			$count++;
		}
		return $count;
	}

	private function import_entidades(array $rows): int {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_entidades";
		$zonas = "{$wpdb->prefix}mdr_zonas";
		$count = 0;
		foreach ($rows as $row) {
			$nombre = sanitize_text_field($row[0] ?? '');
			if ($nombre === '') {
				continue;
			}
			$slug = sanitize_title($nombre);
			$telefono = sanitize_text_field($row[1] ?? '');
			$email    = sanitize_email($row[2] ?? '');
			$direccion = sanitize_text_field($row[3] ?? '');
			$cp       = sanitize_text_field($row[4] ?? '');
			$ciudad   = sanitize_text_field($row[5] ?? '');
			$provincia= sanitize_text_field($row[6] ?? '');
			$pais     = sanitize_text_field($row[7] ?? '');
			$lat      = isset($row[8]) ? (float) $row[8] : null;
			$lng      = isset($row[9]) ? (float) $row[9] : null;
			$zona_nombre = sanitize_text_field($row[10] ?? '');
			$web      = esc_url_raw($row[11] ?? '');

			$zona_id = 0;
			if ($zona_nombre) {
				$zona_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$zonas} WHERE nombre = %s LIMIT 1", $zona_nombre));
				if (! $zona_id) {
					$wpdb->insert($zonas, ['nombre' => $zona_nombre, 'slug' => sanitize_title($zona_nombre)], ['%s','%s']);
					$zona_id = (int) $wpdb->insert_id;
				}
			}

			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE nombre = %s LIMIT 1", $nombre));
			if ($exists) {
				continue;
			}

			$wpdb->insert($table, [
				'nombre' => $nombre,
				'slug' => $slug,
				'telefono' => $telefono,
				'email' => $email,
				'direccion_linea1' => $direccion,
				'cp' => $cp,
				'ciudad' => $ciudad,
				'provincia' => $provincia,
				'pais' => $pais,
				'lat' => $lat,
				'lng' => $lng,
				'zona_id' => $zona_id ?: null,
				'web' => $web,
			], ['%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%d','%s']);
			$count++;
		}
		return $count;
	}

	private function import_recursos(array $rows): int {
		global $wpdb;
		$recursos_table = "{$wpdb->prefix}mdr_recursos";
		$entidades_table = "{$wpdb->prefix}mdr_entidades";
		$ambitos_table = "{$wpdb->prefix}mdr_ambitos";
		$subcats_table = "{$wpdb->prefix}mdr_subcategorias";
		$servicios_table = "{$wpdb->prefix}mdr_servicios";
		$finan_table = "{$wpdb->prefix}mdr_financiaciones";

		$count = 0;
		foreach ($rows as $row) {
			$entidad_nombre = sanitize_text_field($row[0] ?? '');
			$ambito_nombre  = sanitize_text_field($row[1] ?? '');
			$subcat_nombre  = sanitize_text_field($row[2] ?? '');
			$recurso_prog   = sanitize_text_field($row[3] ?? '');
			if ($entidad_nombre === '' || $recurso_prog === '') {
				continue;
			}
			$descripcion    = sanitize_text_field($row[4] ?? '');
			$objetivo       = sanitize_text_field($row[5] ?? '');
			$destinatarios  = sanitize_text_field($row[6] ?? '');
			$periodo_inicio = sanitize_text_field($row[7] ?? '');
			$periodo_fin    = sanitize_text_field($row[8] ?? '');
			$ent_gestora_nombre = sanitize_text_field($row[9] ?? '');
			$finan_nombre   = sanitize_text_field($row[10] ?? '');
			$servicio_nombre = sanitize_text_field($row[11] ?? '');
			$activo         = isset($row[12]) ? (int) $row[12] : 1;

			// IDs opcionales (prioridad sobre nombres si existen).
			$entidad_id = isset($row[13]) ? (int) $row[13] : 0;
			$ambito_id  = isset($row[14]) ? (int) $row[14] : 0;
			$subcat_id  = isset($row[15]) ? (int) $row[15] : 0;
			$servicio_id_csv = isset($row[16]) ? (int) $row[16] : 0;
			$finan_id_csv = isset($row[17]) ? (int) $row[17] : 0;
			$ent_gestora_id_csv = isset($row[18]) ? (int) $row[18] : 0;

			$contact_start = count($row) > 18 ? 19 : 13;
			// Contactos múltiples en grupos de 3 columnas: nombre, email, tel.
			$contactos_cols = array_slice($row, $contact_start);
			$contactos = [];
			for ($i = 0; $i < count($contactos_cols); $i += 3) {
				$c_nombre = sanitize_text_field($contactos_cols[$i] ?? '');
				$c_email  = sanitize_email($contactos_cols[$i + 1] ?? '');
				$c_tel    = sanitize_text_field($contactos_cols[$i + 2] ?? '');
				if ($c_nombre === '' && $c_email === '' && $c_tel === '') {
					continue;
				}
				$contactos[] = [
					'nombre' => $c_nombre,
					'email' => $c_email,
					'telefono' => $c_tel,
				];
			}

			// Entidad: prioridad ID si existe, si no, por nombre.
			if ($entidad_id > 0) {
				$entidad_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entidades_table} WHERE id = %d", $entidad_id));
			} else {
				$entidad_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entidades_table} WHERE nombre = %s LIMIT 1", $entidad_nombre));
				if (! $entidad_id && $entidad_nombre !== '') {
					$slug = $this->ensure_unique_slug($entidades_table, sanitize_title($entidad_nombre));
					$wpdb->insert(
						$entidades_table,
						[
							'nombre' => $entidad_nombre,
							'slug'   => $slug,
						],
						['%s', '%s']
					);
					$entidad_id = (int) $wpdb->insert_id;
					if (! $entidad_id) {
						$entidad_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entidades_table} WHERE nombre = %s LIMIT 1", $entidad_nombre));
					}
				}
			}
			if (! $entidad_id) {
				continue;
			}

			// Ámbito.
			if ($ambito_id > 0) {
				$ambito_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$ambitos_table} WHERE id = %d", $ambito_id));
			} elseif ($ambito_nombre) {
				$ambito_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$ambitos_table} WHERE nombre = %s LIMIT 1", $ambito_nombre));
				if (! $ambito_id) {
					$wpdb->insert($ambitos_table, ['nombre' => $ambito_nombre, 'slug' => sanitize_title($ambito_nombre)], ['%s','%s']);
					$ambito_id = (int) $wpdb->insert_id;
				}
			} else {
				$ambito_id = 0;
			}

			// Subcategoría.
			if ($subcat_id > 0) {
				$subcat_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$subcats_table} WHERE id = %d", $subcat_id));
			} elseif ($subcat_nombre && $ambito_id) {
				$subcat_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$subcats_table} WHERE ambito_id = %d AND nombre = %s LIMIT 1", $ambito_id, $subcat_nombre));
				if (! $subcat_id) {
					$wpdb->insert($subcats_table, ['ambito_id' => $ambito_id, 'nombre' => $subcat_nombre, 'slug' => sanitize_title($subcat_nombre)], ['%d','%s','%s']);
					$subcat_id = (int) $wpdb->insert_id;
				}
			} else {
				$subcat_id = 0;
			}

			// Servicio.
			if ($servicio_id_csv > 0) {
				$servicio_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$servicios_table} WHERE id = %d", $servicio_id_csv));
			} elseif ($servicio_nombre) {
				$servicio_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$servicios_table} WHERE nombre = %s LIMIT 1", $servicio_nombre));
			} else {
				$servicio_id = 0;
			}

			// Financiación.
			if ($finan_id_csv > 0) {
				$finan_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$finan_table} WHERE id = %d", $finan_id_csv));
			} elseif ($finan_nombre) {
				$finan_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$finan_table} WHERE nombre = %s LIMIT 1", $finan_nombre));
			} else {
				$finan_id = 0;
			}

			// Entidad gestora.
			if ($ent_gestora_id_csv > 0) {
				$ent_gestora_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entidades_table} WHERE id = %d", $ent_gestora_id_csv));
			} elseif ($ent_gestora_nombre) {
				$ent_gestora_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entidades_table} WHERE nombre = %s LIMIT 1", $ent_gestora_nombre));
			} else {
				$ent_gestora_id = 0;
			}
				$wpdb->insert($recursos_table, [
					'entidad_id' => $entidad_id,
					'ambito_id' => $ambito_id ?: null,
				'subcategoria_id' => $subcat_id ?: null,
				'recurso_programa' => $recurso_prog,
				'descripcion' => $descripcion,
				'objetivo' => $objetivo,
				'destinatarios' => $destinatarios,
				'periodo_inicio' => $periodo_inicio ?: null,
				'periodo_fin' => $periodo_fin ?: null,
				'entidad_gestora' => $ent_gestora_nombre,
				'entidad_gestora_id' => $ent_gestora_id ?: null,
				'financiacion' => $finan_nombre,
				'financiacion_id' => $finan_id ?: null,
				'contacto' => $contactos ? wp_json_encode($contactos) : '',
				'servicio_id' => $servicio_id ?: null,
				'activo' => $activo ? 1 : 0,
			], ['%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%d']);
			$count++;
		}
		return $count;
	}

	private function ensure_unique_slug(string $table, string $base): string {
		global $wpdb;
		$slug = $base !== '' ? $base : 'item';
		$original = $slug;
		$i = 2;
		while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug)) > 0) {
			$slug = "{$original}-{$i}";
			$i++;
		}
		return $slug;
	}
}
