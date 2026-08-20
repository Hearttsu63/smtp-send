# SMTP Send - Sistema de Envio de E-mails

## Estrutura

```
smtp/
├── index.php          # Painel principal
├── worker.php         # Processador da fila (executa via Cron)
├── smtp.json          # Configuração SMTP (criado automaticamente)
├── queue.json         # Fila de envios (criado automaticamente)
├── .htaccess          # Regras de segurança
└── PHPMailer/
    └── src/
        ├── PHPMailer.php
        ├── SMTP.php
        ├── Exception.php
        ├── POP3.php
        ├── OAuth.php
        ├── OAuthTokenProvider.php
        └── DSNConfigurator.php
```

## Instalação

1. Configure as permissões de escrita para `smtp.json` e `queue.json`
2. Acesse `index.php` pelo navegador

> PHPMailer já incluso no repositório.

## Configuração do Cron

Adicione a seguinte linha ao crontab:

```
* * * * * /usr/bin/php /caminho/smtp/worker.php
```

O worker processa até 10 mensagens por execução, com 2 segundos de intervalo entre cada envio.

## Segurança

- Arquivos JSON e lock são protegidos pelo `.htaccess`
- Senha SMTP não é exibida após salva
- Validação de e-mails
- Lock contra execução simultânea do worker

## Funcionalidades

- Configuração SMTP via painel
- Teste de conexão SMTP
- Fila de envios com controle de status
- Suporte a HTML
- Validação de destinatários
- Remoção de duplicatas
- Controle de tentativas (máximo 3)
