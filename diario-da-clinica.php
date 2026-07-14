<?php
/**
 * Plugin Name: Diário da Clínica
 * Description: Recebe e interpreta o relatório diário de fechamento da recepção, armazena de forma estruturada e gera relatórios consolidados com gráficos e exportação.
 * Version:     1.0.0
 * Author:      Meia Um Labs
 * License:     GPL-2.0+
 * Text Domain: diario-da-clinica
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'DC_VERSION',    '1.0.0' );
define( 'DC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DC_TABLE',      'clinica_relatorios' );

require_once DC_PLUGIN_DIR . 'includes/class-activator.php';
require_once DC_PLUGIN_DIR . 'includes/class-parser.php';
require_once DC_PLUGIN_DIR . 'includes/class-db.php';
require_once DC_PLUGIN_DIR . 'includes/class-export.php';
require_once DC_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(   __FILE__, [ 'DC_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DC_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        new DC_Admin();
    }
} );

// AJAX handlers.
add_action( 'wp_ajax_dc_processar', [ 'DC_Admin', 'ajax_processar' ] );

// Export handlers via admin-post.php.
add_action( 'admin_post_dc_export_csv', [ 'DC_Export', 'handle_export_csv' ] );
add_action( 'admin_post_dc_export_pdf', [ 'DC_Export', 'handle_export_pdf' ] );
