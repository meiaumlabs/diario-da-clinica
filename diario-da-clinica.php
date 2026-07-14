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

// Public shortcode [relatorio_diario_form] (fase 4 — protegido por capability dc_manage).
add_shortcode( 'relatorio_diario_form', 'dc_shortcode_relatorio_form' );

function dc_shortcode_relatorio_form(): string {
    if ( ! current_user_can( 'dc_manage' ) ) {
        return '<p class="dc-aviso-permissao">' .
               esc_html( 'Você não tem permissão para acessar este formulário.' ) .
               '</p>';
    }

    wp_enqueue_style(
        'dc-admin',
        DC_PLUGIN_URL . 'admin/css/admin.css',
        [],
        DC_VERSION
    );
    wp_enqueue_script(
        'dc-admin',
        DC_PLUGIN_URL . 'admin/js/admin.js',
        [ 'jquery' ],
        DC_VERSION,
        true
    );
    wp_localize_script( 'dc-admin', 'DC', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'dc_nonce' ),
    ] );

    ob_start();
    ?>
    <div class="dc-wrap dc-shortcode-form">
        <div class="dc-card">
            <h2>Novo Registro — Diário da Clínica</h2>
            <textarea
                id="dc-texto"
                rows="18"
                style="width:100%;font-family:monospace;"
                placeholder="Fechamento do dia DD/MM/AAAA&#10;Origem LEADS&#10;👥 Indicação de paciente: 0&#10;..."
            ></textarea>
            <div class="dc-actions" style="margin-top:8px;">
                <button id="dc-btn-processar" type="button" class="button button-primary">
                    Processar
                </button>
                <span id="dc-spinner" class="spinner dc-spinner"></span>
            </div>
        </div>
        <div id="dc-resultado" style="display:none;" class="dc-card">
            <div id="dc-msg-area"></div>
            <div id="dc-preview-wrap" style="display:none;">
                <h3 id="dc-preview-titulo"></h3>
                <table class="dc-table widefat striped dc-preview-table">
                    <thead><tr><th>Campo</th><th>Valor</th></tr></thead>
                    <tbody id="dc-preview-tbody"></tbody>
                </table>
                <div id="dc-avisos-wrap" style="display:none;">
                    <h4>Avisos</h4>
                    <ul id="dc-avisos-lista" class="dc-avisos"></ul>
                </div>
                <div class="dc-actions" id="dc-confirm-wrap" style="display:none;">
                    <button id="dc-btn-sobrescrever" type="button" class="button button-primary">
                        Sobrescrever registro existente
                    </button>
                    <button id="dc-btn-cancelar" type="button" class="button">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
