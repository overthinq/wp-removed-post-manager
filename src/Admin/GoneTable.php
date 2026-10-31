<?php

namespace RemovedPostManager\Admin;

use RemovedPostManager\GonePaths;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Elenco delle 410: quelle dei contenuti rimossi si gestiscono dal contenuto, le manuali da qui.
 */
final class GoneTable extends WP_List_Table {

	private const PER_PAGE = 50;

	public function __construct( private GonePaths $gone, private string $page_url ) {
		parent::__construct(
			array(
				'singular' => 'rpm_gone',
				'plural'   => 'rpm_gone',
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'path'       => __( 'Percorso', 'wp-removed-post-manager' ),
			'post'       => __( 'Contenuto', 'wp-removed-post-manager' ),
			'note'       => __( 'Nota', 'wp-removed-post-manager' ),
			'created_at' => __( 'Data', 'wp-removed-post-manager' ),
		);
	}

	protected function get_bulk_actions(): array {
		return array( 'rpm_gone_delete' => __( 'Elimina le 410 manuali', 'wp-removed-post-manager' ) );
	}

	public function no_items(): void {
		esc_html_e( 'Nessuna 410.', 'wp-removed-post-manager' );
	}

	public function prepare_items(): void {
		$search      = sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total       = $this->gone->count( $search );
		$this->items = $this->gone->rows( $search, self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE );
		// L'header dell'admin legge le colonne prima che la tabella esista e il core tiene quella risposta.
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'path' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	protected function column_cb( $item ): string {
		return $item['post_id'] ? '' : sprintf( '<input type="checkbox" name="gone_ids[]" value="%d">', $item['id'] );
	}

	protected function column_path( array $item ): string {
		if ( $item['post_id'] ) {
			return esc_html( $item['path'] );
		}

		$delete = wp_nonce_url( admin_url( 'admin-post.php?action=rpm_gone_delete&id=' . $item['id'] ), 'rpm_gone_delete_' . $item['id'] );
		return esc_html( $item['path'] ) . $this->row_actions(
			array(
				'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'edit', $item['id'], $this->page_url ) ), esc_html__( 'Modifica', 'wp-removed-post-manager' ) ),
				'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete ), esc_html__( 'Elimina', 'wp-removed-post-manager' ) ),
			)
		);
	}

	protected function column_post( array $item ): string {
		if ( ! $item['post_id'] ) {
			return esc_html__( 'Manuale', 'wp-removed-post-manager' );
		}

		$title = get_the_title( (int) $item['post_id'] );
		return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( (int) $item['post_id'] ) ), esc_html( '' !== $title ? $title : '#' . $item['post_id'] ) );
	}

	protected function column_note( array $item ): string {
		return esc_html( $item['note'] );
	}

	protected function column_created_at( array $item ): string {
		return $item['created_at'] ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) ) : '';
	}
}
