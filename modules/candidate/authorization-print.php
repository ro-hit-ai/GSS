<?php
// modules/candidate/authorization-print.php
session_start();
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = $_SESSION['application_id'] ?? '';
$user_name = $_SESSION['user_name'] ?? 'Candidate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authorization Form - ATTEST360</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/candidate.css')); ?>">
</head>
<body class="candidate-auth-print" onload="window.print();">

    <div class="container">
        <h3 class="mb-3 text-center">Release and Authorization Form</h3>

        <p><strong>Application ID:</strong> <?= htmlspecialchars($application_id) ?></p>
        <p><strong>Candidate Name:</strong> <?= htmlspecialchars($user_name) ?></p>

        <div class="auth-box mb-4">
            <p>
                The purpose of this form is to notify you that a consumer report may be prepared on you in
                the course of consideration of employment with the Company.
            </p>
            <p>
                Past employers, education institutions, law enforcement agencies and other entities may be
                contacted in order to obtain information for positive identification when checking public records.
                This information is confidential and will not be used for any other purposes.
            </p>
            <p>
                By signing this form, I hereby authorize all corporations, former employers, credit agencies,
                educational institutions, law enforcement agencies, city, state, county and federal courts and
                military services to release information about my background including but not limited to my
                employment, education, credit history, driving records, criminal records and general public
                records to the Company or any agency or individual engaged by the Company to conduct such
                verification.
            </p>
            <p>
                I understand that this authorization is continuing in nature and may be used at any time during
                my employment or engagement, subject to applicable law. I release the Company and all providers
                of information from any liability whatsoever arising out of the collection and dissemination of
                this information.
            </p>
            <p class="mb-0">
                I certify that I have read and understood this Release and Authorization Form and that the
                information provided by me in my application is true and correct to the best of my knowledge.
            </p>
        </div>

        <div class="row mt-4">
            <div class="col-6">
                <p><strong>Signature: ____________________________</strong></p>
            </div>
            <div class="col-6">
                <p><strong>Date: ____________________________</strong></p>
            </div>
        </div>

        <p class="mt-3" style="font-size:12px;">
            <strong>Note:</strong> Kindly print this form and sign above before sending the scanned copy
            back to the verification team.
        </p>
    </div>
</body>
</html>
