<?php

namespace RemovedPostManager;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tutti i percorsi che rispondono 410, in una tabella con indice univoco sull'hash del percorso:
 * quelli dei contenuti rimossi (post_id) e quelli manuali (post_id 0), per URL senza più un contenuto.
 */
final class GonePaths {

	private const DB_VERSION = '2';

	private const DB_VERSION_OPTION = 'rpm_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rpm_gone';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				path varchar(2048) NOT NULL,
				path_hash char(32) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note text NULL,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY path_hash (path_hash),
				KEY post_id (post_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Percorso relativo alla home, minuscolo, senza query né slash finale. "" se non valido.
	 */
	public static function normalize( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}

		$path = rawurldecode( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		$path = untrailingslashit( (string) preg_replace( '#/+#', '/', '/' . $path ) );
		return '' === $path ? '/' : strtolower( $path );
	}

	/**
	 * La home non risponde mai 410: anche i permalink a query string (/?p=123) si normalizzano in "/".
	 */
	public static function is_valid( string $path ): bool {
		return '' !== $path && '/' !== $path;
	}

	/**
	 * Registra un percorso di un contenuto rimosso; un percorso già presente resta com'è.
	 */
	public function add( string $url, int $post_id ): void {
		global $wpdb;

		$path = self::normalize( $url );
		if ( self::is_valid( $path ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (path, path_hash, post_id, note, created_at, created_by) VALUES (%s, %s, %d, '', %s, %d)", self::table(), $path, md5( $path ), $post_id, current_time( 'mysql' ), get_current_user_id() ) );
		}
	}

	/**
	 * Crea o modifica una 410 manuale.
	 */
	public function save( string $url, string $note, int $id = 0 ): int|WP_Error {
		global $wpdb;

		$path = self::normalize( $url );
		if ( ! self::is_valid( $path ) ) {
			return new WP_Error( 'rpm_gone_path', __( 'Percorso non valido.', 'wp-removed-post-manager' ) );
		}
		$existing = $this->find( $path );
		if ( $existing && (int) $existing['id'] !== $id ) {
			return new WP_Error(
				'rpm_gone_duplicate',
				$existing['post_id']
					? __( 'Questo URL risponde già 410 perché il suo contenuto è rimosso.', 'wp-removed-post-manager' )
					: __( 'Questo URL ha già una 410 manuale.', 'wp-removed-post-manager' )
			);
		}

		$row = array(
			'path'      => $path,
			'path_hash' => md5( $path ),
			'note'      => $note,
		);
		if ( $id ) {
			$saved = $wpdb->update(
				self::table(),
				$row,
				array(
					'id'      => $id,
					'post_id' => 0,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false === $saved ? $this->db_error() : $id;
		}

		$saved = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			$row + array(
				'created_at' => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
			)
		);
		return false === $saved ? $this->db_error() : $wpdb->insert_id;
	}

	/**
	 * Elimina una 410 manuale: quelle dei contenuti spariscono solo col ripristino.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'id'      => $id,
				'post_id' => 0,
			),
			array( '%d', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function delete_post( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Un contenuto rimosso cancellato per sempre lascia le sue 410 come manuali.
	 */
	public function detach_post( int $post_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'post_id' => 0 ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array{id: string, post_id: string}|null
	 */
	public function find( string $path ): ?array {
		global $wpdb;

		if ( ! self::is_valid( $path ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, post_id FROM %i WHERE path_hash = %s', self::table(), md5( $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND post_id = 0', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function first_path( int $post_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT path FROM %i WHERE post_id = %d ORDER BY id LIMIT 1', self::table(), $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array<int, array<string, string>> Dalla più recente.
	 */
	public function rows( string $search = '', int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$sql = $this->search_sql( 'SELECT *', $search ) . ' ORDER BY created_at DESC, id DESC';
		if ( $limit ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- search_sql() è preparata.
	}

	public function count( string $search = '' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $this->search_sql( 'SELECT COUNT(*)', $search ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- search_sql() è preparata.
	}

	private function search_sql( string $select, string $search ): string {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return $wpdb->prepare( "$select FROM %i WHERE path LIKE %s OR note LIKE %s", self::table(), $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select è una costante del chiamante.
	}

	private function db_error(): WP_Error {
		return new WP_Error( 'rpm_gone_db', __( 'Impossibile salvare la 410.', 'wp-removed-post-manager' ) );
	}
}
