<?php

class BancoInterService
{
    private $gatewayPagamento;
    
    function __construct(GatewayPagamento $gatewayPagamento)
    {
        $this->gatewayPagamento = $gatewayPagamento;
    
        if($gatewayPagamento->oauth_token && !$this->verificaValidadeTokenOAuth())
        {
            $this->generateOAuthToken();
        }
        elseif(!$gatewayPagamento->oauth_token)
        {
            $this->generateOAuthToken();
        }
    }
    
    public function verificaValidadeTokenOAuth()
    {
        // Converte as strings para objetos DateTime
        $datetime1 = new DateTime(date('Y-m-d H:i:s'));
        $datetime2 = new DateTime($this->gatewayPagamento->oauth_token_created_at);
        
        // Calcula a diferença
        $interval = $datetime1->diff($datetime2);
        
        // Calcula a duração total em minutos
        $duracaoEmMinutos = $interval->days * 24 * 60 + $interval->h * 60 + $interval->i;
        
        if($duracaoEmMinutos > 59 )
        {
            return false;
        }
        
        return true;

    }
    
    public function generateOAuthToken()
    {
        if(!$this->gatewayPagamento->certificado_crt)
        {
            throw new Exception('Certificado da API do banco inter não foi configurado!');
        }
        
        if(!$this->gatewayPagamento->certificado_key)
        {
            throw new Exception('A chave do certificado da API do banco inter não foi configurada!');
        }
        
        $dados = [
            'client_id' => $this->gatewayPagamento->client_id,
            'client_secret' => $this->gatewayPagamento->client_secret,
            'grant_type' => 'client_credentials',
            'scope' => 'boleto-cobranca.read boleto-cobranca.write pix.write pix.read webhook.write webhook.read'
        ];
        
        try 
        {
            $retorno = BuilderHttpClientService::post('https://cdpj.partners.bancointer.com.br/oauth/v2/token', $dados, null, [], [
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_SSLCERT => $this->gatewayPagamento->certificado_crt,
                CURLOPT_SSLKEY => $this->gatewayPagamento->certificado_key
            ], false);   
        }
        catch (Exception $e) 
        {
            throw new Exception("Ocorreu um erro ao gerar o token do Banco Inter: {$e->getMessage()}");
        }
        
        if(!empty($retorno->access_token))
        {
            $this->gatewayPagamento->oauth_token = $retorno->access_token;
            $this->gatewayPagamento->oauth_token_created_at = date('Y-m-d H:i:s');
            $this->gatewayPagamento->store();
        }
    }

    public function gerarBoletoPix($dadosCobrancaBoletoPix)
    {
       
        $dados = [
            'seuNumero' => $dadosCobrancaBoletoPix->nosso_numero,
            'valorNominal' => $dadosCobrancaBoletoPix->valor_total,
            'dataVencimento' => date('Y-m-d', strtotime($dadosCobrancaBoletoPix->data_vencimento)), 
            'numDiasAgenda' => $dadosCobrancaBoletoPix->dias_validade_apos_vencimento ?? 0, // Default: 0 Número de dias corridos após o vencimento para o cancelamento efetivo automático da cobrança. (de 0 até 60)            
            'pagador' => [
               'email' => $dadosCobrancaBoletoPix->cliente->email ?? '',
               'ddd' => $dadosCobrancaBoletoPix->cliente->dd ?? '',
               'telefone' => $dadosCobrancaBoletoPix->cliente->telefone ?? '',
               'numero' => $dadosCobrancaBoletoPix->cliente->endereco->numero ?? '',
               'complemento' => $dadosCobrancaBoletoPix->cliente->endereco->complemento ?? '',
               'cpfCnpj' => $dadosCobrancaBoletoPix->cliente->documento,
               'tipoPessoa' => $dadosCobrancaBoletoPix->cliente->tipo_cliente_id == TipoCliente::FISICA ? 'FISICA' : 'JURIDICA',
               'nome' => $dadosCobrancaBoletoPix->cliente->nome,
               'endereco' => $dadosCobrancaBoletoPix->cliente->endereco->rua,
               'bairro' => $dadosCobrancaBoletoPix->cliente->endereco->bairro ?? '',
               'cidade' => $dadosCobrancaBoletoPix->cliente->endereco->cidade->nome,
               'uf' => $dadosCobrancaBoletoPix->cliente->endereco->cidade->estado->sigla,
               'cep' => $dadosCobrancaBoletoPix->cliente->endereco->cep,
           ]
        ];

        try {
            
            $retorno = $this->httpPost(
                'https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas', 
                $dados,
                [
                    "x-conta-corrente: {$this->gatewayPagamento->conta_corrente}"
                ]
            );
        } catch (Exception $e) {            
            $erro = self::parseErro($e->getMessage());
            throw new Exception("Ocorreu um erro ao gerar o boleto/pix no Banco Inter: {$erro}");
        }

        
        $dadosRetorno = new stdClass();
        $dadosRetorno->codigoSolicitacao = $retorno->codigoSolicitacao;

        return $dadosRetorno;
    } 
    
    public function criarWebhook()
    {
        try
        {
            $url = 'https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas/webhook';
            
            $data = [
                'webhookUrl' => $this->gatewayPagamento->webhook_url
            ];
            
            $headers = [
                'Content-Type: application/json',
                'x-conta-corrente: ' . $this->gatewayPagamento->conta_corrente
            ];
            
            $retorno = $this->httpPut($url, $data, $headers);
            
            if ($retorno->status !== 200) {
                throw new Exception("Erro ao criar ou editar o Webhook: {$retorno->status} - {$retorno->message}");
            }
        }
        catch (Exception $e)
        {
            $erro = $e->getMessage();
            throw new Exception("Ocorreu um erro ao criar ou editar o Webhook no Banco Inter: {$erro}");
        }
        
        return true;
    } 
    
    public function getWebhookCadastrado()
    {
        try
        {
            $url = 'https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas/webhook';
            
            $headers = [
                'x-conta-corrente: ' . $this->gatewayPagamento->conta_corrente
            ];
            
            $retorno = $this->httpGet($url, [], $headers);
        }
        catch (Exception $e) 
        {
            $erro = $e->getMessage();
            
            throw new Exception("Ocorreu um erro ao obter o Webhook cadastrado no Banco Inter: {$erro}");
        }
        
        return $retorno;
    }
    
    public function getWebhookCallbacksEnviados($filter)
    {
        try
        {            
            $url = 'https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas/webhook/callbacks';            
            
            $headers = [
                'x-conta-corrente: ' . $this->gatewayPagamento->conta_corrente
            ];
            
            $retorno = $this->httpGet($url, $filter, $headers);
        }
        catch (Exception $e) 
        {
            $erro = $e->getMessage();
            
            throw new Exception("Ocorreu um erro ao consultar os callbacks enviados no Banco Inter: {$erro}");
        }
        
        return $retorno;
    }
    
    public function getBoletoPixPdf($codigo)
    {
        $headers = [
            'x-conta-corrente' => $this->gatewayPagamento->conta_corrente
        ];

        try {
            $retorno = $this->httpGet("https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas/{$codigo}/pdf", [], $headers);
        } catch (Exception $e) {
            throw new Exception("Ocorreu um erro ao gerar PDF do boleto/pix no Banco Inter: " . $e->getMessage() . "<br>Error Code: " . $e->getCode());
        }
        
        $dadosRetorno = new stdClass();
        $dadosRetorno->tipo = 'base64';
        $dadosRetorno->conteudo = $retorno->pdf;
        
        return $dadosRetorno;
    }
    
    public function buscaCobranca(Cobranca $cobranca)
    {
        $headers = [
            'x-conta-corrente' => $this->gatewayPagamento->conta_corrente
        ];

        try
        {
            $retorno = $this->httpGet("https://cdpj.partners.bancointer.com.br/cobranca/v3/cobrancas/{$cobranca->codigo}", [], $headers);
        }
        catch (Exception $e) 
        {
            throw new Exception("Ocorreu um erro ao buscar a cobrança no Banco Inter: ".$e->getMessage()."<br>Error Code: ".$e->getCode());
        }
        
        $dadosRetorno = new stdClass();
        $dadosRetorno->status = $retorno->cobranca->situacao;
        $dadosRetorno->dados_cobranca = $retorno;
        
        return $dadosRetorno;
    }   
    
    public static function parseErro($erroJson)
    {
        $data = json_decode($erroJson);
        $title = $data->title;
        $detail = $data->detail;
        $timestamp = $data->timestamp;
        $violacoes = $data->violacoes;
        
        // Armazenar as informações em uma variável
        $result = "Título: $title<br>";
        $result .= "Detalhes: $detail<br>";
        $result .= "Violácões:<br>";
        foreach ($violacoes as $violacao) {
            $razao = $violacao->razao;
            $propriedade = $violacao->propriedade;
            $valor = $violacao->valor;
            $result .= " - Motivo: $razao<br>";
            $result .= "   Propriedade: $propriedade<br>";
            $result .= "   Valor informado: $valor<br>";
        }
        
        return $result;
    }
    
    private function httpGet($url, $dados, $headers = [])
    {
        return BuilderHttpClientService::get($url, $dados, "Bearer {$this->gatewayPagamento->oauth_token}", [
            CURLOPT_HTTPHEADER => $headers
        ], [
            CURLOPT_SSLCERT => $this->gatewayPagamento->certificado_crt,
            CURLOPT_SSLKEY => $this->gatewayPagamento->certificado_key
        ]);
    }

    private function httpPut($url, $dados, $headers = [])
    {
        return BuilderHttpClientService::put($url, $dados, "Bearer {$this->gatewayPagamento->oauth_token}", [
            CURLOPT_HTTPHEADER => $headers
        ], [
            CURLOPT_SSLCERT => $this->gatewayPagamento->certificado_crt,
            CURLOPT_SSLKEY => $this->gatewayPagamento->certificado_key
        ]);
    }    
    
    private function httpPost($url, $dados, $headers = [])
    {        
        return BuilderHttpClientService::post($url, $dados, "Bearer {$this->gatewayPagamento->oauth_token}", [
            CURLOPT_HTTPHEADER => $headers
        ], [
            CURLOPT_SSLCERT => $this->gatewayPagamento->certificado_crt,
            CURLOPT_SSLKEY => $this->gatewayPagamento->certificado_key
        ]);
    }
}
