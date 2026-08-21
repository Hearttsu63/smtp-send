# SMTP Send - Email Sending System

A simple PHP email sending system using PHPMailer with queue processing via Cron.

## Features

- SMTP configuration via web panel
- **Multiple SMTP credentials** with rotation
- **Bulk upload** TXT file for SMTP credentials
- **Bulk upload** TXT file for email recipients
- SMTP connection test
- Email queue with status control (pending, processing, sent, failed)
- HTML support
- Email validation and deduplication
- Retry control (max 3 attempts)
- File-based locking to prevent concurrent workers
- Security via .htaccess
- **Collapsible sections** for better UX with many credentials

## Structure

```
smtp/
├── index.php          # Main panel
├── worker.php         # Queue processor (runs via Cron)
├── smtp-configs.json  # Multiple SMTP configs (auto-created)
├── queue.json         # Email queue (auto-created)
├── smtp-index.json    # Rotation index (auto-created)
├── .htaccess          # Security rules
├── .gitignore         # Git ignore rules
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
chmod 644 smtp-configs.json queue.json smtp-index.json
```

Or in cPanel File Manager:
1. Right-click each JSON file → Permissions → Set to `644`

### Step 3: Access the Panel

Open your browser and go to:
```
https://yourdomain.com/smtp/index.php
```

### Step 4: Configure SMTP

**Option A - Add Single:**
1. Click "Add Single" tab
2. Fill in SMTP details (Host, Port, Username, Password, etc.)
3. Click "Add SMTP"

**Option B - Bulk Upload:**
1. Click "Bulk Upload" tab
2. Create a TXT file with format: `smtpHost|port|username|password`
3. Upload the file
4. Click "Import SMTPs"

**Example TXT format:**
```
smtp.office365.com|587|user1@domain.com|pass123
smtp.gmail.com|587|user2@gmail.com|pass456
smtp.mail.yahoo.com|587|user3@yahoo.com|pass789
```

### Step 5: Test SMTP

1. Click "Test SMTP" tab
2. Select a configuration from the dropdown
3. Enter your test email
4. Click "Test SMTP"
5. Check your inbox

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
- **Rotates through all active SMTP credentials**

### Step 7: Send Emails

**Option A - Manual:**
1. Enter Subject and Message
2. Enter recipients (one per line, comma, or semicolon separated)
3. Click "Add to Queue"

**Option B - Upload TXT File:**
1. Enter Subject and Message
2. Click "Choose File" and select a `.txt` file
3. The file should contain one email per line (or comma/semicolon separated)
4. Click "Add to Queue"

---

## SMTP Rotation

When you have multiple SMTP configurations, the worker automatically rotates through them:

- Each email sent uses a different SMTP credential
- The rotation index is saved in `smtp-index.json`
- Continues from where it left off on the next run
- If an SMTP fails, it moves to the next one

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
| Too many SMTPs showing | Click section header to collapse |

---

## License

- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - LGPL v2.1
