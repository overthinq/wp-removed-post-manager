<?php

namespace RemovedPostManager;

use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lo stato Removed: il contenuto resta nel database, ripristinabile, ma i suoi URL rispondono 410.
 * Alla rimozione si salvano stato precedente, data e autore; i percorsi vanno in GonePaths.
 */
final class RemovedPosts {

	// Lo stato di un post ha un limite di 20 caratteri.
	public const STATUS = 'rpm_removed';

	public const META_PREVIOUS_STATUS = '_rpm_previous_status';

	public const META_REMOVED_AT = '_rpm_removed_at';

	public const META_REMOVED_BY = '_rpm_removed_by';

	public function __construct( private GonePaths $paths ) {}

	public static function register_status(): void {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Rimosso', 'stato del post', 'wp-removed-post-manager' ),
				/* translators: %s: numero di contenuti. */
				'label_count'               => _n_noop( 'Rimossi <span class="count">(%s)</span>', 'Rimossi <span class="count">(%s)</span>', 'wp-removed-post-manager' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => false,
			)
		);
	}

	public function post_types(): array {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);
		unset( $types['attachment'] );

		return apply_filters( 'removed_post_manager_post_types', array_values( $types ) );
	}

	public function supports( ?WP_Post $post ): bool {
		return $post && in_array( $post->post_type, $this->post_types(), true );
	}

	/**
	 * @return bool|WP_Error true se il contenuto è stato rimosso, false se lo era già.
	 */
	public function remove( int $post_id, array $paths = array() ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $this->supports( $post ) ) {
			return new WP_Error( 'rpm_invalid_post', __( 'Contenuto non valido.', 'wp-removed-post-manager' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rpm_forbidden', __( 'Operazione non consentita.', 'wp-removed-post-manager' ) );
		}

		// Il permalink va letto prima del cambio di stato: dopo diventa /?p=ID.
		$paths[] = (string) get_permalink( $post );
		// I vecchi permalink salvati da Slim SEO: il suo redirect automatico porterebbe a /?p=ID.
		$paths = array_merge( $paths, get_post_meta( $post_id, '_ss_old_permalink', false ) );
		foreach ( apply_filters( 'removed_post_manager_removed_paths', $paths, $post ) as $path ) {
			$this->paths->add( $path, $post_id );
		}
		if ( self::STATUS === $post->post_status ) {
			return false;
		}

		update_post_meta( $post_id, self::META_PREVIOUS_STATUS, $post->post_status );
		update_post_meta( $post_id, self::META_REMOVED_AT, current_time( 'mysql' ) );
		update_post_meta( $post_id, self::META_REMOVED_BY, get_current_user_id() );

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => self::STATUS,
			),
			true
		);
		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Riporta il contenuto allo stato che aveva prima della rimozione, o a bozza.
	 */
	public function restore( int $post_id ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post || self::STATUS !== $post->post_status ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rpm_forbidden', __( 'Operazione non consentita.', 'wp-removed-post-manager' ) );
		}

		$status = get_post_meta( $post_id, self::META_PREVIOUS_STATUS, true );
		$status = get_post_status_object( $status ) && self::STATUS !== $status ? $status : 'draft';

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		foreach ( array( self::META_PREVIOUS_STATUS, self::META_REMOVED_AT, self::META_REMOVED_BY ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
		$this->paths->delete_post( $post_id );
		return true;
	}

	/**
	 * Rimuove il contenuto indicato da un URL o da un ID. In simulazione non modifica nulla.
	 *
	 * @return array{ImportResult, int} Esito e ID del contenuto.
	 */
	public function import( string $entry, bool $dry_run = false ): array {
		$entry = trim( $entry );
		$by_id = ctype_digit( $entry );
		if ( $by_id ) {
			// get_post(0) restituirebbe il post globale.
			$post = (int) $entry ? get_post( (int) $entry ) : null;
		} else {
			$path = GonePaths::normalize( $entry );
			if ( ! GonePaths::is_valid( $path ) ) {
				return array( ImportResult::Invalid, 0 );
			}
			$gone = $this->paths->find( $path );
			if ( $gone ) {
				return array( ImportResult::Already, (int) $gone['post_id'] );
			}
			$post = $this->find_post( $entry );
		}

		if ( ! $this->supports( $post ) ) {
			return array( ImportResult::NotFound, 0 );
		}

		$was_removed = self::STATUS === $post->post_status;
		if ( $was_removed && $by_id ) {
			return array( ImportResult::Already, $post->ID );
		}
		if ( ! $dry_run ) {
			$removed = $this->remove( $post->ID, $by_id ? array() : array( $entry ) );
			if ( is_wp_error( $removed ) ) {
				return array( ImportResult::Error, $post->ID );
			}
		}
		return array( $was_removed ? ImportResult::PathAdded : ImportResult::Removed, $post->ID );
	}

	/**
	 * Trova il contenuto di un URL, anche se già rimosso: url_to_postid(), poi il percorso,
	 * poi lo slug confrontato col permalink attuale.
	 */
	public function find_post( string $url ): ?WP_Post {
		$id = url_to_postid( $url );
		if ( $id ) {
			return get_post( $id );
		}

		$path = GonePaths::normalize( $url );
		$post = get_page_by_path( trim( $path, '/' ), OBJECT, $this->post_types() );
		if ( $post ) {
			return $post;
		}

		$slug = basename( $path );
		if ( '' === $slug ) {
			return null;
		}
		$query = new WP_Query(
			array(
				'name'                => sanitize_title( $slug ),
				'post_type'           => $this->post_types(),
				'post_status'         => array_values( get_post_stati() ),
				'posts_per_page'      => 10,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);
		foreach ( $query->posts as $candidate ) {
			if ( GonePaths::normalize( (string) get_permalink( $candidate ) ) === $path ) {
				return $candidate;
			}
		}
		return null;
	}

	public function removed_url( int $post_id ): string {
		return home_url( $this->paths->first_path( $post_id ) ?? '' );
	}
}
