<?php
defined( 'ABSPATH' ) || exit;

class DC_Parser {

    /** Mapa rótulo (normalizado, sem emoji) → chave interna. */
    private static array $mapa = [
        'indicação de paciente'                    => 'ind_paciente',
        'indicacao de paciente'                    => 'ind_paciente',
        'indicação de médico'                      => 'ind_medico',
        'indicacao de medico'                      => 'ind_medico',
        'tráfego pago'                             => 'trafego_pago',
        'trafego pago'                             => 'trafego_pago',
        'site'                                     => 'site',
        'instagram orgânico'                       => 'instagram_organico',
        'instagram organico'                       => 'instagram_organico',
        'paciente já da clínica'                   => 'paciente_antigo',
        'paciente ja da clinica'                   => 'paciente_antigo',
        'outros'                                   => 'outros',
        'total de leads'                           => 'total_leads',
        'agendamentos (tráfego)'                   => 'agend_trafego',
        'agendamentos (trafego)'                   => 'agend_trafego',
        'agendamentos (site)'                      => 'agend_site',
        'agendamentos (indicação)'                 => 'agend_indicacao',
        'agendamentos (indicacao)'                 => 'agend_indicacao',
        'agendamentos (pacientes antigos)'         => 'agend_antigos',
        'consultas realizadas'                     => 'consultas_total',
        'consultas realizadas (tráfego)'           => 'consultas_trafego',
        'consultas realizadas (trafego)'           => 'consultas_trafego',
        'consultas realizadas (orgânico)'          => 'consultas_organico',
        'consultas realizadas (organico)'          => 'consultas_organico',
        'consultas realizadas (pacientes antigos)' => 'consultas_antigos',
        'consultas realizadas (indicação)'         => 'consultas_indicacao',
        'consultas realizadas (indicacao)'         => 'consultas_indicacao',
    ];

    /** Todos os campos esperados (usados para detectar ausentes). */
    public static array $campos = [
        'ind_paciente', 'ind_medico', 'trafego_pago', 'site',
        'instagram_organico', 'paciente_antigo', 'outros', 'total_leads',
        'agend_trafego', 'agend_site', 'agend_indicacao', 'agend_antigos',
        'consultas_total', 'consultas_trafego', 'consultas_organico',
        'consultas_antigos', 'consultas_indicacao',
    ];

    /** Remove emojis e outros codepoints especiais. */
    private static function strip_emoji( string $text ): string {
        return preg_replace(
            '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FEFF}' .
            '\x{200B}-\x{200F}\x{FFF9}-\x{FFFD}\x{00A0}]/u',
            ' ',
            $text
        ) ?? $text;
    }

    /** Normaliza para lookup: strip_emoji + lower + trim. */
    private static function normalizar( string $s ): string {
        $s = self::strip_emoji( $s );
        $s = mb_strtolower( $s, 'UTF-8' );
        // Collapse multiple spaces.
        $s = preg_replace( '/\s+/', ' ', $s ) ?? $s;
        return trim( $s );
    }

    /**
     * Parseia texto bruto do relatório de fechamento.
     *
     * @return array{
     *   data: string|null,
     *   campos: array<string, int>,
     *   avisos: string[],
     *   linhas_ignoradas: string[]
     * }
     */
    public static function parse( string $texto ): array {
        $resultado = [
            'data'             => null,
            'campos'           => [],
            'avisos'           => [],
            'linhas_ignoradas' => [],
        ];

        $linhas = preg_split( '/\r?\n/', $texto ) ?: [];

        foreach ( $linhas as $linha ) {
            $linha = trim( $linha );
            if ( $linha === '' ) {
                continue;
            }

            // Detectar data: "Fechamento do dia DD/MM/AAAA".
            if ( preg_match( '/fechamento\s+do\s+dia\s+(\d{1,2})\/(\d{1,2})\/(\d{2,4})/ui', $linha, $m ) ) {
                $d   = str_pad( $m[1], 2, '0', STR_PAD_LEFT );
                $mes = str_pad( $m[2], 2, '0', STR_PAD_LEFT );
                $ano = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $resultado['data'] = "{$ano}-{$mes}-{$d}";
                continue;
            }

            // Ignorar cabeçalho "Origem LEADS".
            if ( preg_match( '/^origem\s+leads$/ui', self::normalizar( $linha ) ) ) {
                continue;
            }

            // Detectar "Rótulo: valor".
            if ( str_contains( $linha, ':' ) ) {
                [ $rotulo_raw, $valor_raw ] = explode( ':', $linha, 2 );
                $rotulo_norm = self::normalizar( $rotulo_raw );
                $valor_raw   = trim( $valor_raw );

                if ( isset( self::$mapa[ $rotulo_norm ] ) ) {
                    $chave     = self::$mapa[ $rotulo_norm ];
                    $valor_int = (int) preg_replace( '/\D/', '', $valor_raw );

                    // Só pega a primeira ocorrência de cada chave.
                    if ( ! isset( $resultado['campos'][ $chave ] ) ) {
                        $resultado['campos'][ $chave ] = $valor_int;
                    }
                    continue;
                }
            }

            $resultado['linhas_ignoradas'][] = $linha;
        }

        // Campos ausentes → 0 + aviso.
        foreach ( self::$campos as $campo ) {
            if ( ! isset( $resultado['campos'][ $campo ] ) ) {
                $resultado['campos'][ $campo ] = 0;
                $resultado['avisos'][] = 'Campo "' . $campo . '" não encontrado — assumido 0.';
            }
        }

        // Aviso de coerência: soma origens vs. Total de Leads.
        $c = $resultado['campos'];
        $soma_origens = $c['ind_paciente'] + $c['ind_medico'] + $c['trafego_pago']
                      + $c['site'] + $c['instagram_organico'] + $c['paciente_antigo']
                      + $c['outros'];
        if ( $c['total_leads'] > 0 && $soma_origens !== $c['total_leads'] ) {
            $resultado['avisos'][] = sprintf(
                'Coerência: soma das origens (%d) ≠ Total de Leads (%d).',
                $soma_origens,
                $c['total_leads']
            );
        }

        // Aviso de coerência: soma consultas por origem vs. Consultas realizadas.
        $soma_consultas = $c['consultas_trafego'] + $c['consultas_organico']
                        + $c['consultas_antigos'] + $c['consultas_indicacao'];
        if ( $c['consultas_total'] > 0 && $soma_consultas !== $c['consultas_total'] ) {
            $resultado['avisos'][] = sprintf(
                'Coerência: soma das consultas por origem (%d) ≠ Consultas realizadas (%d).',
                $soma_consultas,
                $c['consultas_total']
            );
        }

        return $resultado;
    }
}
