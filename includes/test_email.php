<?php
require_once 'mail.php';

echo "Testing Node.js Email Connection\n";
echo "===============================\n\n";

// Test 1: Check if Node.js is healthy
echo "1. Checking Node.js health...\n";
$healthy = is_node_service_healthy();
if ($healthy) {
    echo "✅ Node.js is reachable!\n";
} else {
    echo "❌ Cannot reach Node.js. Check:\n";
    echo "   - Is Node.js running? (node server.js)\n";
    echo "   - Is port 5004 correct?\n";
    echo "   - Is API key correct in both .env files?\n";
    exit;
}

// Test 2: Send a test email
echo "\n2. Sending test email...\n";
$result = send_app_mail(
    'rohithkomatireddy051@gmail.com',  // CHANGE THIS TO YOUR EMAIL
    'Test from PHP via Node.js',
    '<h1>Success! 🎉</h1><p>Your PHP app is now sending emails through Node.js!</p><p>Time: ' . date('Y-m-d H:i:s') . '</p>',
    'PHP Tester'
);

if ($result) {
    echo "✅ Email sent successfully! Check your inbox.\n";
} else {
    echo "❌ Email failed to send. Check Node.js logs.\n";
}