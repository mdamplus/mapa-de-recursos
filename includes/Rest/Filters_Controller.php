<?php

declare(strict_types=1);

namespace MapaDeRecursos\Rest;

use MapaDeRecursos\Cache;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
	exit;
}

class Filters_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = 'mdr/v1';
		$this->rest_base = 'filtros';
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
				],
			]
		);
	}

	public function get_items($request) {
		$cache_key = 'mdr_filtros_all';
		$cached = Cache::get($cache_key);
		if (false !== $cached && null !== $cached) {
			return new WP_REST_Response($cached, 200);
		}

		global $wpdb;

		$zonas_table   = "{$wpdb->prefix}mdr_zonas";
		$ambitos_table = "{$wpdb->prefix}mdr_ambitos";
		$subcats_table = "{$wpdb->prefix}mdr_subcategorias";
		$servicios_table = "{$wpdb->prefix}mdr_servicios";

		$zonas = $wpdb->get_results(
			"SELECT id, nombre, slug FROM {$zonas_table} ORDER BY nombre ASC",
			ARRAY_A
		);

		$ambitos = $wpdb->get_results(
			"SELECT id, nombre, slug FROM {$ambitos_table} ORDER BY nombre ASC",
			ARRAY_A
		);

		$subcategorias = $wpdb->get_results(
			"SELECT id, ambito_id, nombre, slug FROM {$subcats_table} ORDER BY nombre ASC",
			ARRAY_A
		);

		$servicios = $wpdb->get_results(
			"SELECT id, nombre, slug, icono_media_id, icono_svg FROM {$servicios_table} ORDER BY nombre ASC",
			ARRAY_A
		);

		$data = [
			'zonas'         => array_map([$this, 'map_simple'], $zonas),
			'ambitos'       => array_map([$this, 'map_simple'], $ambitos),
			'subcategorias' => array_map(
				function (array $row): array {
					return [
						'id'        => (int) $row['id'],
						'ambito_id' => (int) $row['ambito_id'],
						'nombre'    => $row['nombre'],
						'slug'      => $row['slug'],
					];
				},
				$subcategorias
			),
			'servicios'     => array_map(
				function (array $row): array {
					return [
						'id'             => (int) $row['id'],
						'nombre'         => $row['nombre'],
						'slug'           => $row['slug'],
						'icono_media_id' => isset($row['icono_media_id']) ? (int) $row['icono_media_id'] : null,
						'icono_svg'      => $row['icono_svg'],
					];
				},
				$servicios
			),
		];

		Cache::set($cache_key, $data, 600);

		return new WP_REST_Response($data, 200);
	}

	private function map_simple(array $row): array {
		return [
			'id'     => (int) $row['id'],
			'nombre' => $row['nombre'],
			'slug'   => $row['slug'],
		];
	}
}
