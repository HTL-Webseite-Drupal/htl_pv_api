/**
 * PV Dashboard — Chart.js chart + client polling.
 * Chart data is read from drupalSettings.htl_pv_api.chart
 */
(function (Drupal, drupalSettings) {
    "use strict";

    function initChart() {
        var canvas = document.getElementById("pv-chart");
        if (!canvas) return;

        var cfg =
            drupalSettings &&
            drupalSettings.htl_pv_api &&
            drupalSettings.htl_pv_api.chart;
        if (!cfg) return;

        new Chart(canvas, {
            type: "line",
            data: {
                labels: cfg.labels,
                datasets: [
                    {
                        label: "PV Leistung (W)",
                        data: cfg.datasets[0].data,
                        borderColor: "#3dba4e",
                        backgroundColor: "rgba(61,186,78,.10)",
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 2,
                        spanGaps: false,
                    },
                ],
            },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.parsed.y != null
                                    ? (ctx.parsed.y / 1000).toFixed(2) + " kW"
                                    : "–";
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            font: { size: 10 },
                            callback: function (val, idx) {
                                var l = this.getLabelForValue(idx);
                                return l || null;
                            },
                        },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: "Watt" },
                        ticks: { font: { size: 11 } },
                    },
                },
            },
        });
    }

    function startPolling() {
        var interval =
            (drupalSettings.htl_pv_api &&
                drupalSettings.htl_pv_api.poll_interval) ||
            5;
        setInterval(function () {
            fetch("/pvoutput/fetch", {
                headers: { Accept: "application/json" },
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (d) {
                    if (!d.ok) return;
                    // Update SVG text elements with data-pv-power attribute
                    document
                        .querySelectorAll("[data-pv-power]")
                        .forEach(function (el) {
                            if (d.power_w != null) {
                                el.textContent = (d.power_w / 1000).toFixed(2);
                            }
                        });
                })
                .catch(function () {});
        }, interval * 1000);
    }

    Drupal.behaviors.pvDashboard = {
        attach: function (context, settings) {
            var el = context.querySelector
                ? context.querySelector("[data-pv-dashboard]")
                : null;
            if (!el) return;
            // Run once per element
            if (el.dataset.pvInited) return;
            el.dataset.pvInited = "1";

            initChart();
            startPolling();
        },
    };
})(Drupal, drupalSettings);
