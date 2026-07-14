<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$opcoes = get_option( 'dc_opcoes', [] );
if ( ! empty( $opcoes['apagar_dados'] ) ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clinica_relatorios" );
    delete_option( 'dc_opcoes' );
    delete_option( 'dc_version' );
}
