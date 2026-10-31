<?php

namespace RemovedPostManager;

defined( 'ABSPATH' ) || exit;

/**
 * Risponde 410 Gone agli URL dei contenuti rimossi e alle 410 manuali.
 *
 * Un contenuto rimosso ha un permalink semplice (/?p=ID), come ogni stato non pubblico: i
 * redirect automatici dai vecchi URL lo porterebbero lì, a un 404. Per questo il controllo
 * gira prima di loro (priorità 9) e intercetta le vecchie slug del core; i redirect manuali
 * dei plugin SEO, a priorità più bassa, vincono comunque.
 */
final class Frontend {

	public function __construct( private GonePaths $paths ) {}

	public function hook(): void {
		add_action( 'template_redirect', array( $this, 'maybe_gone' ), 9 );
		add_filter( 'old_slug_redirect_post_id', array( $this, 'old_slug' ) );
	}

	public function maybe_gone(): void {
		// Un contenuto rimosso non è pubblico: il suo URL è già un 404, il controllo serve solo lì.
		if ( ! is_404() ) {
			return;
		}

		$gone = $this->paths->find( GonePaths::normalize( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) );
		if ( $gone ) {
			$this->respond( (int) $gone['post_id'] );
		}
	}

	public function old_slug( int $post_id ): int {
		if ( RemovedPosts::STATUS !== get_post_status( $post_id ) ) {
			return $post_id;
		}

		$this->respond( $post_id );
		return 0;
	}

	/**
	 * La richiesta resta un 404 per WordPress: il tema mostra il suo template 404, o un 410.php se lo fornisce.
	 */
	private function respond( int $post_id ): void {
		status_header( 410 );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		set_query_var( 'rpm_removed_post', $post_id );
		add_filter( '404_template_hierarchy', static fn( $templates ) => array_merge( array( '410.php' ), $templates ) );
		add_filter( 'pre_get_document_title', static fn() => __( 'Contenuto rimosso', 'wp-removed-post-manager' ) );
		add_filter( 'body_class', static fn( $classes ) => array_merge( array_diff( $classes, array( 'error404' ) ), array( 'error410' ) ) );
	}
}
