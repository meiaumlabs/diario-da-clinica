<?php
defined( 'ABSPATH' ) || exit;

class DC_Export {

    /** Cabeçalhos legíveis para CSV/PDF (coluna → label). */
    public static function cabecalhos(): array {
        return [
            'data_fechamento'     => 'Data',
            'ind_paciente'        => 'Ind. Paciente',
            'ind_medico'          => 'Ind. Médico',
            'trafego_pago'        => 'Tráfego Pago',
            'site'                => 'Site',
            'instagram_organico'  => 'Instagram Org.',
            'paciente_antigo'     => 'Pac. Antigo',
            'outros'              => 'Outros',
            'total_leads'         => 'Total Leads',
            'agend_trafego'       => 'Agend. Tráfego',
            'agend_site'          => 'Agend. Site',
            'agend_indicacao'     => 'Agend. Indicação',
            'agend_antigos'       => 'Agend. Antigos',
            'consultas_total'     => 'Consultas Total',
            'consultas_trafego'   => 'Consul. Tráfego',
            'consultas_organico'  => 'Consul. Orgânico',
            'consultas_antigos'   => 'Consul. Antigos',
            'consultas_indicacao' => 'Consul. Indicação',
        ];
    }

    /** Handler para admin-post: exporta CSV. */
    public static function handle_export_csv(): void {
        check_admin_referer( 'dc_export' );

        if ( ! current_user_can( 'dc_manage' ) ) {
            wp_die( 'Sem permissão.' );
        }

        $de  = sanitize_text_field( wp_unslash( $_GET['de']  ?? '' ) );
        $ate = sanitize_text_field( wp_unslash( $_GET['ate'] ?? '' ) );

        $rows = DC_DB::exportar( $de, $ate );
        self::saida_csv( $rows );
    }

    /** Handler para admin-post: exporta PDF (HTML + print). */
    public static function handle_export_pdf(): void {
        check_admin_referer( 'dc_export' );

        if ( ! current_user_can( 'dc_manage' ) ) {
            wp_die( 'Sem permissão.' );
        }

        $de  = sanitize_text_field( wp_unslash( $_GET['de']  ?? '' ) );
        $ate = sanitize_text_field( wp_unslash( $_GET['ate'] ?? '' ) );

        $rows = DC_DB::exportar( $de, $ate );
        self::saida_html_print( $rows );
    }

    /** Envia arquivo CSV para o browser. */
    private static function saida_csv( array $rows ): void {
        $filename = 'diario-clinica-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM — Excel UTF-8.

        $cabecalhos = self::cabecalhos();
        fputcsv( $out, array_values( $cabecalhos ), ';' );

        foreach ( $rows as $row ) {
            $linha = [];
            foreach ( array_keys( $cabecalhos ) as $col ) {
                $valor = $row->$col ?? '';
                // Format date for Brazilian convention.
                if ( $col === 'data_fechamento' && $valor ) {
                    $valor = date( 'd/m/Y', strtotime( $valor ) );
                }
                $linha[] = $valor;
            }
            fputcsv( $out, $linha, ';' );
        }

        fclose( $out );
        exit;
    }

    /** Gera HTML formatado para impressão (PDF via browser print). */
    private static function saida_html_print( array $rows ): void {
        $cabecalhos = self::cabecalhos();
        $data_hoje  = gmdate( 'd/m/Y' );

        header( 'Content-Type: text/html; charset=utf-8' );

        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
        echo '<title>Diário da Clínica — Exportação ' . esc_html( $data_hoje ) . '</title>';
        echo '<style>
body{font-family:Arial,sans-serif;font-size:11px;margin:10mm;}
h1{font-size:14px;margin-bottom:8px;}
table{border-collapse:collapse;width:100%;}
th,td{border:1px solid #999;padding:3px 5px;text-align:center;}
th{background:#1d4ed8;color:#fff;}
tr:nth-child(even){background:#eff6ff;}
@media print{@page{size:landscape;margin:8mm}button{display:none}}
</style></head><body>';
        echo '<h1>Diário da Clínica — Exportação ' . esc_html( $data_hoje ) . '</h1>';
        echo '<button onclick="window.print()" style="margin-bottom:12px;padding:6px 14px;cursor:pointer;">Imprimir / Salvar PDF</button>';
        echo '<table><thead><tr>';
        foreach ( $cabecalhos as $label ) {
            echo '<th>' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( array_keys( $cabecalhos ) as $col ) {
                $valor = $row->$col ?? '';
                if ( $col === 'data_fechamento' && $valor ) {
                    $valor = date( 'd/m/Y', strtotime( $valor ) );
                }
                echo '<td>' . esc_html( (string) $valor ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
        exit;
    }
}
