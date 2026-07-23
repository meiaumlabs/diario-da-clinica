/* Diário da Clínica — Painel Público JS */
/* global DC_PAINEL, DC_PUB_CHART_DATA, Chart, ChartDataLabels, jQuery */

(function ($) {
    'use strict';

    // =========================================================
    // Gráficos Chart.js (mesma lógica do admin)
    // =========================================================
    function initCharts() {
        if (typeof window.DC_PUB_CHART_DATA === 'undefined') return;
        if (typeof Chart === 'undefined') return;

        if (typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels);
        }

        var data = window.DC_PUB_CHART_DATA;

        // Gráfico de linha — evolução.
        var ctxEv = document.getElementById('dc-pub-chart-evolucao');
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
                        legend:     { position: 'top' },
                        datalabels: { display: false },
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    },
                },
            });
        }

        // Gráfico de rosca — origens.
        var ctxOr = document.getElementById('dc-pub-chart-origens');
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
                                label: function (ctx) {
                                    var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                    var pct   = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                    return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                                },
                            },
                        },
                        datalabels: {
                            color:     '#fff',
                            font:      { weight: 'bold', size: 11 },
                            textAlign: 'center',
                            formatter: function (value, ctx) {
                                if (!value) return null;
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total > 0 ? Math.round(value / total * 1000) / 10 : 0;
                                return value + '\n' + pct + '%';
                            },
                            display: function (ctx) {
                                return ctx.dataset.data[ctx.dataIndex] > 0;
                            },
                        },
                    },
                },
            });
        }
    }

    // =========================================================
    // Modal de novo registro
    // =========================================================
    var $modal, $form, $msgArea, $btnSubmit, $btnCancelar, $spinner;
    var pendingOverwrite = false;

    function initModal() {
        $modal      = $('#dc-pub-modal');
        $form       = $('#dc-pub-form');
        $msgArea    = $('#dc-pub-form-msg');
        $btnSubmit  = $('#dc-pub-btn-submit');
        $btnCancelar = $('#dc-pub-btn-cancelar');
        $spinner    = $('#dc-pub-spinner');

        if (!$modal.length) return;

        // Abrir modal.
        $('#dc-pub-btn-novo').on('click', function () {
            pendingOverwrite = false;
            $msgArea.empty();
            $form[0].reset();
            $('#dc-pub-import-texto').val('');
            $modal.show();
            $modal.find('input[type="date"]').val(today());
        });

        // Importar via texto colado.
        $('#dc-pub-btn-importar').on('click', importarTexto);

        // Fechar modal.
        $('#dc-pub-modal-close, #dc-pub-btn-cancelar').on('click', closeModal);

        // Fechar ao clicar fora.
        $modal.on('click', function (e) {
            if ($(e.target).is($modal)) {
                closeModal();
            }
        });

        // Submit.
        $form.on('submit', function (e) {
            e.preventDefault();
            salvar(false);
        });
    }

    function closeModal() {
        $modal.hide();
        pendingOverwrite = false;
        $msgArea.empty();
        $('#dc-pub-import-texto').val('');
    }

    function importarTexto() {
        if (!DC_PAINEL || !DC_PAINEL.ajax_url) return;

        var texto = $('#dc-pub-import-texto').val().trim();
        if (!texto) {
            showMsg('error', 'Cole o texto do relatório antes de processar.');
            return;
        }

        var $btn = $('#dc-pub-btn-importar');
        $btn.prop('disabled', true);
        $msgArea.empty();

        $.ajax({
            url:    DC_PAINEL.ajax_url,
            method: 'POST',
            data: {
                action: 'dc_painel_parse',
                nonce:  DC_PAINEL.nonce,
                texto:  texto,
            },
            success: function (resp) {
                $btn.prop('disabled', false);
                if (resp.success) {
                    preencherForm(resp.data.data, resp.data.campos);
                    var msg = 'Texto processado. Revise os campos e clique em “Salvar registro”.';
                    if (resp.data.avisos && resp.data.avisos.length) {
                        msg += '<ul class="dc-pub-avisos"><li>' +
                               resp.data.avisos.map(escHtml).join('</li><li>') +
                               '</li></ul>';
                    }
                    showMsg('success', msg);
                } else {
                    showMsg('error', resp.data ? resp.data.msg : 'Erro desconhecido.');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                showMsg('error', 'Falha na comunicação com o servidor.');
            },
        });
    }

    function preencherForm(data, campos) {
        if (data) {
            $('#dc-pub-data').val(data);
        }
        $.each(campos || {}, function (chave, valor) {
            var $inp = $('#dc-pub-' + chave);
            if ($inp.length) {
                $inp.val(parseInt(valor, 10) || 0);
            }
        });
    }

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    function salvar(sobrescrever) {
        if (!DC_PAINEL || !DC_PAINEL.ajax_url) return;

        var formData = $form.serialize();
        formData += '&action=dc_painel_salvar';
        formData += '&nonce=' + encodeURIComponent(DC_PAINEL.nonce);
        if (sobrescrever) {
            formData += '&sobrescrever=1';
        }

        $btnSubmit.prop('disabled', true);
        $spinner.show();
        $msgArea.empty();

        $.ajax({
            url:    DC_PAINEL.ajax_url,
            method: 'POST',
            data:   formData,
            success: function (resp) {
                $spinner.hide();
                $btnSubmit.prop('disabled', false);

                if (resp.success) {
                    var savedData   = resp.data.data;
                    var savedCampos = resp.data.campos || {};
                    $form[0].reset();
                    $form.find('input[type="date"]').val(today());
                    $('#dc-pub-import-texto').val('');
                    pendingOverwrite = false;
                    closeModal();
                    addHistoricoRow(savedData, savedCampos);
                    openWaModal(buildWhatsapp(savedData, savedCampos));
                } else if (resp.data && resp.data.duplicado) {
                    showMsg('warn',
                        escHtml(resp.data.msg) +
                        ' <button type="button" class="dc-btn-primary" id="dc-pub-btn-sobrescrever" style="margin-left:10px;font-size:13px;padding:6px 12px;">Sobrescrever</button>'
                    );
                    $('#dc-pub-btn-sobrescrever').on('click', function () {
                        salvar(true);
                    });
                } else {
                    showMsg('error', resp.data ? resp.data.msg : 'Erro desconhecido.');
                }
            },
            error: function () {
                $spinner.hide();
                $btnSubmit.prop('disabled', false);
                showMsg('error', 'Falha na comunicação com o servidor.');
            },
        });
    }

    function showMsg(type, html) {
        $msgArea.html('<div class="dc-pub-' + type + '">' + html + '</div>');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDataBR(iso) {
        var p = String(iso).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }

    // =========================================================
    // WhatsApp — geração de texto, modal e histórico
    // =========================================================
    var DC_WA_ROWS = [
        { emoji: '\uD83D\uDC65',                   label: 'Indicação de paciente',                    key: 'ind_paciente' },
        { emoji: '\uD83D\uDC68\u200D\u2695\uFE0F', label: 'Indicação de médico',                      key: 'ind_medico' },
        { emoji: '\uD83D\uDCB0',                   label: 'Tráfego pago',                             key: 'trafego_pago' },
        { emoji: '\uD83D\uDCBB',                   label: 'Site',                                     key: 'site' },
        { emoji: '\uD83D\uDCF1',                   label: 'Instagram orgânico',                       key: 'instagram_organico' },
        { emoji: '\uD83C\uDFE5',                   label: 'Paciente já da clínica',                   key: 'paciente_antigo' },
        { emoji: '\uD83D\uDCCC',                   label: 'Outros',                                   key: 'outros' },
        { sep: true },
        { emoji: '\uD83D\uDCC8',                   label: 'Total de Leads',                           key: 'total_leads' },
        { emoji: '\uD83D\uDCC5',                   label: 'Agendamentos (tráfego)',                   key: 'agend_trafego' },
        { emoji: '\uD83D\uDCC5',                   label: 'Agendamentos (site)',                      key: 'agend_site' },
        { emoji: '\uD83D\uDCC5',                   label: 'Agendamentos (indicação)',                 key: 'agend_indicacao' },
        { emoji: '\uD83D\uDCC5',                   label: 'Agendamentos (pacientes antigos)',         key: 'agend_antigos' },
        { emoji: '\u2705',                         label: 'Consultas realizadas',                     key: 'consultas_total' },
        { emoji: '\u2705',                         label: 'Consultas realizadas (tráfego)',           key: 'consultas_trafego' },
        { emoji: '\u2705',                         label: 'Consultas realizadas (orgânico)',          key: 'consultas_organico' },
        { emoji: '\u2705',                         label: 'Consultas realizadas (pacientes antigos)', key: 'consultas_antigos' },
        { emoji: '\u2705',                         label: 'Consultas realizadas (indicação)',         key: 'consultas_indicacao' }
    ];

    function fmtNum(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) n = 0;
        return n === 0 ? '0' : String(n).padStart(2, '0');
    }

    function buildWhatsapp(dataIso, campos) {
        campos = campos || {};
        var lines = ['Fechamento do dia ' + formatDataBR(dataIso), 'Origem LEADS'];
        DC_WA_ROWS.forEach(function (r) {
            if (r.sep) { lines.push(''); return; }
            lines.push(r.emoji + '  ' + r.label + ': ' + fmtNum(campos[r.key]));
        });
        return lines.join('\n');
    }

    var $waModal, $waText, $waFeedback;

    function initWhatsapp() {
        $waModal    = $('#dc-pub-wa-modal');
        $waText     = $('#dc-pub-wa-text');
        $waFeedback = $('#dc-pub-wa-feedback');

        if (!$waModal.length) return;

        $('#dc-pub-wa-close').on('click', closeWaModal);
        $waModal.on('click', function (e) {
            if ($(e.target).is($waModal)) closeWaModal();
        });
        $('#dc-pub-wa-copy').on('click', copyWaText);

        // Botões de copiar no histórico (delegado — cobre linhas dinâmicas).
        $('#dc-pub-historico-tbody').on('click', '.dc-pub-wa-btn', function () {
            var $btn = $(this);
            openWaModal(buildWhatsapp($btn.data('data'), $btn.data('campos')));
        });
    }

    function openWaModal(text) {
        if (!$waModal || !$waModal.length) return;
        $waText.val(text);
        $waFeedback.text('').removeClass('dc-pub-wa-ok');
        $waModal.show();
    }

    function closeWaModal() {
        if ($waModal) $waModal.hide();
    }

    function copyWaText() {
        var text = $waText.val();

        function done() {
            $waFeedback.text('Copiado!').addClass('dc-pub-wa-ok');
            setTimeout(function () { $waFeedback.text('').removeClass('dc-pub-wa-ok'); }, 2500);
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

    function addHistoricoRow(dataIso, campos) {
        var $tbody = $('#dc-pub-historico-tbody');
        if (!$tbody.length) return;
        campos = campos || {};

        $tbody.find('.dc-pub-hist-vazio').remove();
        $tbody.find('tr[data-data="' + dataIso + '"]').remove();

        var agend = (parseInt(campos.agend_trafego, 10) || 0)
                  + (parseInt(campos.agend_site, 10) || 0)
                  + (parseInt(campos.agend_indicacao, 10) || 0)
                  + (parseInt(campos.agend_antigos, 10) || 0);

        var $row = $('<tr>').attr('data-data', dataIso);
        $row.append($('<td>').html('<strong>' + escHtml(formatDataBR(dataIso)) + '</strong>'));
        $row.append($('<td>').text(parseInt(campos.total_leads, 10) || 0));
        $row.append($('<td>').text(agend));
        $row.append($('<td>').text(parseInt(campos.consultas_total, 10) || 0));

        var $btn = $('<button type="button" class="dc-btn-secondary dc-pub-wa-btn">Copiar WhatsApp</button>')
            .attr('data-data', dataIso)
            .attr('data-campos', JSON.stringify(campos));
        $row.append($('<td>').append($btn));

        $tbody.prepend($row);
    }

    // =========================================================
    // Bootstrap
    // =========================================================
    $(function () {
        initCharts();
        initModal();
        initWhatsapp();
    });

}(jQuery));
