<?php
/**
 * Nova Booth Studio — shared mail library.
 *
 * Secure helper used by form-handler.php. Contains NO credentials: all SMTP
 * settings are loaded at runtime from environment variables or a config file
 * kept outside the web root (see nova-mail-config.sample.php). This file is
 * safe to commit and safe to serve-block.
 */

declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Load mail configuration without ever hardcoding secrets in a committed file.
 *
 * Resolution order:
 *   1. Environment variables (NOVA_SMTP_HOST, …) — set in Hostinger hPanel.
 *   2. A PHP config file returning an array, located OUTSIDE public_html.
 * Returns the config array, or null if nothing usable is found.
 */
function nova_load_config(): ?array
{
    $defaults = [
        'transport'    => 'smtp',            // 'smtp' in production, 'log' for local testing
        'smtp_host'    => '',
        'smtp_port'    => 587,
        'smtp_secure'  => 'tls',             // 'tls' (587) or 'ssl' (465) or '' (none)
        'smtp_user'    => '',
        'smtp_pass'    => '',
        'smtp_auth'    => true,
        'from_email'   => 'website@novaboothstudio.ca',
        'from_name'    => 'Nova Booth Studio Website',
        'to_email'     => 'novaboothstudio@gmail.com',
        'to_name'      => 'Nova Booth Studio',
        'log_path'     => '',                // used only when transport = 'log'
        'rate_max'     => 6,                 // max submissions per window per IP
        'rate_window'  => 600,               // seconds
        'min_seconds'  => 3,                 // reject faster-than-human submissions
        'max_seconds'  => 7200,             // reject stale (2h) form timestamps
    ];

    // 1. Environment variables take precedence when the host defines them.
    $env = [
        'transport'   => getenv('NOVA_MAIL_TRANSPORT'),
        'smtp_host'   => getenv('NOVA_SMTP_HOST'),
        'smtp_port'   => getenv('NOVA_SMTP_PORT'),
        'smtp_secure' => getenv('NOVA_SMTP_SECURE'),
        'smtp_user'   => getenv('NOVA_SMTP_USER'),
        'smtp_pass'   => getenv('NOVA_SMTP_PASS'),
        'from_email'  => getenv('NOVA_MAIL_FROM'),
        'from_name'   => getenv('NOVA_MAIL_FROM_NAME'),
        'to_email'    => getenv('NOVA_MAIL_TO'),
        'log_path'    => getenv('NOVA_MAIL_LOG'),
    ];
    $env = array_filter($env, static fn ($v) => $v !== false && $v !== '');

    $fileConfig = [];
    foreach (nova_config_candidates() as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            $loaded = require $candidate;
            if (is_array($loaded)) {
                $fileConfig = $loaded;
                break;
            }
        }
    }

    // Merge: defaults < file < environment.
    $config = array_merge($defaults, $fileConfig, $env);

    // Normalise numeric/bool types (env values arrive as strings).
    $config['smtp_port']   = (int) $config['smtp_port'];
    $config['rate_max']    = (int) $config['rate_max'];
    $config['rate_window'] = (int) $config['rate_window'];
    $config['min_seconds'] = (int) $config['min_seconds'];
    $config['max_seconds'] = (int) $config['max_seconds'];
    $config['smtp_auth']   = filter_var($config['smtp_auth'], FILTER_VALIDATE_BOOLEAN);

    if ($config['transport'] === 'log') {
        if ($config['log_path'] === '') {
            $config['log_path'] = sys_get_temp_dir() . '/nova-mail.log';
        }
        return $config;
    }

    // For real delivery we need at least a host and a from/to address.
    if ($config['smtp_host'] === '' || $config['from_email'] === '' || $config['to_email'] === '') {
        return null;
    }

    return $config;
}

/**
 * Candidate paths for the external config file. Ordered most-specific first.
 * The real file lives OUTSIDE public_html so it can never be served.
 */
function nova_config_candidates(): array
{
    $paths = [];

    $explicit = getenv('NOVA_MAIL_CONFIG');
    if (is_string($explicit) && $explicit !== '') {
        $paths[] = $explicit;
    }

    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot !== '') {
        // One level above public_html — the recommended, non-web-accessible spot.
        $paths[] = dirname($docRoot) . '/nova-mail-config.php';
        $paths[] = $docRoot . '/../nova-mail-config.php';
    }

    // Fallbacks relative to this library (used mainly for local testing).
    $paths[] = dirname(__DIR__, 3) . '/nova-mail-config.php';
    $paths[] = dirname(__DIR__, 1) . '/nova-mail-config.php';

    return $paths;
}

/** Best-effort client IP for rate limiting. */
function nova_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

/**
 * Strict email validation. Rejects addresses containing CR/LF or other
 * characters that enable header injection.
 */
function nova_is_valid_email(string $email): bool
{
    if ($email === '' || strlen($email) > 254) {
        return false;
    }
    if (preg_match('/[\r\n\t]/', $email)) {
        return false;
    }
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Detect header-injection attempts in any single-line field. */
function nova_has_header_injection(string $value): bool
{
    if (preg_match('/[\r\n]/', $value)) {
        return true;
    }
    $lower = strtolower($value);
    foreach (['content-type:', 'bcc:', 'cc:', 'to:', 'mime-version:', 'content-transfer-encoding:'] as $needle) {
        if (strpos($lower, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Collapse whitespace and strip control characters from a submitted value.
 * $multiline preserves newlines (for the message textarea) but still strips
 * other control chars.
 */
function nova_clean(string $value, bool $multiline = false): string
{
    $value = str_replace(["\0"], '', $value);
    if ($multiline) {
        $value = preg_replace('/[^\P{C}\n]+/u', '', $value) ?? '';
        $value = preg_replace('/\r\n?/', "\n", $value) ?? '';
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? '';
    } else {
        $value = preg_replace('/[^\P{C}]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s{2,}/', ' ', $value) ?? '';
    }
    return trim($value);
}

/**
 * Simple file-based per-IP throttle. Returns true when the request is allowed.
 * Uses an atomic append log per IP in the system temp dir; failures here never
 * block a legitimate send (fails open).
 */
function nova_check_rate_limit(string $ip, array $config): bool
{
    $max    = max(1, (int) $config['rate_max']);
    $window = max(60, (int) $config['rate_window']);
    $dir    = sys_get_temp_dir() . '/nova-rate';

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // cannot track — fail open
    }

    $file = $dir . '/' . sha1($ip) . '.log';
    $now  = time();
    $recent = [];

    if (is_file($file)) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $ts = (int) $line;
            if ($ts > 0 && ($now - $ts) < $window) {
                $recent[] = $ts;
            }
        }
    }

    if (count($recent) >= $max) {
        return false;
    }

    $recent[] = $now;
    @file_put_contents($file, implode("\n", $recent) . "\n", LOCK_EX);
    return true;
}

/**
 * Send (or, in 'log' transport, record) the notification email.
 * Returns [bool $ok, string $errorForLog].
 */
function nova_send_mail(array $config, string $subject, string $replyToEmail, string $replyToName, string $bodyText, string $bodyHtml): array
{
    // Subjects are internal constants, but guard anyway.
    $subject = nova_clean($subject);

    if ($config['transport'] === 'log') {
        $record = "==== " . date('c') . " ====\n"
            . "To: {$config['to_email']}\n"
            . "From: {$config['from_name']} <{$config['from_email']}>\n"
            . "Reply-To: {$replyToName} <{$replyToEmail}>\n"
            . "Subject: {$subject}\n\n"
            . $bodyText . "\n\n";
        $written = @file_put_contents($config['log_path'], $record, FILE_APPEND | LOCK_EX);
        return $written !== false ? [true, ''] : [false, 'log write failed'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->Port       = $config['smtp_port'];
        $mail->SMTPAuth   = $config['smtp_auth'];
        if ($config['smtp_auth']) {
            $mail->Username = $config['smtp_user'];
            $mail->Password = $config['smtp_pass'];
        }
        if ($config['smtp_secure'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($config['smtp_secure'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 15;

        // Domain-based sender (never the customer's address).
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($config['to_email'], $config['to_name']);
        // Replies go straight to the customer.
        $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();
        return [true, ''];
    } catch (PHPMailerException $e) {
        return [false, $mail->ErrorInfo ?? $e->getMessage()];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/** Build a plain-text body from an ordered list of [label, value] rows. */
function nova_body_text(string $heading, array $rows): string
{
    $out = $heading . "\n" . str_repeat('=', strlen($heading)) . "\n\n";
    foreach ($rows as [$label, $value]) {
        if ($value === '') {
            continue;
        }
        $out .= $label . ":\n" . $value . "\n\n";
    }
    $out .= "----\nSent from the novaboothstudio.ca website.\n";
    return $out;
}

/** Build a readable HTML body from an ordered list of [label, value] rows. */
function nova_body_html(string $heading, array $rows): string
{
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $trs = '';
    foreach ($rows as [$label, $value]) {
        if ($value === '') {
            continue;
        }
        $valueHtml = nl2br($esc($value));
        $trs .= '<tr>'
            . '<td style="padding:8px 14px;border-bottom:1px solid #eee;font-weight:600;color:#333;vertical-align:top;white-space:nowrap;">' . $esc($label) . '</td>'
            . '<td style="padding:8px 14px;border-bottom:1px solid #eee;color:#111;">' . $valueHtml . '</td>'
            . '</tr>';
    }
    return '<!doctype html><html><body style="margin:0;background:#f6f6f4;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e5e2da;">'
        . '<tr><td style="background:#0c0c11;padding:20px 24px;">'
        . '<span style="color:#c9a468;font-size:12px;letter-spacing:2px;text-transform:uppercase;">Nova Booth Studio</span>'
        . '<div style="color:#efeae0;font-size:20px;font-weight:bold;margin-top:4px;">' . $esc($heading) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 10px;"><table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">'
        . $trs
        . '</table></td></tr>'
        . '<tr><td style="padding:14px 24px;background:#faf8f3;color:#777;font-size:12px;">Sent from the novaboothstudio.ca website. Reply directly to this email to reach the customer.</td></tr>'
        . '</table></body></html>';
}
