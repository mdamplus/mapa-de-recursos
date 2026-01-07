<?php

declare(strict_types=1);

namespace MapaDeRecursos\Geo;

if (! defined('ABSPATH')) {
	exit;
}

class BBox {
	/**
	 * Parse bbox string "minLng,minLat,maxLng,maxLat"
	 */
	public static function parse(?string $bbox): ?array {
		if (empty($bbox)) {
			return null;
		}

		$parts = array_map('trim', explode(',', $bbox));
		if (count($parts) !== 4) {
			return null;
		}

		[$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $parts);

		if ($minLat === 0.0 && $maxLat === 0.0 && $minLng === 0.0 && $maxLng === 0.0) {
			return null;
		}

		return [
			'minLng' => $minLng,
			'minLat' => $minLat,
			'maxLng' => $maxLng,
			'maxLat' => $maxLat,
		];
	}
}
