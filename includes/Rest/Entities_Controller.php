<?php

declare(strict_types=1);

namespace MapaDeRecursos\Rest;

use MapaDeRecursos\Cache;
use MapaDeRecursos\Geo\BBox;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
	exit;
}

class Entities_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = 'mdr/v1';
		$this->rest_base = 'entidades';
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
		$bbox = BBox::parse($request->get_param('bbox'));
		$zona = $request->get_param('zona');
		$ambito = $request->get_param('ambito');
		$subcategoria = $request->get_param('subcategoria');
		$servicio = $request->get_param('servicio');
		$q = $request->get_param('q');
		$all = (bool) $request->get_param('all');
		$include_empty = (bool) $request->get_param('include_empty');

		$params_for_cache = [
			'bbox'         => $bbox,
			'zona'         => $zona,
			'ambito'       => $ambito,
			'subcategoria' => $subcategoria,
			'servicio'     => $servicio,
			'q'            => $q,
			'all'          => $all,
			'include_empty' => $include_empty,
		];

		$cache_key = 'mdr_entidades_' . md5(wp_json_encode($params_for_cache));
		$cached = Cache::get($cache_key);
		if (false !== $cached && null !== $cached) {
			return new WP_REST_Response($cached, 200);
		}

		global $wpdb;

		$table_entidades = "{$wpdb->prefix}mdr_entidades";
		$table_recursos  = "{$wpdb->prefix}mdr_recursos";

		$where   = [];
		$joins   = [];
		$params  = [];

		$needs_join = ! $include_empty || ! empty($ambito) || ! empty($subcategoria) || ! empty($servicio);
		if ($needs_join) {
			$joins[] = "INNER JOIN {$table_recursos} r ON r.entidad_id = e.id AND r.activo = 1 AND (r.periodo_fin IS NULL OR r.periodo_fin >= CURDATE())";
		}

		if ($bbox) {
			$where[]  = 'e.lat IS NOT NULL AND e.lng IS NOT NULL';
			$where[]  = 'e.lat BETWEEN %f AND %f';
			$where[]  = 'e.lng BETWEEN %f AND %f';
			$params[] = $bbox['minLat'];
			$params[] = $bbox['maxLat'];
			$params[] = $bbox['minLng'];
			$params[] = $bbox['maxLng'];
		} elseif (! $all) {
			// Si no hay bbox y no se pide todo, filtramos a los que tengan coordenadas.
			$where[] = 'e.lat IS NOT NULL AND e.lng IS NOT NULL';
		}

		if (! empty($zona)) {
			$where[]  = 'e.zona_id = %d';
			$params[] = absint($zona);
		}

		if (! empty($ambito)) {
			$where[]  = 'r.ambito_id = %d';
			$params[] = absint($ambito);
		}

		if (! empty($subcategoria)) {
			$where[]  = 'r.subcategoria_id = %d';
			$params[] = absint($subcategoria);
		}

		if (! empty($servicio)) {
			$where[]  = 'r.servicio_id = %d';
			$params[] = absint($servicio);
		}

		if (! empty($q)) {
			$like = '%' . $wpdb->esc_like($q) . '%';
			$where[]  = '(e.nombre LIKE %s OR r.recurso_programa LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$sql = "
			SELECT DISTINCT e.id, e.nombre, e.slug, e.lat, e.lng, e.zona_id, e.telefono, e.email, e.logo_url, e.logo_media_id, e.direccion_linea1
			FROM {$table_entidades} e
			" . implode(' ', $joins) . "
			{$where_sql}
			LIMIT 2000
		";

		$prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
		$results  = $wpdb->get_results($prepared, ARRAY_A);

		$data = array_map(
			static function (array $row): array {
				return [
					'id'      => (int) $row['id'],
					'nombre'  => $row['nombre'],
					'slug'    => $row['slug'],
					'lat'     => $row['lat'] !== null ? (float) $row['lat'] : null,
					'lng'     => $row['lng'] !== null ? (float) $row['lng'] : null,
					'zona_id' => isset($row['zona_id']) ? (int) $row['zona_id'] : null,
					'telefono' => $row['telefono'],
					'email'    => $row['email'],
					'logo_url' => $row['logo_url'],
					'logo_media_id' => isset($row['logo_media_id']) ? (int) $row['logo_media_id'] : null,
					'direccion' => $row['direccion_linea1'] ?? '',
				];
			},
			$results
		);

		Cache::set($cache_key, $data, 300);

		return new WP_REST_Response($data, 200);
	}

	public function get_collection_params(): array {
		return [
			'bbox' => [
				'description' => __('Bounding box minLng,minLat,maxLng,maxLat', 'mapa-de-recursos'),
				'type'        => 'string',
				'required'    => false,
			],
			'zona' => [
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			],
			'ambito' => [
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			],
			'subcategoria' => [
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			],
			'servicio' => [
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			],
			'q' => [
				'type'        => 'string',
				'required'    => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'all' => [
				'type'        => 'boolean',
				'required'    => false,
				'description' => __('Si es true ignora bbox y devuelve todas las entidades (máx 2000).', 'mapa-de-recursos'),
			],
			'include_empty' => [
				'type'        => 'boolean',
				'required'    => false,
				'description' => __('Si es true devuelve también entidades sin recursos activos.', 'mapa-de-recursos'),
			],
		];
	}
}
