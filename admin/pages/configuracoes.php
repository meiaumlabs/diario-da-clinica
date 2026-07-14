<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'dc_manage' ) ) {
    wp_die( 'Acesso negado.' );
}

$opcoes     = get_option( 'dc_opcoes', [] );
$salvo      = false;
$erro       = '';
$senha_salva = false;
$senha_erro  = '';

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

    // Troca de senha do painel público.
    if ( ! empty( $_POST['dc_nova_senha'] ) ) {
        $nova  = wp_unslash( $_POST['dc_nova_senha'] );
        $conf  = wp_unslash( $_POST['dc_conf_senha'] ?? '' );

        if ( $nova !== $conf ) {
            $senha_erro = 'As senhas não coincidem.';
        } elseif ( strlen( $nova ) < 6 ) {
            $senha_erro = 'A senha deve ter pelo menos 6 caracteres.';
        } else {
            update_option( 'dc_painel_senha_hash', password_hash( $nova, PASSWORD_BCRYPT ), false );
            $senha_salva = true;
        }
    }
}
?>
<div class="wrap dc-wrap">
    <h1>Diário da Clínica — Configurações</h1>

    <?php if ( $salvo ) : ?>
        <div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>
    <?php endif; ?>
    <?php if ( $senha_salva ) : ?>
        <div class="notice notice-success is-dismissible"><p>Senha do painel público atualizada.</p></div>
    <?php endif; ?>
    <?php if ( $senha_erro ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $senha_erro ); ?></p></div>
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
                <tr>
                    <th scope="row">Senha do painel público</th>
                    <td>
                        <fieldset>
                            <p><label for="dc-nova-senha"><strong>Nova senha</strong></label><br>
                            <input type="password" name="dc_nova_senha" id="dc-nova-senha" autocomplete="new-password" class="regular-text">
                            </p>
                            <p><label for="dc-conf-senha"><strong>Confirmar nova senha</strong></label><br>
                            <input type="password" name="dc_conf_senha" id="dc-conf-senha" autocomplete="new-password" class="regular-text">
                            </p>
                        </fieldset>
                        <p class="description">
                            Deixe em branco para manter a senha atual.<br>
                            Shortcode: <code>[diario_clinica_painel]</code>
                        </p>
                    </td>
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
