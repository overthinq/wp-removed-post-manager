<?php

namespace RemovedPostManager;

use Generator;

defined( 'ABSPATH' ) || exit;

/**
 * Import massivo condiviso da pagina admin e WP-CLI: un URL o un ID per voce.
 */
final class Import {

	/** @var array<string, int> */
	public array $counts = array();

	/** @var string[] */
	public array $missing = array();

	public function __construct( private RemovedPosts $posts, private bool $dry_run = false ) {}

	/**
	 * @param iterable<string>                         $entries
	 * @param callable(string, ImportResult, int):void $after_each
	 */
	public function run( iterable $entries, ?callable $after_each = null ): void {
		foreach ( $entries as $entry ) {
			[ $result, $post_id ] = $this->posts->import( $entry, $this->dry_run );

			$this->counts[ $result->value ] = ( $this->counts[ $result->value ] ?? 0 ) + 1;
			if ( ImportResult::NotFound === $result ) {
				$this->missing[] = $entry;
			}
			if ( $after_each ) {
				$after_each( $entry, $result, $post_id );
			}
		}
	}

	public function summary(): string {
		/* translators: %d: voci elaborate. */
		$parts = array( sprintf( __( 'Elaborati: %d', 'wp-removed-post-manager' ), array_sum( $this->counts ) ) );
		foreach ( ImportResult::cases() as $result ) {
			if ( isset( $this->counts[ $result->value ] ) ) {
				$parts[] = $result->label() . ': ' . $this->counts[ $result->value ];
			}
		}
		return implode( '. ', $parts ) . '.';
	}

	/**
	 * Prima colonna di un CSV; salta righe vuote, BOM e un'intestazione "url".
	 *
	 * @return Generator<string>
	 */
	public static function read_csv( string $file ): Generator {
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return;
		}

		$first = true;
		while ( false !== ( $row = fgetcsv( $handle, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$value = trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) ( $row[0] ?? '' ) ) );
			if ( '' !== $value && ! ( $first && 'url' === strtolower( $value ) ) ) {
				yield $value;
			}
			$first = false;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
