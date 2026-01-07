<?php

declare(strict_types=1);

namespace MapaDeRecursos\Rest;

use MapaDeRecursos\Cache;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
	exit;
}

class Recursos_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = 'mdr/v1';
		$this->rest_base = 'recursos';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'get_items'],
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	public function get_items($request) {
		$entidad_id = (int) $request->get_param('entidad_id');
		if ($entidad_id <= 0) {
			return new WP_Error('mdr_invalid_entidad', __('Entidad requerida', 'mapa-de-recursos'), ['status' => 400]);
		}

		$cache_key = 'mdr_recursos_' . $entidad_id;
		$cached = Cache::get($cache_key);
		if (false !== $cached && null !== $cached) {
			return new WP_REST_Response($cached, 200);
		}

		global $wpdb;
		$table = "{$wpdb->prefix}mdr_recursos";

		$sql = "
			SELECT id, recurso_programa, descripcion, objetivo, destinatarios, periodo_ejecucion,
				   periodo_inicio, periodo_fin, entidad_gestora, entidad_gestora_id, financiacion, financiacion_id, contacto, servicio_id, ambito_id, subcategoria_id, activo
			FROM {$table}
			WHERE entidad_id = %d AND activo = 1 AND (periodo_fin IS NULL OR periodo_fin >= CURDATE())
			ORDER BY updated_at DESC
			LIMIT 500
		";

		$results = $wpdb->get_results($wpdb->prepare($sql, $entidad_id), ARRAY_A);

		$data = array_map(
			static function (array $row): array {
				$contactos = [];
				if (! empty($row['contacto'])) {
					$decoded = json_decode($row['contacto'], true);
					if (is_array($decoded)) {
						$contactos = array_map(
							static function ($c) {
								return [
									'nombre' => $c['nombre'] ?? '',
									'email'  => $c['email'] ?? '',
									'telefono' => $c['telefono'] ?? '',
								];
							},
							$decoded
						);
					}
				}
				$fallback_contacto = '';
				if ($contactos) {
					$fallback_contacto = implode(' / ', array_filter(array_map(
						static function ($c) {
							$parts = array_filter([$c['nombre'] ?? '', $c['email'] ?? '', $c['telefono'] ?? '']);
							return implode(' - ', $parts);
						},
						$contactos
					)));
				} elseif (! empty($row['contacto'])) {
					$fallback_contacto = $row['contacto'];
				}

				return [
					'id'               => (int) $row['id'],
					'recurso_programa' => $row['recurso_programa'],
					'descripcion'      => $row['descripcion'],
					'objetivo'         => $row['objetivo'],
					'destinatarios'    => $row['destinatarios'],
					'periodo_ejecucion' => $row['periodo_ejecucion'],
					'periodo_inicio'   => $row['periodo_inicio'],
					'periodo_fin'      => $row['periodo_fin'],
					'entidad_gestora'  => $row['entidad_gestora'],
					'entidad_gestora_id' => isset($row['entidad_gestora_id']) ? (int) $row['entidad_gestora_id'] : null,
					'financiacion'     => $row['financiacion'],
					'financiacion_id'  => isset($row['financiacion_id']) ? (int) $row['financiacion_id'] : null,
					'contacto'         => $fallback_contacto,
					'contactos'        => $contactos,
					'servicio_id'      => isset($row['servicio_id']) ? (int) $row['servicio_id'] : null,
					'ambito_id'        => isset($row['ambito_id']) ? (int) $row['ambito_id'] : null,
					'subcategoria_id'  => isset($row['subcategoria_id']) ? (int) $row['subcategoria_id'] : null,
				];
			},
			$results
		);

		Cache::set($cache_key, $data, 300);

		return new WP_REST_Response($data, 200);
	}

	public function get_collection_params(): array {
		return [
			'entidad_id' => [
				'description' => __('ID de entidad', 'mapa-de-recursos'),
				'type'        => 'integer',
				'required'    => true,
				'minimum'     => 1,
			],
		];
	}
}
