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

    // ==============================================================
    // Backup — exporta CSV round-trippable (chaves internas + data ISO)
    // ==============================================================

    /** Handler para admin-post: exporta backup CSV completo. */
    public static function handle_export_backup_csv(): void {
        check_admin_referer( 'dc_backup' );

        if ( ! current_user_can( 'dc_manage' ) ) {
            wp_die( 'Sem permissão.' );
        }

        $rows     = DC_DB::exportar();
        $filename = 'diario-clinica-backup-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $cols = array_merge( [ 'data_fechamento' ], DC_Parser::$campos );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM — Excel UTF-8.
        fputcsv( $out, $cols, ';' );

        foreach ( $rows as $row ) {
            $linha = [];
            foreach ( $cols as $col ) {
                $valor = $row->$col ?? '';
                if ( $col === 'data_fechamento' && $valor ) {
                    $valor = date( 'Y-m-d', strtotime( $valor ) );
                }
                $linha[] = $valor;
            }
            fputcsv( $out, $linha, ';' );
        }

        fclose( $out );
        exit;
    }

    // ==============================================================
    // Backup — importa CSV (arquivo enviado ou texto colado)
    // ==============================================================

    /** Handler para admin-post: importa registros a partir de CSV. */
    public static function handle_import_csv(): void {
        check_admin_referer( 'dc_import' );

        if ( ! current_user_can( 'dc_manage' ) ) {
            wp_die( 'Sem permissão.' );
        }

        $conteudo = '';

        if ( ! empty( $_FILES['dc_import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['dc_import_file']['tmp_name'] ) ) {
            $conteudo = (string) file_get_contents( $_FILES['dc_import_file']['tmp_name'] ); // phpcs:ignore
        } elseif ( ! empty( $_POST['dc_import_texto'] ) ) {
            $conteudo = (string) wp_unslash( $_POST['dc_import_texto'] );
        }

        $res = self::importar_conteudo( $conteudo );

        $args = [
            'page'   => 'dc-config',
            'dc_imp' => $res['erro'] ? '0' : '1',
            'imp'    => $res['importados'],
            'err'    => $res['erros'],
        ];
        if ( $res['erro'] ) {
            $args['msg'] = rawurlencode( $res['erro'] );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Interpreta o conteúdo CSV e grava os registros (sobrescrevendo por data).
     *
     * @return array{ importados: int, erros: int, erro: string }
     */
    private static function importar_conteudo( string $conteudo ): array {
        $conteudo = ltrim( $conteudo, "\xEF\xBB\xBF" ); // remove BOM.
        $conteudo = str_replace( [ "\r\n", "\r" ], "\n", $conteudo );
        $linhas   = array_values( array_filter(
            explode( "\n", $conteudo ),
            static fn( string $l ): bool => trim( $l ) !== ''
        ) );

        if ( count( $linhas ) < 2 ) {
            return [ 'importados' => 0, 'erros' => 0, 'erro' => 'Arquivo vazio ou sem linhas de dados.' ];
        }

        // Detecta o delimitador pela linha de cabeçalho.
        $header_raw = $linhas[0];
        $delim      = ';';
        $melhor     = 0;
        foreach ( [ ';', ',', "\t" ] as $cand ) {
            $qtd = substr_count( $header_raw, $cand );
            if ( $qtd > $melhor ) {
                $melhor = $qtd;
                $delim  = $cand;
            }
        }

        // Mapeia cada coluna do cabeçalho para uma chave interna.
        $header      = str_getcsv( $header_raw, $delim );
        $validas     = array_merge( [ 'data_fechamento' ], DC_Parser::$campos );
        $mapa_labels = self::mapa_labels();
        $col_map     = []; // índice → chave interna.

        foreach ( $header as $i => $cel ) {
            $bruto = trim( (string) $cel );
            $norm  = self::normalizar_rotulo( $bruto );
            if ( in_array( $bruto, $validas, true ) ) {
                $col_map[ $i ] = $bruto;
            } elseif ( isset( $mapa_labels[ $norm ] ) ) {
                $col_map[ $i ] = $mapa_labels[ $norm ];
            }
        }

        $idx_data = array_search( 'data_fechamento', $col_map, true );
        if ( false === $idx_data ) {
            return [ 'importados' => 0, 'erros' => 0, 'erro' => 'Coluna de data não encontrada no cabeçalho (ex.: "data_fechamento" ou "Data").' ];
        }

        $importados = 0;
        $erros      = 0;

        for ( $n = 1; $n < count( $linhas ); $n++ ) {
            $cells = str_getcsv( $linhas[ $n ], $delim );

            $data = self::normalizar_data( (string) ( $cells[ $idx_data ] ?? '' ) );
            if ( ! $data ) {
                $erros++;
                continue;
            }

            $campos = [];
            foreach ( DC_Parser::$campos as $campo ) {
                $campos[ $campo ] = 0;
            }
            foreach ( $col_map as $i => $chave ) {
                if ( $chave === 'data_fechamento' ) {
                    continue;
                }
                $valor           = isset( $cells[ $i ] ) ? (int) preg_replace( '/\D/', '', (string) $cells[ $i ] ) : 0;
                $campos[ $chave ] = max( 0, $valor );
            }

            $result = DC_DB::salvar( $data, $campos, '', true );
            if ( ! empty( $result['ok'] ) ) {
                $importados++;
            } else {
                $erros++;
            }
        }

        return [ 'importados' => $importados, 'erros' => $erros, 'erro' => '' ];
    }

    /** Mapa: rótulo humano normalizado → chave interna. */
    private static function mapa_labels(): array {
        $mapa = [];
        foreach ( self::cabecalhos() as $chave => $label ) {
            $mapa[ self::normalizar_rotulo( $label ) ] = $chave;
        }
        return $mapa;
    }

    /** Normaliza um rótulo para comparação (minúsculo, sem acento, sem pontuação). */
    private static function normalizar_rotulo( string $s ): string {
        $s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
        $s = strtolower( trim( $s ) );
        return preg_replace( '/[^a-z0-9]+/', '_', $s );
    }

    /** Converte data ISO ou BR (dd/mm/aaaa) para 'Y-m-d'. */
    private static function normalizar_data( string $v ): ?string {
        $v = trim( $v );
        if ( $v === '' ) {
            return null;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
            return $v;
        }
        if ( preg_match( '#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m ) ) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        $ts = strtotime( $v );
        return $ts ? date( 'Y-m-d', $ts ) : null;
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
