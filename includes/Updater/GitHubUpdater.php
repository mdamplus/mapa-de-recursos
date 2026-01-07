<?php

declare(strict_types=1);

namespace MapaDeRecursos\Updater;

use MapaDeRecursos\Logs\Logger;
use stdClass;

if (! defined('ABSPATH')) {
	exit;
}

class GitHubUpdater {
	private string $slug; // plugin folder
	private string $repo_owner;
	private string $repo_name;
	private string $current_version;
	private ?Logger $logger;
	private string $api_url;
	private string $zip_url;
	private ?array $release_cache = null;

	public function __construct(string $slug, string $repo_owner, string $repo_name, $current_version_or_logger = '', $maybe_logger = null) {
		$this->slug       = $slug; // e.g. mapa-de-recursos
		$this->repo_owner = $repo_owner;
		$this->repo_name  = $repo_name;

		// Backward compatibility: previous signature had logger as 4th argument.
		if ($current_version_or_logger instanceof Logger) {
			$this->logger = $current_version_or_logger;
			$this->current_version = is_string($maybe_logger) ? $maybe_logger : (defined('MAPA_DE_RECURSOS_VERSION') ? MAPA_DE_RECURSOS_VERSION : '0.0.0');
		} else {
			$this->current_version = is_string($current_version_or_logger) ? $current_version_or_logger : '0.0.0';
			$this->logger = $maybe_logger instanceof Logger ? $maybe_logger : null;
		}

		$this->api_url    = "https://api.github.com/repos/{$repo_owner}/{$repo_name}/releases/latest";
		$this->zip_url    = "https://github.com/{$repo_owner}/{$repo_name}/archive/refs/tags/%s.zip";
	}

	public function register(): void {
		add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
		add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
		add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
	}

	public function check_for_update($transient) {
		if (empty($transient->checked)) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if (! $release) {
			return $transient;
		}

		$latest_version = ltrim($release['tag_name'], 'v');
		if (version_compare($this->current_version, $latest_version, '>=')) {
			return $transient;
		}

		$plugin_file = $this->slug . '/' . $this->slug . '.php';
		$item = new stdClass();
		$item->slug = $this->slug;
		$item->plugin = $plugin_file;
		$item->new_version = $latest_version;
		$item->package = sprintf($this->zip_url, $release['tag_name']);
		$item->tested = '6.7';
		$item->requires_php = '8.3';
		$item->url = $release['html_url'] ?? '';
		$item->icons = [];
		$item->banners = [];
		$item->sections = [
			'changelog' => $release['body'] ?? '',
		];

		$transient->response[$plugin_file] = $item;

		return $transient;
	}

	public function plugins_api($res, $action, $args) {
		if ($action !== 'plugin_information' || ! isset($args->slug) || $args->slug !== $this->slug) {
			return $res;
		}

		$release = $this->get_latest_release();
		if (! $release) {
			return $res;
		}

		$latest_version = ltrim($release['tag_name'], 'v');

		$info = new stdClass();
		$info->name = 'Mapa de recursos';
		$info->slug = $this->slug;
		$info->version = $latest_version;
		$info->author = '<a href="https://example.com">Equipo Eracis</a>';
		$info->homepage = $release['html_url'] ?? '';
		$info->download_link = sprintf($this->zip_url, $release['tag_name']);
		$info->tested = '6.7';
		$info->requires_php = '8.3';
		$info->sections = [
			'description' => __('Actualización desde GitHub Releases.', 'mapa-de-recursos'),
			'changelog'   => wp_kses_post($release['body'] ?? ''),
		];

		return $info;
	}

	public function after_install($response, $hook_extra, $result) {
		if (! isset($hook_extra['plugin']) || strpos($hook_extra['plugin'], $this->slug . '.php') === false) {
			return $response;
		}

		// mover el plugin al directorio correcto ya lo hace WP, aquí sólo logueamos
		if ($this->logger) {
			$this->logger->log('update_plugin', 'plugin', ['slug' => $this->slug, 'version' => $this->current_version, 'result' => $result], 'update');
		}

		return $response;
	}

	private function get_latest_release(): ?array {
		if (null !== $this->release_cache) {
			return $this->release_cache;
		}

		$response = wp_remote_get($this->api_url, [
			'user-agent' => 'mapa-de-recursos-updater',
			'timeout'    => 10,
		]);

		if (is_wp_error($response)) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (! is_array($data) || empty($data['tag_name'])) {
			return null;
		}

		$this->release_cache = $data;
		return $data;
	}
}
