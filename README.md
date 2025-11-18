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

## Integração com o W-API

Configure o arquivo `.env` com os dados fornecidos pela plataforma. A coleção mais recente utiliza:

```ini
WAPI_BASE_URL=https://api.w-api.app/v1
WAPI_STATUS_ENDPOINT=/instance/status-instance?instanceId={{id}}
WAPI_PROFILE_ENDPOINT=/instance/device?instanceId={{id}}
WAPI_QR_ENDPOINT=/instance/qr-code?instanceId={{id}}&image=disable&syncContacts=disable
WAPI_FETCH_GROUPS_ENDPOINT=/group/get-all-groups?instanceId={{id}}
WAPI_FETCH_CHATS_ENDPOINT=/chats/fetch-chats?instanceId={{id}}&perPage=100&page=1
WAPI_DISCONNECT_ENDPOINT=/instance/disconnect?instanceId={{id}}
```

Defina também `WAPI_INSTANCE_ID` e `WAPI_AUTH_TOKEN` com os valores da sua instância antes de iniciar a aplicação.

Para popular a lista de conversas automaticamente em uma nova instância, mantenha `WAPI_AUTO_SYNC_CHATS=true` (valor padrão). A sincronização usa, por padrão, o endpoint `group/get-all-groups`; é possível substituir pelos seus próprios endpoints com `WAPI_FETCH_GROUPS_ENDPOINT` ou `WAPI_FETCH_CHATS_ENDPOINT`, bem como ajustar a paginação (`WAPI_CHATS_PER_PAGE`, `WAPI_CHATS_PAGE`).

## Configuração do Webhook

1. **URL pública:** exponha o servidor local (ex: `https://seu-dominio.com/api/webhook.php`) usando um túnel como [ngrok](https://ngrok.com/) ou configure o host diretamente.
2. **Token de verificação:** defina a variável de ambiente `WEBHOOK_VERIFY_TOKEN` com o valor que será configurado no painel do W-API.
3. **Assinatura do webhook:** o endpoint responde à verificação GET (`hub.challenge`). Para mensagens reais, envie `POST` com o payload recebido do W-API.

### Verificação do Webhook

Durante o cadastro, a plataforma da W-API (ou Meta/WhatsApp Cloud) envia um `GET` para confirmar o webhook. O endpoint aceita os parâmetros tradicionais (`hub_mode`, `hub_verify_token`, `hub_challenge`) ou equivalentes (`mode`, `token`, `challenge`).

Exemplo manual com `curl`:

```bash
curl "https://seu-dominio.com/api/webhook.php?hub_mode=subscribe&hub_verify_token=seu_token&hub_challenge=123456"
```

Se o `hub_verify_token` recebido for igual ao valor configurado em `WEBHOOK_VERIFY_TOKEN`, o endpoint responderá com o conteúdo de `hub_challenge` (HTTP 200). Caso contrário, retorna 403.

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
