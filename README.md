# BancoInterService

A classe `BancoInterService` facilita a integração com a API do Banco Inter para realizar operações relacionadas a cobranças, como geração de boletos e PIX, gerenciamento de webhooks e recuperação de informações de cobranças.

## Sumário

- [Instalação](#instalação)
- [Uso](#uso)
- [Métodos Disponíveis](#métodos-disponíveis)
- [Exceções](#exceções)
- [Dependências](#dependências)
- [Licença](#licença)

## Instalação

1. Clone este repositório para o seu ambiente local.
2. Certifique-se de que todas as dependências do projeto estejam instaladas.
3. Configure a classe `GatewayPagamento` com as credenciais necessárias (client_id, client_secret, certificado_crt, certificado_key, oauth_token).

## Uso

### Instanciando o Serviço

Para começar a usar o `BancoInterService`, instancie a classe passando uma instância configurada de `GatewayPagamento`:

```php
$gatewayPagamento = new GatewayPagamento(/* parâmetros de configuração */);
$bancoInterService = new BancoInterService($gatewayPagamento);
```
### Gerando um Boleto/PIX

```php
$dadosCobrancaBoletoPix = /* Objeto com os dados da cobrança */;
$bancoInterService->gerarBoletoPix($dadosCobrancaBoletoPix);
```
