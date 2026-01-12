<?php

declare(strict_types=1);

namespace MapaDeRecursos\Database;

use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles database creation and seeding.
 */
class Installer {
	public const DB_VERSION_OPTION = 'mapa_de_recursos_db_version';

	public function install(): void {
		$this->create_tables();
		$this->seed_zones();
		$this->seed_services();
		self::ensure_caps();

		update_option(self::DB_VERSION_OPTION, MAPA_DE_RECURSOS_VERSION);
	}

	public static function maybe_upgrade(): void {
		$current = get_option(self::DB_VERSION_OPTION);
		if ($current === MAPA_DE_RECURSOS_VERSION) {
			return;
		}
		$installer = new self();
		$installer->create_tables();
		$installer->seed_zones();
		$installer->seed_services();
		self::ensure_caps();
		update_option(self::DB_VERSION_OPTION, MAPA_DE_RECURSOS_VERSION);
	}

	private function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$entidades_table    = "{$wpdb->prefix}mdr_entidades";
		$recursos_table     = "{$wpdb->prefix}mdr_recursos";
		$ambitos_table      = "{$wpdb->prefix}mdr_ambitos";
		$subcategorias_table = "{$wpdb->prefix}mdr_subcategorias";
		$zonas_table        = "{$wpdb->prefix}mdr_zonas";
		$servicios_table    = "{$wpdb->prefix}mdr_servicios";
		$financiaciones_table = "{$wpdb->prefix}mdr_financiaciones";
		$logs_table         = "{$wpdb->prefix}mdr_logs";

		$tables = [
			"CREATE TABLE {$entidades_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				logo_url varchar(255) DEFAULT NULL,
				logo_media_id bigint(20) unsigned DEFAULT NULL,
				telefono varchar(50) DEFAULT NULL,
				email varchar(190) DEFAULT NULL,
				web varchar(190) DEFAULT NULL,
				descripcion longtext DEFAULT NULL,
				direccion_linea1 varchar(190) DEFAULT NULL,
				cp varchar(20) DEFAULT NULL,
				ciudad varchar(120) DEFAULT NULL,
				provincia varchar(120) DEFAULT NULL,
				pais varchar(120) DEFAULT NULL,
				lat decimal(10,7) DEFAULT NULL,
				lng decimal(10,7) DEFAULT NULL,
				zona_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY nombre_unique (nombre),
				UNIQUE KEY slug_unique (slug),
				KEY zona_id (zona_id),
				KEY lat_lng (lat,lng)
			) {$charset_collate};",
			"CREATE TABLE {$recursos_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entidad_id bigint(20) unsigned NOT NULL,
				ambito_id bigint(20) unsigned DEFAULT NULL,
				subcategoria_id bigint(20) unsigned DEFAULT NULL,
				recurso_programa varchar(190) NOT NULL,
				descripcion longtext DEFAULT NULL,
				objetivo text DEFAULT NULL,
				destinatarios text DEFAULT NULL,
				periodo_ejecucion varchar(190) DEFAULT NULL,
				periodo_inicio date DEFAULT NULL,
				periodo_fin date DEFAULT NULL,
				entidad_gestora varchar(190) DEFAULT NULL,
				entidad_gestora_id bigint(20) unsigned DEFAULT NULL,
				financiacion varchar(190) DEFAULT NULL,
				financiacion_id bigint(20) unsigned DEFAULT NULL,
				contacto varchar(190) DEFAULT NULL,
				servicio_id bigint(20) unsigned DEFAULT NULL,
				activo tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY entidad_id (entidad_id),
				KEY ambito_id (ambito_id),
				KEY subcategoria_id (subcategoria_id),
				KEY servicio_id (servicio_id),
				KEY entidad_gestora_id (entidad_gestora_id),
				KEY financiacion_id (financiacion_id),
				KEY activo (activo)
			) {$charset_collate};",
			"CREATE TABLE {$ambitos_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY nombre_unique (nombre),
				UNIQUE KEY slug_unique (slug)
			) {$charset_collate};",
			"CREATE TABLE {$subcategorias_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ambito_id bigint(20) unsigned NOT NULL,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY ambito_nombre_unique (ambito_id,nombre),
				KEY ambito_id (ambito_id),
				KEY slug_unique (slug)
			) {$charset_collate};",
			"CREATE TABLE {$zonas_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY nombre_unique (nombre),
				UNIQUE KEY slug_unique (slug)
			) {$charset_collate};",
			"CREATE TABLE {$servicios_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				icono_media_id bigint(20) unsigned DEFAULT NULL,
				icono_svg longtext DEFAULT NULL,
				icono_clase varchar(190) DEFAULT NULL,
				marker_style longtext DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY nombre_unique (nombre),
				UNIQUE KEY slug_unique (slug)
			) {$charset_collate};",
			"CREATE TABLE {$financiaciones_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				logo_media_id bigint(20) unsigned DEFAULT NULL,
				descripcion text DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY nombre_unique (nombre),
				UNIQUE KEY slug_unique (slug)
			) {$charset_collate};",
			"CREATE TABLE {$logs_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned DEFAULT NULL,
				accion varchar(190) NOT NULL,
				objeto varchar(190) DEFAULT NULL,
				user_name varchar(190) DEFAULT NULL,
				detalles longtext DEFAULT NULL,
				ip varchar(100) DEFAULT NULL,
				tipo varchar(50) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY tipo (tipo),
				KEY created_at (created_at)
			) {$charset_collate};",
		];

		foreach ($tables as $sql) {
			dbDelta($sql);
		}
	}

	private function seed_zones(): void {
		global $wpdb;

		$table = "{$wpdb->prefix}mdr_zonas";
		$now   = current_time('mysql');

		$zones = [
			'Chiclana',
			'Málaga Centro',
			'Málaga Carretera de Cádiz',
			'Palma Palmilla',
		];

		foreach ($zones as $zone) {
			$slug = function_exists('sanitize_title') ? sanitize_title($zone) : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $zone) ?? '', '-'));

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (nombre, slug, created_at, updated_at) VALUES (%s, %s, %s, %s)",
					$zone,
					$slug,
					$now,
					$now
				)
			);
		}
	}

	/**
	 * Ensure roles and capabilities exist (usable on activation and runtime).
	 */
	public static function ensure_caps(): void {
		$caps = [
			'mdr_manage'      => true,
			'mdr_edit'        => true,
			'mdr_view_logs'   => true,
			'mdr_export_pdf'  => true,
		];

		if (! get_role('tecnico')) {
			add_role('tecnico', __('Tecnico', 'mapa-de-recursos'), array_merge(
				[
					'read'         => true,
					'edit_posts'   => false,
					'delete_posts' => false,
				],
				$caps
			));
		}

		foreach (['administrator', 'editor', 'tecnico'] as $role_slug) {
			$role = get_role($role_slug);
			if (! $role) {
				continue;
			}

			foreach ($caps as $cap => $grant) {
				if (! $role->has_cap($cap)) {
					$role->add_cap($cap, $grant);
				}
			}
		}
	}

	/**
	 * Seed default services/icons if they do not exist.
	 */
	private function seed_services(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}mdr_servicios";
		$now   = current_time('mysql');

		$items = [
			['Empleo y Desarrollo', 'fa-solid fa-briefcase'],
			['Formación reglada', 'fa-solid fa-graduation-cap'],
			['Formación no reglada', 'fa-solid fa-book-open'],
			['Orientación e Intermediación', 'fa-solid fa-compass'],
			['Apoyo al Autoempleo y Tejido Empresarial', 'fa-solid fa-handshake'],
			['Colectivos Específicos (Diversidad funcional)', 'fa-solid fa-wheelchair'],
			['Colectivos Específicos (Jóvenes)', 'fa-solid fa-child-reaching'],
			['Colectivos Específicos (Mujer)', 'fa-solid fa-person-dress'],
			['Colectivos Específicos (Riesgo de exclusión)', 'fa-solid fa-people-arrows'],
			['Inclusión Socio-Familiar', 'fa-solid fa-people-roof'],
			['Infancia y Juventud', 'fa-solid fa-child-reaching'],
			['Familias', 'fa-solid fa-people-roof'],
			['Diversidad Funcional', 'fa-solid fa-wheelchair'],
			['Salud', 'fa-solid fa-heart-pulse'],
			['Adicciones', 'fa-solid fa-pills'],
			['Personas Inmigrantes/Emigrantes', 'fa-solid fa-earth-europe'],
			['Personas Mayores', 'fa-solid fa-person-cane'],
			['Personas sin hogar', 'fa-solid fa-house-chimney'],
			['Mejora del Hábitat y la Convivencia', 'fa-solid fa-people-group'],
			['Seguridad Ciudadana, Movilidad y Transporte', 'fa-solid fa-shield'],
			['Cultura y Deporte', 'fa-solid fa-masks-theater'],
			['Medio Ambiente y Espacios Verdes', 'fa-solid fa-tree'],
			['Participación Ciudadana', 'fa-solid fa-handshake-angle'],
			['Urbanismo y Equipamientos', 'fa-solid fa-city'],
			['Vivienda', 'fa-solid fa-house'],
			['Turismo', 'fa-solid fa-plane-departure'],
		];

		foreach ($items as [$nombre, $icono_clase]) {
			$slug = function_exists('sanitize_title') ? sanitize_title($nombre) : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $nombre) ?? '', '-'));
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (nombre, slug, icono_media_id, icono_svg, icono_clase, marker_style, created_at, updated_at)
					VALUES (%s, %s, NULL, '', %s, '', %s, %s)",
					$nombre,
					$slug,
					$icono_clase,
					$now,
					$now
				)
			);
		}
	}
}
