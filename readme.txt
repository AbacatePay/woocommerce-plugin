=== AbacatePay for WooCommerce ===
Contributors: abacatepay
Tags: pix, woocommerce, abacatepay, pagamento, qr code
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receba pagamentos via Pix com a AbacatePay: QR Code e Pix Copia e Cola direto na sua loja, com confirmação automática do pedido.

== Description ==

O cliente finaliza a compra e vê o QR Code e o código Pix Copia e Cola na página do pedido, sem sair da loja. Assim que o Pix é pago, o pedido é confirmado automaticamente.

* Checkout transparente: QR Code e Pix Copia e Cola na página de "pedido recebido", em "Minha conta" e no e-mail do pedido.
* Confirmação automática via webhook, com página do pedido que se atualiza sozinha.
* Cancelamento automático (com devolução de estoque) quando o Pix expira sem pagamento.
* Reembolso pela tela do pedido (a AbacatePay só permite reembolso total).
* Reembolsos e disputas feitos fora do WooCommerce também atualizam o pedido.
* Dev mode: cobranças de teste e botão "Simular pagamento do Pix" no pedido.
* Cartão de crédito em até 12x, com o formulário seguro da AbacatePay (3DS incluído). Os dados do cartão nunca passam pela loja.
* Compatível com o checkout em blocos e com HPOS.
* Envia CPF/CNPJ do cliente quando a loja usa o plugin "Brazilian Market on WooCommerce".
* Boleto: linha digitável, link para imprimir e Pix alternativo na mesma cobrança, com vencimento, juros e multa configuráveis.

== Installation ==

1. Envie a pasta do plugin para `wp-content/plugins/` e ative o plugin.
2. Vá em WooCommerce > Configurações > Pagamentos > Pix AbacatePay.
3. No dashboard da AbacatePay (Integração), crie uma chave de API com as permissões `TRANSPARENT:CREATE`, `TRANSPARENT:READ` e `REFUND:CREATE` e cole em "Chave de API". Uma chave de Dev mode gera cobranças de teste.
4. No dashboard da AbacatePay, crie um webhook com a URL e o secret exibidos na tela de configurações, assinando os eventos `transparent.completed`, `transparent.refunded`, `transparent.disputed` e `transparent.lost`. A URL precisa ser HTTPS pública.
5. A moeda da loja precisa ser o real brasileiro (BRL).
6. Para cartão: em WooCommerce > Configurações > Pagamentos > Cartão de crédito AbacatePay, cole a chave pública (`pk_...`, em Integração > Checkout próprio no dashboard), do mesmo ambiente da chave de API.

== Frequently Asked Questions ==

= O pedido não é confirmado sem o webhook? =

É, mas com atraso: enquanto o cliente está na página do pedido, ela consulta o status na AbacatePay a cada poucos segundos. Além disso, uma tarefa agendada consulta a cobrança logo depois que o Pix expira. O webhook continua sendo o jeito recomendado.

= Como testar sem dinheiro real? =

Use uma chave de API de Dev mode. Faça um pedido com Pix e, na tela do pedido no admin, escolha a ação "Simular pagamento do Pix (Dev mode)".

= Posso personalizar o visual do QR Code? =

Sim. Copie os arquivos da pasta `templates/` para `seu-tema/abacatepay-for-woocommerce/` e edite.

== Changelog ==

= 1.0.0 =
* Primeira versão: Pix via checkout transparente da AbacatePay.
* Boleto via checkout transparente da AbacatePay.
