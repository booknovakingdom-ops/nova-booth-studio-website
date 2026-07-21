<?php
/**
 * Nova Booth Studio — mail configuration TEMPLATE.
 *
 * HOW TO USE (Hostinger Premium shared hosting)
 * ---------------------------------------------
 * 1. In hPanel → Emails, create a mailbox for the domain, e.g.
 *        website@novaboothstudio.ca   (or bookings@novaboothstudio.ca)
 *    and note its password. This is the account the site sends FROM.
 *
 * 2. Copy this file to  nova-mail-config.php  and fill in the real values
 *    (SMTP host/user/pass). Find SMTP details in hPanel → Emails →
 *    Connect Devices / Configure. For Hostinger they are typically:
 *        host: smtp.hostinger.com   port: 465 (ssl)   or   587 (tls)
 *
 * 3. Upload  nova-mail-config.php  ONE LEVEL ABOVE public_html, i.e. to
 *        /home/USER/domains/novaboothstudio.ca/nova-mail-config.php
 *    so it is NEVER web-accessible. The handler looks there automatically.
 *    (If you cannot upload above public_html, you may place it in public_html;
 *     the bundled .htaccess blocks direct web access to it as a fallback.)
 *
 * 4. NEVER commit the filled-in nova-mail-config.php to git. Only this
 *    .sample file belongs in the repository. The real file is git-ignored.
 *
 * Alternative to a config file: set these as environment variables in
 * hPanel (NOVA_SMTP_HOST, NOVA_SMTP_PORT, NOVA_SMTP_SECURE, NOVA_SMTP_USER,
 * NOVA_SMTP_PASS, NOVA_MAIL_FROM, NOVA_MAIL_FROM_NAME, NOVA_MAIL_TO). Env
 * vars take precedence over this file.
 */

return [
    // Transport: 'smtp' for real delivery, 'log' only for local testing.
    'transport'   => 'smtp',

    // SMTP connection — the domain mailbox you created in hPanel.
    'smtp_host'   => 'smtp.hostinger.com',
    'smtp_port'   => 465,          // 465 with 'ssl', or 587 with 'tls'
    'smtp_secure' => 'ssl',        // 'ssl' | 'tls' | ''
    'smtp_auth'   => true,
    'smtp_user'   => 'website@novaboothstudio.ca',   // the mailbox address
    'smtp_pass'   => 'REPLACE_WITH_MAILBOX_PASSWORD', // set on the server only

    // Sender shown on the email (domain-based, NOT the customer's address).
    'from_email'  => 'website@novaboothstudio.ca',
    'from_name'   => 'Nova Booth Studio Website',

    // Business inbox that receives every enquiry.
    'to_email'    => 'novaboothstudio@gmail.com',
    'to_name'     => 'Nova Booth Studio',

    // Anti-spam tuning (optional — sensible defaults if omitted).
    'rate_max'    => 6,     // max submissions per window, per IP
    'rate_window' => 600,   // window in seconds (10 minutes)
    'min_seconds' => 3,     // reject submissions faster than a human could type
    'max_seconds' => 7200,  // reject stale form timestamps (2 hours)
];
