/**
 * Enhanced Admin scripts for Dashboard.
 */
(function($) {
	'use strict';

	function padDate(n) {
		return String(n).padStart(2, '0');
	}

	function formatDate(d) {
		return d.getFullYear() + '-' + padDate(d.getMonth() + 1) + '-' + padDate(d.getDate());
	}

	function offsetDate(days) {
		var d = new Date();
		d.setDate(d.getDate() + days);
		return formatDate(d);
	}

	$(function() {
		// Preset button handler — populate date inputs and submit.
		$('.utm-preset-btn').on('click', function() {
			var range = $(this).data('range');
			var today = formatDate(new Date());
			var from, to;

			to = today;
			switch (range) {
				case 'today': from = today;              break;
				case '7d':    from = offsetDate(-6);     break;
				case '30d':   from = offsetDate(-29);    break;
				case '90d':   from = offsetDate(-89);    break;
				case 'year':  from = new Date().getFullYear() + '-01-01'; break;
				default:      from = offsetDate(-29);
			}

			$('#utm-from-date').val(from);
			$('#utm-to-date').val(to);
			$('#utm-range-val').val(range);
			$('#utm-filter-form').submit();
		});

		// Confirm before the Visits bulk delete.
		$('#utm-visits-form').on('submit', function(e) {
			var action = $('#bulk-action-selector-top').val() !== '-1' ? $('#bulk-action-selector-top').val() : $('#bulk-action-selector-bottom').val();
			if (action === 'delete' && $(this).find('input[name="visit[]"]:checked').length && !window.confirm($(this).data('confirm'))) {
				e.preventDefault();
			}
		});

		// Noise cleanup: enable Delete once confirmed, and poll the background job.
		$('#utm-cleanup-confirm').on('change', function() {
			$('#utm-cleanup-delete').prop('disabled', !this.checked);
		});

		var $progress = $('#utm-cleanup-progress');
		if ($progress.length) {
			var poll = function() {
				$.post($progress.data('ajax'), { action: 'utm_attribution_cleanup_status', nonce: $progress.data('nonce') }, function(res) {
					if (!res || !res.success) {
						return;
					}
					if (res.data.status !== 'running') {
						window.location.href = window.location.href.replace(/&utm_notice=[^&]*/, '');
						return;
					}
					$progress.find('.utm-cleanup-bar span').css('width', res.data.percent + '%');
					$progress.find('.utm-cleanup-pct').text(res.data.percent + '%');
					$progress.find('.utm-cleanup-wait').text(res.data.idle > 120 ? $progress.data('waiting') : '');
				});
			};
			setInterval(poll, 3000);
			poll();
		}

		if (typeof utm_attribution_data === 'undefined') {
			return;
		}

		var ctx = document.getElementById('utm-attribution-main-chart');
		if (!ctx) {
			return;
		}

		// Initialize gradients
		var gradientVisits = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
		gradientVisits.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
		gradientVisits.addColorStop(1, 'rgba(99, 102, 241, 0)');

		var gradientConvs = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
		gradientConvs.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
		gradientConvs.addColorStop(1, 'rgba(16, 185, 129, 0)');

		var data = utm_attribution_data.series;
		var labels = data.map(function(row) { 
            // Format labels for cleaner display
            var date = new Date(row.period);
            if (utm_attribution_data.granularity === 'month') {
                return date.toLocaleDateString('default', { month: 'short', year: 'numeric' });
            } else if (utm_attribution_data.granularity === 'year') {
                return date.getFullYear();
            }
            return date.toLocaleDateString('default', { month: 'short', day: 'numeric' });
        });
		var visits = data.map(function(row) { return row.visits; });
		var conversions = data.map(function(row) { return row.conversions; });

		new Chart(ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Visits',
						data: visits,
						borderColor: '#6366f1',
						backgroundColor: gradientVisits,
						borderWidth: 3,
						fill: true,
						tension: 0.4,
						pointRadius: 4,
						pointBackgroundColor: '#fff',
						pointBorderWidth: 2,
						pointHoverRadius: 6,
						yAxisID: 'y'
					},
					{
						label: 'Conversions',
						data: conversions,
						borderColor: '#10b981',
						backgroundColor: gradientConvs,
						borderWidth: 3,
						fill: true,
						tension: 0.4,
						pointRadius: 4,
						pointBackgroundColor: '#fff',
						pointBorderWidth: 2,
						pointHoverRadius: 6,
						yAxisID: 'y1'
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: {
					mode: 'index',
					intersect: false,
				},
				plugins: {
					legend: {
						position: 'top',
						align: 'end',
						labels: {
							usePointStyle: true,
							padding: 20,
							font: { size: 12, weight: '600' }
						}
					},
					tooltip: {
						backgroundColor: '#1e293b',
						padding: 12,
						titleFont: { size: 14, weight: '700' },
						bodyFont: { size: 13 },
						cornerRadius: 8,
						displayColors: true
					}
				},
				scales: {
					y: {
						type: 'linear',
						display: true,
						position: 'left',
						beginAtZero: true,
						grid: {
							color: '#f1f5f9',
							drawBorder: false
						},
						ticks: {
							color: '#64748b',
							font: { size: 11 }
						}
					},
					y1: {
						type: 'linear',
						display: true,
						position: 'right',
						beginAtZero: true,
						grid: {
							display: false
						},
						ticks: {
							color: '#64748b',
							font: { size: 11 }
						}
					},
					x: {
						grid: {
							display: false
						},
						ticks: {
							color: '#64748b',
							font: { size: 11 },
							maxRotation: 0
						}
					}
				}
			}
		});
	});

})(jQuery);
