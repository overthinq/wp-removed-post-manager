<?php

namespace RemovedPostManager;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Rimuove e ripristina contenuti in massa. Serve un utente con permessi di modifica: --user=<id>.
 */
final class Cli {

	public function __construct( private RemovedPosts $posts ) {}

	/**
	 * Rimuove i contenuti elencati in un CSV: prima colonna URL o ID, intestazione "url" facoltativa.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Percorso del CSV.
	 *
	 * [--dry-run]
	 * : Mostra l'esito senza modificare nulla.
	 *
	 * [--missing-file=<path>]
	 * : Scrive in questo file le voci senza un contenuto corrispondente.
	 *
	 * ## EXAMPLES
	 *
	 *     wp removed-posts import rimossi.csv --user=1 --dry-run
	 *     wp removed-posts import rimossi.csv --user=1 --missing-file=non-trovati.txt
	 */
	public function import( array $args, array $assoc_args ): void {
		[ $file ] = $args;
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "File non leggibile: $file" );
		}

		$dry_run  = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$total    = iterator_count( Import::read_csv( $file ) );
		$progress = Utils\make_progress_bar( $dry_run ? 'Simulazione' : 'Import', $total );
		$import   = new Import( $this->posts, $dry_run );
		$done     = 0;

		$this->in_bulk(
			static fn() => $import->run(
				Import::read_csv( $file ),
				static function () use ( $progress, &$done ): void {
					$progress->tick();
					// Sugli elenchi lunghi la cache in memoria crescerebbe senza limiti.
					++$done;
					if ( 0 === $done % 200 ) {
						wp_cache_flush_runtime();
					}
				}
			)
		);
		$progress->finish();

		$missing_file = Utils\get_flag_value( $assoc_args, 'missing-file' );
		if ( $missing_file && $import->missing ) {
			file_put_contents( $missing_file, implode( "\n", $import->missing ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		WP_CLI::success( $import->summary() . ( $dry_run ? ' Simulazione: nessuna modifica.' : '' ) );
	}

	/**
	 * Ripristina i contenuti rimossi allo stato che avevano prima.
	 *
	 * ## OPTIONS
	 *
	 * --post-type=<types>
	 * : Post type separati da virgola, oppure "any".
	 *
	 * [--dry-run]
	 * : Elenca i contenuti senza ripristinarli.
	 *
	 * ## EXAMPLES
	 *
	 *     wp removed-posts restore --post-type=page --user=1 --dry-run
	 *     wp removed-posts restore --post-type=any --user=1
	 */
	public function restore( array $args, array $assoc_args ): void {
		$types   = $assoc_args['post-type'];
		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$ids     = get_posts(
			array(
				'post_type'        => 'any' === $types ? $this->posts->post_types() : explode( ',', $types ),
				'post_status'      => RemovedPosts::STATUS,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);

		if ( $dry_run ) {
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '#%d %s', $id, get_the_title( $id ) ) );
			}
			WP_CLI::success( sprintf( 'Da ripristinare: %d. Simulazione: nessuna modifica.', count( $ids ) ) );
			return;
		}

		$restored = 0;
		$this->in_bulk(
			function () use ( $ids, &$restored ): void {
				foreach ( $ids as $i => $id ) {
					$restored += true === $this->posts->restore( $id ) ? 1 : 0;
					if ( 0 === ( $i + 1 ) % 200 ) {
						wp_cache_flush_runtime();
					}
				}
			}
		);
		WP_CLI::success( sprintf( 'Ripristinati: %d di %d.', $restored, count( $ids ) ) );
	}

	/**
	 * Rinvia il ricalcolo dei conteggi di termini e commenti alla fine dell'operazione.
	 */
	private function in_bulk( callable $work ): void {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		$work();
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
	}
}
