<?php
defined( 'ABSPATH' ) || exit;

class DC_Activator {

    public static function activate(): void {
        global $wpdb;
        $table   = $wpdb->prefix . DC_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            data_fechamento     DATE            NOT NULL,
            ind_paciente        INT UNSIGNED    NOT NULL DEFAULT 0,
            ind_medico          INT UNSIGNED    NOT NULL DEFAULT 0,
            trafego_pago        INT UNSIGNED    NOT NULL DEFAULT 0,
            site                INT UNSIGNED    NOT NULL DEFAULT 0,
            instagram_organico  INT UNSIGNED    NOT NULL DEFAULT 0,
            paciente_antigo     INT UNSIGNED    NOT NULL DEFAULT 0,
            outros              INT UNSIGNED    NOT NULL DEFAULT 0,
            total_leads         INT UNSIGNED    NOT NULL DEFAULT 0,
            agend_trafego       INT UNSIGNED    NOT NULL DEFAULT 0,
            agend_site          INT UNSIGNED    NOT NULL DEFAULT 0,
            agend_indicacao     INT UNSIGNED    NOT NULL DEFAULT 0,
            agend_antigos       INT UNSIGNED    NOT NULL DEFAULT 0,
            consultas_total     INT UNSIGNED    NOT NULL DEFAULT 0,
            consultas_trafego   INT UNSIGNED    NOT NULL DEFAULT 0,
            consultas_organico  INT UNSIGNED    NOT NULL DEFAULT 0,
            consultas_antigos   INT UNSIGNED    NOT NULL DEFAULT 0,
            consultas_indicacao INT UNSIGNED    NOT NULL DEFAULT 0,
            texto_original      LONGTEXT,
            criado_em           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            criado_por          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY data_fechamento (data_fechamento)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'dc_version', DC_VERSION );

        // Senha do painel público — armazenada APENAS como hash bcrypt.
        // O texto claro nunca aparece aqui nem em nenhum outro arquivo.
        if ( ! get_option( 'dc_painel_senha_hash' ) ) {
            update_option(
                'dc_painel_senha_hash',
                '$2b$12$cnjfJ8giWD/8hrQJiLaETeyeO4py5DDEuaXpTWI7ycsddOyb2.8rO',
                false
            );
        }

        // Custom role for reception staff.
        if ( ! get_role( 'dc_recepcao' ) ) {
            add_role(
                'dc_recepcao',
                'Recepção (Diário da Clínica)',
                [ 'read' => true, 'dc_manage' => true ]
            );
        }

        // Grant capability to administrators.
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( 'dc_manage' );
        }
    }

    public static function deactivate(): void {
        // Intentionally empty.
    }
}
