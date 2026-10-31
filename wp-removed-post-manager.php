<?php
/**
 * Plugin Name:       Removed Post Manager
 * Description:       Segna i contenuti rimossi con uno stato dedicato e risponde 410 Gone ai loro URL.
 * Version:           0.3.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-removed-post-manager
 */

namespace RemovedPostManager;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			require __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
		}
	}
);

register_activation_hook( __FILE__, array( GonePaths::class, 'install' ) );

add_action( 'init', array( RemovedPosts::class, 'register_status' ) );
add_action( 'admin_init', array( GonePaths::class, 'maybe_install' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		$paths = new GonePaths();
		$posts = new RemovedPosts( $paths );
		( new Frontend( $paths ) )->hook();
		add_action( 'deleted_post', array( $paths, 'detach_post' ) );

		if ( is_admin() ) {
			( new Admin\PostActions( $posts ) )->hook();
			( new Admin\ToolsPage( $posts, $paths, __FILE__ ) )->hook();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'removed-posts', new Cli( $posts ) );
		}
	}
);
