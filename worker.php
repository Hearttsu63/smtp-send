<?php
$batchSize = 10;
$delaySeconds = 2;
$maxAttempts = 3;

$dir = __DIR__;
$smtpFile = $dir . '/smtp.json';
$queueFile = $dir . '/queue.json';
$lockFile = $dir . '/worker.lock';

if (!file_exists($smtpFile) || !file_exists($queueFile)) {
    exit(0);
}

$lock = @fopen($lockFile, 'x');
if ($lock === false) {
    exit(0);
}
fwrite($lock, getmypid());
fclose($lock);

register_shutdown_function(function () use ($lockFile) {
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

try {
    $smtpConfig = json_decode(file_get_contents($smtpFile), true);
    $queue = json_decode(file_get_contents($queueFile), true);

    if (!is_array($queue)) {
        $queue = [];
    }

    $pending = [];
    $processing = [];
    $others = [];

    foreach ($queue as $key => $item) {
        $status = $item['status'] ?? 'pending';
        if ($status === 'pending') {
            $pending[] = ['key' => $key, 'item' => $item];
        } elseif ($status === 'processing') {
            $processing[] = ['key' => $key, 'item' => $item];
        } else {
            $others[] = $item;
        }
    }

    foreach ($processing as $p) {
        $item = $p['item'];
        $item['status'] = 'pending';
        $item['attempts'] = ($item['attempts'] ?? 0) + 1;
        if ($item['attempts'] >= $maxAttempts) {
            $item['status'] = 'failed';
            $item['error'] = 'Timeout no processamento';
        }
        $queue[$p['key']] = $item;
    }

    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $queue = json_decode(file_get_contents($queueFile), true);
    $pending = [];
    foreach ($queue as $key => $item) {
        if (($item['status'] ?? 'pending') === 'pending' && ($item['attempts'] ?? 0) < $maxAttempts) {
            $pending[] = ['key' => $key, 'item' => $item];
        }
    }

    if (empty($pending)) {
        exit(0);
    }

    $batch = array_slice($pending, 0, $batchSize);

    require_once $dir . '/PHPMailer/src/Exception.php';
    require_once $dir . '/PHPMailer/src/PHPMailer.php';
    require_once $dir . '/PHPMailer/src/SMTP.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    $sent = 0;
    $failed = 0;

    foreach ($batch as $b) {
        $key = $b['key'];
        $item = $b['item'];

        if (empty($smtpConfig['host']) || empty($smtpConfig['from_email'])) {
            $item['status'] = 'failed';
            $item['error'] = 'Configuração SMTP incompleta';
            $item['attempts'] = ($item['attempts'] ?? 0) + 1;
            $queue[$key] = $item;
            $failed++;
            continue;
        }

        $item['status'] = 'processing';
        $queue[$key] = $item;
        file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpConfig['host'];
            $mail->Port = intval($smtpConfig['port']);
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            if (!empty($smtpConfig['user'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $smtpConfig['user'];
                $mail->Password = $smtpConfig['password'];
            }

            $enc = strtolower($smtpConfig['encryption'] ?? '');
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name'] ?? '');
            $mail->addAddress($item['email']);
            $mail->isHTML(!empty($item['html']));
            $mail->Subject = $item['subject'];
            $mail->Body = $item['body'];
            $mail->AltBody = strip_tags($item['body']);

            $mail->send();

            $item['status'] = 'sent';
            $item['error'] = null;
            $item['sent_at'] = date('c');
            $item['attempts'] = ($item['attempts'] ?? 0) + 1;
            $queue[$key] = $item;
            $sent++;
        } catch (Exception $e) {
            $item['attempts'] = ($item['attempts'] ?? 0) + 1;
            $item['error'] = $e->getMessage();
            if ($item['attempts'] >= $maxAttempts) {
                $item['status'] = 'failed';
            } else {
                $item['status'] = 'pending';
            }
            $queue[$key] = $item;
            $failed++;
        }

        file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

} catch (Exception $e) {
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true);
        if (is_array($queue)) {
            foreach ($queue as &$item) {
                if (($item['status'] ?? '') === 'processing') {
                    $item['status'] = 'pending';
                }
            }
            file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
} finally {
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
}
