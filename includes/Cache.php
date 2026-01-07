<?php

declare(strict_types=1);

namespace MapaDeRecursos;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Small helper to handle cached responses.
 */
class Cache {
	private const OPTION_KEYS = 'mapa_de_recursos_cache_keys';

	public static function get(string $key) {
		return get_transient($key);
	}

	public static function set(string $key, $value, int $expiration = 300): void {
		set_transient($key, $value, $expiration);

		$keys = get_option(self::OPTION_KEYS, []);
		if (! in_array($key, $keys, true)) {
			$keys[] = $key;
			update_option(self::OPTION_KEYS, $keys, false);
		}
	}

	public static function flush_all(): void {
		$keys = get_option(self::OPTION_KEYS, []);
		if (! is_array($keys)) {
			return;
		}

		foreach ($keys as $key) {
			delete_transient($key);
		}

		delete_option(self::OPTION_KEYS);
	}
}
