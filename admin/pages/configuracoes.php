<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'dc_manage' ) ) {
    wp_die( 'Acesso negado.' );
}

$opcoes = get_option( 'dc_opcoes', [] );
$salvo  = false;
$erro   = '';

// Handle save.
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset( $_POST['dc_config_nonce'] ) &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dc_config_nonce'] ) ), 'dc_save_config' )
) {
    $opcoes['apagar_dados'] = ! empty( $_POST['apagar_dados'] ) ? 1 : 0;

    if ( update_option( 'dc_opcoes', $opcoes ) || true ) {
        $salvo = true;
    }
}
?>
<div class="wrap dc-wrap">
    <h1>Diário da Clínica — Configurações</h1>

    <?php if ( $salvo ) : ?>
        <div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>
    <?php endif; ?>

    <div class="dc-card">
        <form method="POST">
            <?php wp_nonce_field( 'dc_save_config', 'dc_config_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Dados ao desinstalar</th>
                    <td>
                        <label>
                            <input type="checkbox" name="apagar_dados" value="1"
                                <?php checked( ! empty( $opcoes['apagar_dados'] ) ); ?>>
                            Apagar todos os dados (<code>wp_clinica_relatorios</code>) ao desinstalar o plugin
                        </label>
                        <p class="description">
                            Se desmarcado, os dados permanecem no banco mesmo após desinstalar.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Versão do plugin</th>
                    <td><?php echo esc_html( DC_VERSION ); ?></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Salvar configurações</button>
            </p>
        </form>
    </div>

    <div class="dc-card">
        <h2>Diagnóstico</h2>
        <?php
        global $wpdb;
        $table  = $wpdb->prefix . DC_TABLE;
        $existe = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        $total  = $existe ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
        ?>
        <table class="form-table">
            <tr>
                <th>Tabela</th>
                <td><code><?php echo esc_html( $table ); ?></code>
                    <?php echo $existe ? '<span style="color:green">✔ existe</span>' : '<span style="color:red">✘ não existe</span>'; ?>
                </td>
            </tr>
            <tr>
                <th>Registros</th>
                <td><?php echo esc_html( $total ); ?></td>
            </tr>
            <tr>
                <th>WordPress</th>
                <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
            </tr>
            <tr>
                <th>PHP</th>
                <td><?php echo esc_html( PHP_VERSION ); ?></td>
            </tr>
        </table>
    </div>
</div>
