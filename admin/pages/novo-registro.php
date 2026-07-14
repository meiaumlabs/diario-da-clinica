<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'dc_manage' ) ) {
    wp_die( 'Acesso negado.' );
}

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
<div class="wrap dc-wrap">
    <h1>Diário da Clínica — Novo Registro</h1>

    <div class="dc-card">
        <h2>Cole o relatório de fechamento</h2>
        <p class="description">
            Cole o texto completo do relatório (com os emojis, como recebido da recepção) e clique em
            <strong>Processar</strong>. Os campos serão reconhecidos automaticamente.
        </p>

        <textarea
            id="dc-texto"
            rows="22"
            placeholder="Fechamento do dia DD/MM/AAAA&#10;Origem LEADS&#10;👥 Indicação de paciente: 0&#10;👨‍⚕️ Indicação de médico: 0&#10;💰 Tráfego pago: 0&#10;..."
        ></textarea>

        <div class="dc-actions">
            <button id="dc-btn-processar" type="button" class="button button-primary button-hero">
                ⚙️ Processar
            </button>
            <span id="dc-spinner" class="spinner dc-spinner"></span>
        </div>
    </div>

    <div id="dc-resultado" style="display:none;" class="dc-card">
        <div id="dc-msg-area"></div>

        <div id="dc-preview-wrap" style="display:none;">
            <h3 id="dc-preview-titulo"></h3>

            <table class="dc-table dc-preview-table widefat striped">
                <thead>
                    <tr><th>Campo</th><th>Valor</th></tr>
                </thead>
                <tbody id="dc-preview-tbody"></tbody>
            </table>

            <div id="dc-avisos-wrap" style="display:none;">
                <h4>Avisos</h4>
                <ul id="dc-avisos-lista" class="dc-avisos"></ul>
            </div>

            <div class="dc-actions" id="dc-confirm-wrap" style="display:none;">
                <button id="dc-btn-sobrescrever" type="button" class="button button-primary">
                    Sobrescrever registro existente
                </button>
                <button id="dc-btn-cancelar" type="button" class="button">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script>
// Inline data for field labels — used by admin.js.
window.DC_LABELS = <?php echo wp_json_encode( $labels ); ?>;
</script>
