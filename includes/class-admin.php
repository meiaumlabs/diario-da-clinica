<?php
defined( 'ABSPATH' ) || exit;

class DC_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'registrar_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function registrar_menu(): void {
        $cap = 'dc_manage';

        add_menu_page(
            'Diário da Clínica',
            'Diário da Clínica',
            $cap,
            'dc-diario',
            [ $this, 'page_novo_registro' ],
            'dashicons-clipboard',
            30
        );

        add_submenu_page(
            'dc-diario', 'Novo Registro',       'Novo Registro',
            $cap, 'dc-diario',      [ $this, 'page_novo_registro' ]
        );
        add_submenu_page(
            'dc-diario', 'Registros',            'Registros',
            $cap, 'dc-registros',   [ $this, 'page_registros' ]
        );
        add_submenu_page(
            'dc-diario', 'Relatórios/Gráficos', 'Relatórios/Gráficos',
            $cap, 'dc-relatorios',  [ $this, 'page_relatorios' ]
        );
        add_submenu_page(
            'dc-diario', 'Configurações',        'Configurações',
            $cap, 'dc-config',      [ $this, 'page_configuracoes' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        // Our pages all carry 'dc-' in the hook string.
        if ( false === strpos( $hook, 'dc-' ) ) {
            return;
        }

        wp_enqueue_style(
            'dc-admin',
            DC_PLUGIN_URL . 'admin/css/admin.css',
            [],
            DC_VERSION
        );

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'dc-admin',
            DC_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery', 'chartjs' ],
            DC_VERSION,
            true
        );

        wp_localize_script( 'dc-admin', 'DC', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dc_nonce' ),
        ] );
    }

    public function page_novo_registro(): void {
        require DC_PLUGIN_DIR . 'admin/pages/novo-registro.php';
    }

    public function page_registros(): void {
        require DC_PLUGIN_DIR . 'admin/pages/registros.php';
    }

    public function page_relatorios(): void {
        require DC_PLUGIN_DIR . 'admin/pages/relatorios.php';
    }

    public function page_configuracoes(): void {
        require DC_PLUGIN_DIR . 'admin/pages/configuracoes.php';
    }

    /** AJAX: recebe texto colado, parseia e salva. */
    public static function ajax_processar(): void {
        check_ajax_referer( 'dc_nonce', 'nonce' );

        if ( ! current_user_can( 'dc_manage' ) ) {
            wp_send_json_error( [ 'msg' => 'Sem permissão.' ], 403 );
        }

        // Unslash before sanitize to preserve characters like quotes.
        $texto        = sanitize_textarea_field( wp_unslash( $_POST['texto']        ?? '' ) );
        $sobrescrever = isset( $_POST['sobrescrever'] ) && '1' === $_POST['sobrescrever'];

        if ( $texto === '' ) {
            wp_send_json_error( [ 'msg' => 'Texto vazio.' ] );
        }

        $parsed = DC_Parser::parse( $texto );

        if ( empty( $parsed['data'] ) ) {
            wp_send_json_error( [
                'msg' => 'Data de fechamento não encontrada. Certifique-se de que o texto contém "Fechamento do dia DD/MM/AAAA".',
            ] );
        }

        $result = DC_DB::salvar( $parsed['data'], $parsed['campos'], $texto, $sobrescrever );

        if ( ! $result['ok'] && $result['duplicado'] ) {
            wp_send_json_error( [
                'msg'       => "Já existe um registro para {$parsed['data']}. Deseja sobrescrever?",
                'duplicado' => true,
                'data'      => $parsed['data'],
                'campos'    => $parsed['campos'],
                'avisos'    => $parsed['avisos'],
            ] );
        }

        if ( ! $result['ok'] ) {
            wp_send_json_error( [
                'msg' => 'Erro ao salvar: ' . esc_html( $result['msg'] ),
            ] );
        }

        wp_send_json_success( [
            'msg'    => "Registro de {$parsed['data']} salvo com sucesso (ID #{$result['id']}).",
            'campos' => $parsed['campos'],
            'avisos' => $parsed['avisos'],
            'id'     => $result['id'],
        ] );
    }
}
