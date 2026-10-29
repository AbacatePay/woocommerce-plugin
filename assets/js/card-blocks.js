/**
 * Registers AbacatePay credit card in the Checkout block.
 *
 * The card form is drawn by abacatepay.js. When the order is placed, onPaymentSetup tokenizes the card
 * (3DS included) and sends only the ctok_ token, the installments and the CPF/CNPJ as payment data.
 */
(function (wc, wp) {
	'use strict';

	var settings = wc.wcSettings.getSetting('abacatepay_card_data', {});
	var i18n = settings.i18n || {};
	var decode = wp.htmlEntities.decodeEntities;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var title = decode(settings.title || '') || 'Cartão de crédito';
	var money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

	function errorMessage(error) {
		var code = error && error.code;
		var text = String((error && error.message) || '');

		if (code === 'CARD_INCOMPLETE') return i18n.incomplete;
		if (code === 'CARD_INVALID') return i18n.invalid;
		if (code === 'THREE_DS_FAILED') return i18n.threeDsFailed;
		if (code === 'PAYER_INVALID') return i18n.payerInvalid;
		if (code === 'NETWORK') return i18n.network;
		if (code === 'NOT_MOUNTED' || code === 'PROVIDER_LOAD' || code === 'MOUNT_TARGET') return i18n.notLoaded;
		if (/não foi possível processar/i.test(text)) return i18n.declined;
		if (code === 'API' && text) return text;

		return i18n.generic;
	}

	function installmentOptions(amount) {
		var max = Math.max(1, Math.min(settings.maxInstallments || 1, Math.floor(amount / (settings.minInstallmentAmount || 1000))));
		var options = [];

		for (var count = 1; count <= max; count++) {
			var each = money.format(amount / 100 / count);
			var label = count === 1
				? String(i18n.singleInstallment).replace('%s', each)
				: String(i18n.installment).replace('%1$d', count).replace('%2$s', each);

			options.push(el('option', { key: count, value: String(count) }, label));
		}

		return options;
	}

	function Content(props) {
		var billing = props.billing;
		var responseTypes = props.emitResponse.responseTypes;
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var amount = parseInt(billing.cartTotal.value, 10);

		var host = useRef(null);
		var instance = useRef(null);
		var errorState = useState('');
		var taxIdState = useState('');
		var installmentsState = useState('1');

		// Mounted again when the total changes: the token amount must match the charge.
		useEffect(
			function () {
				if (!host.current || typeof window.AbacatePay === 'undefined' || !settings.publicKey || !(amount >= 100)) {
					return undefined;
				}

				var target = host.current.appendChild(document.createElement('div'));
				var checkout = null;

				try {
					checkout = window.AbacatePay.create({ publicKey: settings.publicKey, amount: amount, locale: 'pt', icons: true });
					instance.current = checkout;
					checkout.mount(target).catch(function (error) {
						errorState[1](errorMessage(error));
					});
				} catch (error) {
					errorState[1](errorMessage(error));
				}

				return function () {
					instance.current = null;

					try {
						if (checkout) checkout.destroy();
					} catch (e) {}

					target.remove();
				};
			},
			[amount]
		);

		useEffect(
			function () {
				return onPaymentSetup(function () {
					var address = billing.billingAddress || {};
					var taxId = taxIdState[0].trim();

					if (!instance.current) {
						return { type: responseTypes.ERROR, message: i18n.notLoaded };
					}

					if (!taxId) {
						return { type: responseTypes.ERROR, message: i18n.taxIdRequired };
					}

					return instance.current
						.tokenize({
							name: ((address.first_name || '') + ' ' + (address.last_name || '')).trim(),
							email: address.email || '',
							taxId: taxId,
							cellphone: address.phone || '',
						})
						.then(
							function (result) {
								return {
									type: responseTypes.SUCCESS,
									meta: {
										paymentMethodData: {
											abacatepay_card_token: result.token,
											abacatepay_card_installments: installmentsState[0],
											abacatepay_card_tax_id: taxId,
										},
									},
								};
							},
							function (error) {
								return { type: responseTypes.ERROR, message: errorMessage(error) };
							}
						);
				});
			},
			[onPaymentSetup, billing.billingAddress, taxIdState[0], installmentsState[0]]
		);

		var options = installmentOptions(amount);

		return el(
			'div',
			{ className: 'abacatepay-card' },
			settings.description ? el('p', null, decode(settings.description)) : null,
			el('div', { className: 'abacatepay-card__form', ref: host }),
			el(
				'p',
				{ className: 'abacatepay-card__field' },
				el('label', { htmlFor: 'abacatepay-card-tax-id' }, i18n.cardholderTax),
				el('input', {
					id: 'abacatepay-card-tax-id',
					type: 'text',
					inputMode: 'numeric',
					autoComplete: 'off',
					value: taxIdState[0],
					onChange: function (event) {
						taxIdState[1](event.target.value);
					},
				})
			),
			options.length > 1
				? el(
						'p',
						{ className: 'abacatepay-card__field' },
						el('label', { htmlFor: 'abacatepay-card-installments' }, i18n.installments),
						el(
							'select',
							{
								id: 'abacatepay-card-installments',
								value: installmentsState[0],
								onChange: function (event) {
									installmentsState[1](event.target.value);
								},
							},
							options
						)
				  )
				: null,
			errorState[0] ? el('p', { className: 'abacatepay-card__error', role: 'alert' }, errorState[0]) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;

		return el(PaymentMethodLabel, { text: title });
	}

	wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'abacatepay_card',
		label: el(Label),
		content: el(Content),
		edit: el('div', null, decode(settings.description || '')),
		canMakePayment: function () {
			return !!settings.publicKey;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})(window.wc, window.wp);
