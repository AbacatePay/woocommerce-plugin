/**
 * Pix instructions: copy button, expiration countdown and payment status polling.
 */
(function () {
	'use strict';

	var POLL_INTERVAL = 5000;

	function copyText(field) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(field.value);
		}

		field.select();
		document.execCommand('copy');
		return Promise.resolve();
	}

	function formatRemaining(ms) {
		var total = Math.max(0, Math.floor(ms / 1000));
		var hours = Math.floor(total / 3600);
		var minutes = Math.floor((total % 3600) / 60);
		var seconds = total % 60;
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};

		return (hours ? hours + ':' + pad(minutes) : minutes) + ':' + pad(seconds);
	}

	function init(root) {
		var field = root.querySelector('.abacatepay-pix__code');
		var copyButton = root.querySelector('.abacatepay-pix__copy-button');
		var statusEl = root.querySelector('.abacatepay-pix__status');
		var countdownEl = root.querySelector('.abacatepay-pix__countdown');
		var expiresAt = Date.parse(root.getAttribute('data-expires-at') || '') || 0;
		var statusUrl = root.getAttribute('data-status-url');
		var finished = false;

		if (field && copyButton) {
			var label = copyButton.textContent;

			copyButton.addEventListener('click', function () {
				copyText(field).then(function () {
					copyButton.textContent = copyButton.getAttribute('data-copied-label');
					setTimeout(function () {
						copyButton.textContent = label;
					}, 2000);
				});
			});
		}

		function finish(message, reload) {
			finished = true;
			statusEl.textContent = message;

			if (reload) {
				setTimeout(function () {
					window.location.reload();
				}, 1500);
			}
		}

		function tick() {
			if (finished || !expiresAt) {
				return;
			}

			var remaining = expiresAt - Date.now();

			if (countdownEl) {
				countdownEl.textContent = '(' + formatRemaining(remaining) + ')';
			}

			if (remaining <= 0) {
				finish(root.getAttribute('data-expired-message'), false);
				return;
			}

			setTimeout(tick, 1000);
		}

		function poll() {
			if (finished || !statusUrl) {
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
					if (json && json.success && json.data.paid) {
						finish(root.getAttribute('data-paid-message'), true);
					} else if (json && json.success && json.data.expired) {
						finish(root.getAttribute('data-expired-message'), false);
					}
				})
				.catch(function () {})
				.then(function () {
					setTimeout(poll, POLL_INTERVAL);
				});
		}

		tick();
		setTimeout(poll, POLL_INTERVAL);
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.abacatepay-pix__pending'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
