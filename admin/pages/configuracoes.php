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

    // Cores do painel público (deixar em branco / restaurar = paleta padrão clean).
    if ( ! empty( $_POST['cor_reset'] ) ) {
        $opcoes['cor_primaria']   = '';
        $opcoes['cor_secundaria'] = '';
    } else {
        $opcoes['cor_primaria']   = sanitize_hex_color( wp_unslash( $_POST['cor_primaria']   ?? '' ) ) ?? '';
        $opcoes['cor_secundaria'] = sanitize_hex_color( wp_unslash( $_POST['cor_secundaria'] ?? '' ) ) ?? '';
    }

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

    <?php
    // Feedback da importação (redirecionamento de admin-post).
    if ( isset( $_GET['dc_imp'] ) ) :
        $imp_ok  = '1' === sanitize_text_field( wp_unslash( $_GET['dc_imp'] ) );
        $imp_qtd = isset( $_GET['imp'] ) ? (int) $_GET['imp'] : 0;
        $imp_err = isset( $_GET['err'] ) ? (int) $_GET['err'] : 0;
        $imp_msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        if ( $imp_ok ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( sprintf( 'Importação concluída: %d registro(s) importado(s)%s.', $imp_qtd, $imp_err ? sprintf( ', %d linha(s) ignorada(s)', $imp_err ) : '' ) ); ?></p>
            </div>
        <?php else : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( 'Falha na importação' . ( $imp_msg ? ': ' . $imp_msg : '.' ) ); ?></p>
            </div>
        <?php endif;
    endif;
    ?>

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
                    <th scope="row">Cores do painel público</th>
                    <td>
                        <?php
                        $cor_primaria   = $opcoes['cor_primaria']   ?? '';
                        $cor_secundaria = $opcoes['cor_secundaria'] ?? '';
                        ?>
                        <p style="margin:0 0 10px;">
                            <label style="display:inline-block;min-width:190px;">Cor primária (cabeçalho/destaque)</label>
                            <input type="color" name="cor_primaria" value="<?php echo esc_attr( $cor_primaria ?: '#3f5170' ); ?>">
                        </p>
                        <p style="margin:0 0 10px;">
                            <label style="display:inline-block;min-width:190px;">Cor de destaque (botões/realces)</label>
                            <input type="color" name="cor_secundaria" value="<?php echo esc_attr( $cor_secundaria ?: '#5c8cf5' ); ?>">
                        </p>
                        <p style="margin:0 0 6px;">
                            <label><input type="checkbox" name="cor_reset" value="1"> Restaurar paleta padrão (clean)</label>
                        </p>
                        <p class="description">
                            Personaliza apenas o <strong>painel do usuário</strong> (shortcode <code>[diario_clinica_painel]</code>).
                            O logotipo exibido no painel usa o <strong>logo/ícone do site</strong> definido no WordPress.
                            A área administrativa mantém a identidade 61labs.
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
        <h2>Backup e restauração (CSV / Excel)</h2>
        <p class="description" style="margin-bottom:14px;">
            Exporte todos os registros em uma planilha CSV (abre no Excel) para guardar como backup.
            Para restaurar, importe o mesmo arquivo CSV — os registros são gravados por data,
            sobrescrevendo dias já existentes.
        </p>

        <h3 style="margin:6px 0 8px;">Exportar backup</h3>
        <form method="GET" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:22px;">
            <input type="hidden" name="action" value="dc_export_backup_csv">
            <?php wp_nonce_field( 'dc_backup' ); ?>
            <button type="submit" class="button button-primary">⬇ Baixar backup CSV (todos os registros)</button>
        </form>

        <hr style="margin:18px 0;">

        <h3 style="margin:6px 0 8px;">Importar / restaurar</h3>
        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="dc_import_csv">
            <?php wp_nonce_field( 'dc_import' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="dc-import-file">Arquivo CSV</label></th>
                    <td>
                        <input type="file" name="dc_import_file" id="dc-import-file" accept=".csv,text/csv,text/plain">
                        <p class="description">
                            Aceita arquivos <code>.csv</code> (delimitados por <code>;</code> ou <code>,</code>).
                            No Excel, use <em>Salvar como &rarr; CSV UTF-8</em>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dc-import-texto">…ou cole o CSV</label></th>
                    <td>
                        <textarea name="dc_import_texto" id="dc-import-texto" rows="6" class="large-text code"
                                  placeholder="data_fechamento;ind_paciente;ind_medico;...&#10;2026-07-10;2;0;..."></textarea>
                        <p class="description">
                            Aceita cabeçalho com as chaves internas (<code>data_fechamento</code>, <code>total_leads</code>…)
                            ou com os rótulos do backup (<code>Data</code>, <code>Total Leads</code>…). Datas em
                            <code>AAAA-MM-DD</code> ou <code>DD/MM/AAAA</code>.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">⬆ Importar dados</button>
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
