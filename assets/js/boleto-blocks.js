/**
 * Registers AbacatePay boleto in the Cart and Checkout blocks.
 */
(function (wc, wp) {
	'use strict';

	var settings = wc.wcSettings.getSetting('abacatepay_boleto_data', {});
	var decode = wp.htmlEntities.decodeEntities;
	var el = wp.element.createElement;
	var title = decode(settings.title || '') || 'Boleto';

	function Content() {
		return el('div', null, decode(settings.description || ''));
	}

	function Label(props) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;

		return el(PaymentMethodLabel, { text: title });
	}

	wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'abacatepay_boleto',
		label: el(Label),
		content: el(Content),
		edit: el(Content),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})(window.wc, window.wp);
