<?php

namespace RemovedPostManager\Admin;

use RemovedPostManager\RemovedPosts;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Rimozione e ripristino dalle liste dei contenuti: azioni sulla riga e azioni di gruppo.
 */
final class PostActions {

	private const REMOVE = 'rpm_remove';

	private const RESTORE = 'rpm_restore';

	public function __construct( private RemovedPosts $posts ) {}

	public function hook(): void {
		add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );
		add_action( 'admin_post_' . self::REMOVE, array( $this, 'handle_single' ) );
		add_action( 'admin_post_' . self::RESTORE, array( $this, 'handle_single' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function register_bulk_actions(): void {
		foreach ( $this->posts->post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk' ), 10, 3 );
		}
	}

	public function row_actions( array $actions, WP_Post $post ): array {
		if ( ! $this->posts->supports( $post ) || ! current_user_can( 'edit_post', $post->ID ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return $actions;
		}

		if ( RemovedPosts::STATUS !== $post->post_status ) {
			$actions[ self::REMOVE ] = $this->link( self::REMOVE, $post->ID, __( 'Rimuovi (410)', 'wp-removed-post-manager' ) );
			return $actions;
		}

		unset( $actions['view'], $actions['inline hide-if-no-js'] );
		$actions['rpm_url']       = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $this->posts->removed_url( $post->ID ) ), esc_html__( 'URL', 'wp-removed-post-manager' ) );
		$actions[ self::RESTORE ] = $this->link( self::RESTORE, $post->ID, __( 'Ripristina', 'wp-removed-post-manager' ) );
		return $actions;
	}

	public function post_states( array $states, WP_Post $post ): array {
		if ( RemovedPosts::STATUS === $post->post_status ) {
			$states[ RemovedPosts::STATUS ] = _x( 'Rimosso', 'stato del post', 'wp-removed-post-manager' );
		}
		return $states;
	}

	public function bulk_actions( array $actions ): array {
		$actions[ self::REMOVE ]  = __( 'Rimuovi (410)', 'wp-removed-post-manager' );
		$actions[ self::RESTORE ] = __( 'Ripristina da rimossi', 'wp-removed-post-manager' );
		return $actions;
	}

	public function handle_bulk( string $redirect, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( self::REMOVE, self::RESTORE ), true ) ) {
			return $redirect;
		}

		$changed = 0;
		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			$result   = self::REMOVE === $action ? $this->posts->remove( $post_id ) : $this->posts->restore( $post_id );
			$changed += true === $result ? 1 : 0;
		}
		return add_query_arg(
			array(
				'rpm_done'  => $action,
				'rpm_count' => $changed,
			),
			$redirect
		);
	}

	public function handle_single(): void {
		$action  = str_replace( 'admin_post_', '', current_action() );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		check_admin_referer( "{$action}_{$post_id}" );

		$result = self::REMOVE === $action ? $this->posts->remove( $post_id ) : $this->posts->restore( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}

		$redirect = admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'rpm_done'  => $action,
					'rpm_count' => (int) $result,
				),
				$redirect
			)
		);
		exit;
	}

	public function notice(): void {
		// Esito passato dal redirect: solo testo da mostrare, nessuna azione.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( $_GET['rpm_done'] ?? '' );
		$count  = absint( $_GET['rpm_count'] ?? 0 );
		// phpcs:enable
		if ( ! in_array( $action, array( self::REMOVE, self::RESTORE ), true ) ) {
			return;
		}

		$message = self::REMOVE === $action
			/* translators: %d: numero di contenuti. */
			? _n( '%d contenuto rimosso.', '%d contenuti rimossi.', $count, 'wp-removed-post-manager' )
			/* translators: %d: numero di contenuti. */
			: _n( '%d contenuto ripristinato.', '%d contenuti ripristinati.', $count, 'wp-removed-post-manager' );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( $message, $count ) ) );
	}

	private function link( string $action, int $post_id, string $label ): string {
		$url = wp_nonce_url( admin_url( "admin-post.php?action={$action}&post_id={$post_id}" ), "{$action}_{$post_id}" );
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}
}
