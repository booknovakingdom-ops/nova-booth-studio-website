<?php
/**
 * Nova Booth Studio — form submission endpoint.
 *
 * Handles the Check Availability / Get a Quote form for the Mirror Photo Booth
 * and 360 Video Booth. Returns JSON. Contains no secrets — SMTP settings come
 * from the environment or an out-of-web-root config file (see assets/php/mailer.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/assets/php/mailer.php';

/** Emit a JSON response and stop. */
function nova_respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$FALLBACK = "We couldn't send your request just now. Please email novaboothstudio@gmail.com or call or text 902-990-0005 and we'll take care of it.";

// --- Method guard --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nova_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$config = nova_load_config();
if ($config === null) {
    // Misconfigured server (no SMTP settings). Never claim success.
    nova_respond(500, ['ok' => false, 'error' => $FALLBACK]);
}

// --- Read input ----------------------------------------------------------
$post = $_POST;
if (empty($post)) {
    // Support JSON bodies as well as form-encoded / FormData.
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $post = $decoded;
    }
}

$get = static function (string $key) use ($post): string {
    $v = $post[$key] ?? '';
    return is_string($v) ? $v : '';
};

$formType = $get('form_type');
if ($formType !== 'quote') {
    nova_respond(400, ['ok' => false, 'error' => 'Unknown form.']);
}

// --- Honeypot: real users never fill this hidden field -------------------
if (trim($get('website')) !== '' || trim($get('company_url')) !== '') {
    // Silently accept without sending so bots don't retry.
    nova_respond(200, ['ok' => true, 'skipped' => true]);
}

// --- Timestamp / timing check --------------------------------------------
$formTime = (int) $get('form_time'); // milliseconds since epoch, set by JS on load
$nowMs = (int) round(microtime(true) * 1000);
if ($formTime > 0) {
    $elapsed = ($nowMs - $formTime) / 1000;
    if ($elapsed < $config['min_seconds'] || $elapsed > $config['max_seconds']) {
        nova_respond(422, ['ok' => false, 'error' => $FALLBACK]);
    }
}

// --- Rate limiting -------------------------------------------------------
if (!nova_check_rate_limit(nova_client_ip(), $config)) {
    nova_respond(429, ['ok' => false, 'error' => "You've sent a few requests already. Please email novaboothstudio@gmail.com or call 902-990-0005 and we'll help right away."]);
}

// --- Field specs ---------------------------------------------------------
// Each entry: key => [label, required, multiline]
$QUOTE_FIELDS = [
    'name'           => ['Name', true, false],
    'email'          => ['Email', true, false],
    'phone'          => ['Phone', true, false],
    'event-date'     => ['Event date', true, false],
    'start-time'     => ['Start time', false, false],
    'event-type'     => ['Event type', true, false],
    'venue'          => ['Venue / city', true, false],
    'setting'        => ['Indoor or outdoor', false, false],
    'guests'         => ['Expected guests', false, false],
    'experience'     => ['Experience requested', true, false],
    'duration'       => ['Duration', false, false],
    'prints'         => ['Prints required', false, false],
    'branding'       => ['Backdrop / branding interest', false, false],
    'message'        => ['Message', false, true],
    'contact-method' => ['Preferred contact method', false, false],
];

$spec    = $QUOTE_FIELDS;
$heading = 'New Quote Request';
$subject = 'New Nova Booth Studio Quote Request';

// --- Validate + sanitise every field server-side -------------------------
$errors = [];
$rows   = [];
$clean  = [];

foreach ($spec as $key => [$label, $required, $multiline]) {
    $value = nova_clean($get($key), $multiline);

    if ($value === '') {
        if ($required) {
            $errors[$key] = 'This field is required.';
        }
        $clean[$key] = '';
        $rows[] = [$label, ''];
        continue;
    }

    if ($key === 'email') {
        if (!nova_is_valid_email($value)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
    } elseif (!$multiline && nova_has_header_injection($value)) {
        // Suspicious content in a single-line field.
        $errors[$key] = 'This field contains invalid characters.';
    }

    // Length guards.
    $limit = $multiline ? 4000 : 300;
    if (mb_strlen($value) > $limit) {
        $value = mb_substr($value, 0, $limit);
    }

    $clean[$key] = $value;
    $rows[] = [$label, $value];
}

// Phone: require a plausible number of digits.
if (!isset($errors['phone'])) {
    $digits = preg_replace('/\D/', '', $clean['phone'] ?? '');
    if (strlen((string) $digits) < 10 || strlen((string) $digits) > 15) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }
}

// Consent is a checkbox — required for both forms.
$consent = trim($get('consent'));
if ($consent === '' || $consent === '0' || strtolower($consent) === 'false') {
    $errors['consent'] = 'Please confirm you are happy to be contacted.';
} else {
    $rows[] = ['Consent', 'Agreed to be contacted'];
}

if (!empty($errors)) {
    nova_respond(422, [
        'ok'     => false,
        'error'  => 'Some details need attention.',
        'fields' => $errors,
    ]);
}

// --- Compose + send ------------------------------------------------------
$replyToEmail = $clean['email'];
$replyToName  = $clean['name'];

// Append submission metadata for the business's records.
$meta = [];
$ipForLog = nova_client_ip();
$meta[] = ['Submitted', date('D, j M Y, g:i a T')];
if ($ipForLog !== '0.0.0.0') {
    $meta[] = ['Source IP', $ipForLog];
}
$allRows = array_merge($rows, $meta);

$bodyText = nova_body_text($heading, $allRows);
$bodyHtml = nova_body_html($heading, $allRows);

[$ok, $err] = nova_send_mail($config, $subject, $replyToEmail, $replyToName, $bodyText, $bodyHtml);

if (!$ok) {
    error_log('[nova-form] send failed: ' . $err);
    nova_respond(502, ['ok' => false, 'error' => $FALLBACK]);
}

nova_respond(200, [
    'ok'      => true,
    'message' => 'Your request is on its way — we\'ll be in touch soon.',
]);
