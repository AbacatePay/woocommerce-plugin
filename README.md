# AbacatePay for WooCommerce

Plugin de pagamento Pix e cartão de crédito para WooCommerce usando o [checkout transparente da AbacatePay](https://docs.abacatepay.com/pages/transparents/reference) (API v2).

## Como funciona

1. No checkout, o `process_payment` cria a cobrança com `POST /v2/transparents/create` (`method: "PIX"`) e guarda no pedido o ID da cobrança, o `brCode` e o QR Code.
2. O cliente é levado para a página de "pedido recebido", que mostra o QR Code, o Pix Copia e Cola e uma contagem regressiva. A página consulta o status a cada 5 s.
3. A AbacatePay chama o webhook (`/wc-api/abacatepay_wc_webhook/`) com `transparent.completed` e o pedido passa a "Processando" (ou ao status configurado).

Redes de segurança, caso o webhook não chegue:

- O endpoint de status usado pela página do pedido consulta `GET /v2/transparents/check`, no máximo uma vez a cada 10 s por pedido.
- Uma tarefa do Action Scheduler roda 2 min depois da expiração do Pix. Se a cobrança estiver paga, confirma o pedido; se expirou, cancela e devolve o estoque (configurável).

## Segurança do webhook

Cada entrega é validada duas vezes, como recomenda a [documentação](https://docs.abacatepay.com/pages/webhooks/security):

- o `webhookSecret` da query string precisa ser igual ao secret das configurações (gerado automaticamente);
- o `X-Webhook-Signature` precisa ser o HMAC-SHA256 em base64 do corpo bruto, calculado com a chave pública da AbacatePay.

Além disso, o pedido é localizado pelo `metadata.wcOrderId` e a cobrança do evento precisa ser uma das cobranças registradas naquele pedido. O valor pago precisa ser igual ao total do pedido; se for diferente, o pedido vai para "Aguardando" com uma nota. Os handlers são idempotentes, então as retentativas da AbacatePay não duplicam nada.

## Eventos tratados

| Evento | Efeito |
| - | - |
| `transparent.completed` | Confirma o pagamento |
| `transparent.refunded` | Registra o reembolso no WooCommerce, se ainda não existir |
| `transparent.disputed` | `UNDER_DISPUTE` coloca o pedido em "Aguardando"; `PAID` devolve para "Processando"; `REFUNDED` registra o reembolso |
| `transparent.lost` | Registra o reembolso (disputa perdida) |

## Cartão de crédito

1. O formulário do cartão é desenhado pelo [abacatepay.js](https://docs.abacatepay.com/pages/transparents/abacatepay-js) num iframe seguro, com a chave pública (`pk_...`). O número e o CVV nunca passam pela loja.
2. Ao finalizar o pedido, o navegador chama `tokenize()` (com 3DS quando o banco pede) e envia só o token `ctok_`, as parcelas e, se o checkout não tiver o campo, o CPF/CNPJ do titular. Funciona no checkout clássico, na página "Pagar pedido" e no checkout em blocos.
3. O servidor cobra na hora com `POST /v2/transparents/create` (`method: "CARD"`). `APPROVED` confirma o pedido; `PENDING` deixa em "Aguardando" até o webhook `transparent.completed`; recusa mostra uma mensagem pedindo outro cartão.
4. **Timeout, erro de rede ou 5xx não são recusa:** o cartão pode ter sido cobrado. O pedido vai para "Aguardando", não há nova tentativa, e uma tarefa agendada procura a cobrança na listagem (`GET /v2/transparents/list?method=CARD`) pelo `metadata.wcOrderId`. Se o webhook chegar antes, ele é aceito e registra a cobrança. Sem cobrança encontrada depois de 6 consultas, o pedido vira "Malsucedido" e o cliente pode pagar de novo.

Parcelas vão de 1x a 12x (configurável), sem juros para o cliente e com no mínimo R$ 10,00 por parcela. A chave pública e a chave de API precisam ser do mesmo ambiente.

## Boleto

- A cobrança é criada com `POST /v2/transparents/create` (`method: "BOLETO"`). O vencimento (`dueDate`) é calculado em dias corridos a partir do pedido, no fuso da loja. Juros (`interest`, % ao mês) e multa (`fine`, percentual ou valor fixo) são opcionais.
- A página do pedido, "Minha conta" e o e-mail mostram a linha digitável, o link para ver e imprimir o boleto, o vencimento e o Pix alternativo que a AbacatePay gera para a mesma cobrança. A página consulta o status a cada 10 s, porque um pagamento pelo Pix alternativo confirma na hora.
- Não existe webhook de vencimento. Um dia depois do vencimento, uma tarefa do Action Scheduler consulta `GET /v2/transparents/get` uma vez por dia: confirma o pedido se estiver pago e cancela (configurável) quando a AbacatePay informa `EXPIRED`. São até 7 consultas, ou 60 quando há juros ou multa, porque nesse caso o boleto pode ser pago com atraso.
- CPF/CNPJ e nome são obrigatórios; e-mail e telefone vão junto quando existem.
- A API não reembolsa boletos, então o reembolso precisa ser manual.

## Estrutura

```
abacatepay-for-woocommerce.php            Cabeçalho do plugin, compatibilidade HPOS/blocos
includes/
  class-abacatepay-wc.php                 Bootstrap e registro de hooks
  class-abacatepay-wc-api.php             Cliente da API v2 (create, check, refund, simulate-payment)
  abstract-abacatepay-wc-gateway.php      Base dos métodos: credenciais, dados do cliente, pago/reembolso, eventos do webhook
  class-abacatepay-wc-pix-gateway.php     WC_Payment_Gateway: cobrança, reembolso, sincronização de status
  class-abacatepay-wc-webhook.php         Receptor do webhook
  class-abacatepay-wc-order-details.php   QR Code na loja e no e-mail, endpoints de status/imagem, box e ações no admin
  class-abacatepay-wc-pix-blocks-integration.php  Checkout em blocos
  class-abacatepay-wc-card-gateway.php    Cartão: formulário, cobrança, parcelas, consulta de cobranças sem resposta
  class-abacatepay-wc-card-blocks-integration.php  Cartão no checkout em blocos
  class-abacatepay-wc-boleto-gateway.php  Boleto: emissão, vencimento/juros/multa, consulta agendada
  class-abacatepay-wc-boleto-details.php  Boleto na loja e no e-mail, Pix alternativo, status, ações no admin
  class-abacatepay-wc-boleto-blocks-integration.php  Boleto no checkout em blocos
  class-abacatepay-wc-logger.php          Logs em WooCommerce > Status > Logs
templates/                                Templates sobrescrevíveis pelo tema
assets/                                   JS/CSS da página do pedido e do checkout em blocos
languages/                                .pot e tradução pt_BR
```

## Hooks para desenvolvedores

- `abacatepay_wc_pix_charge_data` (filter): dados enviados em `data` ao criar a cobrança. Por exemplo, para adicionar `ensureSameTaxId` ou `utm`.
- `abacatepay_wc_api_url` (filter): URL base da API.
- `abacatepay_wc_webhook_signature_key` (filter): chave usada para validar o HMAC.
- `abacatepay_wc_pix_icon` (filter): ícone exibido no checkout.
- `abacatepay_wc_card_charge_data` (filter): dados enviados em `data` na cobrança do cartão.
- `abacatepay_wc_card_icon` (filter): ícone do cartão no checkout.
- `abacatepay_wc_order_has_charge` (filter): se uma cobrança recebida no webhook pertence ao pedido.
- `abacatepay_wc_boleto_charge_data` / `abacatepay_wc_boleto_icon` (filters): o mesmo, para o boleto.

## Limitações conhecidas

- A AbacatePay só aceita reembolso total; reembolsos parciais são recusados na tela do pedido com uma mensagem.
- O valor mínimo de um Pix é R$ 1,00.
- Para PIX a API só aceita o `customer` com os quatro campos juntos: nome, e-mail, CPF/CNPJ e telefone. Por padrão o Pix recusa o pedido e diz ao cliente o que falta preencher (opção "Dados do cliente"). Se essa opção for desligada, o pedido é cobrado sem cliente e os campos que faltaram vão para o log. O CPF/CNPJ é lido dos campos do plugin "Brazilian Market on WooCommerce" (`_billing_cpf` / `_billing_cnpj`). O telefone precisa estar como obrigatório no checkout.
- A chave de API, o secret do webhook e o log de depuração ficam na tela do Pix e valem para todas as formas de pagamento da AbacatePay.

## Traduções

As strings de origem estão em inglês, com tradução completa para pt_BR em `languages/`. Para regenerar o `.mo` depois de editar o `.po`:

```bash
msgfmt -o languages/abacatepay-for-woocommerce-pt_BR.mo languages/abacatepay-for-woocommerce-pt_BR.po
```
