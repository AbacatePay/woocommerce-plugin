/**
 * Boleto instructions: copy buttons. Payment confirmation comes from the webhook.
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
		Array.prototype.forEach.call(root.querySelectorAll('.abacatepay-boleto__copy-button'), initCopyButton);
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
