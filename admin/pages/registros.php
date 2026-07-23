<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'dc_manage' ) ) {
    wp_die( 'Acesso negado.' );
}

// Handle delete action.
if (
    isset( $_GET['dc_action'], $_GET['id'], $_GET['_wpnonce'] ) &&
    'excluir' === $_GET['dc_action'] &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dc_excluir_' . (int) $_GET['id'] )
) {
    if ( DC_DB::excluir( (int) $_GET['id'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Registro excluído.</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>Erro ao excluir.</p></div>';
    }
}

$pagina     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$por_pagina = 20;
$result     = DC_DB::listar( $pagina, $por_pagina );
$rows       = $result['rows'];
$total      = $result['total'];
$num_pags   = (int) ceil( $total / $por_pagina );

$base_url   = admin_url( 'admin.php?page=dc-registros' );
?>
<div class="wrap dc-wrap">
    <h1 class="wp-heading-inline">Diário da Clínica — Registros</h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dc-diario' ) ); ?>"
       class="page-title-action">+ Novo Registro</a>
    <hr class="wp-header-end">

    <?php if ( empty( $rows ) ) : ?>
        <p>Nenhum registro encontrado. <a href="<?php echo esc_url( admin_url( 'admin.php?page=dc-diario' ) ); ?>">Adicionar primeiro registro.</a></p>
    <?php else : ?>
        <p class="dc-total">Total: <strong><?php echo esc_html( $total ); ?></strong> registro(s) &nbsp;|&nbsp;
           Página <?php echo esc_html( $pagina ); ?> de <?php echo esc_html( $num_pags ); ?></p>

        <table class="dc-table widefat striped">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Total Leads</th>
                    <th>Agend. Total</th>
                    <th>Consultas</th>
                    <th>Conv. L→A</th>
                    <th>Conv. A→C</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $agend_total = (int) $r->agend_trafego + (int) $r->agend_site
                             + (int) $r->agend_indicacao + (int) $r->agend_antigos;
                $conv_la = $r->total_leads > 0
                    ? round( $agend_total / $r->total_leads * 100, 1 )
                    : '—';
                $conv_ac = $agend_total > 0
                    ? round( (int) $r->consultas_total / $agend_total * 100, 1 )
                    : '—';
                $data_br   = esc_html( date( 'd/m/Y', strtotime( $r->data_fechamento ) ) );
                $data_iso  = date( 'Y-m-d', strtotime( $r->data_fechamento ) );
                $campos_row = [];
                foreach ( DC_Parser::$campos as $c ) {
                    $campos_row[ $c ] = (int) ( $r->$c ?? 0 );
                }
                $url_excl  = wp_nonce_url(
                    add_query_arg( [ 'dc_action' => 'excluir', 'id' => $r->id ], $base_url ),
                    'dc_excluir_' . $r->id
                );
                $url_ver   = admin_url( 'admin.php?page=dc-registros&dc_action=ver&id=' . $r->id );
            ?>
                <tr>
                    <td><strong><?php echo $data_br; ?></strong></td>
                    <td><?php echo esc_html( $r->total_leads ); ?></td>
                    <td><?php echo esc_html( $agend_total ); ?></td>
                    <td><?php echo esc_html( $r->consultas_total ); ?></td>
                    <td><?php echo is_numeric( $conv_la ) ? esc_html( $conv_la ) . '%' : '—'; ?></td>
                    <td><?php echo is_numeric( $conv_ac ) ? esc_html( $conv_ac ) . '%' : '—'; ?></td>
                    <td class="dc-acoes">
                        <button type="button" class="button-link dc-wa-btn"
                                data-data="<?php echo esc_attr( $data_iso ); ?>"
                                data-campos="<?php echo esc_attr( wp_json_encode( $campos_row ) ); ?>">
                            WhatsApp
                        </button>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( $url_ver ); ?>">Ver</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( $url_excl ); ?>"
                           class="dc-link-excluir"
                           onclick="return confirm('Excluir o registro de <?php echo $data_br; ?>?');">
                            Excluir
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $num_pags > 1 ) : ?>
        <div class="dc-paginacao">
            <?php if ( $pagina > 1 ) : ?>
                <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $pagina - 1, $base_url ) ); ?>">« Anterior</a>
            <?php endif; ?>
            <?php if ( $pagina < $num_pags ) : ?>
                <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $pagina + 1, $base_url ) ); ?>">Próxima »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Detail view.
    if (
        isset( $_GET['dc_action'], $_GET['id'] ) &&
        'ver' === $_GET['dc_action']
    ) {
        $reg = DC_DB::obter( (int) $_GET['id'] );
        if ( $reg ) :
            $labels = [
                'ind_paciente'        => 'Indicação de Paciente',
                'ind_medico'          => 'Indicação de Médico',
                'trafego_pago'        => 'Tráfego Pago',
                'site'                => 'Site',
                'instagram_organico'  => 'Instagram Orgânico',
                'paciente_antigo'     => 'Paciente Já da Clínica',
                'outros'              => 'Outros',
                'total_leads'         => 'Total de Leads',
                'agend_trafego'       => 'Agendamentos (Tráfego)',
                'agend_site'          => 'Agendamentos (Site)',
                'agend_indicacao'     => 'Agendamentos (Indicação)',
                'agend_antigos'       => 'Agendamentos (Pacientes Antigos)',
                'consultas_total'     => 'Consultas Realizadas',
                'consultas_trafego'   => 'Consultas (Tráfego)',
                'consultas_organico'  => 'Consultas (Orgânico)',
                'consultas_antigos'   => 'Consultas (Pacientes Antigos)',
                'consultas_indicacao' => 'Consultas (Indicação)',
            ];
    ?>
    <div class="dc-card" style="margin-top:24px;">
        <h2>Detalhe — <?php echo esc_html( date( 'd/m/Y', strtotime( $reg->data_fechamento ) ) ); ?></h2>
        <table class="dc-table widefat striped dc-preview-table">
            <tbody>
            <?php foreach ( $labels as $campo => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td><?php echo esc_html( $reg->$campo ?? '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( ! empty( $reg->texto_original ) ) : ?>
        <details style="margin-top:12px;">
            <summary>Texto original</summary>
            <pre class="dc-texto-original"><?php echo esc_html( $reg->texto_original ); ?></pre>
        </details>
        <?php endif; ?>
    </div>
    <?php
        endif;
    }
    ?>
</div>
