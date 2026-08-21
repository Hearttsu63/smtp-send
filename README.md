# SMTP Send - Email Sending System

A simple PHP email sending system using PHPMailer with queue processing via Cron.

## Features

- SMTP configuration via web panel
- SMTP connection test
- Email queue with status control (pending, processing, sent, failed)
- HTML support
- **Upload TXT file with email list**
- Email validation and deduplication
- Retry control (max 3 attempts)
- File-based locking to prevent concurrent workers
- Security via .htaccess

## Structure

```
smtp/
├── index.php          # Main panel
├── worker.php         # Queue processor (runs via Cron)
├── smtp.json          # SMTP config (auto-created)
├── queue.json         # Email queue (auto-created)
├── .htaccess          # Security rules
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

---

## Step-by-Step Installation

### Step 1: Upload Files

Upload the entire `smtp` folder to your hosting via:
- **File Manager** (cPanel, Plesk, etc.)
- **FTP** (FileZilla, WinSCP, etc.)
- **SSH** (if available)

Example path: `/public_html/smtp/` or `/home/user/domain.com/smtp/`

### Step 2: Set File Permissions

Set write permissions for JSON files. In File Manager or via SSH:

```bash
chmod 644 smtp.json queue.json
```

Or in cPanel File Manager:
1. Right-click `smtp.json` → Permissions → Set to `644`
2. Right-click `queue.json` → Permissions → Set to `644`

### Step 3: Access the Panel

Open your browser and go to:
```
https://yourdomain.com/smtp/index.php
```

### Step 4: Configure SMTP

Fill in the SMTP fields:

| Field | Example (Gmail) | Example (Yahoo) |
|-------|-----------------|-----------------|
| SMTP Host | smtp.gmail.com | smtp.mail.yahoo.com |
| Port | 587 | 587 |
| Encryption | TLS | TLS |
| Username | your@gmail.com | your@yahoo.com |
| Password | App Password | App Password |
| Sender Email | your@gmail.com | your@yahoo.com |
| Sender Name | Your Name | Your Name |

Click **"Save Configuration"**.

### Step 5: Test SMTP

1. Enter your email in the "Test Email" field
2. Click **"Test SMTP"**
3. Check your inbox for the test email

### Step 6: Set Up Cron Job

In cPanel:
1. Go to **Advanced** → **Cron Jobs**
2. Add a new cron job with this command:

```
* * * * * /usr/bin/php /home/username/public_html/smtp/worker.php
```

> Replace `/usr/bin/php` with your PHP path (run `which php` via SSH)
> Replace `/home/username/public_html/smtp/` with your actual path

**How it works:**
- Runs every minute
- Processes up to 10 emails per execution
- 2-second delay between each email
- Max 3 retry attempts per email

### Step 7: Send Emails

**Option A - Manual:**
1. Enter Subject and Message
2. Enter recipients (one per line, comma, or semicolon separated)
3. Click **"Add to Queue"**

**Option B - Upload TXT File:**
1. Enter Subject and Message
2. Click **"Choose File"** and select a `.txt` file
3. The file should contain one email per line (or comma/semicolon separated)
4. Click **"Add to Queue"**

---

## Gmail Setup (App Password)

1. Go to https://myaccount.google.com/security
2. Enable **2-Step Verification**
3. Go to https://myaccount.google.com/apppasswords
4. Generate a new app password
5. Use that password in the SMTP config

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| 535 Authentication Failed | Check username/password, use App Password |
| Connection Timed Out | Check host/port, try port 465 with SSL |
| Permission Denied | Set chmod 644 on JSON files |
| Emails not sending | Check Cron job is running |
| Worker not processing | Check PHP path in Cron command |

---

## License

- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - LGPL v2.1
