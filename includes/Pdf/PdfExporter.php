<?php

declare(strict_types=1);

namespace MapaDeRecursos\Pdf;

use MapaDeRecursos\Logs\Logger;
use WP_Error;
use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class PdfExporter {
	private Logger $logger;

	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	/**
	 * Genera un PDF con recursos actualizados en rango o últimas 24h.
	 *
	 * @return array|WP_Error ['path' => string, 'url' => string]
	 */
	public function generate(?string $from, ?string $to, bool $last24h = false) {
		global $wpdb;

		$where = [];
		$params = [];

		if ($last24h) {
			$from = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
			$to   = gmdate('Y-m-d H:i:s');
		} else {
			if (! empty($from)) {
				$where[] = 'r.updated_at >= %s';
				$params[] = $from . ' 00:00:00';
			}
			if (! empty($to)) {
				$where[] = 'r.updated_at <= %s';
				$params[] = $to . ' 23:59:59';
			}
		}

		$table_r = "{$wpdb->prefix}mdr_recursos";
		$table_e = "{$wpdb->prefix}mdr_entidades";
		$table_z = "{$wpdb->prefix}mdr_zonas";
		$table_a = "{$wpdb->prefix}mdr_ambitos";
		$table_s = "{$wpdb->prefix}mdr_subcategorias";

		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$sql = "
			SELECT r.id, r.recurso_programa, r.descripcion, r.destinatarios, r.periodo_ejecucion,
				   r.entidad_gestora, r.financiacion, r.contacto,
				   r.updated_at,
				   e.nombre as entidad, z.nombre as zona,
				   a.nombre as ambito, s.nombre as subcategoria
			FROM {$table_r} r
			LEFT JOIN {$table_e} e ON e.id = r.entidad_id
			LEFT JOIN {$table_z} z ON z.id = e.zona_id
			LEFT JOIN {$table_a} a ON a.id = r.ambito_id
			LEFT JOIN {$table_s} s ON s.id = r.subcategoria_id
			{$where_sql}
			ORDER BY r.updated_at DESC
			LIMIT 5000
		";

		$prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
		$rows = $wpdb->get_results($prepared, ARRAY_A);

		if (empty($rows)) {
			return new WP_Error('mdr_pdf_empty', __('No hay recursos en el rango seleccionado.', 'mapa-de-recursos'));
		}

		$title = __('Recursos actualizados', 'mapa-de-recursos') . ' ' . gmdate('d/m/Y H:i');
		$text_lines = [];
		$text_lines[] = $title;
		foreach ($rows as $row) {
			$text_lines[] = '----------------------------------------';
			$text_lines[] = sprintf('%s | %s', $row['zona'] ?? '', $row['entidad'] ?? '');
			$text_lines[] = $row['recurso_programa'] ?? '';
			if (! empty($row['ambito']) || ! empty($row['subcategoria'])) {
				$text_lines[] = trim(($row['ambito'] ?? '') . ' / ' . ($row['subcategoria'] ?? ''));
			}
			if (! empty($row['contacto'])) {
				$text_lines[] = __('Contacto', 'mapa-de-recursos') . ': ' . $row['contacto'];
			}
			if (! empty($row['destinatarios'])) {
				$text_lines[] = __('Destinatarios', 'mapa-de-recursos') . ': ' . $row['destinatarios'];
			}
			if (! empty($row['periodo_ejecucion'])) {
				$text_lines[] = __('Periodo', 'mapa-de-recursos') . ': ' . $row['periodo_ejecucion'];
			}
			if (! empty($row['descripcion'])) {
				$text_lines[] = __('Descripción', 'mapa-de-recursos') . ': ' . $this->trim_text($row['descripcion']);
			}
			$text_lines[] = __('Actualizado', 'mapa-de-recursos') . ': ' . $row['updated_at'];
		}

		$pdf_binary = $this->build_simple_pdf($text_lines);

		$upload = wp_upload_dir();
		if (! empty($upload['error'])) {
			return new WP_Error('mdr_pdf_upload', $upload['error']);
		}

		$dir = trailingslashit($upload['basedir']) . 'mapa-de-recursos';
		wp_mkdir_p($dir);
		$filename = 'recursos-' . gmdate('Ymd-His') . '.pdf';
		$filepath = $dir . '/' . $filename;

		$result = file_put_contents($filepath, $pdf_binary);
		if (false === $result) {
			return new WP_Error('mdr_pdf_write', __('No se pudo escribir el PDF.', 'mapa-de-recursos'));
		}

		$url = trailingslashit($upload['baseurl']) . 'mapa-de-recursos/' . $filename;

		$this->logger->log('generate_pdf', 'pdf', ['file' => $filepath, 'url' => $url, 'count' => count($rows)], 'pdf');

		return [
			'path' => $filepath,
			'url'  => $url,
		];
	}

	private function trim_text(string $text, int $max = 260): string {
		$text = wp_strip_all_tags($text);
		if (strlen($text) > $max) {
			return substr($text, 0, $max) . '...';
		}
		return $text;
	}

	/**
	 * Very small PDF writer (text only) to avoid external deps.
	 */
	private function build_simple_pdf(array $lines): string {
		$objects = [];
		$pdf = '%PDF-1.4' . "\n";

		// 1: Catalog
		$objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
		// 2: Pages
		$objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

		// Build content stream
		$content = "BT\n/F1 10 Tf\n72 760 Td\n";
		foreach ($lines as $line) {
			$content .= '(' . $this->escape_text($line) . ') Tj\n0 -14 Td\n';
		}
		$content .= "ET";
		$objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
		$objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
		$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

		$offsets = [0]; // object 0 is free
		$index = 1;
		foreach ($objects as $obj) {
			$offsets[$index] = strlen($pdf);
			$pdf .= $index . " 0 obj\n" . $obj . "\nendobj\n";
			$index++;
		}

		$xref_pos = strlen($pdf);
		$count = count($objects);
		$pdf .= "xref\n0 " . ($count + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($i = 1; $i <= $count; $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}
		$pdf .= "trailer << /Size " . ($count + 1) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;
	}

	private function escape_text(string $text): string {
		$text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
		return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
	}
}
