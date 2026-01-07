<?php

declare(strict_types=1);

namespace MapaDeRecursos\Geo;

if (! defined('ABSPATH')) {
	exit;
}

class Haversine {
	public static function distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
		$earth_radius = 6371; // km

		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);

		$a = sin($dLat / 2) * sin($dLat / 2) +
			cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
			sin($dLon / 2) * sin($dLon / 2);
		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
		return $earth_radius * $c;
	}
}
