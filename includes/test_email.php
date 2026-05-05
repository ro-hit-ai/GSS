<?php
require_once __DIR__ . '/mail.php';

$to = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($to === '') {
    echo "Usage: php includes/test_email.php recipient@example.com [transport]\n";
    echo "Example: php includes/test_email.php 256testingtest123@gmail.com smtp\n";
    exit(1);
}

$forcedTransport = isset($argv[2]) ? trim((string)$argv[2]) : '';
$options = [];
if ($forcedTransport !== '') {
    $options['transport'] = $forcedTransport;
}

$smtp = app_mail_smtp_config();
$order = app_mail_transport_order($options, $smtp);
$safeConfig = [
    'driver_env' => (string)(env_get('APP_MAIL_DRIVER', 'smtp') ?? 'smtp'),
    'transport_order' => $order,
    'smtp_host' => $smtp['host'],
    'smtp_port' => $smtp['port'],
    'smtp_secure' => $smtp['secure'] === '' ? 'none' : $smtp['secure'],
    'smtp_auth' => $smtp['smtp_auth'],
    'smtp_username' => $smtp['username'],
    'smtp_ready' => app_mail_smtp_is_ready($smtp)
];

echo "Testing App Mail\n";
echo "================\n";
echo json_encode($safeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$subject = 'SMTP transport test - ' . date('Y-m-d H:i:s');
$body = '<div style="font-family:Arial,sans-serif">'
    . '<h2>VATI GSS Mail Test</h2>'
    . '<p>This confirms send_app_mail() transport flow.</p>'
    . '<p>Time: ' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p>'
    . '</div>';

$ok = send_app_mail($to, $subject, $body, 'VATI GSS', $options);
echo $ok ? "SEND_RESULT=success\n" : "SEND_RESULT=failed\n";
exit($ok ? 0 : 2);
