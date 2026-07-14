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
            $modal.show();
            $modal.find('input[type="date"]').val(today());
        });

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
                    showMsg('success', resp.data.msg);
                    $form[0].reset();
                    $form.find('input[type="date"]').val(today());
                    pendingOverwrite = false;
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

    // =========================================================
    // Bootstrap
    // =========================================================
    $(function () {
        initCharts();
        initModal();
    });

}(jQuery));
