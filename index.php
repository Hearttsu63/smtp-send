<?php
header('Content-Type: text/html; charset=UTF-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

$dir = __DIR__;
$smtpConfigsFile = $dir . '/smtp-configs.json';
$queueFile = $dir . '/queue.json';

if (!file_exists($smtpConfigsFile)) {
    file_put_contents($smtpConfigsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if (!file_exists($queueFile)) {
    file_put_contents($queueFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$smtpConfigs = json_decode(file_get_contents($smtpConfigsFile), true);
$queue = json_decode(file_get_contents($queueFile), true);

$message = '';
$messageType = '';

function parseEmails($text) {
    $emails = preg_split('/[,\n;]+/', $text);
    $validEmails = [];
    $seen = [];
    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        if ($email === '') continue;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($seen[$email])) continue;
        $seen[$email] = true;
        $validEmails[] = $email;
    }
    return $validEmails;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_smtp') {
        $newConfig = [
            'id' => uniqid('smtp_', true),
            'host' => trim($_POST['host'] ?? ''),
            'port' => intval($_POST['port'] ?? 587),
            'user' => trim($_POST['user'] ?? ''),
            'password' => trim($_POST['password'] ?? ''),
            'encryption' => $_POST['encryption'] ?? 'tls',
            'from_email' => trim($_POST['from_email'] ?? ''),
            'from_name' => trim($_POST['from_name'] ?? ''),
            'active' => true
        ];

        if (empty($newConfig['host']) || empty($newConfig['user'])) {
            $message = 'Host and Username are required.';
            $messageType = 'error';
        } else {
            $smtpConfigs[] = $newConfig;
            file_put_contents($smtpConfigsFile, json_encode($smtpConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $message = 'SMTP configuration added successfully.';
            $messageType = 'success';
        }
    }

    if ($action === 'upload_smtp') {
        if (!empty($_FILES['smtp_file']['tmp_name'])) {
            $fileContent = file_get_contents($_FILES['smtp_file']['tmp_name']);
            $lines = explode("\n", $fileContent);
            $added = 0;

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = explode('|', $line);
                if (count($parts) < 4) continue;

                $newConfig = [
                    'id' => uniqid('smtp_', true),
                    'host' => trim($parts[0]),
                    'port' => intval(trim($parts[1])),
                    'user' => trim($parts[2]),
                    'password' => trim($parts[3]),
                    'encryption' => isset($parts[4]) ? trim($parts[4]) : 'tls',
                    'from_email' => trim($parts[2]),
                    'from_name' => 'SMTP Send',
                    'active' => true
                ];

                if (!empty($newConfig['host']) && !empty($newConfig['user'])) {
                    $smtpConfigs[] = $newConfig;
                    $added++;
                }
            }

            file_put_contents($smtpConfigsFile, json_encode($smtpConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $message = "$added SMTP configuration(s) imported successfully.";
            $messageType = 'success';
        } else {
            $message = 'Please select a file to upload.';
            $messageType = 'error';
        }
    }

    if ($action === 'toggle_smtp') {
        $configId = $_POST['config_id'] ?? '';
        foreach ($smtpConfigs as &$config) {
            if ($config['id'] === $configId) {
                $config['active'] = !$config['active'];
                break;
            }
        }
        file_put_contents($smtpConfigsFile, json_encode($smtpConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = 'SMTP configuration updated.';
        $messageType = 'success';
    }

    if ($action === 'delete_smtp') {
        $configId = $_POST['config_id'] ?? '';
        $smtpConfigs = array_filter($smtpConfigs, function($config) use ($configId) {
            return $config['id'] !== $configId;
        });
        $smtpConfigs = array_values($smtpConfigs);
        file_put_contents($smtpConfigsFile, json_encode($smtpConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = 'SMTP configuration deleted.';
        $messageType = 'success';
    }

    if ($action === 'delete_all_smtp') {
        $count = count($smtpConfigs);
        $smtpConfigs = [];
        file_put_contents($smtpConfigsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = "$count SMTP configuration(s) deleted.";
        $messageType = 'success';
    }

    if ($action === 'test_smtp') {
        $configId = $_POST['config_id'] ?? '';
        $testEmail = trim($_POST['test_email'] ?? '');

        $selectedConfig = null;
        foreach ($smtpConfigs as $config) {
            if ($config['id'] === $configId) {
                $selectedConfig = $config;
                break;
            }
        }

        if (!$selectedConfig) {
            $message = 'SMTP configuration not found.';
            $messageType = 'error';
        } elseif (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid test email.';
            $messageType = 'error';
        } else {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $selectedConfig['host'];
                $mail->Port = $selectedConfig['port'];
                $mail->CharSet = 'UTF-8';
                $mail->SMTPAuth = true;
                $mail->Username = $selectedConfig['user'];
                $mail->Password = $selectedConfig['password'];

                $enc = strtolower($selectedConfig['encryption']);
                if ($enc === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($enc === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPAutoTLS = false;
                }

                $mail->setFrom($selectedConfig['from_email'], $selectedConfig['from_name']);
                $mail->addAddress($testEmail);
                $mail->isHTML(true);
                $mail->Subject = 'SMTP Connection Test';
                $mail->Body = '<h2>Success!</h2><p>SMTP configuration is working correctly.</p>';
                $mail->AltBody = 'Success! SMTP configuration is working correctly.';

                $mail->smtpConnect();
                $mail->smtpClose();

                $message = 'SMTP connection tested successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'SMTP Error: ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    }

    if ($action === 'add_to_queue') {
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $allowHtml = isset($_POST['allow_html']);
        $rawEmails = $_POST['emails'] ?? '';

        if (!empty($_FILES['email_file']['tmp_name'])) {
            $fileContent = file_get_contents($_FILES['email_file']['tmp_name']);
            $rawEmails .= "\n" . $fileContent;
        }

        if (empty($subject)) {
            $message = 'Subject is required.';
            $messageType = 'error';
        } elseif (empty(trim($rawEmails))) {
            $message = 'Enter at least one recipient.';
            $messageType = 'error';
        } else {
            $validEmails = parseEmails($rawEmails);

            if (empty($validEmails)) {
                $message = 'No valid emails found.';
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
                $message = "$added recipient(s) added to queue.";
                $messageType = 'success';
            }
        }
    }
}

$smtpConfigs = json_decode(file_get_contents($smtpConfigsFile), true);
$queue = json_decode(file_get_contents($queueFile), true);

$counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
foreach ($queue as $item) {
    $s = $item['status'] ?? 'pending';
    if (isset($counts[$s])) $counts[$s]++;
}

$activeConfigs = array_filter($smtpConfigs, fn($c) => $c['active']);
$smtpStatus = count($activeConfigs) > 0 ? count($activeConfigs) . ' Active' : 'None';
$recentResults = array_reverse(array_slice($queue, -20));
$totalConfigs = count($smtpConfigs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Send - Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        h1 { text-align: center; margin-bottom: 20px; color: #2c3e50; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; color: #34495e; font-size: 1.2em; border-bottom: 2px solid #3498db; padding-bottom: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .card h2::after { content: '\25BC'; font-size: 0.7em; transition: transform 0.2s; }
        .card.collapsed h2::after { transform: rotate(-90deg); }
        .card.collapsed .card-body { display: none; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        input[type="text"], input[type="password"], input[type="number"], input[type="file"], textarea, select {
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
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
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
        .badge-active { background: #55efc4; color: #155724; }
        .badge-inactive { background: #ddd; color: #666; }
        .empty { text-align: center; color: #999; padding: 20px; }
        .divider { border: none; border-top: 1px dashed #ddd; margin: 15px 0; }
        .or-text { text-align: center; color: #999; font-size: 0.9em; }
        .file-upload { background: #f8f9fa; border: 2px dashed #ddd; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer; transition: border-color 0.2s; }
        .file-upload:hover { border-color: #3498db; }
        .file-upload input[type="file"] { display: none; }
        .file-upload .icon { font-size: 2em; margin-bottom: 10px; }
        .smtp-list { margin-top: 15px; max-height: 400px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; }
        .smtp-item { background: #f8f9fa; border-bottom: 1px solid #eee; padding: 12px; display: flex; justify-content: space-between; align-items: center; }
        .smtp-item:last-child { border-bottom: none; }
        .smtp-item .info { flex: 1; }
        .smtp-item .host { font-weight: 600; color: #333; }
        .smtp-item .user { font-size: 0.9em; color: #666; }
        .smtp-item .actions { display: flex; gap: 5px; }
        .tabs { display: flex; gap: 5px; margin-bottom: 15px; }
        .tab { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; background: #eee; color: #666; }
        .tab.active { background: #3498db; color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .show-more { padding: 10px; text-align: center; background: #f8f9fa; cursor: pointer; color: #3498db; font-weight: 600; }
        .show-more:hover { background: #eee; }
        @media (max-width: 600px) { .row { flex-direction: column; } .smtp-item { flex-direction: column; gap: 10px; } }
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
            <div class="label">Pending</div>
        </div>
        <div class="status-item processing">
            <div class="count"><?php echo $counts['processing']; ?></div>
            <div class="label">Processing</div>
        </div>
        <div class="status-item sent">
            <div class="count"><?php echo $counts['sent']; ?></div>
            <div class="label">Sent</div>
        </div>
        <div class="status-item failed">
            <div class="count"><?php echo $counts['failed']; ?></div>
            <div class="label">Failed</div>
        </div>
    </div>

    <div class="card" id="smtp-card">
        <h2 onclick="toggleCard('smtp-card')">SMTP Configurations <span class="smtp-badge <?php echo count($activeConfigs) > 0 ? 'ok' : 'nok'; ?>"><?php echo $smtpStatus; ?> (<?php echo $totalConfigs; ?> total)</span></h2>
        <div class="card-body">
            <div class="tabs">
                <button class="tab active" onclick="showTab('add-single')">Add Single</button>
                <button class="tab" onclick="showTab('add-bulk')">Bulk Upload</button>
                <button class="tab" onclick="showTab('test-smtp')">Test SMTP</button>
            </div>

            <div id="tab-add-single" class="tab-content active">
                <form method="POST">
                    <input type="hidden" name="action" value="add_smtp">
                    <div class="row">
                        <div>
                            <label>SMTP Host</label>
                            <input type="text" name="host" placeholder="smtp.office365.com" required>
                        </div>
                        <div>
                            <label>Port</label>
                            <input type="number" name="port" value="587" required>
                        </div>
                        <div>
                            <label>Encryption</label>
                            <select name="encryption">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div>
                            <label>Username</label>
                            <input type="text" name="user" placeholder="user@example.com" required>
                        </div>
                        <div>
                            <label>Password</label>
                            <input type="password" name="password" required>
                        </div>
                    </div>
                    <div class="row">
                        <div>
                            <label>Sender Email</label>
                            <input type="text" name="from_email" placeholder="sender@example.com">
                        </div>
                        <div>
                            <label>Sender Name</label>
                            <input type="text" name="from_name" placeholder="My SMTP">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add SMTP</button>
                </form>
            </div>

            <div id="tab-add-bulk" class="tab-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_smtp">
                    <label>Upload TXT File with SMTP Credentials</label>
                    <div class="file-upload" onclick="document.getElementById('smtp_file').click();">
                        <div class="icon">&#128196;</div>
                        <p>Click to select a .txt file</p>
                        <p style="font-size:0.8em;color:#999;">Format: smtpHost|port|username|password</p>
                        <input type="file" name="smtp_file" id="smtp_file" accept=".txt">
                    </div>
                    <p id="smtp-file-name" style="margin-top:8px;font-size:0.9em;color:#666;"></p>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-success">Import SMTPs</button>
                    </div>
                </form>
                <div style="margin-top:15px;padding:15px;background:#f8f9fa;border-radius:6px;">
                    <strong>Example TXT format:</strong>
                    <pre style="margin-top:10px;padding:10px;background:#fff;border:1px solid #eee;border-radius:4px;font-size:12px;">smtp.office365.com|587|user1@domain.com|pass123
smtp.gmail.com|587|user2@gmail.com|pass456
smtp.mail.yahoo.com|587|user3@yahoo.com|pass789</pre>
                </div>
            </div>

            <div id="tab-test-smtp" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="test_smtp">
                    <label>Select SMTP Configuration</label>
                    <select name="config_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($smtpConfigs as $config): ?>
                            <option value="<?php echo $config['id']; ?>"><?php echo htmlspecialchars($config['host'] . ' - ' . $config['user']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Test Email</label>
                    <input type="text" name="test_email" placeholder="your@email.com" required>
                    <button type="submit" class="btn btn-warning">Test SMTP</button>
                </form>
            </div>

            <?php if (!empty($smtpConfigs)): ?>
            <div class="smtp-list" id="smtp-list">
                <h3 style="padding:10px;margin:0;background:#f8f9fa;border-bottom:1px solid #eee;">Saved Configurations (<?php echo $totalConfigs; ?>)</h3>
                <?php foreach ($smtpConfigs as $index => $config): ?>
                <div class="smtp-item" data-index="<?php echo $index; ?>" style="<?php echo $index >= 20 ? 'display:none;' : ''; ?>">
                    <div class="info">
                        <div class="host"><?php echo htmlspecialchars($config['host']); ?>:<?php echo $config['port']; ?></div>
                        <div class="user"><?php echo htmlspecialchars($config['user']); ?></div>
                    </div>
                    <div class="actions">
                        <span class="badge <?php echo $config['active'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo $config['active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_smtp">
                            <input type="hidden" name="config_id" value="<?php echo $config['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <?php echo $config['active'] ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this SMTP configuration?');">
                            <input type="hidden" name="action" value="delete_smtp">
                            <input type="hidden" name="config_id" value="<?php echo $config['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($totalConfigs > 20): ?>
                <div class="show-more" onclick="showAllSmtps()">Show All (<?php echo $totalConfigs; ?>)</div>
                <?php endif; ?>
                <div style="padding:10px;background:#fff;border-top:1px solid #eee;text-align:right;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('DELETE ALL <?php echo $totalConfigs; ?> SMTP CONFIGURATIONS? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_all_smtp">
                        <button type="submit" class="btn btn-danger">Delete All (<?php echo $totalConfigs; ?>)</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" id="send-card">
        <h2 onclick="toggleCard('send-card')">Send Message</h2>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="send-form">
                <input type="hidden" name="action" value="add_to_queue">
                <label>Subject</label>
                <input type="text" name="subject" required>
                <label>Message Body</label>
                <textarea name="body" rows="5" required></textarea>
                <label class="checkbox-label">
                    <input type="checkbox" name="allow_html" value="1"> Allow HTML
                </label>
                
                <label>Recipients (one per line, comma, or semicolon)</label>
                <textarea name="emails" rows="4" placeholder="email1@example.com&#10;email2@example.com&#10;email3@example.com"></textarea>
                
                <hr class="divider">
                <p class="or-text">OR</p>
                <hr class="divider">
                
                <label>Upload TXT File with Emails</label>
                <div class="file-upload" onclick="document.getElementById('email_file').click();">
                    <div class="icon">&#128196;</div>
                    <p>Click to select a .txt file</p>
                    <p style="font-size:0.8em;color:#999;">One email per line, or comma/semicolon separated</p>
                    <input type="file" name="email_file" id="email_file" accept=".txt">
                </div>
                <p id="email-file-name" style="margin-top:8px;font-size:0.9em;color:#666;"></p>
                
                <div class="btn-group" style="margin-top:15px;">
                    <button type="submit" class="btn btn-success">Add to Queue</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" id="results-card">
        <h2 onclick="toggleCard('results-card')">Recent Results</h2>
        <div class="card-body">
            <?php if (empty($recentResults)): ?>
                <div class="empty">No results yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Error</th>
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
</div>

<script>
function toggleCard(cardId) {
    document.getElementById(cardId).classList.toggle('collapsed');
}

function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}

function showAllSmtps() {
    document.querySelectorAll('.smtp-item').forEach(el => el.style.display = 'flex');
    document.querySelector('.show-more').style.display = 'none';
}

document.getElementById('smtp_file').addEventListener('change', function(e) {
    var fileName = e.target.files[0] ? e.target.files[0].name : '';
    document.getElementById('smtp-file-name').textContent = fileName ? 'Selected: ' + fileName : '';
});

document.getElementById('email_file').addEventListener('change', function(e) {
    var fileName = e.target.files[0] ? e.target.files[0].name : '';
    document.getElementById('email-file-name').textContent = fileName ? 'Selected: ' + fileName : '';
});
</script>
</body>
</html>
