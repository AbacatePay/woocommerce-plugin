/**
 * Boleto instructions: copy buttons and payment status polling (the alternative Pix confirms instantly).
 */
(function () {
	'use strict';

	var POLL_INTERVAL = 10000;

	function copyText(field) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(field.value);
		}

		field.select();
		document.execCommand('copy');
		return Promise.resolve();
	}

	function initCopyButton(button) {
		var field = document.getElementById(button.getAttribute('data-target'));
		var label = button.textContent;

		if (!field) {
			return;
		}

		button.addEventListener('click', function () {
			copyText(field).then(function () {
				button.textContent = button.getAttribute('data-copied-label');
				setTimeout(function () {
					button.textContent = label;
				}, 2000);
			});
		});
	}

	function init(root) {
		var statusEl = root.querySelector('.abacatepay-boleto__status');
		var statusUrl = root.getAttribute('data-status-url');

		Array.prototype.forEach.call(root.querySelectorAll('.abacatepay-boleto__copy-button'), initCopyButton);

		function poll() {
			if (!statusUrl) {
				return;
			}

			// Don't hit the server from background tabs; check again when the tab is back.
			if (document.hidden) {
				setTimeout(poll, POLL_INTERVAL);
				return;
			}

			fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' })
				.then(function (response) {
					return response.ok ? response.json() : null;
				})
				.then(function (json) {
					if (json && json.success && (json.data.paid || json.data.expired)) {
						if (json.data.paid) {
							statusEl.textContent = root.getAttribute('data-paid-message');
						}

						setTimeout(function () {
							window.location.reload();
						}, 1500);
						return;
					}

					setTimeout(poll, POLL_INTERVAL);
				})
				.catch(function () {
					setTimeout(poll, POLL_INTERVAL);
				});
		}

		setTimeout(poll, POLL_INTERVAL);
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.abacatepay-boleto__pending'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
