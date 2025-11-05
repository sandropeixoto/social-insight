# Social Insight – Prototype

Prototipo inicial da plataforma Social Insight (VotoHub) para monitoramento de mensagens recebidas via API do WhatsApp (W-API). A aplicação registra grupos e mensagens em SQLite e apresenta uma interface web inspirada no WhatsApp Web para navegação das conversas.

## Visão Geral

- **Stack:** PHP 8+, SQLite, HTML/CSS/JS puro.
- **Funcionalidades:** armazenamento de grupos, persistência de mensagens recebidas por webhook, listagem de grupos e exibição das mensagens em layout similar ao WhatsApp.
- **Integração:** endpoint `/api/webhook.php` compatível com o payload de entrada do W-API (adapta automaticamente campos comuns do WhatsApp Cloud API / W-API).

## Pré-requisitos

- PHP 8.1 ou superior com extensões `pdo_sqlite` habilitadas.
- Composer opcional (não utilizado, mas recomendado para futuras dependências).

## Como executar localmente

```bash
cd c:\Dev\social-insight
php -S 0.0.0.0:8080 -t public
```

A aplicação ficará disponível em `http://localhost:8080`.

Na primeira execução, o arquivo `data/social_insight.sqlite` é criado automaticamente com as tabelas necessárias.

## Configuração do Webhook

1. **URL pública:** exponha o servidor local (ex: `https://seu-dominio.com/api/webhook.php`) usando um túnel como [ngrok](https://ngrok.com/) ou configure o host diretamente.
2. **Token de verificação:** defina a variável de ambiente `WEBHOOK_VERIFY_TOKEN` com o valor que será configurado no painel do W-API.
3. **Assinatura do webhook:** o endpoint responde à verificação GET (`hub.challenge`). Para mensagens reais, envie `POST` com o payload recebido do W-API.

### Exemplo de payload (simplificado)

```json
{
  "entry": [
    {
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "contacts": [
              {
                "wa_id": "5581999999999",
                "profile": {
                  "name": "Coordenador HQ"
                }
              }
            ],
            "messages": [
              {
                "id": "wamid.HBgLN...",
                "from": "5581999999999",
                "timestamp": "1714864500",
                "type": "text",
                "text": {
                  "body": "Reunião confirmada às 14h."
                },
                "chatId": "120363046566792385@g.us",
                "chatName": "Equipe Mobilização"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

Use `curl` para testar a ingestão enquanto o servidor está ativo:

```bash
curl -X POST http://localhost:8080/api/webhook.php ^
  -H "Content-Type: application/json" ^
  -d "@payload.json"
```

Após enviar o payload, recarregue a interface web e selecione o grupo correspondente.

## Estrutura de Diretórios

```
.
├── bootstrap.php          # Inicializa PDO/SQLite e funções utilitárias
├── public/
│   ├── index.php          # Interface web (layout tipo WhatsApp)
│   ├── assets/
│   │   ├── app.js         # Lógica de frontend (fetch, renderização)
│   │   └── styles.css     # Estilos inspirados no WhatsApp Web
│   └── api/
│       ├── groups.php     # Lista grupos com metadata
│       ├── messages.php   # Lista mensagens de um grupo
│       └── webhook.php    # Endpoint para ingestão via webhook
└── data/
    └── social_insight.sqlite (gerado automaticamente)
```

## Próximos Passos Sugeridos

- Home para gerenciamento de múltiplos números/instâncias (número emprestado x número dedicado).
- Autenticação básica e segregação de clientes.
- Normalização específica dos eventos da W-API (entrega, status, mídia, etc).
- Processamento assíncrono e fila de ingestão para alto volume.

## Licença

Uso interno e experimental para o projeto Social Insight.
