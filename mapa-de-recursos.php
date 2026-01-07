<?php
/**
 * Plugin Name: Mapa de recursos
 * Plugin URI: https://martinarnedo.com/mapa-de-recursos
 * Description: Geolocaliza al usuario y muestra recursos cercanos en un mapa con filtros y clustering.
 * Version: 2026.1
 * Author: Martín D. Arnedo Mahr
 * Text Domain: mapa-de-recursos
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.3
 */

declare(strict_types=1);

namespace MapaDeRecursos;

if (! defined('ABSPATH')) {
	exit;
}

define('MAPA_DE_RECURSOS_VERSION', '2026.1');
define('MAPA_DE_RECURSOS_FILE', __FILE__);
define('MAPA_DE_RECURSOS_PATH', plugin_dir_path(__FILE__));
define('MAPA_DE_RECURSOS_URL', plugin_dir_url(__FILE__));

require_once MAPA_DE_RECURSOS_PATH . 'includes/Autoloader.php';

Autoloader::register();

$plugin = Plugin::instance();
$plugin->run();

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
