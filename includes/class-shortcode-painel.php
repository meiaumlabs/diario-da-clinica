<?php
/**
 * Shortcode [diario_clinica_painel] — painel público com gate de senha.
 * A senha é armazenada APENAS como hash em wp_options; nenhum texto claro
 * aparece neste arquivo ou em qualquer outro arquivo do plugin.
 */
defined( 'ABSPATH' ) || exit;

class DC_Shortcode_Painel {

    const OPTION_HASH   = 'dc_painel_senha_hash';
    const TRANSIENT_TTL = 3600; // 1 hora
    const COOKIE_NAME   = 'dc_painel_token';
    const COOKIE_TTL    = 3600; // 1 hora

    public static function init(): void {
        add_shortcode( 'diario_clinica_painel', [ __CLASS__, 'render' ] );
        add_action( 'init', [ __CLASS__, 'handle_auth_post' ] );
        add_action( 'wp',   [ __CLASS__, 'maybe_enqueue' ] );
        add_action( 'wp_ajax_nopriv_dc_painel_salvar', [ __CLASS__, 'ajax_salvar' ] );
        add_action( 'wp_ajax_dc_painel_salvar',        [ __CLASS__, 'ajax_salvar' ] );
        add_action( 'wp_ajax_nopriv_dc_painel_parse',  [ __CLASS__, 'ajax_parse_texto' ] );
        add_action( 'wp_ajax_dc_painel_parse',         [ __CLASS__, 'ajax_parse_texto' ] );
    }

    /**
     * Enfileira assets somente em páginas que contêm o shortcode.
     * Chamado no hook 'wp' (após query, antes de wp_head).
     */
    public static function maybe_enqueue(): void {
        global $post;
        if (
            is_a( $post, 'WP_Post' ) &&
            has_shortcode( $post->post_content, 'diario_clinica_painel' )
        ) {
            // O gate de senha embute um nonce no HTML. Se a página for cacheada
            // (plugin de cache/CDN), o nonce fica obsoleto e o login é recusado.
            // Sinalizamos que esta página não deve ser cacheada.
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
            nocache_headers();
            add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        }
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style(
            'dc-painel-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'dc-painel',
            DC_PLUGIN_URL . 'public/css/painel.css',
            [ 'dc-painel-fonts' ],
            DC_VERSION
        );

        // Cores personalizadas (painel externo) — sobrescreve as variáveis padrão.
        $theme_css = self::inline_theme_css();
        if ( '' !== $theme_css ) {
            wp_add_inline_style( 'dc-painel', $theme_css );
        }

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'chartjs-datalabels',
            'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js',
            [ 'chartjs' ],
            '2.2.0',
            true
        );

        wp_enqueue_script(
            'dc-painel',
            DC_PLUGIN_URL . 'public/js/painel.js',
            [ 'jquery', 'chartjs', 'chartjs-datalabels' ],
            DC_VERSION,
            true
        );

        wp_localize_script( 'dc-painel', 'DC_PAINEL', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dc_painel_nonce' ),
        ] );
    }

    /**
     * Logotipo do site (definido no WordPress) para o painel externo.
     * Usa o logo do tema; na ausência, o ícone do site; por fim, a inicial do nome.
     */
    private static function site_logo_html(): string {
        $name = get_bloginfo( 'name' );

        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $src = wp_get_attachment_image_url( $logo_id, 'medium' );
            if ( $src ) {
                return '<img class="dc-site-logo" src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '">';
            }
        }

        $icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 96 ) : '';
        if ( $icon ) {
            return '<img class="dc-site-logo dc-site-logo-icon" src="' . esc_url( $icon ) . '" alt="' . esc_attr( $name ) . '">';
        }

        $inicial = strtoupper( mb_substr( wp_strip_all_tags( (string) $name ), 0, 1 ) );
        return '<span class="dc-site-initial" aria-hidden="true">' . esc_html( '' !== $inicial ? $inicial : 'D' ) . '</span>';
    }

    /** Ícone neutro (barras) para estados vazios — sem marca. */
    private static function empty_icon_svg(): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V10m5 9V5m5 14v-7m5 7V8"/></svg>';
    }

    // ----------------------------------------------------------------
    // Tema configurável (cores) — apenas painel externo
    // ----------------------------------------------------------------

    private static function sanitize_hex( $v ): string {
        $v = is_string( $v ) ? trim( $v ) : '';
        return preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? strtolower( $v ) : '';
    }

    private static function darken( string $hex, float $amt ): string {
        $r = hexdec( substr( $hex, 1, 2 ) );
        $g = hexdec( substr( $hex, 3, 2 ) );
        $b = hexdec( substr( $hex, 5, 2 ) );
        $f = max( 0.0, 1.0 - $amt );
        return sprintf( '#%02x%02x%02x', (int) round( $r * $f ), (int) round( $g * $f ), (int) round( $b * $f ) );
    }

    private static function hex_rgba( string $hex, float $a ): string {
        $r = hexdec( substr( $hex, 1, 2 ) );
        $g = hexdec( substr( $hex, 3, 2 ) );
        $b = hexdec( substr( $hex, 5, 2 ) );
        return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( number_format( $a, 2, '.', '' ), '0' ), '.' ) );
    }

    private static function contrast_ink( string $hex ): string {
        $r   = hexdec( substr( $hex, 1, 2 ) );
        $g   = hexdec( substr( $hex, 3, 2 ) );
        $b   = hexdec( substr( $hex, 5, 2 ) );
        $lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
        return $lum > 0.62 ? '#0a0c10' : '#ffffff';
    }

    /** CSS inline que aplica as cores configuradas ao painel externo. */
    private static function inline_theme_css(): string {
        $o    = get_option( 'dc_opcoes', [] );
        $prim = self::sanitize_hex( $o['cor_primaria']   ?? '' );
        $acc  = self::sanitize_hex( $o['cor_secundaria'] ?? '' );
        if ( '' === $prim && '' === $acc ) {
            return '';
        }
        $css = '.dc-painel-wrap.dc61,.dc-painel-gate.dc61{';
        if ( '' !== $prim ) {
            $css .= '--dc-ink:' . $prim . ';';
            $css .= '--dc-ink-2:' . self::darken( $prim, 0.12 ) . ';';
            $css .= '--dc-glow:' . self::hex_rgba( $prim, 0.20 ) . ';';
        }
        if ( '' !== $acc ) {
            $css .= '--dc-signal:' . $acc . ';';
            $css .= '--dc-signal-d:' . self::darken( $acc, 0.10 ) . ';';
            $css .= '--dc-signal-ink:' . self::contrast_ink( $acc ) . ';';
            $css .= '--dc-teal:' . $acc . ';';
            $css .= '--dc-best:' . self::hex_rgba( $acc, 0.12 ) . ';';
            $css .= '--dc-focus:' . self::hex_rgba( $acc, 0.18 ) . ';';
        }
        $css .= '}';
        return $css;
    }

    /** True se o cookie de sessão for válido. */
    private static function is_authenticated(): bool {
        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ?? '' ) );
        if ( $token === '' ) {
            return false;
        }
        return false !== get_transient( 'dc_painel_tok_' . $token );
    }

    /**
     * Intercepta o POST do gate de senha antes de os headers serem enviados.
     * Hook: 'init'.
     */
    public static function handle_auth_post(): void {
        if (
            'POST' !== $_SERVER['REQUEST_METHOD'] ||
            ! isset( $_POST['dc_auth_nonce'], $_POST['dc_painel_senha'] )
        ) {
            return;
        }

        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['dc_auth_nonce'] ) ),
            'dc_painel_auth'
        ) ) {
            // Nonce inválido/expirado — normalmente cópia cacheada da página.
            // Damos feedback ao usuário em vez de falhar silenciosamente.
            wp_safe_redirect( add_query_arg( 'dc_auth_erro', '2' ) );
            exit;
        }

        $senha = wp_unslash( $_POST['dc_painel_senha'] );
        $hash  = (string) get_option( self::OPTION_HASH, '' );

        if ( $hash !== '' && password_verify( $senha, $hash ) ) {
            $token = bin2hex( random_bytes( 32 ) );
            set_transient( 'dc_painel_tok_' . $token, 1, self::TRANSIENT_TTL );
            setcookie(
                self::COOKIE_NAME,
                $token,
                [
                    'expires'  => time() + self::COOKIE_TTL,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure'   => is_ssl(),
                ]
            );
            wp_safe_redirect( remove_query_arg( 'dc_auth_erro' ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'dc_auth_erro', '1' ) );
        exit;
    }

    /** Callback do shortcode. */
    public static function render( array $atts ): string {
        if ( ! self::is_authenticated() ) {
            return self::render_gate();
        }
        return self::render_panel();
    }

    // ----------------------------------------------------------------
    // Gate de senha
    // ----------------------------------------------------------------

    private static function render_gate(): string {
        $erro_code = isset( $_GET['dc_auth_erro'] ) ? (int) $_GET['dc_auth_erro'] : 0;
        $nonce = wp_create_nonce( 'dc_painel_auth' );
        ob_start();
        ?>
        <div class="dc-painel-gate dc61">
            <div class="dc-painel-gate-card">
                <span class="dc61-gate-logo"><?php echo self::site_logo_html(); // phpcs:ignore ?></span>
                <h2 class="dc-painel-gate-titulo">Diário da Clínica</h2>
                <p>Informe a senha para acessar o painel.</p>
                <?php if ( 2 === $erro_code ) : ?>
                    <p class="dc-painel-erro" role="alert">Sua sessão do formulário expirou (provável cache da página). Recarregue a página e tente novamente.</p>
                <?php elseif ( $erro_code > 0 ) : ?>
                    <p class="dc-painel-erro" role="alert">Senha incorreta. Tente novamente.</p>
                <?php endif; ?>
                <form method="POST" class="dc-gate-form">
                    <input type="hidden" name="dc_auth_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <div class="dc-gate-field">
                        <label for="dc-painel-senha-input">Senha</label>
                        <input
                            type="password"
                            name="dc_painel_senha"
                            id="dc-painel-senha-input"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                    <button type="submit" class="dc-btn-primary">Entrar</button>
                </form>
                <p class="dc61-signature">Desenvolvido por <a href="https://61labs.com.br" target="_blank" rel="noreferrer noopener">61 Labs</a></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ----------------------------------------------------------------
    // Painel completo
    // ----------------------------------------------------------------

    private static function render_panel(): string {
        // Filtro de período.
        $de_default  = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
        $ate_default = gmdate( 'Y-m-d' );
        $de  = sanitize_text_field( wp_unslash( $_GET['dc_de']  ?? $de_default ) );
        $ate = sanitize_text_field( wp_unslash( $_GET['dc_ate'] ?? $ate_default ) );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $de ) )  $de  = $de_default;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ate ) ) $ate = $ate_default;

        $rows = DC_DB::relatorio_periodo( $de, $ate );

        // Totais.
        $totais = [];
        foreach ( DC_Parser::$campos as $c ) {
            $totais[ $c ] = array_sum( array_map( static fn( $r ) => (int) ( $r->$c ?? 0 ), $rows ) );
        }

        $total_agend = $totais['agend_trafego'] + $totais['agend_site']
                     + $totais['agend_indicacao'] + $totais['agend_antigos'];
        $conv_la = $totais['total_leads'] > 0
            ? round( $total_agend / $totais['total_leads'] * 100, 1 ) : null;
        $conv_ac = $total_agend > 0
            ? round( $totais['consultas_total'] / $total_agend * 100, 1 ) : null;

        // Funil por origem.
        $funil = [
            [ 'grupo' => 'Tráfego pago',             'leads' => $totais['trafego_pago'],                           'agend' => $totais['agend_trafego'],   'consultas' => $totais['consultas_trafego'] ],
            [ 'grupo' => 'Indicação',                 'leads' => $totais['ind_paciente'] + $totais['ind_medico'],  'agend' => $totais['agend_indicacao'], 'consultas' => $totais['consultas_indicacao'] ],
            [ 'grupo' => 'Orgânico (Site + Instagram)', 'leads' => $totais['site'] + $totais['instagram_organico'], 'agend' => $totais['agend_site'],      'consultas' => $totais['consultas_organico'] ],
            [ 'grupo' => 'Pacientes antigos',         'leads' => $totais['paciente_antigo'],                       'agend' => $totais['agend_antigos'],   'consultas' => $totais['consultas_antigos'] ],
            [ 'grupo' => 'Outros',                    'leads' => $totais['outros'],                                'agend' => null,                       'consultas' => null ],
        ];

        // Origem campeã (maior taxa Leads→Consultas).
        $campeao_conv = null;
        $max_taxa_val = -1.0;
        foreach ( $funil as $g ) {
            if ( $g['consultas'] === null || $g['leads'] <= 0 ) continue;
            $taxa_val = $g['consultas'] / $g['leads'] * 100;
            if ( $taxa_val > $max_taxa_val ) {
                $max_taxa_val = $taxa_val;
                $campeao_conv = $g;
            }
        }

        // Dados para Chart.js.
        $chart_labels    = [];
        $chart_leads     = [];
        $chart_agend     = [];
        $chart_consultas = [];
        foreach ( $rows as $r ) {
            $chart_labels[]    = date( 'd/m', strtotime( $r->data_fechamento ) );
            $r_agend           = (int) $r->agend_trafego + (int) $r->agend_site
                               + (int) $r->agend_indicacao + (int) $r->agend_antigos;
            $chart_leads[]     = (int) $r->total_leads;
            $chart_agend[]     = $r_agend;
            $chart_consultas[] = (int) $r->consultas_total;
        }

        $origens_labels = [ 'Indicação Pac.', 'Indicação Méd.', 'Tráfego Pago', 'Site', 'Instagram Org.', 'Pac. Antigo', 'Outros' ];
        $origens_data   = [
            $totais['ind_paciente'], $totais['ind_medico'], $totais['trafego_pago'],
            $totais['site'], $totais['instagram_organico'], $totais['paciente_antigo'], $totais['outros'],
        ];

        $taxa = static function ( int $num, ?int $den ): string {
            return ( $den !== null && $den > 0 ) ? round( $num / $den * 100, 1 ) . '%' : '—';
        };

        $page_url = esc_url( get_permalink() ?: home_url() );

        // Histórico dos últimos registros (independente do filtro de período).
        $hist      = DC_DB::listar( 1, 30 );
        $hist_rows = $hist['rows'];

        ob_start();
        ?>
        <div class="dc-painel-wrap dc61">

            <!-- Header (logotipo do site) -->
            <header class="dc61-header">
                <div class="dc61-brand">
                    <span class="dc61-logo"><?php echo self::site_logo_html(); // phpcs:ignore ?></span>
                    <div class="dc61-brand-text">
                        <h1>Diário da Clínica</h1>
                        <p>Relatório de conversões da recepção</p>
                    </div>
                </div>
                <div class="dc61-header-actions">
                    <button type="button" class="dc61-btn dc61-btn-signal" id="dc-pub-btn-novo">
                        <span class="dc61-btn-ico" aria-hidden="true">+</span> Novo registro
                    </button>
                </div>
            </header>

            <!-- Barra de período -->
            <div class="dc61-toolbar">
                <form method="GET" action="<?php echo $page_url; ?>" class="dc61-filtro">
                    <div class="dc61-filtro-fields">
                        <label>De
                            <input type="date" name="dc_de" value="<?php echo esc_attr( $de ); ?>" required>
                        </label>
                        <label>Até
                            <input type="date" name="dc_ate" value="<?php echo esc_attr( $ate ); ?>" required>
                        </label>
                    </div>
                    <button type="submit" class="dc61-btn dc61-btn-ink">Consultar</button>
                </form>
                <span class="dc61-periodo">
                    <?php echo esc_html( date( 'd/m/Y', strtotime( $de ) ) ); ?>
                    &rarr;
                    <?php echo esc_html( date( 'd/m/Y', strtotime( $ate ) ) ); ?>
                </span>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <div class="dc61-empty">
                    <span class="dc61-empty-ico" aria-hidden="true"><?php echo self::empty_icon_svg(); // phpcs:ignore ?></span>
                    <p>Nenhum dado encontrado para o período selecionado.</p>
                    <button type="button" class="dc61-btn dc61-btn-signal" id="dc-pub-btn-novo-2">Registrar o primeiro dia</button>
                </div>
            <?php else : ?>

            <!-- Métricas resumidas -->
            <div class="dc61-metrics">
                <div class="dc61-metric">
                    <span class="dc61-metric-lbl">Total de Leads</span>
                    <span class="dc61-metric-val"><?php echo esc_html( $totais['total_leads'] ); ?></span>
                    <span class="dc61-metric-hint">no período</span>
                </div>
                <div class="dc61-metric">
                    <span class="dc61-metric-lbl">Agendamentos</span>
                    <span class="dc61-metric-val"><?php echo esc_html( $total_agend ); ?></span>
                    <span class="dc61-metric-hint">agendados</span>
                </div>
                <div class="dc61-metric">
                    <span class="dc61-metric-lbl">Consultas</span>
                    <span class="dc61-metric-val"><?php echo esc_html( $totais['consultas_total'] ); ?></span>
                    <span class="dc61-metric-hint">realizadas</span>
                </div>
                <div class="dc61-metric dc61-metric-conv">
                    <span class="dc61-metric-lbl">Conv. Leads &rarr; Agend.</span>
                    <span class="dc61-metric-val"><?php echo $conv_la !== null ? esc_html( $conv_la ) . '%' : '—'; ?></span>
                    <span class="dc61-metric-hint">taxa de agendamento</span>
                </div>
                <div class="dc61-metric dc61-metric-conv">
                    <span class="dc61-metric-lbl">Conv. Agend. &rarr; Consul.</span>
                    <span class="dc61-metric-val"><?php echo $conv_ac !== null ? esc_html( $conv_ac ) . '%' : '—'; ?></span>
                    <span class="dc61-metric-hint">taxa de comparecimento</span>
                </div>
            </div>

            <?php if ( $campeao_conv ) :
                $campeao_taxa = round( $campeao_conv['consultas'] / $campeao_conv['leads'] * 100, 1 );
            ?>
            <!-- Destaque: origem com mais conversões -->
            <section class="dc61-hero">
                <div class="dc61-hero-badge" aria-hidden="true">★ Destaque</div>
                <div class="dc61-hero-body">
                    <span class="dc61-hero-label">Origem com mais conversões</span>
                    <span class="dc61-hero-name"><?php echo esc_html( $campeao_conv['grupo'] ); ?></span>
                    <span class="dc61-hero-detail">
                        <?php echo esc_html( $campeao_conv['consultas'] ); ?> consultas
                        de <?php echo esc_html( $campeao_conv['leads'] ); ?> leads
                    </span>
                </div>
                <div class="dc61-hero-rate">
                    <span class="dc61-hero-rate-val"><?php echo esc_html( $campeao_taxa ); ?>%</span>
                    <span class="dc61-hero-rate-lbl">Leads &rarr; Consultas</span>
                </div>
            </section>
            <?php endif; ?>

            <!-- Gráficos -->
            <div class="dc61-charts">
                <section class="dc61-card dc61-chart">
                    <header class="dc61-card-head">
                        <h3>Evolução diária</h3>
                        <span class="dc61-card-sub">Leads · Agendamentos · Consultas</span>
                    </header>
                    <div class="dc61-chart-canvas">
                        <canvas id="dc-pub-chart-evolucao" height="120"></canvas>
                    </div>
                </section>
                <section class="dc61-card dc61-chart">
                    <header class="dc61-card-head">
                        <h3>Distribuição de origens</h3>
                        <span class="dc61-card-sub">Participação de cada canal</span>
                    </header>
                    <div class="dc61-chart-canvas">
                        <canvas id="dc-pub-chart-origens" height="120"></canvas>
                    </div>
                </section>
            </div>

            <!-- Funil de conversão por origem -->
            <section class="dc61-card">
                <header class="dc61-card-head">
                    <h3>Conversão por origem</h3>
                    <span class="dc61-card-sub">Da captação à consulta realizada</span>
                </header>
                <div class="dc61-table-wrap">
                    <table class="dc61-table dc61-table-funil">
                        <thead>
                            <tr>
                                <th>Origem</th>
                                <th>Leads</th>
                                <th>Agend.</th>
                                <th>Consultas</th>
                                <th>Taxa L&rarr;A</th>
                                <th>Taxa A&rarr;C</th>
                                <th>Taxa L&rarr;C</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $campeao_nome = $campeao_conv['grupo'] ?? null;
                        foreach ( $funil as $g ) :
                            $g_leads     = (int) $g['leads'];
                            $g_agend     = $g['agend'] !== null ? (int) $g['agend'] : null;
                            $g_consultas = $g['consultas'] !== null ? (int) $g['consultas'] : null;
                            $is_best     = ( $campeao_nome !== null && $g['grupo'] === $campeao_nome );
                        ?>
                            <tr<?php echo $is_best ? ' class="dc61-row-best"' : ''; ?>>
                                <td>
                                    <strong><?php echo esc_html( $g['grupo'] ); ?></strong>
                                    <?php echo $is_best ? ' <span class="dc61-tag">Campeã</span>' : ''; ?>
                                </td>
                                <td><?php echo esc_html( $g_leads ); ?></td>
                                <td><?php echo $g_agend !== null ? esc_html( $g_agend ) : '<span class="dc-nd">—</span>'; ?></td>
                                <td><?php echo $g_consultas !== null ? esc_html( $g_consultas ) : '<span class="dc-nd">—</span>'; ?></td>
                                <td><?php echo esc_html( $taxa( $g_agend ?? 0, $g_leads > 0 ? $g_leads : null ) ); ?></td>
                                <td><?php echo esc_html( $taxa( $g_consultas ?? 0, $g_agend ) ); ?></td>
                                <td><?php echo esc_html( $taxa( $g_consultas ?? 0, $g_leads > 0 ? $g_leads : null ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Dados para Chart.js (inline para sincronizar com os canvas acima) -->
            <script>
            window.DC_PUB_CHART_DATA = <?php echo wp_json_encode( [
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

            <!-- Histórico de registros -->
            <section class="dc61-card" id="dc-pub-historico-card">
                <header class="dc61-card-head">
                    <h3>Histórico de registros</h3>
                    <span class="dc61-card-sub">Últimos fechamentos enviados</span>
                </header>
                <div class="dc61-table-wrap">
                    <table class="dc61-table dc61-table-hist" id="dc-pub-historico-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Leads</th>
                                <th>Agend.</th>
                                <th>Consultas</th>
                                <th class="dc61-col-acoes">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="dc-pub-historico-tbody">
                        <?php if ( empty( $hist_rows ) ) : ?>
                            <tr class="dc-pub-hist-vazio"><td colspan="5">Nenhum registro ainda.</td></tr>
                        <?php else :
                            foreach ( $hist_rows as $hr ) :
                                $h_campos = [];
                                foreach ( DC_Parser::$campos as $c ) {
                                    $h_campos[ $c ] = (int) ( $hr->$c ?? 0 );
                                }
                                $h_agend = $h_campos['agend_trafego'] + $h_campos['agend_site']
                                         + $h_campos['agend_indicacao'] + $h_campos['agend_antigos'];
                                $h_iso   = date( 'Y-m-d', strtotime( $hr->data_fechamento ) );
                                $h_br    = date( 'd/m/Y', strtotime( $hr->data_fechamento ) );
                                $h_json  = wp_json_encode( $h_campos );
                        ?>
                            <tr data-data="<?php echo esc_attr( $h_iso ); ?>">
                                <td><strong><?php echo esc_html( $h_br ); ?></strong></td>
                                <td><?php echo esc_html( $h_campos['total_leads'] ); ?></td>
                                <td><?php echo esc_html( $h_agend ); ?></td>
                                <td><?php echo esc_html( $h_campos['consultas_total'] ); ?></td>
                                <td class="dc61-col-acoes">
                                    <div class="dc61-row-actions">
                                        <button type="button" class="dc61-btn dc61-btn-ghost dc-pub-edit-btn"
                                                data-data="<?php echo esc_attr( $h_iso ); ?>"
                                                data-campos="<?php echo esc_attr( $h_json ); ?>">
                                            Editar
                                        </button>
                                        <button type="button" class="dc61-btn dc61-btn-ghost dc-pub-wa-btn"
                                                data-data="<?php echo esc_attr( $h_iso ); ?>"
                                                data-campos="<?php echo esc_attr( $h_json ); ?>">
                                            WhatsApp
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Assinatura discreta -->
            <footer class="dc61-footer">
                <span>Desenvolvido por <a href="https://61labs.com.br" target="_blank" rel="noreferrer noopener">61 Labs</a></span>
            </footer>

            <!-- Modal WhatsApp -->
            <div id="dc-pub-wa-modal" class="dc-pub-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dc-pub-wa-titulo">
                <div class="dc-pub-modal-inner">
                    <div class="dc-pub-modal-header">
                        <h3 id="dc-pub-wa-titulo">Enviar para WhatsApp</h3>
                        <button type="button" id="dc-pub-wa-close" class="dc-pub-modal-close" aria-label="Fechar">&times;</button>
                    </div>
                    <textarea id="dc-pub-wa-text" class="dc-pub-wa-text" rows="18" readonly></textarea>
                    <div class="dc-pub-wa-actions">
                        <button type="button" id="dc-pub-wa-copy" class="dc61-btn dc61-btn-signal">📋 Copiar</button>
                        <span id="dc-pub-wa-feedback" class="dc-pub-wa-feedback" aria-live="polite"></span>
                    </div>
                </div>
            </div>

            <!-- Modal de novo registro -->
            <div id="dc-pub-modal" class="dc-pub-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dc-pub-modal-titulo">
                <div class="dc-pub-modal-inner">
                    <div class="dc-pub-modal-header">
                        <h3 id="dc-pub-modal-titulo">Novo Registro</h3>
                        <button type="button" id="dc-pub-modal-close" class="dc-pub-modal-close" aria-label="Fechar">&times;</button>
                    </div>
                    <div id="dc-pub-form-msg"></div>

                    <div id="dc-pub-import-block">
                        <div class="dc-pub-import">
                            <label for="dc-pub-import-texto"><strong>Importar colando o texto do fechamento</strong></label>
                            <textarea
                                id="dc-pub-import-texto"
                                class="dc-pub-import-textarea"
                                rows="8"
                                placeholder="Fechamento do dia DD/MM/AAAA&#10;Origem LEADS&#10;👥 Indicação de paciente: 0&#10;👨‍⚕️ Indicação de médico: 0&#10;💰 Tráfego pago: 0&#10;..."></textarea>
                            <div class="dc-pub-import-actions">
                                <button type="button" id="dc-pub-btn-importar" class="dc61-btn dc61-btn-ink">Processar texto</button>
                                <span class="dc-pub-import-hint">Os campos abaixo serão preenchidos automaticamente. Revise e clique em “Salvar registro”.</span>
                            </div>
                        </div>
                        <hr class="dc-pub-sep">
                    </div>

                    <form id="dc-pub-form" class="dc-pub-form">
                        <div class="dc-pub-field dc-pub-field-date">
                            <label for="dc-pub-data"><strong>Data de fechamento</strong></label>
                            <input type="date" id="dc-pub-data" name="data_fechamento" required value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
                        </div>

                        <fieldset class="dc-pub-fieldset">
                            <legend>Origens de Leads</legend>
                            <div class="dc-pub-fields-grid">
                                <?php
                                $campos_origens = [
                                    'ind_paciente'       => 'Indicação de Paciente',
                                    'ind_medico'         => 'Indicação de Médico',
                                    'trafego_pago'       => 'Tráfego Pago',
                                    'site'               => 'Site',
                                    'instagram_organico' => 'Instagram Orgânico',
                                    'paciente_antigo'    => 'Paciente Já da Clínica',
                                    'outros'             => 'Outros',
                                    'total_leads'        => 'Total de Leads',
                                ];
                                foreach ( $campos_origens as $campo => $label ) : ?>
                                <div class="dc-pub-field">
                                    <label for="dc-pub-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $label ); ?></label>
                                    <input type="number" min="0" id="dc-pub-<?php echo esc_attr( $campo ); ?>" name="<?php echo esc_attr( $campo ); ?>" value="0">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <fieldset class="dc-pub-fieldset">
                            <legend>Agendamentos</legend>
                            <div class="dc-pub-fields-grid">
                                <?php
                                $campos_agend = [
                                    'agend_trafego'   => 'Agend. Tráfego',
                                    'agend_site'      => 'Agend. Site',
                                    'agend_indicacao' => 'Agend. Indicação',
                                    'agend_antigos'   => 'Agend. Pacientes Antigos',
                                ];
                                foreach ( $campos_agend as $campo => $label ) : ?>
                                <div class="dc-pub-field">
                                    <label for="dc-pub-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $label ); ?></label>
                                    <input type="number" min="0" id="dc-pub-<?php echo esc_attr( $campo ); ?>" name="<?php echo esc_attr( $campo ); ?>" value="0">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <fieldset class="dc-pub-fieldset">
                            <legend>Consultas Realizadas</legend>
                            <div class="dc-pub-fields-grid">
                                <?php
                                $campos_consultas = [
                                    'consultas_total'     => 'Consultas Total',
                                    'consultas_trafego'   => 'Consultas Tráfego',
                                    'consultas_organico'  => 'Consultas Orgânico',
                                    'consultas_antigos'   => 'Consultas Pac. Antigos',
                                    'consultas_indicacao' => 'Consultas Indicação',
                                ];
                                foreach ( $campos_consultas as $campo => $label ) : ?>
                                <div class="dc-pub-field">
                                    <label for="dc-pub-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $label ); ?></label>
                                    <input type="number" min="0" id="dc-pub-<?php echo esc_attr( $campo ); ?>" name="<?php echo esc_attr( $campo ); ?>" value="0">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="dc-pub-form-actions">
                            <button type="submit" id="dc-pub-btn-submit" class="dc61-btn dc61-btn-signal">Salvar registro</button>
                            <button type="button" id="dc-pub-btn-cancelar" class="dc61-btn dc61-btn-ghost">Cancelar</button>
                            <span id="dc-pub-spinner" class="dc-pub-spinner" style="display:none;">Salvando…</span>
                        </div>
                    </form>
                </div>
            </div><!-- #dc-pub-modal -->

        </div><!-- .dc-painel-wrap -->
        <?php
        return ob_get_clean();
    }

    // ----------------------------------------------------------------
    // AJAX — salvar registro via formulário estruturado
    // ----------------------------------------------------------------

    public static function ajax_salvar(): void {
        check_ajax_referer( 'dc_painel_nonce', 'nonce' );

        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ?? '' ) );
        if ( $token === '' || false === get_transient( 'dc_painel_tok_' . $token ) ) {
            wp_send_json_error( [ 'msg' => 'Sessão expirada. Recarregue a página e faça login novamente.' ], 403 );
        }

        $data = sanitize_text_field( wp_unslash( $_POST['data_fechamento'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data ) ) {
            wp_send_json_error( [ 'msg' => 'Data inválida.' ] );
        }

        $campos = [];
        foreach ( DC_Parser::$campos as $campo ) {
            $val             = sanitize_text_field( wp_unslash( $_POST[ $campo ] ?? '0' ) );
            $campos[ $campo ] = max( 0, (int) $val );
        }

        $sobrescrever = isset( $_POST['sobrescrever'] ) && '1' === $_POST['sobrescrever'];

        $result = DC_DB::salvar( $data, $campos, '', $sobrescrever );

        if ( ! $result['ok'] && $result['duplicado'] ) {
            wp_send_json_error( [
                'msg'       => "Já existe um registro para {$data}. Deseja sobrescrever?",
                'duplicado' => true,
                'data'      => $data,
            ] );
        }

        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'msg' => 'Erro ao salvar: ' . esc_html( $result['msg'] ) ] );
        }

        wp_send_json_success( [
            'msg'    => "Registro de {$data} salvo com sucesso (ID #{$result['id']}).",
            'id'     => $result['id'],
            'data'   => $data,
            'campos' => $campos,
        ] );
    }

    // ----------------------------------------------------------------
    // AJAX — interpreta texto colado do fechamento (mesmo parser do admin)
    // ----------------------------------------------------------------

    public static function ajax_parse_texto(): void {
        check_ajax_referer( 'dc_painel_nonce', 'nonce' );

        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ?? '' ) );
        if ( $token === '' || false === get_transient( 'dc_painel_tok_' . $token ) ) {
            wp_send_json_error( [ 'msg' => 'Sessão expirada. Recarregue a página e faça login novamente.' ], 403 );
        }

        $texto = sanitize_textarea_field( wp_unslash( $_POST['texto'] ?? '' ) );
        if ( $texto === '' ) {
            wp_send_json_error( [ 'msg' => 'Cole o texto do relatório antes de processar.' ] );
        }

        $parsed = DC_Parser::parse( $texto );

        if ( empty( $parsed['data'] ) ) {
            wp_send_json_error( [
                'msg' => 'Data de fechamento não encontrada. Certifique-se de que o texto contém "Fechamento do dia DD/MM/AAAA".',
            ] );
        }

        wp_send_json_success( [
            'data'   => $parsed['data'],
            'campos' => $parsed['campos'],
            'avisos' => $parsed['avisos'],
        ] );
    }
}
