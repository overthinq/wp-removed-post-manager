<?php

namespace RemovedPostManager\Admin;

use RemovedPostManager\GonePaths;
use RemovedPostManager\Import;
use RemovedPostManager\ImportResult;
use RemovedPostManager\RemovedPosts;

defined( 'ABSPATH' ) || exit;

/**
 * Strumenti → Contenuti rimossi: elenco delle 410, 410 manuali, import massivo ed export CSV.
 */
final class ToolsPage {

	private const SLUG = 'rpm-removed-posts';

	// Le 410 manuali valgono per tutto il sito: le gestisce chi può modificare i contenuti altrui.
	private const CAPABILITY = 'edit_others_posts';

	public function __construct( private RemovedPosts $posts, private GonePaths $gone, private string $file ) {}

	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_rpm_gone_save', array( $this, 'save_gone' ) );
		add_action( 'admin_post_rpm_gone_delete', array( $this, 'delete_gone' ) );
		add_action( 'admin_post_rpm_gone_export', array( $this, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'action_link' ) );
	}

	public function add_page(): void {
		$hook = add_management_page( __( 'Contenuti rimossi', 'wp-removed-post-manager' ), __( 'Contenuti rimossi', 'wp-removed-post-manager' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
		// Le azioni di gruppo dell'elenco reindirizzano: vanno gestite prima dell'output.
		add_action( "load-{$hook}", array( $this, 'bulk_delete' ) );
	}

	public function action_link( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $this->url() ), esc_html__( 'Elenco 410', 'wp-removed-post-manager' ) ) );
		return $links;
	}

	public function save_gone(): void {
		$this->authorize();
		check_admin_referer( 'rpm_gone_save' );
		$result = $this->gone->save(
			sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
			absint( $_POST['id'] ?? 0 )
		);
		$this->back( is_wp_error( $result ) ? array( 'rpm_error' => $result->get_error_message() ) : array( 'rpm_gone' => 'saved' ) );
	}

	public function delete_gone(): void {
		$this->authorize();
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( "rpm_gone_delete_{$id}" );
		$this->gone->delete( $id );
		$this->back( array( 'rpm_gone' => 'deleted' ) );
	}

	public function bulk_delete(): void {
		if ( 'rpm_gone_delete' !== ( $_POST['action'] ?? '' ) && 'rpm_gone_delete' !== ( $_POST['action2'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$this->authorize();
		check_admin_referer( 'bulk-rpm_gone' );
		foreach ( array_map( 'absint', (array) wp_unslash( $_POST['gone_ids'] ?? array() ) ) as $id ) {
			$this->gone->delete( $id );
		}
		$this->back( array( 'rpm_gone' => 'deleted' ) );
	}

	public function export(): void {
		$this->authorize();
		check_admin_referer( 'rpm_gone_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="410-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $output, array( 'url', 'post_id', 'note', 'created_at' ), ',', '"', '' );
		foreach ( $this->gone->rows( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ) as $row ) {
			fputcsv( $output, array( home_url( $row['path'] ), $row['post_id'], $row['note'], $row['created_at'] ), ',', '"', '' );
		}
		exit;
	}

	public function render(): void {
		$tab = 'import' === ( $_GET['tab'] ?? '' ) ? 'import' : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contenuti rimossi', 'wp-removed-post-manager' ); ?></h1>
			<?php $this->notice(); ?>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->url() ); ?>" class="nav-tab <?php echo 'list' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Elenco 410', 'wp-removed-post-manager' ); ?></a>
				<a href="<?php echo esc_url( $this->url( array( 'tab' => 'import' ) ) ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Import', 'wp-removed-post-manager' ); ?></a>
			</nav>
			<?php 'import' === $tab ? $this->render_import() : $this->render_list(); ?>
		</div>
		<?php
	}

	private function render_list(): void {
		$edit_id = absint( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = $edit_id ? $this->gone->get( $edit_id ) : null;
		$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table   = new GoneTable( $this->gone, $this->url() );
		$table->prepare_items();
		?>
		<h2><?php echo $editing ? esc_html__( 'Modifica 410 manuale', 'wp-removed-post-manager' ) : esc_html__( 'Aggiungi 410 manuale', 'wp-removed-post-manager' ); ?></h2>
		<p><?php esc_html_e( 'Per URL senza più un contenuto, per esempio articoli cancellati prima di usare il plugin. Un redirect impostato altrove sullo stesso URL vince sulla 410.', 'wp-removed-post-manager' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'rpm_gone_save' ); ?>
			<input type="hidden" name="action" value="rpm_gone_save">
			<input type="hidden" name="id" value="<?php echo (int) ( $editing['id'] ?? 0 ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rpm-path"><?php esc_html_e( 'URL o percorso', 'wp-removed-post-manager' ); ?></label></th>
					<td><input type="text" name="path" id="rpm-path" class="large-text code" value="<?php echo esc_attr( $editing['path'] ?? '' ); ?>" placeholder="/vecchio-articolo/" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="rpm-note"><?php esc_html_e( 'Nota', 'wp-removed-post-manager' ); ?></label></th>
					<td><textarea name="note" id="rpm-note" class="large-text" rows="2"><?php echo esc_textarea( $editing['note'] ?? '' ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( $editing ? __( 'Aggiorna', 'wp-removed-post-manager' ) : __( 'Aggiungi', 'wp-removed-post-manager' ) ); ?>
		</form>

		<h2>
			<?php esc_html_e( 'Tutte le 410', 'wp-removed-post-manager' ); ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rpm_gone_export&s=' . rawurlencode( $search ) ), 'rpm_gone_export' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Esporta CSV', 'wp-removed-post-manager' ); ?></a>
		</h2>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php $table->search_box( __( 'Cerca', 'wp-removed-post-manager' ), 'rpm-gone' ); ?>
		</form>
		<form method="post">
			<?php $table->display(); ?>
		</form>
		<?php
	}

	private function render_import(): void {
		$import = null;
		$log    = array();
		if ( isset( $_POST['rpm_import'] ) ) {
			check_admin_referer( 'rpm_import' );
			$entries = preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['entries'] ?? '' ) ) );
			$file    = $_FILES['csv']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- percorso temporaneo di PHP, controllato con is_uploaded_file().
			if ( $file && is_uploaded_file( $file ) ) {
				$entries = array_merge( $entries, iterator_to_array( Import::read_csv( $file ), false ) );
			}

			$import = new Import( $this->posts );
			$import->run(
				array_unique( array_filter( array_map( 'trim', $entries ) ) ),
				static function ( string $entry, ImportResult $result, int $post_id ) use ( &$log ): void {
					$log[] = $result->label() . ': ' . $entry . ( $post_id ? " (#$post_id)" : '' );
				}
			);
		}
		?>
		<p><?php esc_html_e( 'Rimuove i contenuti indicati da URL o ID. Gli URL senza contenuto vengono solo segnalati: aggiungili come 410 manuali se servono.', 'wp-removed-post-manager' ); ?></p>
		<?php if ( $import ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $import->summary() ); ?></p></div>
			<textarea readonly class="large-text code" rows="10"><?php echo esc_textarea( implode( "\n", $log ) ); ?></textarea>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'rpm_import' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rpm-entries"><?php esc_html_e( 'URL o ID', 'wp-removed-post-manager' ); ?></label></th>
					<td><textarea name="entries" id="rpm-entries" class="large-text code" rows="8" placeholder="https://example.com/vecchio-articolo/"></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="rpm-csv"><?php esc_html_e( 'CSV', 'wp-removed-post-manager' ); ?></label></th>
					<td>
						<input type="file" name="csv" id="rpm-csv" accept=".csv,text/csv">
						<p class="description"><?php esc_html_e( 'Prima colonna: URL o ID. Un\'intestazione "url" è ammessa. Per elenchi lunghi usa wp removed-posts import.', 'wp-removed-post-manager' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Rimuovi i contenuti trovati', 'wp-removed-post-manager' ), 'primary', 'rpm_import' ); ?>
		</form>
		<?php
	}

	private function notice(): void {
		// Esito passato dal redirect: solo testo da mostrare, nessuna azione.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error = sanitize_text_field( wp_unslash( $_GET['rpm_error'] ?? '' ) );
		$done  = sanitize_key( $_GET['rpm_gone'] ?? '' );
		// phpcs:enable
		$message = match ( $done ) {
			'saved'   => __( '410 manuale salvata.', 'wp-removed-post-manager' ),
			'deleted' => __( '410 manuali eliminate.', 'wp-removed-post-manager' ),
			default   => '',
		};
		if ( $error || $message ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error ? 'error' : 'success', esc_html( $error ? $error : $message ) );
		}
	}

	private function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Operazione non consentita.', 'wp-removed-post-manager' ), 403 );
		}
	}

	private function back( array $args ): void {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	private function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'tools.php' ) );
	}
}
