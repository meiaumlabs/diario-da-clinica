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
        $confirmWrap, $btnSobrescrever, $btnCancelar;

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
        initCharts();
    });

}(jQuery));
