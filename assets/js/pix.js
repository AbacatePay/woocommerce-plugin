/**
 * Pix instructions: copy button and expiration countdown. Payment confirmation comes from the webhook.
 */
(function () {
	'use strict';

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

		function tick() {
			if (!expiresAt) {
				return;
			}

			var remaining = expiresAt - Date.now();

			if (countdownEl) {
				countdownEl.textContent = '(' + formatRemaining(remaining) + ')';
			}

			if (remaining <= 0) {
				statusEl.textContent = root.getAttribute('data-expired-message');
				return;
			}

			setTimeout(tick, 1000);
		}

		tick();
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
