/**
 * PV Dashboard — Interactive Chart with Period Navigation
 */
(function (Drupal, drupalSettings) {
    "use strict";

    if (typeof Chart === "undefined") {
        return;
    }

    let chart = null;
    let currentPeriod = "day";
    let currentDate = new Date();

    /**
     * Format date as YYYY-MM-DD
     */
    function formatDate(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, "0");
        const d = String(date.getDate()).padStart(2, "0");
        return y + "-" + m + "-" + d;
    }

    /**
     * Load chart data from API
     */
    function loadChartData(period, date) {
        const url =
            "/pvoutput/chart-data?period=" +
            encodeURIComponent(period) +
            "&date=" +
            encodeURIComponent(date);

        fetch(url, { headers: { Accept: "application/json" } })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data.ok) return;
                updateChart(data);
                updateDateLabel(data.label);
                updatePeakInfo(data.peak, data.count);
            })
            .catch(function () {});
    }

    /**
     * Update Chart.js instance with new data.
     * Null gaps are spanned with a straight connecting line (spanGaps: true).
     */
    function updateChart(data) {
        if (!chart) return;

        chart.data.labels = data.labels;
        chart.data.datasets[0].data = data.data;

        const cfg = drupalSettings && drupalSettings.htl_pv_api;
        const configMax = cfg && cfg.chart_max_w ? cfg.chart_max_w : 10000;
        const dataMax = data.peak > 0 ? data.peak : 1000;
        chart.options.scales.y.max = Math.max(dataMax * 1.1, configMax);

        chart.update("none");
    }

    /**
     * Update date label
     */
    function updateDateLabel(label) {
        const el = document.querySelector("[data-date-label]");
        if (el) el.textContent = label;
    }

    /**
     * Update peak and sample count info
     */
    function updatePeakInfo(peak, count) {
        const cfg = drupalSettings && drupalSettings.htl_pv_api;
        const fm = cfg && cfg.field_map && cfg.field_map.power_w;
        const scale = fm ? fm.scale : 0.001;
        const unit = fm ? fm.unit : "kW";

        const peakEl = document.querySelector("[data-chart-peak]");
        if (peakEl) {
            peakEl.textContent = (peak * scale).toFixed(2) + " " + unit;
        }

        const countEl = document.querySelector("[data-chart-samples]");
        if (countEl) countEl.textContent = count;
    }

    /**
     * Initialize empty Chart.js instance, then immediately load real data.
     */
    function initChart() {
        const canvas = document.getElementById("pv-chart");
        if (!canvas) return;

        const ctx = canvas.getContext("2d");

        chart = new Chart(ctx, {
            type: "line",
            data: {
                labels: [],
                datasets: [
                    {
                        label: "PV Leistung (W)",
                        data: [],
                        borderColor: "#3dba4e",
                        backgroundColor: "rgba(61, 186, 78, 0.1)",
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 2,
                        spanGaps: true,
                    },
                ],
            },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: "index",
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const cfg2 =
                                    drupalSettings && drupalSettings.htl_pv_api;
                                const fm2 =
                                    cfg2 &&
                                    cfg2.field_map &&
                                    cfg2.field_map.power_w;
                                const s = fm2 ? fm2.scale : 0.001;
                                const u = fm2 ? fm2.unit : "kW";
                                return ctx.parsed.y != null
                                    ? (ctx.parsed.y * s).toFixed(2) + " " + u
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
                            minRotation: 0,
                            font: { size: 11 },
                        },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        max: 10000,
                        title: {
                            display: true,
                            text: "Leistung (W)",
                            font: { size: 12, weight: "600" },
                        },
                        ticks: {
                            stepSize: 1000,
                            font: { size: 11 },
                            callback: function (value) {
                                return value >= 1000
                                    ? value / 1000 + " kW"
                                    : value + " W";
                            },
                        },
                    },
                },
            },
        });

        // Load real data immediately
        loadChartData(currentPeriod, formatDate(currentDate));
    }

    /**
     * Setup period tab navigation
     */
    function setupPeriodTabs() {
        const tabs = document.querySelectorAll("[data-period]");

        tabs.forEach(function (tab) {
            tab.addEventListener("click", function () {
                const period = this.dataset.period;

                tabs.forEach(function (t) {
                    t.classList.remove("pv-chart-tab--active");
                });
                this.classList.add("pv-chart-tab--active");

                currentPeriod = period;
                currentDate = new Date();
                loadChartData(currentPeriod, formatDate(currentDate));
            });
        });
    }

    /**
     * Setup date navigation (prev/next buttons)
     */
    function setupDateNavigation() {
        const prevBtn = document.querySelector('[data-nav="prev"]');
        const nextBtn = document.querySelector('[data-nav="next"]');

        if (prevBtn) {
            prevBtn.addEventListener("click", function () {
                navigateDate(-1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener("click", function () {
                navigateDate(1);
            });
        }
    }

    /**
     * Navigate to previous/next period
     */
    function navigateDate(direction) {
        const newDate = new Date(currentDate);

        switch (currentPeriod) {
            case "day":
                newDate.setDate(newDate.getDate() + direction);
                break;
            case "week":
                newDate.setDate(newDate.getDate() + direction * 7);
                break;
            case "month":
                newDate.setMonth(newDate.getMonth() + direction);
                break;
            case "year":
                newDate.setFullYear(newDate.getFullYear() + direction);
                break;
        }

        currentDate = newDate;
        loadChartData(currentPeriod, formatDate(currentDate));
    }

    /**
     * Start live polling for gauge update
     */
    function startPolling() {
        const cfg = drupalSettings && drupalSettings.htl_pv_api;
        const interval = cfg && cfg.poll_interval ? cfg.poll_interval : 5;

        setInterval(function () {
            fetch("/pvoutput/fetch", {
                headers: { Accept: "application/json" },
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (d) {
                    if (!d.ok) return;
                    document
                        .querySelectorAll("[data-pv-power]")
                        .forEach(function (el) {
                            if (d.power_w != null) {
                                const fm =
                                    cfg &&
                                    cfg.field_map &&
                                    cfg.field_map.power_w;
                                const scale = fm ? fm.scale : 0.001;
                                el.textContent = (d.power_w * scale).toFixed(2);
                            }
                        });
                })
                .catch(function () {});
        }, interval * 1000);
    }

    /**
     * Drupal behavior
     */
    Drupal.behaviors.pvDashboardInteractive = {
        attach: function (context) {
            const el = context.querySelector
                ? context.querySelector("[data-pv-dashboard]")
                : null;
            if (!el || el.dataset.pvInited) return;
            el.dataset.pvInited = "1";

            initChart();
            setupPeriodTabs();
            setupDateNavigation();
            startPolling();
        },
    };
})(Drupal, drupalSettings);
