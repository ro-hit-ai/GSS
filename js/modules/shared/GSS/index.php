<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/audit_log.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function norm_role(string $r): string {
    $r = strtolower(trim($r));
    if ($r === 'customer_admin') $r = 'client_admin';
    return $r;
}

function session_access_list(): array {
    $raw = '';
    if (!empty($_SESSION['auth_all_moduleAccess'])) {
        $raw = (string)$_SESSION['auth_all_moduleAccess'];
    } elseif (!empty($_SESSION['auth_moduleAccess'])) {
        $raw = (string)$_SESSION['auth_moduleAccess'];
    }
    $raw = strtolower(trim($raw));
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = norm_role((string)$p);
        if ($p !== '') $out[$p] = true;
    }
    return $out;
}

function can_switch_to(string $role): bool {
    if (empty($_SESSION['auth_user_id'])) return false;
    $role = norm_role($role);
    if ($role === '') return false;
    $set = session_access_list();
    return isset($set[$role]);
}

$requestedRole = isset($_GET['role']) ? norm_role((string)$_GET['role']) : '';
$switchMode = isset($_GET['switch']) && (string)$_GET['switch'] === '1';

// If explicit role requested, switch active role and redirect
if ($requestedRole !== '') {
    if (!can_switch_to($requestedRole)) {
        audit_log_event('login', 'role_switch', 'failed', [
            'requested_role' => $requestedRole,
            'reason' => 'not_allowed'
        ], !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null, null, null);
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $prev = isset($_SESSION['auth_moduleAccess']) ? norm_role((string)$_SESSION['auth_moduleAccess']) : '';
    $_SESSION['auth_moduleAccess'] = $requestedRole;
    audit_log_event('login', 'role_switch', 'success', [
        'from_role' => $prev,
        'to_role' => $requestedRole
    ], !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null, $requestedRole, !empty($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : null);
}

$access = isset($_SESSION['auth_moduleAccess']) ? norm_role((string)$_SESSION['auth_moduleAccess']) : '';

if ($switchMode) {
    if (empty($_SESSION['auth_user_id'])) {
        header('Location: ' . app_url('/login.php'));
        exit;
    }

    $roles = session_access_list();
    $labels = [
        'gss_admin' => 'GSS Admin',
        'client_admin' => 'Client Admin',
        'company_recruiter' => 'HR Recruiter',
        'team_lead' => 'Team Lead',
        'validator' => 'Validator',
        'verifier' => 'Component Verifier',
        'db_verifier' => 'DB Verifier',
        'qa' => 'QA'
    ];

    ?><!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Switch Role - VATI GSS</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    </head>
    <body style="background:#0b1220;">
    <div class="container" style="max-width:720px; padding:28px 16px;">
        <div class="card" style="border-radius:16px; border:1px solid rgba(148,163,184,0.25); background:#0f172a; color:#e5e7eb;">
            <div class="card-body" style="padding:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <h5 style="margin:0; font-weight:900;">Switch Role</h5>
                    <a class="btn btn-sm btn-outline-light" href="<?php echo htmlspecialchars(app_url('/logout.php')); ?>" style="border-radius:999px;">Logout</a>
                </div>
                <div style="margin-top:6px; color:#94a3b8; font-size:13px;">Choose the workspace you want to open.</div>

                <div class="list-group" style="margin-top:14px;">
                    <?php
                    $order = ['gss_admin','client_admin','company_recruiter','team_lead','validator','verifier','db_verifier','qa'];
                    foreach ($order as $r) {
                        if (!isset($roles[$r])) continue;
                        $href = app_url('/index.php?role=' . rawurlencode($r));
                        $text = $labels[$r] ?? strtoupper($r);
                        $active = ($access === $r);
                        ?>
                        <a class="list-group-item list-group-item-action" href="<?php echo htmlspecialchars($href); ?>" style="border-radius:12px; margin-bottom:8px; border:1px solid rgba(148,163,184,0.18); background:<?php echo $active ? '#1d4ed8' : 'rgba(2,6,23,0.55)'; ?>; color:#e5e7eb;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                                <div style="font-weight:900;"><?php echo htmlspecialchars($text); ?></div>
                                <?php if ($active): ?><span class="badge bg-light text-dark">Active</span><?php endif; ?>
                            </div>
                        </a>
                        <?php
                    }
                    ?>
                </div>

                <div style="margin-top:8px; color:#94a3b8; font-size:12px;">If you don’t see a role here, it’s not assigned to your user.</div>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($access !== '') {
    $defaultMap = [
        'gss_admin' => '/modules/gss_admin/dashboard.php',
        'client_admin' => '/modules/client_admin/dashboard.php',
        'company_recruiter' => '/modules/hr_recruiter/dashboard.php',
        'team_lead' => '/modules/team_lead/dashboard.php',
        'verifier' => '/modules/verifier/dashboard.php',
        'db_verifier' => '/modules/db_verifier/candidates_list.php',
        'validator' => '/modules/validator/dashboard.php',
        'qa' => '/modules/qa/review_list.php'
    ];

    $target = isset($defaultMap[$access]) ? $defaultMap[$access] : '';
    if ($target !== '') {
        header('Location: ' . $target);
        exit;
    }
}

header('Location: ' . app_url('/login.php'));
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VATI GSS - Modules</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root{--bg:#0b1220;--panel:#0f172a;--card:#0b1220;--stroke:rgba(148,163,184,0.18);--text:#e5e7eb;--muted:#94a3b8;--brand:#60a5fa;}
        body{background:radial-gradient(1200px 600px at 20% 10%, rgba(96,165,250,0.18), transparent 60%),radial-gradient(900px 500px at 80% 20%, rgba(34,197,94,0.10), transparent 55%),var(--bg); color:var(--text);}
        .ml-shell{min-height:100vh; display:grid; grid-template-columns:280px 1fr;}
        .ml-sidebar{background:linear-gradient(180deg, rgba(15,23,42,0.92), rgba(2,6,23,0.92)); border-right:1px solid var(--stroke); padding:18px 14px; position:sticky; top:0; height:100vh;}
        .ml-main{padding:22px 22px 26px;}
        .ml-brand{display:flex; align-items:center; gap:10px; padding:10px 10px 14px; border-bottom:1px solid var(--stroke); margin-bottom:14px;}
        .ml-mark{width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg, rgba(96,165,250,0.35), rgba(255,255,255,0.06)); border:1px solid var(--stroke); display:flex; align-items:center; justify-content:center; font-weight:800; color:#bfdbfe;}
        .ml-brand h1{font-size:14px; margin:0; letter-spacing:.06em; text-transform:uppercase;}
        .ml-brand p{margin:0; color:var(--muted); font-size:12px;}
        .ml-nav{display:flex; flex-direction:column; gap:6px; padding:8px 6px;}
        .ml-nav a{display:flex; align-items:center; justify-content:space-between; gap:10px; text-decoration:none; color:var(--text); padding:10px 10px; border-radius:12px; border:1px solid transparent; background:transparent; transition:background .18s ease, border-color .18s ease, transform .15s ease;}
        .ml-nav a:hover{background:rgba(96,165,250,0.10); border-color:rgba(96,165,250,0.20); transform:translateX(2px);}
        .ml-pill{font-size:11px; color:#0b1220; background:#bfdbfe; border-radius:999px; padding:2px 8px; font-weight:700;}
        .ml-topbar{display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:16px;}
        .ml-title h2{margin:0; font-size:22px; font-weight:800; letter-spacing:-.02em;}
        .ml-title .sub{margin-top:4px; color:var(--muted); font-size:13px;}
        .ml-actions{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
        .ml-search{min-width:260px; border-radius:12px; border:1px solid var(--stroke); background:rgba(2,6,23,0.55); color:var(--text); padding:10px 12px; font-size:13px; outline:none;}
        .ml-search::placeholder{color:rgba(148,163,184,0.85);}
        .ml-toggle{border-radius:12px; border:1px solid var(--stroke); background:rgba(2,6,23,0.55); color:var(--text); padding:10px 12px; font-size:13px;}
        .ml-grid{display:grid; grid-template-columns:repeat(12, 1fr); gap:14px;}
        .ml-card{grid-column:span 4; background:linear-gradient(180deg, rgba(15,23,42,0.72), rgba(2,6,23,0.72)); border:1px solid var(--stroke); border-radius:16px; box-shadow:0 18px 40px rgba(2,6,23,0.55); overflow:hidden;}
        .ml-card-head{padding:14px 14px 10px; display:flex; align-items:center; justify-content:space-between; gap:10px;}
        .ml-card-title{display:flex; align-items:center; gap:10px;}
        .ml-ico{width:34px; height:34px; border-radius:12px; background:rgba(96,165,250,0.18); border:1px solid rgba(96,165,250,0.25); display:flex; align-items:center; justify-content:center; color:#bfdbfe; font-weight:900;}
        .ml-card h3{margin:0; font-size:14px; font-weight:800;}
        .ml-card p{margin:6px 0 0; color:var(--muted); font-size:12px;}
        .ml-card-body{padding:0 14px 14px;}
        .ml-links{display:grid; gap:8px; margin-top:10px;}
        .ml-links a{display:flex; align-items:center; justify-content:space-between; gap:10px; text-decoration:none; color:var(--text); border:1px solid var(--stroke); background:rgba(2,6,23,0.55); padding:10px 12px; border-radius:12px; font-size:13px;}
        .ml-links a:hover{border-color:rgba(96,165,250,0.35); background:rgba(96,165,250,0.10);}
        .ml-kbd{font-size:11px; color:rgba(148,163,184,0.95); border:1px solid var(--stroke); border-radius:8px; padding:2px 6px;}
        .ml-foot{margin-top:14px; color:rgba(148,163,184,0.92); font-size:12px;}
        .ml-sidebar-collapsed .ml-shell{grid-template-columns:78px 1fr;}
        .ml-sidebar-collapsed .ml-sidebar{padding:18px 10px;}
        .ml-sidebar-collapsed .ml-brand h1,.ml-sidebar-collapsed .ml-brand p,.ml-sidebar-collapsed .ml-nav span{display:none;}
        .ml-sidebar-collapsed .ml-mark{border-radius:14px; width:44px; height:44px;}
        .ml-sidebar-collapsed .ml-nav a{justify-content:center;}
        @media (max-width: 992px){
            .ml-shell{grid-template-columns:1fr;}
            .ml-sidebar{position:relative; height:auto;}
            .ml-card{grid-column:span 12;}
            .ml-actions{width:100%;}
            .ml-search{flex:1; min-width:0; width:100%;}
        }
        @media (max-width: 1200px){
            .ml-card{grid-column:span 6;}
        }
    </style>
</head>
<body>

<script>
    (function () {
        try {
            var stored = window.localStorage ? window.localStorage.getItem('gss_launcher_sidebar') : null;
            if (stored === '1') {
                document.body.classList.add('ml-sidebar-collapsed');
            }
        } catch (e) {
        }
    })();
</script>
<!-- 
<div class="ml-shell">
    <aside class="ml-sidebar">
        <div class="ml-brand">
            <div class="ml-mark">VT</div>
            <div>
                <h1>VATI GSS</h1>
                <p>Module Launcher</p>
            </div>
        </div>

        <div class="d-grid gap-2 px-2" style="margin-bottom:10px;">
            <button type="button" class="ml-toggle" id="launcherToggle">Toggle Sidebar</button>
        </div>

        <nav class="ml-nav" id="launcherNav">
            <a href="#gss_admin"><span>GSS Admin</span><span class="ml-pill">Admin</span></a>
            <a href="#client_admin"><span>Client Admin</span><span class="ml-pill">Client</span></a>
            <a href="#db_verifier"><span>DB Verifier</span><span class="ml-pill">DB</span></a>
            <a href="#verifier"><span>Verifier</span><span class="ml-pill">Ops</span></a>
            <a href="#qa"><span>QA</span><span class="ml-pill">QA</span></a>
            <a href="#candidate"><span>Candidate Portal</span><span class="ml-pill">Portal</span></a>
            <a href="#reports"><span>Reports / Settings</span><span class="ml-pill">Tools</span></a>
        </nav>

        <div class="ml-foot" style="padding:10px 10px 0;">
            Authentication is disabled for now.
        </div>
    </aside>

    <main class="ml-main">
        <div class="ml-topbar">
            <div class="ml-title">
                <h2>Launch your workspace</h2>
                <div class="sub">Quick access to modules with a clean sidebar navigation.</div>
            </div>
            <div class="ml-actions">
                <input class="ml-search" id="launcherSearch" type="text" placeholder="Search modules (e.g. candidate, users, qa)..." />
            </div>
        </div>

        <div class="ml-grid" id="launcherCards">
            <section class="ml-card" id="gss_admin" data-keywords="gss admin client users locations verification profile candidates">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico">GA</div>
                        <div>
                            <h3>GSS Admin</h3>
                            <p>Setup clients, profiles, users and manage all candidates.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#gss_admin</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/gss_admin/dashboard.php')); ?>"><span>Dashboard</span><span class="ml-kbd">D</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/gss_admin/clients_list.php')); ?>"><span>Clients List</span><span class="ml-kbd">C</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/gss_admin/users_list.php')); ?>"><span>Users List</span><span class="ml-kbd">U</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/gss_admin/candidates_list.php')); ?>"><span>Candidate List</span><span class="ml-kbd">L</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/gss_admin/verification_profiles_list.php')); ?>"><span>Verification Profiles</span><span class="ml-kbd">V</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="client_admin" data-keywords="client admin candidate create bulk users reports">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(34,197,94,0.16); border-color:rgba(34,197,94,0.22); color:#bbf7d0;">CA</div>
                        <div>
                            <h3>Client Admin</h3>
                            <p>Create applicants, upload bulk data and monitor reports.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#client_admin</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/client_admin/dashboard.php')); ?>"><span>Dashboard</span><span class="ml-kbd">D</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/client_admin/candidates_list.php')); ?>"><span>Candidate List</span><span class="ml-kbd">L</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/client_admin/candidate_create.php')); ?>"><span>Create Applicant</span><span class="ml-kbd">N</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/client_admin/candidate_bulk.php')); ?>"><span>Bulk Upload</span><span class="ml-kbd">B</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="db_verifier" data-keywords="db verifier database check ecourt driver license queue candidates">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(251,191,36,0.16); border-color:rgba(251,191,36,0.24); color:#fde68a;">DB</div>
                        <div>
                            <h3>DB Verifier</h3>
                            <p>Database-level checks and verification queue.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#db_verifier</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/db_verifier/dashboard.php')); ?>"><span>Dashboard</span><span class="ml-kbd">D</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/db_verifier/candidates_list.php')); ?>"><span>Candidate List</span><span class="ml-kbd">L</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="verifier" data-keywords="verifier component address education employment">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(56,189,248,0.16); border-color:rgba(56,189,248,0.24); color:#bae6fd;">VR</div>
                        <div>
                            <h3>Component Verifier</h3>
                            <p>Manual component checks (address, employment, education).</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#verifier</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/verifier/dashboard.php')); ?>"><span>Dashboard</span><span class="ml-kbd">D</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/verifier/candidates_list.php')); ?>"><span>Candidate List</span><span class="ml-kbd">L</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="qa" data-keywords="qa review approve rework">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(248,113,113,0.16); border-color:rgba(248,113,113,0.22); color:#fecaca;">QA</div>
                        <div>
                            <h3>QA</h3>
                            <p>Review completed verifications and approve / send back.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#qa</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/qa/dashboard.php')); ?>"><span>Dashboard</span><span class="ml-kbd">D</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/qa/review_list.php')); ?>"><span>Review List</span><span class="ml-kbd">R</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="candidate" data-keywords="candidate portal login form">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(148,163,184,0.16); border-color:rgba(148,163,184,0.22); color:#e2e8f0;">CP</div>
                        <div>
                            <h3>Candidate Portal</h3>
                            <p>Candidate login and form submission.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#candidate</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/candidate/index.php')); ?>"><span>Candidate Index</span><span class="ml-kbd">I</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/candidate/login.php')); ?>"><span>Candidate Login</span><span class="ml-kbd">L</span></a>
                    </div>
                </div>
            </section>

            <section class="ml-card" id="reports" data-keywords="reports settings profile">
                <div class="ml-card-head">
                    <div class="ml-card-title">
                        <div class="ml-ico" style="background:rgba(100,116,139,0.18); border-color:rgba(100,116,139,0.26); color:#e2e8f0;">RS</div>
                        <div>
                            <h3>Reports / Settings</h3>
                            <p>Reports dashboard and profile settings.</p>
                        </div>
                    </div>
                    <span class="ml-kbd">#reports</span>
                </div>
                <div class="ml-card-body">
                    <div class="ml-links">
                        <a href="<?php echo htmlspecialchars(app_url('/modules/reports/dashboard.php')); ?>"><span>Reports Dashboard</span><span class="ml-kbd">R</span></a>
                        <a href="<?php echo htmlspecialchars(app_url('/modules/settings/profile.php')); ?>"><span>Profile Settings</span><span class="ml-kbd">P</span></a>
                    </div>
                </div>
            </section>
        </div>

        <div class="ml-foot">
            Note: This is a temporary page for navigation. Authentication flow will be re-enabled later.
        </div>
    </main>
</div> -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<script>
    (function () {
        var toggle = document.getElementById('launcherToggle');
        var search = document.getElementById('launcherSearch');
        var cardsHost = document.getElementById('launcherCards');

        function setCollapsed(val) {
            document.body.classList.toggle('ml-sidebar-collapsed', !!val);
            try {
                if (window.localStorage) {
                    window.localStorage.setItem('gss_launcher_sidebar', val ? '1' : '0');
                }
            } catch (e) {
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                setCollapsed(!document.body.classList.contains('ml-sidebar-collapsed'));
            });
        }

        function normalize(s) {
            return String(s || '').toLowerCase();
        }

        function filterCards() {
            if (!cardsHost) return;
            var q = normalize(search ? search.value : '');
            var cards = cardsHost.querySelectorAll('.ml-card');
            cards.forEach(function (card) {
                var k = normalize(card.getAttribute('data-keywords') || '');
                var id = normalize(card.id || '');
                var match = !q || k.indexOf(q) >= 0 || id.indexOf(q) >= 0;
                card.style.display = match ? '' : 'none';
            });
        }

        if (search) {
            search.addEventListener('input', function () {
                filterCards();
            });
        }
    })();
</script>

<?php /*
// Remaining code commented as requested (login with captcha + OTP)

// Global login with captcha + OTP
session_start();

if (!function_exists('generateCaptchaCode')) {
    function generateCaptchaCode(int $length = 6): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max   = strlen($chars) - 1;
        $code  = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }
}

$_SESSION['login_captcha'] = generateCaptchaCode();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VATI GSS - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/GSS/assets/css/login.css">
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <div style="display:flex; flex-direction:column; align-items:center; text-align:center; margin-bottom:18px;">
            <div style="width:64px; height:64px; border-radius:999px; background:linear-gradient(145deg,#e5edff,#ffffff); box-shadow: 6px 6px 14px rgba(148,163,184,0.6), -6px -6px 14px rgba(255,255,255,1); display:flex; align-items:center; justify-content:center; margin-bottom:10px;">
                <span style="font-size:28px; color:#4f46e5;">👤</span>
            </div>
            <h2 style="font-size:22px; font-weight:600; margin:0 0 4px; color:#0f172a;">Welcome back</h2>
            <p style="font-size:12px; color:#6b7280; margin:0;">Please sign in once to continue to VATI GSS.</p>
        </div>

        <div id="loginMessage" class="mb-2" style="display:block;"></div>

        <div class="login-form-pane" style="background:transparent; padding:0;">
            <form id="loginForm" class="mb-3">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label for="captcha" class="form-label">Captcha</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="captcha-box"><?php echo htmlspecialchars($_SESSION['login_captcha'] ?? '------'); ?></div>
                        <input type="text" class="form-control" id="captcha" name="captcha" placeholder="Enter code" required>
                    </div>
                    <div class="form-text" style="color:#9ca3af; font-size:11px; text-align:left;">Type the characters shown to prove you are a real user.</div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:12px; color:#6b7280;">
                    <div class="form-check" style="padding-left:0; display:flex; align-items:center; gap:6px;">
                        <input class="form-check-input" type="checkbox" id="rememberMe" style="margin-top:0; box-shadow: 2px 2px 5px rgba(148,163,184,0.6), -2px -2px 5px rgba(255,255,255,0.9);">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                    </div>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" style="text-decoration:none; color:#4f46e5; font-weight:500;">Forgot password?</a>
                </div>
                <button type="submit" class="btn w-100" style="border-radius:999px; background:#eef2ff; border:none; color:#111827; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.7), -6px -6px 18px rgba(255,255,255,1); height:42px;">Request OTP</button>
            </form>

            <div id="otpCard" style="display:none; margin-top:12px;">
                <h6 class="mb-1" style="color:#0f172a; font-size:13px;">Enter OTP</h6>
                <p class="mb-2" style="font-size:11px; color:#6b7280;">We have sent a one-time password to your registered contact.</p>
                <form id="otpForm">
                    <div class="mb-3">
                        <label for="otp" class="form-label">One Time Password</label>
                        <input type="text" class="form-control" id="otp" name="otp" placeholder="Enter OTP" required>
                    </div>
                    <button type="submit" class="btn w-100" style="border-radius:999px; background:#e0fbe2; border:none; color:#166534; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.4), -6px -6px 18px rgba(255,255,255,1); height:40px;">Verify &amp; Continue</button>
                </form>
            </div>
        </div>

        <div class="env-foot">
            Environment: Staging · Login (username, password, captcha, OTP)
        </div>

        <!-- Forgot Password Modal (staging info only) -->
        <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:18px; box-shadow: 0 18px 40px rgba(15,23,42,0.25);">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title" id="forgotPasswordLabel">Password help</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="font-size:13px; color:#4b5563;">
                        This is the staging environment. To reset your VATI GSS password, please contact your
                        administrator or support team.
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius:999px; padding-inline:18px; font-size:13px;">Got it</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="/GSS/js/index.js"></script>
</body>
</html>
*/ ?>
</body>
</html>
