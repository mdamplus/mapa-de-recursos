<?php

declare(strict_types=1);

namespace MapaDeRecursos\Logs;

use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Logger {
	public function register_hooks(): void {
		add_action('save_post', [$this, 'log_post_change'], 10, 3);
		add_action('delete_post', [$this, 'log_post_delete'], 10, 1);
		add_action('created_term', [$this, 'log_term_change'], 10, 3);
		add_action('edited_term', [$this, 'log_term_change'], 10, 3);
		add_action('upgrader_process_complete', [$this, 'log_updates'], 10, 2);
	}

	public function log(string $action, string $object, array $details = [], string $type = 'plugin'): void {
		global $wpdb;

		$table = "{$wpdb->prefix}mdr_logs";
		$user  = get_current_user_id() ?: null;
		$user_display = $user ? $this->get_user_display($user) : null;
		$ip    = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : null;

		$wpdb->insert(
			$table,
			[
				'user_id'   => $user,
				'accion'    => sanitize_text_field($action),
				'objeto'    => sanitize_text_field($object),
				'detalles'  => wp_json_encode($details, JSON_UNESCAPED_UNICODE),
				'ip'        => $ip,
				'tipo'      => sanitize_text_field($type),
				'created_at' => current_time('mysql'),
				'user_name' => $user_display,
			]
		);
	}

	private function get_user_display(int $user_id): string {
		$user = get_userdata($user_id);
		if (! $user) {
			return (string) $user_id;
		}
		$name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
		if ($name === '') {
			$name = $user->display_name ?? '';
		}
		if ($name === '') {
			$name = $user->user_login ?? '';
		}
		return $name ?: (string) $user_id;
	}

	public function log_post_change(int $post_id, \WP_Post $post, bool $update): void {
		$action = $update ? 'update_post' : 'create_post';
		$this->log($action, 'post', ['post_id' => $post_id, 'post_type' => $post->post_type], 'cms');
	}

	public function log_post_delete(int $post_id): void {
		$post = get_post($post_id);
		if (! $post) {
			return;
		}
		$this->log('delete_post', 'post', ['post_id' => $post_id, 'post_type' => $post->post_type], 'cms');
	}

	public function log_term_change(int $term_id, int $tt_id, string $taxonomy): void {
		$this->log('term_change', 'term', ['term_id' => $term_id, 'taxonomy' => $taxonomy], 'cms');
	}

	public function log_updates($upgrader, array $hook_extra): void {
		$type = $hook_extra['type'] ?? 'core';
		$action = $hook_extra['action'] ?? 'update';
		$this->log('update_' . $type, 'update', ['type' => $type, 'action' => $action, 'result' => $hook_extra], 'update');
	}
}
