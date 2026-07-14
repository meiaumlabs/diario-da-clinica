<?php
defined( 'ABSPATH' ) || exit;

class DC_DB {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . DC_TABLE;
    }

    /**
     * Insere ou atualiza um registro.
     *
     * @param string             $data        'YYYY-MM-DD'
     * @param array<string, int> $campos
     * @param string             $texto_orig
     * @param bool               $sobrescrever
     * @return array{ ok: bool, duplicado: bool, id: int, msg: string }
     */
    public static function salvar(
        string $data,
        array  $campos,
        string $texto_orig,
        bool   $sobrescrever = false
    ): array {
        global $wpdb;
        $table = self::table();

        $existente = $wpdb->get_row(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE data_fechamento = %s", $data )
        );

        if ( $existente && ! $sobrescrever ) {
            return [
                'ok'        => false,
                'duplicado' => true,
                'id'        => (int) $existente->id,
                'msg'       => 'Data duplicada.',
            ];
        }

        $row = array_merge(
            [
                'data_fechamento' => $data,
                'texto_original'  => $texto_orig,
                'criado_por'      => get_current_user_id(),
            ],
            $campos
        );

        if ( $existente && $sobrescrever ) {
            $ok = $wpdb->update( $table, $row, [ 'id' => (int) $existente->id ] );
            return [
                'ok'        => $ok !== false,
                'duplicado' => false,
                'id'        => (int) $existente->id,
                'msg'       => $ok !== false ? 'Atualizado.' : $wpdb->last_error,
            ];
        }

        $ok = $wpdb->insert( $table, $row );
        return [
            'ok'        => $ok !== false,
            'duplicado' => false,
            'id'        => (int) $wpdb->insert_id,
            'msg'       => $ok !== false ? 'Inserido.' : $wpdb->last_error,
        ];
    }

    /**
     * Lista registros paginados.
     *
     * @return array{ rows: object[], total: int }
     */
    public static function listar(
        int    $pagina    = 1,
        int    $por_pagina = 20,
        string $ordem     = 'DESC'
    ): array {
        global $wpdb;
        $table  = self::table();
        $ordem  = $ordem === 'ASC' ? 'ASC' : 'DESC';
        $offset = ( $pagina - 1 ) * $por_pagina;

        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY data_fechamento {$ordem} LIMIT %d OFFSET %d",
                $por_pagina,
                $offset
            )
        ) ?: [];

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        return [ 'rows' => $rows, 'total' => $total ];
    }

    /** Retorna um único registro por ID. */
    public static function obter( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . DC_TABLE . " WHERE id = %d", $id )
        ) ?: null;
    }

    /** Exclui um registro. */
    public static function excluir( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . DC_TABLE,
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    /**
     * Relatório diário por período (todos os campos, ordenado por data).
     *
     * @return object[]
     */
    public static function relatorio_periodo( string $de, string $ate ): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE data_fechamento BETWEEN %s AND %s
                 ORDER BY data_fechamento ASC",
                $de,
                $ate
            )
        ) ?: [];
    }

    /**
     * Relatório agregado: semanal ou mensal.
     *
     * @return object[]
     */
    public static function relatorio_agregado(
        string $de,
        string $ate,
        string $grupo = 'mensal'
    ): array {
        global $wpdb;
        $table = self::table();

        $format   = $grupo === 'semanal'
            ? 'YEARWEEK(data_fechamento, 1)'
            : "DATE_FORMAT(data_fechamento, '%Y-%m')";

        $somas_sql = implode( ', ', array_map(
            static fn( string $c ) => "SUM({$c}) AS {$c}",
            DC_Parser::$campos
        ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$format} AS periodo,
                        MIN(data_fechamento) AS data_inicio,
                        MAX(data_fechamento) AS data_fim,
                        COUNT(*) AS dias,
                        {$somas_sql}
                 FROM {$table}
                 WHERE data_fechamento BETWEEN %s AND %s
                 GROUP BY periodo
                 ORDER BY periodo ASC",
                $de,
                $ate
            )
        ) ?: [];
    }

    /**
     * Todos os registros para exportação (opcional: filtro por período).
     *
     * @return object[]
     */
    public static function exportar( string $de = '', string $ate = '' ): array {
        global $wpdb;
        $table = self::table();

        if ( $de !== '' && $ate !== '' ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE data_fechamento BETWEEN %s AND %s
                     ORDER BY data_fechamento ASC",
                    $de,
                    $ate
                )
            ) ?: [];
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY data_fechamento ASC"
        ) ?: [];
    }
}
