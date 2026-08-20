<?php
header('Content-Type: text/html; charset=UTF-8');

$dir = __DIR__;
$smtpFile = $dir . '/smtp.json';
$queueFile = $dir . '/queue.json';

if (!file_exists($smtpFile)) {
    file_put_contents($smtpFile, json_encode([
        'host' => '',
        'port' => 587,
        'user' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => ''
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if (!file_exists($queueFile)) {
    file_put_contents($queueFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$smtpConfig = json_decode(file_get_contents($smtpFile), true);
$queue = json_decode(file_get_contents($queueFile), true);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $newConfig = [
            'host' => trim($_POST['host'] ?? ''),
            'port' => intval($_POST['port'] ?? 587),
            'user' => trim($_POST['user'] ?? ''),
            'password' => trim($_POST['password'] ?? ''),
            'encryption' => $_POST['encryption'] ?? 'tls',
            'from_email' => trim($_POST['from_email'] ?? ''),
            'from_name' => trim($_POST['from_name'] ?? '')
        ];

        file_put_contents($smtpFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $smtpConfig = $newConfig;
        $message = 'Configuração SMTP salva com sucesso.';
        $messageType = 'success';
    }

    if ($action === 'test_smtp') {
        $testEmail = trim($_POST['test_email'] ?? '');

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Informe um e-mail de teste válido.';
            $messageType = 'error';
        } else {
            require_once $dir . '/PHPMailer/src/Exception.php';
            require_once $dir . '/PHPMailer/src/PHPMailer.php';
            require_once $dir . '/PHPMailer/src/SMTP.php';

            use PHPMailer\PHPMailer\PHPMailer;
            use PHPMailer\PHPMailer\Exception;

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpConfig['host'];
                $mail->Port = $smtpConfig['port'];
                $mail->CharSet = 'UTF-8';

                if (!empty($smtpConfig['user'])) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpConfig['user'];
                    $mail->Password = $smtpConfig['password'];
                }

                $enc = strtolower($smtpConfig['encryption']);
                if ($enc === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($enc === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPAutoTLS = false;
                }

                $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
                $mail->addAddress($testEmail);
                $mail->isHTML(true);
                $mail->Subject = 'Teste de Conexão SMTP';
                $mail->Body = '<h2>Sucesso!</h2><p>A configuração SMTP está funcionando corretamente.</p>';
                $mail->AltBody = 'Sucesso! A configuração SMTP está funcionando corretamente.';

                $mail->smtpConnect();
                $mail->smtpClose();

                $message = 'Conexão SMTP testada com sucesso!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Erro SMTP: ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    }

    if ($action === 'add_to_queue') {
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $allowHtml = isset($_POST['allow_html']);
        $rawEmails = $_POST['emails'] ?? '';

        if (empty($subject)) {
            $message = 'O assunto é obrigatório.';
            $messageType = 'error';
        } elseif (empty($rawEmails)) {
            $message = 'Informe pelo menos um destinatário.';
            $messageType = 'error';
        } else {
            $emails = preg_split('/[,\n;]+/', $rawEmails);
            $validEmails = [];
            $seen = [];

            foreach ($emails as $email) {
                $email = trim($email);
                $email = strtolower($email);
                if ($email === '') continue;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                if (isset($seen[$email])) continue;
                $seen[$email] = true;
                $validEmails[] = $email;
            }

            if (empty($validEmails)) {
                $message = 'Nenhum e-mail válido encontrado.';
                $messageType = 'error';
            } else {
                $now = date('c');
                $added = 0;

                foreach ($validEmails as $email) {
                    $queue[] = [
                        'id' => uniqid('mail_', true),
                        'email' => $email,
                        'subject' => $subject,
                        'body' => $body,
                        'html' => $allowHtml ? 1 : 0,
                        'status' => 'pending',
                        'attempts' => 0,
                        'error' => null,
                        'created_at' => $now,
                        'sent_at' => null
                    ];
                    $added++;
                }

                file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $message = "$added destinatário(s) adicionado(s) à fila.";
                $messageType = 'success';
            }
        }
    }
}

$smtpConfig = json_decode(file_get_contents($smtpFile), true);
$queue = json_decode(file_get_contents($queueFile), true);

$counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
foreach ($queue as $item) {
    $s = $item['status'] ?? 'pending';
    if (isset($counts[$s])) $counts[$s]++;
}

$smtpStatus = (!empty($smtpConfig['host']) && !empty($smtpConfig['user'])) ? 'Configurado' : 'Não configurado';
$recentResults = array_reverse(array_slice($queue, -20));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Send - Painel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        h1 { text-align: center; margin-bottom: 20px; color: #2c3e50; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; color: #34495e; font-size: 1.2em; border-bottom: 2px solid #3498db; padding-bottom: 8px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        input[type="text"], input[type="password"], input[type="number"], textarea, select {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; margin-bottom: 12px;
        }
        textarea { resize: vertical; min-height: 100px; }
        .row { display: flex; gap: 15px; }
        .row > div { flex: 1; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; cursor: pointer; }
        .checkbox-label input { width: auto; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background 0.2s; }
        .btn-primary { background: #3498db; color: #fff; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: #fff; }
        .btn-success:hover { background: #219a52; }
        .btn-warning { background: #f39c12; color: #fff; }
        .btn-warning:hover { background: #d68910; }
        .btn-group { display: flex; gap: 10px; margin-top: 10px; }
        .status-bar { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
        .status-item { padding: 12px 20px; border-radius: 6px; text-align: center; min-width: 120px; }
        .status-item.pending { background: #ffeaa7; color: #856404; }
        .status-item.processing { background: #74b9ff; color: #0c5460; }
        .status-item.sent { background: #55efc4; color: #155724; }
        .status-item.failed { background: #fab1a0; color: #721c24; }
        .status-item .count { font-size: 1.8em; font-weight: 700; }
        .status-item .label { font-size: 0.85em; text-transform: uppercase; }
        .smtp-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .smtp-badge.ok { background: #55efc4; color: #155724; }
        .smtp-badge.nok { background: #fab1a0; color: #721c24; }
        .msg { padding: 12px 16px; border-radius: 4px; margin-bottom: 15px; font-weight: 500; }
        .msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge { padding: 2px 8px; border-radius: 3px; font-size: 0.8em; font-weight: 600; }
        .badge-sent { background: #55efc4; color: #155724; }
        .badge-failed { background: #fab1a0; color: #721c24; }
        .badge-pending { background: #ffeaa7; color: #856404; }
        .badge-processing { background: #74b9ff; color: #0c5460; }
        .empty { text-align: center; color: #999; padding: 20px; }
        @media (max-width: 600px) { .row { flex-direction: column; } }
    </style>
</head>
<body>
<div class="container">
    <h1>SMTP Send</h1>

    <?php if ($message): ?>
        <div class="msg <?php echo $messageType; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="status-bar">
        <div class="status-item pending">
            <div class="count"><?php echo $counts['pending']; ?></div>
            <div class="label">Pendentes</div>
        </div>
        <div class="status-item processing">
            <div class="count"><?php echo $counts['processing']; ?></div>
            <div class="label">Processando</div>
        </div>
        <div class="status-item sent">
            <div class="count"><?php echo $counts['sent']; ?></div>
            <div class="label">Enviados</div>
        </div>
        <div class="status-item failed">
            <div class="count"><?php echo $counts['failed']; ?></div>
            <div class="label">Falharam</div>
        </div>
    </div>

    <div class="card">
        <h2>Configuração SMTP <span class="smtp-badge <?php echo $smtpStatus === 'Configurado' ? 'ok' : 'nok'; ?>"><?php echo $smtpStatus; ?></span></h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_config">
            <div class="row">
                <div>
                    <label>Host SMTP</label>
                    <input type="text" name="host" value="<?php echo htmlspecialchars($smtpConfig['host'] ?? ''); ?>" placeholder="smtp.exemplo.com">
                </div>
                <div>
                    <label>Porta</label>
                    <input type="number" name="port" value="<?php echo intval($smtpConfig['port'] ?? 587); ?>">
                </div>
                <div>
                    <label>Criptografia</label>
                    <select name="encryption">
                        <option value="none" <?php echo ($smtpConfig['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>Nenhuma</option>
                        <option value="tls" <?php echo ($smtpConfig['encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="ssl" <?php echo ($smtpConfig['encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div>
                    <label>Usuário</label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($smtpConfig['user'] ?? ''); ?>">
                </div>
                <div>
                    <label>Senha</label>
                    <input type="password" name="password" value="" placeholder="<?php echo !empty($smtpConfig['password']) ? '••••••••' : ''; ?>">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>E-mail do Remetente</label>
                    <input type="text" name="from_email" value="<?php echo htmlspecialchars($smtpConfig['from_email'] ?? ''); ?>">
                </div>
                <div>
                    <label>Nome do Remetente</label>
                    <input type="text" name="from_name" value="<?php echo htmlspecialchars($smtpConfig['from_name'] ?? ''); ?>">
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Salvar Configuração</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Testar SMTP</h2>
        <form method="POST">
            <input type="hidden" name="action" value="test_smtp">
            <label>E-mail de Teste</label>
            <input type="text" name="test_email" placeholder="seu@email.com" required>
            <button type="submit" class="btn btn-warning">Testar SMTP</button>
        </form>
    </div>

    <div class="card">
        <h2>Enviar Mensagem</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_to_queue">
            <label>Assunto</label>
            <input type="text" name="subject" required>
            <label>Corpo da Mensagem</label>
            <textarea name="body" rows="5" required></textarea>
            <label class="checkbox-label">
                <input type="checkbox" name="allow_html" value="1"> Permitir HTML
            </label>
            <label>Destinatários (um por linha, vírgula ou ponto e vírgula)</label>
            <textarea name="emails" rows="6" placeholder="email1@exemplo.com&#10;email2@exemplo.com&#10;email3@exemplo.com" required></textarea>
            <button type="submit" class="btn btn-success">Adicionar à Fila</button>
        </form>
    </div>

    <div class="card">
        <h2>Resultados Recentes</h2>
        <?php if (empty($recentResults)): ?>
            <div class="empty">Nenhum resultado ainda.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>E-mail</th>
                        <th>Status</th>
                        <th>Erro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentResults as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['email']); ?></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($item['status']); ?>"><?php echo htmlspecialchars(ucfirst($item['status'])); ?></span></td>
                            <td><?php echo htmlspecialchars($item['error'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
