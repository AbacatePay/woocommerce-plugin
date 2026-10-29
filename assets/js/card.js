/**
 * Credit card on the classic checkout and on the "Pay for order" page.
 *
 * abacatepay.js draws the card fields in a secure iframe. When the customer places the order, the card is
 * tokenized (3DS included, when the bank asks for it) and only the ctok_ token is submitted.
 */
(function ($) {
	'use strict';

	var GATEWAY = 'abacatepay_card';
	var params = window.abacatepayCardParams || {};
	var i18n = params.i18n || {};
	var checkout = null;
	var mountedOn = null;
	var busy = false;

	function root() {
		return document.querySelector('.abacatepay-card');
	}

	function value(selector) {
		var el = document.querySelector(selector);
		return el ? String(el.value || '').trim() : '';
	}

	function tokenField() {
		return document.getElementById('abacatepay_card_token');
	}

	function showError(message) {
		var el = root() && root().querySelector('.abacatepay-card__error');

		if (el) {
			el.textContent = message || '';
			el.hidden = !message;
		}
	}

	function errorMessage(error) {
		var code = error && error.code;
		var text = String((error && error.message) || '');

		if (code === 'CARD_INCOMPLETE') return i18n.incomplete;
		if (code === 'CARD_INVALID') return i18n.invalid;
		if (code === 'THREE_DS_FAILED') return i18n.threeDsFailed;
		if (code === 'PAYER_INVALID') return i18n.payerInvalid;
		if (code === 'TAX_ID') return i18n.taxIdRequired;
		if (code === 'NETWORK') return i18n.network;
		if (code === 'NOT_MOUNTED' || code === 'PROVIDER_LOAD' || code === 'MOUNT_TARGET') return i18n.notLoaded;
		if (/não foi possível processar/i.test(text)) return i18n.declined;
		if (code === 'API' && text) return text;

		return i18n.generic;
	}

	function isSelected() {
		var checked = document.querySelector('input[name="payment_method"]:checked');
		return checked ? checked.value === GATEWAY : false;
	}

	// The payment box is replaced on every "updated_checkout", so the form is mounted again with the new total.
	function mount() {
		var box = root();

		if (!box || typeof window.AbacatePay === 'undefined') {
			return;
		}

		var target = box.querySelector('.abacatepay-card__form');
		var amount = parseInt(box.getAttribute('data-amount'), 10);

		if (target === mountedOn) {
			return;
		}

		if (checkout) {
			try {
				checkout.destroy();
			} catch (e) {}
			checkout = null;
		}

		mountedOn = target;

		if (!params.publicKey || !(amount >= 100)) {
			return;
		}

		try {
			checkout = window.AbacatePay.create({
				publicKey: params.publicKey,
				amount: amount,
				locale: 'pt',
				icons: true,
			});
			checkout.mount(target).catch(function (error) {
				showError(errorMessage(error));
			});
		} catch (error) {
			showError(errorMessage(error));
		}
	}

	function payer() {
		var fixed = root().getAttribute('data-payer');
		var data = fixed
			? JSON.parse(fixed)
			: {
					name: (value('#billing_first_name') + ' ' + value('#billing_last_name')).trim(),
					email: value('#billing_email'),
					taxId: value('#billing_persontype') === '2' ? value('#billing_cnpj') : value('#billing_cpf') || value('#billing_cnpj'),
					cellphone: value('#billing_cellphone') || value('#billing_phone'),
			  };

		if (value('#abacatepay_card_tax_id')) {
			data.taxId = value('#abacatepay_card_tax_id');
		}

		return data;
	}

	// Sent to the bank for 3DS. Optional, and only valid with all five fields.
	function address() {
		var data = {
			street: value('#billing_address_1'),
			number: value('#billing_number'),
			city: value('#billing_city'),
			state: value('#billing_state'),
			zipCode: value('#billing_postcode'),
		};

		for (var key in data) {
			if (!data[key]) {
				return undefined;
			}
		}

		return data;
	}

	function tokenize() {
		if (!checkout) {
			return Promise.reject({ code: 'NOT_MOUNTED' });
		}

		var data = payer();

		if (!data.taxId) {
			return Promise.reject({ code: 'TAX_ID' });
		}

		return checkout.tokenize(data, address()).then(function (result) {
			tokenField().value = result.token;
			return result;
		});
	}

	// Returns true to let the form submit. Otherwise tokenizes and calls submit() once the token is ready.
	function beforeSubmit(submit) {
		if (!isSelected() || !tokenField() || tokenField().value) {
			return true;
		}

		if (!busy) {
			busy = true;
			showError('');
			$('#place_order').prop('disabled', true);

			tokenize().then(
				function () {
					busy = false;
					$('#place_order').prop('disabled', false);
					submit();
				},
				function (error) {
					busy = false;
					$('#place_order').prop('disabled', false);
					showError(errorMessage(error));
				}
			);
		}

		return false;
	}

	$(document.body).on('updated_checkout payment_method_selected', mount);

	// A token is single-use: after a failed attempt the next one needs a new token.
	$(document.body).on('checkout_error', function () {
		if (tokenField()) {
			tokenField().value = '';
		}
	});

	$('form.checkout').on('checkout_place_order_' + GATEWAY, function () {
		var form = $(this);

		return beforeSubmit(function () {
			form.trigger('submit');
		});
	});

	$('form#order_review').on('submit', function (event) {
		var form = this;

		if (!beforeSubmit(function () { form.submit(); })) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	});

	$(mount);
})(jQuery);
