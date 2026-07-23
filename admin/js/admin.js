/* Diário da Clínica — Admin JS */
/* global DC, DC_LABELS, DC_CHART_DATA, Chart, jQuery */

(function ($) {
    'use strict';

    // =========================================================
    // Novo Registro — parse & save
    // =========================================================
    var $texto, $btnProcessar, $spinner, $resultado, $msgArea,
        $previewWrap, $previewTitulo, $previewTbody,
        $avisosWrap, $avisosList,
        $confirmWrap, $btnSobrescrever, $btnCancelar, $btnWhatsapp;

    var dcLastWa = '';

    function initNovoRegistro() {
        $texto          = $('#dc-texto');
        $btnProcessar   = $('#dc-btn-processar');
        $spinner        = $('#dc-spinner');
        $resultado      = $('#dc-resultado');
        $msgArea        = $('#dc-msg-area');
        $previewWrap    = $('#dc-preview-wrap');
        $previewTitulo  = $('#dc-preview-titulo');
        $previewTbody   = $('#dc-preview-tbody');
        $avisosWrap     = $('#dc-avisos-wrap');
        $avisosList     = $('#dc-avisos-lista');
        $confirmWrap    = $('#dc-confirm-wrap');
        $btnSobrescrever = $('#dc-btn-sobrescrever');
        $btnCancelar    = $('#dc-btn-cancelar');
        $btnWhatsapp    = $('#dc-btn-whatsapp');

        if ( ! $btnProcessar.length ) return;

        $btnProcessar.on('click', function () {
            processar(false);
        });

        $btnSobrescrever.on('click', function () {
            processar(true);
        });

        $btnCancelar.on('click', function () {
            $confirmWrap.hide();
            $msgArea.html('<p class="dc-error">Operação cancelada.</p>');
        });

        $btnWhatsapp.on('click', function () {
            if (dcLastWa) openWaModal(dcLastWa);
        });
    }

    function processar(sobrescrever) {
        var texto = $texto.val().trim();
        if (!texto) {
            showMsg('error', 'Cole o texto do relatório antes de processar.');
            $resultado.show();
            return;
        }

        $btnProcessar.prop('disabled', true);
        $spinner.show();
        $resultado.hide();
        $previewWrap.hide();
        $confirmWrap.hide();
        if ($btnWhatsapp) $btnWhatsapp.hide();
        dcLastWa = '';

        $.ajax({
            url:    DC.ajax_url,
            method: 'POST',
            data: {
                action:       'dc_processar',
                nonce:        DC.nonce,
                texto:        texto,
                sobrescrever: sobrescrever ? '1' : '0',
            },
            success: function (resp) {
                $spinner.hide();
                $btnProcessar.prop('disabled', false);
                $resultado.show();

                if (resp.success) {
                    showMsg('success', resp.data.msg);
                    renderPreview(resp.data.campos, null);
                    renderAvisos(resp.data.avisos || []);
                    $confirmWrap.hide();
                    if (resp.data.data && $btnWhatsapp) {
                        dcLastWa = dcBuildWhatsapp(resp.data.data, resp.data.campos);
                        $btnWhatsapp.show();
                    }
                    $texto.val('');
                } else {
                    if (resp.data && resp.data.duplicado) {
                        showMsg('error', resp.data.msg);
                        renderPreview(resp.data.campos, resp.data.data);
                        renderAvisos(resp.data.avisos || []);
                        $confirmWrap.show();
                    } else {
                        showMsg('error', resp.data ? resp.data.msg : 'Erro desconhecido.');
                        $previewWrap.hide();
                    }
                }
            },
            error: function () {
                $spinner.hide();
                $btnProcessar.prop('disabled', false);
                showMsg('error', 'Falha na comunicação com o servidor.');
                $resultado.show();
            },
        });
    }

    function showMsg(type, msg) {
        $msgArea.html('<p class="dc-' + type + '">' + escHtml(msg) + '</p>');
    }

    function renderPreview(campos, data) {
        var labels = window.DC_LABELS || {};
        var titulo = data
            ? 'Pré-visualização — ' + formatDataBR(data)
            : 'Dados reconhecidos';
        $previewTitulo.text(titulo);

        var html = '';
        $.each(campos, function (chave, valor) {
            var label = labels[chave] || chave;
            html += '<tr><th>' + escHtml(label) + '</th><td>' + escHtml(String(valor)) + '</td></tr>';
        });
        $previewTbody.html(html);
        $previewWrap.show();
    }

    function renderAvisos(avisos) {
        if (!avisos || !avisos.length) {
            $avisosWrap.hide();
            return;
        }
        var html = '';
        $.each(avisos, function (_, a) {
            html += '<li>' + escHtml(a) + '</li>';
        });
        $avisosList.html(html);
        $avisosWrap.show();
    }

    function formatDataBR(iso) {
        // 'YYYY-MM-DD' → 'DD/MM/YYYY'
        var p = iso.split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // =========================================================
    // WhatsApp — geração de texto + modal
    // =========================================================
    var DC_WA_ROWS = [
        { emoji: '\uD83D\uDC65',            label: 'Indicação de paciente',                    key: 'ind_paciente' },
        { emoji: '\uD83D\uDC68\u200D\u2695\uFE0F', label: 'Indicação de médico',               key: 'ind_medico' },
        { emoji: '\uD83D\uDCB0',            label: 'Tráfego pago',                             key: 'trafego_pago' },
        { emoji: '\uD83D\uDCBB',            label: 'Site',                                     key: 'site' },
        { emoji: '\uD83D\uDCF1',            label: 'Instagram orgânico',                       key: 'instagram_organico' },
        { emoji: '\uD83C\uDFE5',            label: 'Paciente já da clínica',                   key: 'paciente_antigo' },
        { emoji: '\uD83D\uDCCC',            label: 'Outros',                                   key: 'outros' },
        { sep: true },
        { emoji: '\uD83D\uDCC8',            label: 'Total de Leads',                           key: 'total_leads' },
        { emoji: '\uD83D\uDCC5',            label: 'Agendamentos (tráfego)',                   key: 'agend_trafego' },
        { emoji: '\uD83D\uDCC5',            label: 'Agendamentos (site)',                      key: 'agend_site' },
        { emoji: '\uD83D\uDCC5',            label: 'Agendamentos (indicação)',                 key: 'agend_indicacao' },
        { emoji: '\uD83D\uDCC5',            label: 'Agendamentos (pacientes antigos)',         key: 'agend_antigos' },
        { emoji: '\u2705',                  label: 'Consultas realizadas',                     key: 'consultas_total' },
        { emoji: '\u2705',                  label: 'Consultas realizadas (tráfego)',           key: 'consultas_trafego' },
        { emoji: '\u2705',                  label: 'Consultas realizadas (orgânico)',          key: 'consultas_organico' },
        { emoji: '\u2705',                  label: 'Consultas realizadas (pacientes antigos)', key: 'consultas_antigos' },
        { emoji: '\u2705',                  label: 'Consultas realizadas (indicação)',         key: 'consultas_indicacao' }
    ];

    function dcFmtNum(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) n = 0;
        return n === 0 ? '0' : String(n).padStart(2, '0');
    }

    function dcBuildWhatsapp(dataIso, campos) {
        campos = campos || {};
        var lines = ['Fechamento do dia ' + formatDataBR(dataIso), 'Origem LEADS'];
        DC_WA_ROWS.forEach(function (r) {
            if (r.sep) { lines.push(''); return; }
            lines.push(r.emoji + '  ' + r.label + ': ' + dcFmtNum(campos[r.key]));
        });
        return lines.join('\n');
    }

    var $waModal, $waText, $waFeedback;

    function ensureWaModal() {
        if ($waModal && $waModal.length) return;
        var html =
            '<div id="dc-wa-modal" class="dc-wa-modal" style="display:none;">' +
              '<div class="dc-wa-backdrop"></div>' +
              '<div class="dc-wa-dialog" role="dialog" aria-modal="true" aria-label="Texto para WhatsApp">' +
                '<div class="dc-wa-header">' +
                  '<h2>Enviar para WhatsApp</h2>' +
                  '<button type="button" class="dc-wa-close" aria-label="Fechar">&times;</button>' +
                '</div>' +
                '<textarea id="dc-wa-text" class="dc-wa-text" rows="20" readonly></textarea>' +
                '<div class="dc-wa-actions">' +
                  '<button type="button" id="dc-wa-copy" class="button button-primary">\uD83D\uDCCB Copiar</button>' +
                  '<span id="dc-wa-feedback" class="dc-wa-feedback" aria-live="polite"></span>' +
                '</div>' +
              '</div>' +
            '</div>';
        $('body').append(html);
        $waModal    = $('#dc-wa-modal');
        $waText     = $('#dc-wa-text');
        $waFeedback = $('#dc-wa-feedback');

        $waModal.on('click', '.dc-wa-close, .dc-wa-backdrop', closeWaModal);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') closeWaModal();
        });
        $('#dc-wa-copy').on('click', copyWaText);
    }

    function openWaModal(text) {
        ensureWaModal();
        $waText.val(text);
        $waFeedback.text('').removeClass('dc-wa-ok');
        $waModal.css('display', 'flex');
    }

    function closeWaModal() {
        if ($waModal) $waModal.hide();
    }

    function copyWaText() {
        var text = $waText.val();

        function done() {
            $waFeedback.text('Copiado!').addClass('dc-wa-ok');
            setTimeout(function () { $waFeedback.text('').removeClass('dc-wa-ok'); }, 2500);
        }
        function legacyCopy() {
            $waText[0].focus();
            $waText[0].select();
            try { document.execCommand('copy'); done(); } catch (e) {}
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, legacyCopy);
        } else {
            legacyCopy();
        }
    }

    function initHistorico() {
        $(document).on('click', '.dc-wa-btn', function () {
            var $btn = $(this);
            openWaModal(dcBuildWhatsapp($btn.data('data'), $btn.data('campos')));
        });
    }

    // =========================================================
    // Relatórios — Chart.js
    // =========================================================
    function initCharts() {
        if ( typeof window.DC_CHART_DATA === 'undefined' ) return;
        if ( typeof Chart === 'undefined' ) return;

        // C-003: register datalabels plugin so it only affects charts that opt in.
        if ( typeof ChartDataLabels !== 'undefined' ) {
            Chart.register(ChartDataLabels);
        }

        var data = window.DC_CHART_DATA;

        // Line chart — evolução.
        var ctxEv = document.getElementById('dc-chart-evolucao');
        if (ctxEv) {
            new Chart(ctxEv, {
                type: 'line',
                data: {
                    labels: data.evolucao.labels,
                    datasets: [
                        {
                            label:           'Leads',
                            data:            data.evolucao.leads,
                            borderColor:     '#1d4ed8',
                            backgroundColor: 'rgba(29,78,216,.1)',
                            tension:         0.3,
                            fill:            true,
                        },
                        {
                            label:           'Agendamentos',
                            data:            data.evolucao.agend,
                            borderColor:     '#7c3aed',
                            backgroundColor: 'rgba(124,58,237,.08)',
                            tension:         0.3,
                            fill:            true,
                        },
                        {
                            label:           'Consultas',
                            data:            data.evolucao.consultas,
                            borderColor:     '#059669',
                            backgroundColor: 'rgba(5,150,105,.08)',
                            tension:         0.3,
                            fill:            true,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top' },
                        // C-003: disable datalabels on the line chart.
                        datalabels: { display: false },
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    },
                },
            });
        }

        // Pie chart — origens.
        var ctxOr = document.getElementById('dc-chart-origens');
        if (ctxOr) {
            new Chart(ctxOr, {
                type: 'doughnut',
                data: {
                    labels: data.origens.labels,
                    datasets: [{
                        data:            data.origens.data,
                        backgroundColor: [
                            '#1d4ed8','#7c3aed','#db2777',
                            '#059669','#d97706','#dc2626','#6b7280',
                        ],
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'right' },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                                    var pct   = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                    return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                                },
                            },
                        },
                        // C-003: permanent labels on each doughnut slice.
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 11 },
                            textAlign: 'center',
                            formatter: function(value, ctx) {
                                if (!value) return null;
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                                var pct = total > 0 ? Math.round(value / total * 1000) / 10 : 0;
                                return value + '\n' + pct + '%';
                            },
                            display: function(ctx) {
                                // Hide label for zero-value slices.
                                return ctx.dataset.data[ctx.dataIndex] > 0;
                            },
                        },
                    },
                },
            });
        }
    }

    // =========================================================
    // Bootstrap
    // =========================================================
    $(function () {
        initNovoRegistro();
        initHistorico();
        initCharts();
    });

}(jQuery));
