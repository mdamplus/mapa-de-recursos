<?php

declare(strict_types=1);

namespace MapaDeRecursos;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Simple PSR-4 style autoloader for the plugin.
 */
class Autoloader {
	public static function register(): void {
		spl_autoload_register([self::class, 'autoload']);
	}

	public static function autoload(string $class): void {
		$prefix = __NAMESPACE__ . '\\';

		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$relative_path = str_replace('\\', '/', $relative);
		$file = MAPA_DE_RECURSOS_PATH . 'includes/' . $relative_path . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
}
