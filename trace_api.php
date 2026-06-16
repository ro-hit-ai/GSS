<?php
session_start();

// Simulate candidate session
$_SESSION['user_type'] = 'candidate';
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Test Candidate';
$_SESSION['case_id'] = 229;
$_SESSION['application_id'] = 'APP-20260612103142613';

$_GET['debug'] = '1';

require __DIR__ . '/api/candidate/case_verification_config.php';
