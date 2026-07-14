<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'dc_manage' ) ) {
    wp_die( 'Acesso negado.' );
}

// Defaults: last 30 days.
$de_default  = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
$ate_default = gmdate( 'Y-m-d' );

$de   = sanitize_text_field( wp_unslash( $_GET['de']   ?? $de_default ) );
$ate  = sanitize_text_field( wp_unslash( $_GET['ate']  ?? $ate_default ) );
$tipo = sanitize_text_field( wp_unslash( $_GET['tipo'] ?? 'diario' ) );

// Sanitize tipo.
$tipo = in_array( $tipo, [ 'diario', 'semanal', 'mensal' ], true ) ? $tipo : 'diario';

// Fetch data.
$rows = $tipo === 'diario'
    ? DC_DB::relatorio_periodo( $de, $ate )
    : DC_DB::relatorio_agregado( $de, $ate, $tipo );

// Compute totals for this period.
$totais = [];
foreach ( DC_Parser::$campos as $c ) {
    $totais[ $c ] = array_sum( array_map( static fn( $r ) => (int) ( $r->$c ?? 0 ), $rows ) );
}

// Derived metrics.
$total_agend  = $totais['agend_trafego'] + $totais['agend_site']
              + $totais['agend_indicacao'] + $totais['agend_antigos'];
$conv_la = $totais['total_leads'] > 0
    ? round( $total_agend / $totais['total_leads'] * 100, 1 )
    : null;
$conv_ac = $total_agend > 0
    ? round( $totais['consultas_total'] / $total_agend * 100, 1 )
    : null;

// Prepare Chart.js data.
$chart_labels   = [];
$chart_leads    = [];
$chart_agend    = [];
$chart_consultas = [];

foreach ( $rows as $r ) {
    if ( $tipo === 'diario' ) {
        $chart_labels[] = date( 'd/m', strtotime( $r->data_fechamento ) );
    } elseif ( $tipo === 'semanal' ) {
        $chart_labels[] = 'Sem ' . date( 'W/Y', strtotime( $r->data_inicio ) );
    } else {
        $chart_labels[] = date( 'm/Y', strtotime( $r->data_inicio . '-01' ) );
    }
    $r_agend          = (int) $r->agend_trafego + (int) $r->agend_site
                       + (int) $r->agend_indicacao + (int) $r->agend_antigos;
    $chart_leads[]    = (int) $r->total_leads;
    $chart_agend[]    = $r_agend;
    $chart_consultas[] = (int) $r->consultas_total;
}

$origens_labels = [ 'Indicação Pac.', 'Indicação Méd.', 'Tráfego Pago', 'Site', 'Instagram Org.', 'Pac. Antigo', 'Outros' ];
$origens_data   = [
    $totais['ind_paciente'],
    $totais['ind_medico'],
    $totais['trafego_pago'],
    $totais['site'],
    $totais['instagram_organico'],
    $totais['paciente_antigo'],
    $totais['outros'],
];

// C-002 — Funnel by origin.
// Note: leads and agendamentos have different granularity (7 vs 4 origins),
// so we group them into 5 buckets for apples-to-apples comparison.
$funil = [
    [
        'grupo'     => 'Tráfego pago',
        'leads'     => $totais['trafego_pago'],
        'agend'     => $totais['agend_trafego'],
        'consultas' => $totais['consultas_trafego'],
    ],
    [
        'grupo'     => 'Indicação',
        'leads'     => $totais['ind_paciente'] + $totais['ind_medico'],
        'agend'     => $totais['agend_indicacao'],
        'consultas' => $totais['consultas_indicacao'],
    ],
    [
        'grupo'     => 'Orgânico (Site + Instagram)',
        'leads'     => $totais['site'] + $totais['instagram_organico'],
        'agend'     => $totais['agend_site'],
        'consultas' => $totais['consultas_organico'],
    ],
    [
        'grupo'     => 'Pacientes antigos',
        'leads'     => $totais['paciente_antigo'],
        'agend'     => $totais['agend_antigos'],
        'consultas' => $totais['consultas_antigos'],
    ],
    [
        'grupo'     => 'Outros',
        'leads'     => $totais['outros'],
        'agend'     => null,
        'consultas' => null,
    ],
];

// Helper: safe percentage string.
$dc_taxa = static function ( int $num, ?int $den ): string {
    return ( $den !== null && $den > 0 )
        ? round( $num / $den * 100, 1 ) . '%'
        : '—';
};

// Find champion origin by conversion rate (Leads→Consultas) and by volume.
$campeao_conv  = null;
$campeao_vol   = null;
$max_taxa_val  = -1.0;
$max_vol       = -1;

foreach ( $funil as $g ) {
    if ( $g['consultas'] === null ) continue;
    if ( $g['leads'] > 0 ) {
        $taxa_val = $g['consultas'] / $g['leads'] * 100;
        if ( $taxa_val > $max_taxa_val ) {
            $max_taxa_val = $taxa_val;
            $campeao_conv = $g;
        }
    }
    if ( (int) $g['consultas'] > $max_vol ) {
        $max_vol      = (int) $g['consultas'];
        $campeao_vol  = $g;
    }
}

// Export nonce.
$export_nonce = wp_create_nonce( 'dc_export' );
$export_base  = admin_url( 'admin-post.php' );
$export_args  = http_build_query( [ '_wpnonce' => $export_nonce, 'de' => $de, 'ate' => $ate ] );
$url_csv      = $export_base . '?action=dc_export_csv&' . $export_args;
$url_pdf      = $export_base . '?action=dc_export_pdf&' . $export_args;
?>
<div class="wrap dc-wrap">
    <h1>Diário da Clínica — Relatórios/Gráficos</h1>

    <div class="dc-card">
        <form method="GET" action="" class="dc-form-relatorio">
            <input type="hidden" name="page" value="dc-relatorios">

            <label><strong>De:</strong>
                <input type="date" name="de" value="<?php echo esc_attr( $de ); ?>" required>
            </label>

            <label><strong>Até:</strong>
                <input type="date" name="ate" value="<?php echo esc_attr( $ate ); ?>" required>
            </label>

            <label><strong>Agrupamento:</strong>
                <select name="tipo">
                    <?php foreach ( [ 'diario' => 'Diário', 'semanal' => 'Semanal', 'mensal' => 'Mensal' ] as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $tipo, $v ); ?>>
                            <?php echo esc_html( $l ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit" class="button button-primary">Consultar</button>
        </form>
    </div>

    <?php if ( empty( $rows ) ) : ?>
        <p class="dc-vazio">Nenhum dado encontrado para o período selecionado.</p>
    <?php else : ?>

    <!-- Métricas resumidas -->
    <div class="dc-metrics-grid">
        <div class="dc-metric">
            <span class="dc-metric-val"><?php echo esc_html( $totais['total_leads'] ); ?></span>
            <span class="dc-metric-lbl">Total de Leads</span>
        </div>
        <div class="dc-metric">
            <span class="dc-metric-val"><?php echo esc_html( $total_agend ); ?></span>
            <span class="dc-metric-lbl">Agendamentos</span>
        </div>
        <div class="dc-metric">
            <span class="dc-metric-val"><?php echo esc_html( $totais['consultas_total'] ); ?></span>
            <span class="dc-metric-lbl">Consultas</span>
        </div>
        <div class="dc-metric dc-metric-conv">
            <span class="dc-metric-val"><?php echo $conv_la !== null ? esc_html( $conv_la ) . '%' : '—'; ?></span>
            <span class="dc-metric-lbl">Conv. Leads→Agend.</span>
        </div>
        <div class="dc-metric dc-metric-conv">
            <span class="dc-metric-val"><?php echo $conv_ac !== null ? esc_html( $conv_ac ) . '%' : '—'; ?></span>
            <span class="dc-metric-lbl">Conv. Agend.→Consul.</span>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="dc-charts-grid">
        <div class="dc-card dc-chart-card">
            <h3>Evolução diária</h3>
            <canvas id="dc-chart-evolucao" height="120"></canvas>
        </div>
        <div class="dc-card dc-chart-card">
            <h3>Distribuição de origens</h3>
            <canvas id="dc-chart-origens" height="120"></canvas>
        </div>
    </div>

    <!-- Conversão por origem (C-002) -->
    <div class="dc-card">
        <h3>Conversão por origem</h3>

        <?php if ( $campeao_conv ) : ?>
        <p class="dc-funil-destaque">
            ⭐ <strong>Origem com mais conversões:</strong>
            <?php echo esc_html( $campeao_conv['grupo'] ); ?>
            (<?php echo esc_html( round( $campeao_conv['consultas'] / $campeao_conv['leads'] * 100, 1 ) ); ?>%
            — <?php echo esc_html( $campeao_conv['consultas'] ); ?> consultas
            de <?php echo esc_html( $campeao_conv['leads'] ); ?> leads)
            <?php if ( $campeao_vol && $campeao_vol['grupo'] !== $campeao_conv['grupo'] && $campeao_vol['consultas'] > 0 ) : ?>
            &nbsp;|&nbsp; 🏆 <strong>Maior volume absoluto:</strong>
            <?php echo esc_html( $campeao_vol['grupo'] ); ?>
            (<?php echo esc_html( $campeao_vol['consultas'] ); ?> consultas)
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <div class="dc-table-wrap">
        <table class="dc-table widefat striped dc-funil-table">
            <thead>
                <tr>
                    <th>Origem</th>
                    <th>Leads</th>
                    <th>Agendamentos</th>
                    <th>Consultas</th>
                    <th>Taxa Leads→Agend.</th>
                    <th>Taxa Agend.→Consul.</th>
                    <th>Taxa Leads→Consul.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $funil as $g ) :
                $g_leads    = (int) $g['leads'];
                $g_agend    = $g['agend'] !== null ? (int) $g['agend'] : null;
                $g_consultas = $g['consultas'] !== null ? (int) $g['consultas'] : null;
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $g['grupo'] ); ?></strong></td>
                    <td><?php echo esc_html( $g_leads ); ?></td>
                    <td><?php echo $g_agend !== null ? esc_html( $g_agend ) : '<span class="dc-nd">—</span>'; ?></td>
                    <td><?php echo $g_consultas !== null ? esc_html( $g_consultas ) : '<span class="dc-nd">—</span>'; ?></td>
                    <td><?php echo esc_html( $dc_taxa( $g_agend ?? 0, $g_leads > 0 ? $g_leads : null ) ); ?></td>
                    <td><?php echo esc_html( $dc_taxa( $g_consultas ?? 0, $g_agend ) ); ?></td>
                    <td><?php echo esc_html( $dc_taxa( $g_consultas ?? 0, $g_leads > 0 ? $g_leads : null ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Tabela consolidada -->
    <div class="dc-card">
        <h3>Tabela consolidada
            <span class="dc-export-btns">
                <a href="<?php echo esc_url( $url_csv ); ?>" class="button button-small">⬇ CSV</a>
                <a href="<?php echo esc_url( $url_pdf ); ?>" target="_blank" class="button button-small">⬇ PDF/Imprimir</a>
            </span>
        </h3>

        <div class="dc-table-wrap">
        <table class="dc-table widefat striped">
            <thead>
                <tr>
                    <th><?php echo $tipo === 'diario' ? 'Data' : 'Período'; ?></th>
                    <?php if ( $tipo !== 'diario' ) : ?><th>Dias</th><?php endif; ?>
                    <th>Leads</th>
                    <th>Ind. Pac.</th>
                    <th>Ind. Méd.</th>
                    <th>Tráf.</th>
                    <th>Site</th>
                    <th>Insta.</th>
                    <th>Ant.</th>
                    <th>Outros</th>
                    <th>Ag. T.</th>
                    <th>Ag. S.</th>
                    <th>Ag. I.</th>
                    <th>Ag. A.</th>
                    <th>Consul.</th>
                    <th>C.T.</th>
                    <th>C.O.</th>
                    <th>C.A.</th>
                    <th>C.I.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                if ( $tipo === 'diario' ) {
                    $periodo_str = esc_html( date( 'd/m/Y', strtotime( $r->data_fechamento ) ) );
                } elseif ( $tipo === 'semanal' ) {
                    $periodo_str = 'Sem ' . esc_html( date( 'W/Y', strtotime( $r->data_inicio ) ) );
                } else {
                    $periodo_str = esc_html( date( 'm/Y', strtotime( $r->data_inicio . '-01' ) ) );
                }
                $cols = [ 'ind_paciente', 'ind_medico', 'trafego_pago', 'site', 'instagram_organico', 'paciente_antigo', 'outros', 'agend_trafego', 'agend_site', 'agend_indicacao', 'agend_antigos', 'consultas_total', 'consultas_trafego', 'consultas_organico', 'consultas_antigos', 'consultas_indicacao' ];
            ?>
                <tr>
                    <td><strong><?php echo $periodo_str; ?></strong></td>
                    <?php if ( $tipo !== 'diario' ) : ?>
                        <td><?php echo esc_html( $r->dias ); ?></td>
                    <?php endif; ?>
                    <td><strong><?php echo esc_html( $r->total_leads ); ?></strong></td>
                    <?php foreach ( $cols as $c ) : ?>
                        <td><?php echo esc_html( (int) ( $r->$c ?? 0 ) ); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dc-totais">
                    <th>TOTAL</th>
                    <?php if ( $tipo !== 'diario' ) : ?><th><?php echo esc_html( count( $rows ) ); ?></th><?php endif; ?>
                    <th><?php echo esc_html( $totais['total_leads'] ); ?></th>
                    <?php foreach ( [ 'ind_paciente', 'ind_medico', 'trafego_pago', 'site', 'instagram_organico', 'paciente_antigo', 'outros', 'agend_trafego', 'agend_site', 'agend_indicacao', 'agend_antigos', 'consultas_total', 'consultas_trafego', 'consultas_organico', 'consultas_antigos', 'consultas_indicacao' ] as $c ) : ?>
                        <th><?php echo esc_html( $totais[ $c ] ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if ( ! empty( $rows ) ) : ?>
<script>
window.DC_CHART_DATA = <?php echo wp_json_encode( [
    'evolucao' => [
        'labels'    => $chart_labels,
        'leads'     => $chart_leads,
        'agend'     => $chart_agend,
        'consultas' => $chart_consultas,
    ],
    'origens' => [
        'labels' => $origens_labels,
        'data'   => $origens_data,
    ],
] ); ?>;
</script>
<?php endif; ?>
