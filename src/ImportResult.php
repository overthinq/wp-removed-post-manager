<?php

namespace RemovedPostManager;

defined( 'ABSPATH' ) || exit;

enum ImportResult: string {

	case Removed   = 'removed';
	case PathAdded = 'path_added';
	case Already   = 'already';
	case NotFound  = 'not_found';
	case Invalid   = 'invalid';
	case Error     = 'error';

	public function label(): string {
		return match ( $this ) {
			self::Removed   => __( 'Rimosso', 'wp-removed-post-manager' ),
			self::PathAdded => __( 'Già rimosso, URL aggiunto', 'wp-removed-post-manager' ),
			self::Already   => __( 'Già rimosso', 'wp-removed-post-manager' ),
			self::NotFound  => __( 'Non trovato', 'wp-removed-post-manager' ),
			self::Invalid   => __( 'URL non valido', 'wp-removed-post-manager' ),
			self::Error     => __( 'Errore', 'wp-removed-post-manager' ),
		};
	}
}
