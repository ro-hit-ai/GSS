<?php
$base = realpath(__DIR__ . '/../api/shared') . '/';

// shim -> canonical subdir mapping
$map = [
    'verification_docs_upload.php'        => 'services/verification_docs_upload.php',
    'candidate_access_resend.php'         => 'services/candidate_access_resend.php',
    'candidate_access_resend_status.php'  => 'services/candidate_access_resend_status.php',
    'candidate_report_version.php'        => 'reports/candidate_report_version.php',
    'correction_eligible_components.php'  => 'corrections/correction_eligible_components.php',
    'correction_history.php'              => 'corrections/correction_history.php',
    'correction_session_create.php'       => 'corrections/correction_session_create.php',
    'mail_templates_resolve.php'          => 'mail/mail_templates_resolve.php',
];

foreach ($map as $shim => $canon) {
    $shimExists  = file_exists($base . $shim)  ? 'EXISTS' : 'MISSING';
    $canonExists = file_exists($base . $canon) ? 'EXISTS' : 'MISSING';
    printf("%-48s  shim=%-7s  canon=%-7s  %s\n", $shim, $shimExists, $canonExists, $canon);
}
