<?php

function gss_admin_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'Clients', 'href' => 'clients_list.php'],

        // [
        //     'label' => 'Customers',
        //     'key' => 'customers',
        //     'children' => [
        //         ['label' => 'Clients List', 'href' => 'clients_list.php'],
        //         ['label' => 'Create Client', 'href' => 'clients_create.php'],
        //     ]
        // ],
        [
            'label' => 'Users',
            'key' => 'users',
            'children' => [
                ['label' => 'GSS Users', 'href' => 'users_list.php?view=staff'],
                ['label' => 'Client Users', 'href' => 'users_list.php?view=client'],
                // ['label' => 'Create Client User', 'href' => 'user_create.php'],
                // ['label' => 'Create GSS User', 'href' => 'staff_user_create.php'],
            ]
        ],
        [
            'label' => 'Candidate',
            'key' => 'candidate',
            'children' => [
                ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
                // ['label' => 'Final Report', 'href' => 'candidate_report.php'],
                ['label' => 'Create Candidate', 'href' => 'candidate_create.php'],
                ['label' => 'Bulk Upload', 'href' => 'candidate_bulk.php'],
            ]
        ],
        [
            'label' => 'Tools',
            'key' => 'tools',
            'children' => [
                ['label' => 'Mail Templates', 'href' => 'mail_templates_list.php'],
                ['label' => 'Holiday Calendar', 'href' => 'holiday_calendar.php'],
            ]
        ],
        // ['label' => 'Profile Settings', 'href' => '../settings/profile.php'],
        ['label' => 'Report', 'href' => 'candidate_report.php'],
    ];
}

function client_admin_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
          [
            'label' => 'Candidate',
            'key' => 'candidate',
            'children' => [
                ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
                ['label' => 'Create Candidate', 'href' => 'candidate_create.php'],
                ['label' => 'Bulk Upload', 'href' => 'candidate_bulk.php'],
            ]
        ],
            ['label' => 'Users List', 'href' => 'users_list.php'],
        // [
        //     'label' => 'List',
        //     'key' => 'list',
        //     'children' => [
        //         ['label' => 'Users List', 'href' => 'users_list.php'],
        //         ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
        //     ]
        // ],
      
        // ['label' => 'Overall Report', 'href' => 'overall_report.php'],
        // ['label' => 'Reports / Billing', 'href' => 'reports_billing.php'],
    ];
}

function db_verifier_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
    ];
}

function verifier_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
    ];
}

function team_lead_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'QA Dashboard', 'href' => '../qa/dashboard.php'],
        ['label' => 'QA Review List', 'href' => '../qa/review_list.php'],
    ];
}

 function validator_menu(): array {
     return [
         ['label' => 'Dashboard', 'href' => 'dashboard.php'],
         ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
     ];
 }

function qa_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'Review List', 'href' => 'review_list.php'],
        ['label' => 'Case Review', 'href' => 'case_review.php'],
        ['label' => 'Reports Tracking', 'href' => 'reports_tracking.php'],
    ];
}

function hr_recruiter_menu(): array {
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        [
            'label' => 'Candidate',
            'key' => 'candidate',
            'children' => [
                ['label' => 'Candidate List', 'href' => 'candidates_list.php'],
                ['label' => 'Create Applicant', 'href' => 'candidate_create.php'],
                ['label' => 'Bulk Upload', 'href' => 'candidate_bulk.php'],
            ]
        ],
    ];
}
