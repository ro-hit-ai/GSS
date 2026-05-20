<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';
// header('Content-Type: application/pdf');
// header('Content-Disposition: inline; filename="document.pdf"');

$candidateReportJsVersion = '1';
try {
    $candidateReportJsVersion = (string)filemtime(__DIR__ . '/../../js/modules/shared/candidate_report.js');
} catch (Throwable $e) {
    $candidateReportJsVersion = '1';
}

$isPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';
$isEmbed = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$role = strtolower(trim((string)($_GET['role'] ?? 'client_admin'))) ?: 'client_admin';
$group = isset($_GET['group']) ? strtoupper(trim((string)$_GET['group'])) : '';
$applicationId = isset($_GET['application_id']) ? trim((string)$_GET['application_id']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;

$fullscreen = !isset($_GET['fullscreen']) || (string)$_GET['fullscreen'] !== '0';

if ($role === 'verifier' && $applicationId === '' && $caseId <= 0) {
    header('Location: ../verifier/dashboard.php');
    exit;
}

if ($applicationId === '' && $caseId > 0) {
    require_once __DIR__ . '/../../config/db.php';
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT application_id, client_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $applicationId = isset($row['application_id']) ? trim((string)$row['application_id']) : '';
            if ($clientId <= 0 && isset($row['client_id'])) {
                $clientId = (int)$row['client_id'];
            }
        }
    } catch (Throwable $e) {
    }
}

auth_session_start();
$normalizeRole = function (string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'customer_admin') return 'client_admin';
    if ($r === 'component verifier' || $r === 'component_verifier') return 'verifier';
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'db verifier' || $r === 'db-verifier') return 'db_verifier';
    if ($r === 'gss admin') return 'gss_admin';
    if ($r === 'team lead' || $r === 'team_lead') return 'team_lead';
    return $r;
};

$sessionRole = $normalizeRole((string)($_SESSION['auth_moduleAccess'] ?? ''));
if ($sessionRole === '') {
    $sessionRole = $normalizeRole((string)($_SESSION['auth_role'] ?? ''));
}
if ($sessionRole === '') {
    $sessionRole = $normalizeRole((string)($_SESSION['role'] ?? ''));
}
if ($sessionRole === '' && !empty($_SESSION['logged_in']) && !empty($_SESSION['application_id'])) {
    $sessionRole = 'candidate';
}
if ($sessionRole !== '') {
    $role = $sessionRole;
}

if ($role !== 'verifier') {
    $group = '';
}
$allowedSections = '';
if (!empty($_SESSION['auth_allowed_sections'])) {
    $allowedSections = (string)$_SESSION['auth_allowed_sections'];
}
// Keep UI scope in sync with latest admin assignment (avoid stale session values).
try {
if ($role === 'verifier' || $role === 'validator' || $role === 'db_verifier') {
    require_once __DIR__ . '/../../config/db.php';
    $uid = (int)($_SESSION['auth_user_id'] ?? 0);

    if ($uid > 0) {
        $pdo = getDB();
        $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
        $st->execute([$uid]);
        $dbAllowed = (string)($st->fetchColumn() ?: '');

        // ✅ FIX START
        if ($role === 'validator') {
            // Validator should see all client-required sections
            $allowedSections = '*';
            $_SESSION['auth_allowed_sections'] = '*';
        } else {
            // Verifier should still respect assigned sections
            $allowedSections = $dbAllowed;
            $_SESSION['auth_allowed_sections'] = $dbAllowed;
        }
        // ✅ FIX END
    }
}
} catch (Throwable $e) {
}
if ($role === 'qa') {
    $allowedSections = '*';
}

// Require login for all staff roles (candidate portal has separate login)
if ($role !== 'candidate') {
    // Special case: QA report view is also allowed for Team Lead (used in QA Case Review iframe)
    if ($role === 'qa') {
        auth_require_any_access(['qa', 'team_lead']);
        $allowedSections = '*';
    } else {
        auth_require_login($role);
    }
}

$roleLabel = 'Client Admin';
$menu = client_admin_menu();

if ($role === 'gss_admin') {
    $roleLabel = 'GSS Admin';
    $menu = gss_admin_menu();
}

if ($role === 'db_verifier') {
    $roleLabel = 'DB Verifier';
    $menu = db_verifier_menu();
}

if ($role === 'verifier') {
    $roleLabel = 'Component Verifier';
    $menu = verifier_menu();
}

 if ($role === 'validator') {
     $roleLabel = 'Validator';
     $menu = validator_menu();
 }

if ($role === 'qa') {
    $roleLabel = 'QA / Team Lead';
    $menu = qa_menu();
}

if ($role === 'company_recruiter') {
    $roleLabel = 'HR Recruiter';
    $menu = hr_recruiter_menu();
}

function rewrite_menu_hrefs(array $menu, string $modulePrefix): array {
    $rewriteHref = function ($href) use ($modulePrefix) {
        $href = (string)$href;
        if ($href === '') return $href;
        if (strpos($href, '../') === 0) return $href;
        return $modulePrefix . ltrim($href, '/');
    };

    $out = [];
    foreach ($menu as $item) {
        if (!is_array($item)) {
            $out[] = $item;
            continue;
        }

        $newItem = $item;
        if (isset($newItem['href'])) {
            $newItem['href'] = $rewriteHref($newItem['href']);
        }
        if (isset($newItem['children']) && is_array($newItem['children'])) {
            $children = [];
            foreach ($newItem['children'] as $child) {
                if (!is_array($child)) {
                    $children[] = $child;
                    continue;
                }
                $newChild = $child;
                if (isset($newChild['href'])) {
                    $newChild['href'] = $rewriteHref($newChild['href']);
                }
                $children[] = $newChild;
            }
            $newItem['children'] = $children;
        }

        $out[] = $newItem;
    }
    return $out;
}

if ($role === 'gss_admin') {
    $menu = rewrite_menu_hrefs($menu, '../gss_admin/');
} elseif ($role === 'db_verifier') {
    $menu = rewrite_menu_hrefs($menu, '../db_verifier/');
} elseif ($role === 'verifier') {
    $menu = rewrite_menu_hrefs($menu, '../verifier/');
} elseif ($role === 'validator') {
    $menu = rewrite_menu_hrefs($menu, '../validator/');
} elseif ($role === 'qa') {
    $menu = rewrite_menu_hrefs($menu, '../qa/');
} elseif ($role === 'company_recruiter') {
    $menu = rewrite_menu_hrefs($menu, '../hr_recruiter/');
} else {
    $menu = rewrite_menu_hrefs($menu, '../client_admin/');
}

$moduleHomeHref = '../../index.php';
if ($role === 'gss_admin') {
    $moduleHomeHref = '../gss_admin/dashboard.php';
} elseif ($role === 'db_verifier') {
    $moduleHomeHref = '../db_verifier/candidates_list.php';
} elseif ($role === 'verifier') {
    $moduleHomeHref = '../verifier/dashboard.php';
} elseif ($role === 'validator') {
    $moduleHomeHref = '../validator/dashboard.php';
} elseif ($role === 'qa') {
    $moduleHomeHref = '../qa/review_list.php';
} elseif ($role === 'client_admin') {
    $moduleHomeHref = '../client_admin/dashboard.php';
} elseif ($role === 'company_recruiter') {
    $moduleHomeHref = '../hr_recruiter/dashboard.php';
}

if ($role === 'company_recruiter') {
    require_once __DIR__ . '/../../config/db.php';
    $uid = !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $cid = !empty($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    if ($uid <= 0 || $cid <= 0) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    try {
        $pdo = getDB();
        $stmt = null;
        if ($caseId > 0) {
            $stmt = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE case_id = ? AND client_id = ? AND COALESCE(created_by_user_id,0) = ? LIMIT 1');
            $stmt->execute([$caseId, $cid, $uid]);
        } elseif ($applicationId !== '') {
            $stmt = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? AND client_id = ? AND COALESCE(created_by_user_id,0) = ? LIMIT 1');
            $stmt->execute([$applicationId, $cid, $uid]);
        }

        $ok = $stmt ? (int)($stmt->fetchColumn() ?: 0) : 0;
        if ($ok <= 0) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$backToListHref = '';
if ($role === 'verifier') {
    $backToListHref = '../verifier/candidates_list.php';
    $incomingView = strtolower(trim((string)($_GET['list_view'] ?? $_GET['view'] ?? '')));
    $allowedViews = ['mine', 'available', 'followup', 'participated', 'history', 'completed'];
    if ($incomingView === '' && isset($_SESSION['verifier_last_list_view'])) {
        $incomingView = strtolower(trim((string)$_SESSION['verifier_last_list_view']));
    }
    if (!in_array($incomingView, $allowedViews, true)) {
        $incomingView = 'mine';
    }
    if ($incomingView === 'history' || $incomingView === 'completed') {
        $incomingView = 'participated';
    }
    $incomingFilter = strtolower(trim((string)($_GET['list_filter'] ?? $_GET['filter'] ?? '')));
    $allowedFilters = ['all', 'active_work', 'awaiting_evaluation', 'waiting_candidate', 'evaluated', 'reopened', 'downstream_processing', 'review_complete'];
    if ($incomingFilter === '' && isset($_SESSION['verifier_last_list_filter'])) {
        $incomingFilter = strtolower(trim((string)$_SESSION['verifier_last_list_filter']));
    }
    if (!in_array($incomingFilter, $allowedFilters, true)) {
        $incomingFilter = '';
    }
    $_SESSION['verifier_last_list_view'] = $incomingView;
    if ($incomingFilter !== '') {
        $_SESSION['verifier_last_list_filter'] = $incomingFilter;
    }
    $backParams = [];
    if ($group !== '') {
        $backParams['group'] = $group;
    }
    $backParams['view'] = $incomingView;
    if ($incomingFilter !== '') {
        $backParams['filter'] = $incomingFilter;
    }
    if (!empty($backParams)) {
        $backToListHref .= '?' . http_build_query($backParams);
    }
} elseif ($role === 'validator') {
    $backToListHref = '../validator/candidates_list.php';
} elseif ($role === 'qa' || $role === 'team_lead') {
    $backToListHref = '../qa/review_list.php';
} elseif ($role === 'gss_admin') {
    $backToListHref = '../gss_admin/candidates_list.php';
} elseif ($role === 'client_admin') {
    $backToListHref = '../client_admin/candidates_list.php';
}

ob_start();
?>
<style>
    body.cr-fullscreen-validator .top-header,body.cr-fullscreen-validator .sidebar,body.cr-fullscreen-validator .app-footer{display:none !important;}
    body.cr-fullscreen-validator .app-shell,body.cr-fullscreen-validator .main{padding:0 !important; margin:0 !important;}
    body.cr-fullscreen-validator{background:#f8fafc;}

    .cr-shell{display:flex; gap:16px; margin-top:14px; align-items:flex-start;}
    .cr-hero{border:1px solid rgba(59,130,246,0.16); background:radial-gradient(900px 420px at 10% 0%, rgba(59,130,246,0.12), transparent 55%), radial-gradient(720px 380px at 90% 0%, rgba(34,197,94,0.10), transparent 55%), linear-gradient(180deg,#ffffff,#f8fafc); border-radius:16px; padding:14px; box-shadow:0 14px 28px rgba(15,23,42,0.08);}
    .cr-hero-top{display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;}
    .cr-hero-head{display:flex; align-items:flex-start; gap:12px;}
    .cr-avatar{width:44px; height:44px; border-radius:14px; display:grid; place-items:center; background:linear-gradient(180deg,#111827,#0b1220); box-shadow:0 10px 18px rgba(15,23,42,0.18); border:1px solid rgba(148,163,184,0.20); overflow:hidden;}
    .cr-avatar svg{width:22px; height:22px; color:#e5e7eb;}
    .cr-avatar img{width:100%; height:100%; object-fit:cover; display:block;}
    .cr-avatar-fallback{font-size:13px; font-weight:900; letter-spacing:.04em; color:#e5e7eb;}
    .cr-actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
    .cr-action-btn{border-radius:12px; padding:8px 12px; font-size:12px; font-weight:800; border:1px solid rgba(148,163,184,0.38); background:#ffffff; color:#0f172a;}
    .cr-action-btn:hover{filter:brightness(0.98);}
    .cr-action-btn.cr-danger{background:rgba(239,68,68,0.12); border-color:rgba(239,68,68,0.30); color:#b91c1c;}
    .cr-action-btn.cr-danger:hover{background:rgba(239,68,68,0.16);}
    .cr-action-btn.cr-dark{background:#111827; border-color:#111827; color:#ffffff;}
    .cr-action-btn.cr-dark:hover{filter:brightness(1.05);}
    .cr-action-btn.cr-ok{background:rgba(34,197,94,0.14); border-color:rgba(34,197,94,0.28); color:#166534;}
    .cr-action-btn.cr-ok:hover{background:rgba(34,197,94,0.18);}
    .cr-action-btn.cr-icon{padding:8px 10px; display:inline-flex; align-items:center; justify-content:center; min-width:38px;}
    .cr-action-btn.cr-icon svg{width:16px; height:16px;}
    .cr-hero-title{font-size:16px; font-weight:800; color:#0f172a; letter-spacing:-.02em; margin:0;}
    .cr-hero-sub{font-size:12px; color:#64748b; margin-top:4px;}
    .cr-stat-row{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; font-size:12px; color:#475569;}
    .cr-stat{display:flex; gap:8px; align-items:center; padding:8px 10px; border-radius:999px; border:1px solid rgba(148,163,184,0.22); background:rgba(255,255,255,0.70);}
    .cr-stat b{color:#0f172a;}
    .cr-sidebar{width:250px; padding:12px; border:1px solid rgba(148,163,184,0.24); border-radius:14px; background:linear-gradient(180deg,#ffffff,#f8fafc); box-shadow:0 10px 26px rgba(15,23,42,0.06); position:sticky; top:12px;}
    .cr-docbar{width:360px; padding:12px; border:1px solid rgba(148,163,184,0.24); border-radius:14px; background:linear-gradient(180deg,#ffffff,#f8fafc); box-shadow:0 10px 26px rgba(15,23,42,0.06); position:sticky; top:12px;}
    .cr-docbar-title{font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.10em; color:#64748b; margin-bottom:10px;}
    .cr-docbar-frame{width:100%; height:520px; border:1px solid rgba(148,163,184,0.28); border-radius:14px; background:#fff; overflow:hidden;}
    .cr-docbar-frame iframe{width:100%; height:100%; border:0;}
    .cr-docbar-frame img{width:100%; height:100%; object-fit:contain; background:#fff;}
    .cr-docbar-list{margin-top:10px; display:flex; flex-direction:column; gap:6px; max-height:240px; overflow:auto; padding-right:4px;}
    .cr-docbar-item{border:1px solid rgba(148,163,184,0.22); background:rgba(255,255,255,0.7); border-radius:12px; padding:8px 10px; cursor:pointer; display:flex; justify-content:space-between; gap:10px; align-items:center;}
    .cr-docbar-item:hover{filter:brightness(0.99); border-color:rgba(59,130,246,0.32);}
    .cr-docbar-item.active{border-color:rgba(59,130,246,0.42); background:rgba(59,130,246,0.08);}
    .cr-docbar-meta{font-size:12px; color:#0f172a; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
    .cr-docbar-sub{font-size:11px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
    .cr-docbar-open{font-size:11px; font-weight:900; color:#2563eb; white-space:nowrap;}
    .cr-sidebar-title{font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.10em; color:#64748b; margin-bottom:10px;}
    .cr-nav{display:flex; flex-direction:column; gap:6px;}
    .cr-nav .list-group-item{border:1px solid transparent; border-radius:12px; background:transparent; padding:10px 10px; cursor:pointer; transition:background .2s ease, border-color .2s ease, transform .15s ease;}
    .cr-nav .section-row{display:flex; justify-content:space-between; align-items:flex-start; gap:12px; min-height:58px;}
    .cr-nav .list-group-item:hover{background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.20); transform:translateX(2px);}
    .cr-nav .list-group-item.active{background:rgba(59,130,246,0.12); border-color:rgba(59,130,246,0.34);}
    .cr-nav-label,.cr-nav .section-left{display:flex; align-items:flex-start; gap:10px; flex:1; min-width:0; padding-right:6px;}
    .cr-nav .section-title{display:block; flex:1 1 auto; min-width:0; max-width:100%; line-height:1.2; white-space:normal; word-break:keep-all; overflow-wrap:normal; hyphens:none;}
    .cr-ico{width:18px; height:18px; color:#64748b; flex:0 0 18px;}
    .cr-nav .list-group-item.active .cr-ico{color:#2563eb;}
    .cr-nav .section-right,.cr-nav .cv-badge-wrap{display:flex; align-items:flex-start; gap:6px; flex:0 0 auto;}
    .cr-nav .badge{border-radius:999px; padding:4px 10px; font-size:11px; font-weight:800; letter-spacing:.02em; white-space:nowrap; flex:0 0 auto; align-self:flex-start; margin-top:2px;}
    .cr-nav .badge.bg-success{background:rgba(34,197,94,0.14) !important; color:#166534 !important; border:1px solid rgba(34,197,94,0.22);}
    .cr-nav .badge.bg-warning{background:rgba(251,191,36,0.16) !important; color:#92400e !important; border:1px solid rgba(251,191,36,0.26);}
    .cr-nav .badge.bg-secondary{background:rgba(100,116,139,0.14) !important; color:#334155 !important; border:1px solid rgba(100,116,139,0.22);}
    .cr-main{flex:1; min-width:0;}

    .cr-compnav{display:none; align-items:center; gap:8px; flex-wrap:wrap; margin-top:12px;}
    .cr-compnav-title{font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.10em; color:#64748b; margin-right:4px;}
    .cr-compnav-btn{border:1px solid rgba(148,163,184,0.36); background:#fff; color:#0f172a; border-radius:999px; padding:7px 12px; font-size:12px; font-weight:900; cursor:pointer; line-height:1;}
    .cr-compnav-btn:hover{border-color:rgba(59,130,246,0.28); background:rgba(59,130,246,0.06);}
    .cr-compnav-btn.active{border-color:rgba(59,130,246,0.42); background:rgba(59,130,246,0.12); color:#1d4ed8;}

    .cr-report-root.cr-role-verifier .cr-compnav,
    .cr-report-root.cr-role-validator .cr-compnav,
    .cr-report-root.cr-role-db_verifier .cr-compnav,
    .cr-report-root.cr-role-qa .cr-compnav,
    .cr-report-root.cr-role-team_lead .cr-compnav{display:none;}

    .cr-report-root.cr-role-verifier .cr-sidebar,
    .cr-report-root.cr-role-validator .cr-sidebar,
    .cr-report-root.cr-role-db_verifier .cr-sidebar,
    .cr-report-root.cr-role-qa .cr-sidebar,
    .cr-report-root.cr-role-team_lead .cr-sidebar{display:block;}
    .cr-report-root.cr-role-qa .cr-nav .section-left,
    .cr-report-root.cr-role-team_lead .cr-nav .section-left{min-width:96px;}

    .cr-report-root.cr-role-verifier .cr-shell,
    .cr-report-root.cr-role-validator .cr-shell,
    .cr-report-root.cr-role-db_verifier .cr-shell,
    .cr-report-root.cr-role-qa .cr-shell,
    .cr-report-root.cr-role-team_lead .cr-shell{gap:0;}

    .cr-report-root.cr-role-verifier,
    .cr-report-root.cr-role-validator,
    .cr-report-root.cr-role-db_verifier,
    .cr-report-root.cr-role-qa,
    .cr-report-root.cr-role-team_lead{background:#fff; border:1px solid rgba(148,163,184,0.22); box-shadow:none;}

    .cr-report-root.cr-role-verifier .cr-hero,
    .cr-report-root.cr-role-validator .cr-hero,
    .cr-report-root.cr-role-db_verifier .cr-hero,
    .cr-report-root.cr-role-qa .cr-hero,
    .cr-report-root.cr-role-team_lead .cr-hero{background:#fff; box-shadow:none; border-radius:10px; border-color:rgba(148,163,184,0.22); padding:12px;}

    .cr-report-root.cr-validator-workspace .cr-hero{
        padding:16px 20px;
        border-radius:14px;
        border:1px solid rgba(203,213,225,0.95);
        background:#ffffff;
        box-shadow:none;
        padding-bottom:14px;
        margin-bottom:12px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-layout{
        display:flex;
        flex-direction:column;
        gap:12px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row{
        width:100%;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-copy{
        margin-bottom:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-kpis{
        margin-bottom:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions{
        padding-top:0;
        padding-bottom:0;
        border-top:0;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions .cr-hero-toolbar{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions .cr-actions{
        margin:0;
        gap:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions .cr-actions-primary,
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions .cr-actions-secondary{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-row-actions .cr-resend-helper{
        display:none;
    }
    .cr-report-root.cr-validator-workspace #cvResendMetaBadge{
        border:0 !important;
        border-radius:0 !important;
        padding:0 !important;
        background:transparent !important;
        color:#64748b !important;
        font-size:12px !important;
    }

    .cr-report-root.cr-role-verifier .cr-avatar,
    .cr-report-root.cr-role-validator .cr-avatar,
    .cr-report-root.cr-role-db_verifier .cr-avatar,
    .cr-report-root.cr-role-qa .cr-avatar,
    .cr-report-root.cr-role-team_lead .cr-avatar{border-radius:8px; box-shadow:none;}

    .cr-report-root.cr-role-verifier .cr-action-btn,
    .cr-report-root.cr-role-validator .cr-action-btn,
    .cr-report-root.cr-role-db_verifier .cr-action-btn,
    .cr-report-root.cr-role-qa .cr-action-btn,
    .cr-report-root.cr-role-team_lead .cr-action-btn{border-radius:6px; padding:7px 10px; font-weight:800;}

    .cr-report-root.cr-role-verifier .card,
    .cr-report-root.cr-role-validator .card,
    .cr-report-root.cr-role-db_verifier .card,
    .cr-report-root.cr-role-qa .card,
    .cr-report-root.cr-role-team_lead .card{box-shadow:none !important; border-radius:10px; border:1px solid rgba(148,163,184,0.22) !important;}

    .cr-report-root.cr-role-verifier .cr-panel,
    .cr-report-root.cr-role-validator .cr-panel,
    .cr-report-root.cr-role-db_verifier .cr-panel,
    .cr-report-root.cr-role-qa .cr-panel,
    .cr-report-root.cr-role-team_lead .cr-panel{box-shadow:none !important; border-radius:10px; border:1px solid rgba(148,163,184,0.22) !important; background:#fff;}

    .cr-report-root.cr-role-verifier .cr-secbar,
    .cr-report-root.cr-role-validator .cr-secbar,
    .cr-report-root.cr-role-db_verifier .cr-secbar,
    .cr-report-root.cr-role-qa .cr-secbar,
    .cr-report-root.cr-role-team_lead .cr-secbar{background:#111827; border-radius:0;}

    .cr-report-root.cr-role-verifier .form-control,
    .cr-report-root.cr-role-validator .form-control,
    .cr-report-root.cr-role-db_verifier .form-control,
    .cr-report-root.cr-role-qa .form-control,
    .cr-report-root.cr-role-team_lead .form-control{background:transparent; border:0; padding:0;}

    .cr-report-root.cr-role-verifier .form-control label,
    .cr-report-root.cr-role-validator .form-control label,
    .cr-report-root.cr-role-db_verifier .form-control label{font-size:11px; color:#475569; font-weight:800; margin-bottom:3px;}

    .cr-report-root.cr-role-verifier .form-control input,
    .cr-report-root.cr-role-verifier .form-control textarea,
    .cr-report-root.cr-role-verifier .form-control select,
    .cr-report-root.cr-role-validator .form-control input,
    .cr-report-root.cr-role-validator .form-control textarea,
    .cr-report-root.cr-role-validator .form-control select,
    .cr-report-root.cr-role-db_verifier .form-control input,
    .cr-report-root.cr-role-db_verifier .form-control textarea,
    .cr-report-root.cr-role-db_verifier .form-control select{
        background:transparent;
        border:0;
        border-bottom:1px solid rgba(148,163,184,0.35);
        border-radius:0;
        padding:6px 0;
        box-shadow:none;
        font-weight:700;
        color:#0f172a;
    }

    .cr-report-root.cr-role-verifier .form-control input:focus,
    .cr-report-root.cr-role-validator .form-control input:focus,
    .cr-report-root.cr-role-db_verifier .form-control input:focus{outline:none; box-shadow:none;}

    .cr-report-root.cr-role-verifier .cr-compnav,
    .cr-report-root.cr-role-validator .cr-compnav,
    .cr-report-root.cr-role-db_verifier .cr-compnav{margin-top:10px; padding:6px 0; border-bottom:1px solid rgba(148,163,184,0.20);}

    .cr-report-root.cr-role-verifier .cr-compnav-btn,
    .cr-report-root.cr-role-validator .cr-compnav-btn,
    .cr-report-root.cr-role-db_verifier .cr-compnav-btn{border-radius:6px; padding:6px 10px; font-size:12px;}

    .cr-review-tabs{
        display:none;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        margin:10px 0 12px;
        padding-bottom:10px;
        border-bottom:1px solid rgba(148,163,184,0.20);
    }
    .cr-review-tab{
        border:1px solid rgba(148,163,184,0.36);
        background:#fff;
        color:#0f172a;
        border-radius:999px;
        padding:7px 12px;
        font-size:12px;
        font-weight:900;
        cursor:pointer;
        line-height:1;
    }
    .cr-review-tab:hover{border-color:rgba(59,130,246,0.28); background:rgba(59,130,246,0.06);}
    .cr-review-tab.active{border-color:rgba(59,130,246,0.42); background:rgba(59,130,246,0.12); color:#1d4ed8;}
    .cr-report-root.cr-role-verifier .cr-review-tabs,
    .cr-report-root.cr-role-validator .cr-review-tabs{display:flex;}

    .cr-case-actions-card{border:1px solid rgba(148,163,184,0.24); border-radius:12px; background:linear-gradient(180deg,#ffffff,#f8fafc); padding:10px; margin-bottom:10px;}
    .cr-case-actions-head{font-size:11px; font-weight:950; letter-spacing:.10em; text-transform:uppercase; color:#64748b; margin-bottom:8px;}
    .cr-case-actions-row{display:flex; gap:8px; flex-wrap:wrap;}

    .cr-report-root.cr-role-verifier .cr-upload-inline,
    .cr-report-root.cr-role-validator .cr-upload-inline,
    .cr-report-root.cr-role-db_verifier .cr-upload-inline{display:none !important;}

    .cr-report-root.cr-role-verifier .cr-docbar,
    .cr-report-root.cr-role-validator .cr-docbar{display:block;}

    .cr-report-root.cr-role-db_verifier .cr-docbar{display:none !important;}

    .cr-report-root.cr-role-verifier .cr-sections-scroll,
    .cr-report-root.cr-role-validator .cr-sections-scroll,
    .cr-report-root.cr-role-db_verifier .cr-sections-scroll{max-height:none; overflow:visible; padding-right:0;}

    .cr-report-root.cr-role-verifier .cr-shell,
    .cr-report-root.cr-role-validator .cr-shell,
    .cr-report-root.cr-role-db_verifier .cr-shell{gap:12px;}

    /* Validator: fixed sidebar + stable content flow */
    /* Validator layout: internal scrolling only */
    .cr-report-root.cr-validator-workspace{
        height:100vh;
        overflow:hidden;
        display:flex;
        flex-direction:column;
    }
    .cr-report-root.cr-validator-workspace .cr-hero{flex-shrink:0;}
    .cr-report-root.cr-validator-workspace .cr-shell{
        flex:1;
        min-height:0;
        display:flex;
        gap:12px;
        overflow:hidden;
    }
    .cr-report-root.cr-validator-workspace .cr-sidebar{
        width:250px;
        flex-shrink:0;
        overflow-y:auto;
        height:fit-content;
        max-height:100%;
    }
    .cr-report-root.cr-validator-workspace .cr-main{
        flex:1;
        min-width:0;
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .cr-report-root.cr-validator-workspace .cr-upload-inline{flex-shrink:0;}
    .cr-report-root.cr-validator-workspace .cr-sections-scroll{
        flex:1;
        overflow-y:auto;
        padding-right:6px;
        max-height:none;
    }
    body.cr-fullscreen-validator .cr-report-root.cr-validator-workspace .cr-sidebar{
        top:12px;
        height:calc(100vh - 24px);
    }
    .cr-report-root.cr-validator-workspace .cr-main{
        min-width:0;
    }
    .cr-report-root.cr-validator-workspace{
        position:relative;
        height:auto;
        overflow:visible;
    }
    .cr-report-root.cr-validator-workspace .cr-sections-scroll{
        max-height:none;
        overflow:visible;
        padding-right:0;
    }

    .cr-remarksbar{width:320px; padding:12px; border:1px solid rgba(148,163,184,0.22); border-radius:10px; background:#fff; position:sticky; top:76px; height:fit-content;}
    .cr-remarksbar-title{font-size:11px; font-weight:950; letter-spacing:.10em; text-transform:uppercase; color:#64748b; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:10px;}
    .cr-remarksbar-title .badge{border-radius:999px; padding:4px 10px; font-size:11px; font-weight:900;}
    .cr-remarksbar-group{border:1px solid rgba(148,163,184,0.18); border-radius:10px; overflow:hidden; margin-bottom:10px;}
    .cr-remarksbar-head{display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; background:rgba(248,250,252,0.9); cursor:pointer;}
    .cr-remarksbar-head.active{background:rgba(59,130,246,0.10);}
    .cr-remarksbar-head b{font-size:12px; color:#0f172a;}
    .cr-remarksbar-body{padding:8px 10px; display:none;}
    .cr-remarksbar-body.open{display:block;}
    .cr-remark-item{padding:8px 0; border-bottom:1px solid rgba(148,163,184,0.14);}
    .cr-remark-item:last-child{border-bottom:0;}
    .cr-remark-meta{font-size:11px; color:#64748b; display:flex; justify-content:space-between; gap:10px;}
    .cr-remark-msg{font-size:12px; color:#0f172a; margin-top:4px; white-space:pre-wrap;}

    .cr-report-root.cr-role-verifier .cr-remarks,
    .cr-report-root.cr-role-validator .cr-remarks,
    .cr-report-root.cr-role-db_verifier .cr-remarks{padding:8px; border-radius:10px; margin-top:10px;}
    .cr-report-root.cr-role-verifier .cr-remarks textarea,
    .cr-report-root.cr-role-validator .cr-remarks textarea,
    .cr-report-root.cr-role-db_verifier .cr-remarks textarea{min-height:60px;}

    @media (max-width: 1100px){
        .cr-remarksbar{width:auto; position:relative; top:auto;}
    }

    .cr-report-root.cr-role-verifier .cr-remarksbar,
    .cr-report-root.cr-role-validator .cr-remarksbar,
    .cr-report-root.cr-role-db_verifier .cr-remarksbar{display:none !important;}

    /* Hide remarks ONLY in normal verifier/validator view */
.cr-report-root.cr-role-verifier:not(.qa-case-review-mode) .cr-remarksbar,
.cr-report-root.cr-role-validator:not(.qa-case-review-mode) .cr-remarksbar,
.cr-report-root.cr-role-db_verifier:not(.qa-case-review-mode) .cr-remarksbar {
    display: none !important;
}

/* Explicitly show remarks in QA Case Review */
.qa-case-review-mode .cr-remarksbar {
    display: flex !important;
}


    .cr-report-root.cr-validator-workspace .cr-remarks{display:none !important;}
    .cr-report-root.cr-validator-workspace{
        background:#f8fafc;
        border:1px solid rgba(203,213,225,0.9);
        border-top:1px solid rgba(203,213,225,0.9);
        padding:14px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero{
        margin-top:0 !important;
        padding:16px 20px;
        border:1px solid rgba(203,213,225,0.9);
        border-radius:16px;
        background:#ffffff;
        box-shadow:none;
        padding-bottom:14px;
        margin-bottom:12px;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-top{
        align-items:flex-start;
        gap:20px;
        flex-wrap:wrap;
    }
    .cr-validator-case-main{
        display:flex;
        flex-direction:column;
        gap:10px;
        flex:1;
        min-width:0;
    }
    .cr-validator-case-copy{
        display:flex;
        flex-direction:column;
        gap:0;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-head{
        align-items:flex-start;
        gap:0;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-title{
        font-size:17px;
        line-height:1.2;
        margin:0;
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }
    .cr-report-root.cr-validator-workspace .cr-hero-sub{
        margin-top:0;
        font-size:12px;
        font-weight:500;
        color:#64748b;
        white-space:nowrap;
    }
    .cr-validator-case-grid{
        display:grid;
        grid-template-columns:repeat(5, minmax(0, 1fr));
        gap:12px;
        margin-top:12px;
        margin-bottom:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-stat{
        display:flex;
        flex-direction:column;
        align-items:flex-start;
        gap:3px;
        padding:12px 14px;
        min-height:68px;
        border-radius:12px;
        border:1px solid rgba(226,232,240,0.95);
        background:#f8fafc;
        min-width:0;
    }
    .cr-report-root.cr-validator-workspace .cr-stat b{
        font-size:11px;
        letter-spacing:.08em;
        text-transform:uppercase;
        color:#64748b;
    }
    .cr-report-root.cr-validator-workspace .cr-stat span{
        font-size:14px;
        font-weight:800;
        color:#0f172a;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
        width:100%;
    }
    .cr-report-root.cr-validator-workspace .cr-actions{
        align-self:auto;
        gap:12px;
        align-items:center;
    }
    .cr-report-root.cr-validator-workspace .cr-actions .cr-action-btn{
        padding:8px 14px;
        min-height:36px;
        font-size:14px;
        line-height:1.2;
        border-radius:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-shell.cr-layout{
        display:grid;
        grid-template-columns:300px minmax(0, 1fr) 320px;
        gap:16px;
        align-items:start;
        margin-top:14px;
    }
    .cr-report-root.cr-validator-workspace .cr-sidebar{
        width:auto;
        padding:16px 15px;
        border-radius:16px;
        border:1px solid rgba(203,213,225,0.95);
        background:#ffffff;
        box-shadow:0 10px 24px rgba(15,23,42,0.04);
        top:14px;
    }
    .cr-report-root.cr-validator-workspace .cr-sidebar-title{
        margin-bottom:14px;
        color:#475569;
    }
    .cr-report-root.cr-validator-workspace .cr-nav{
        gap:8px;
    }
    .cr-report-root.cr-validator-workspace .cr-nav .list-group-item{
        display:flex;
        flex-direction:row;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        padding:10px 14px;
        min-height:58px;
        border:1px solid rgba(226,232,240,0.9);
        background:#fbfdff;
        transform:none;
        border-radius:16px;
    }
    .cr-report-root.cr-validator-workspace .cr-nav .list-group-item:hover{
        transform:none;
        background:#f8fafc;
        border-color:rgba(148,163,184,0.42);
    }
    .cr-report-root.cr-validator-workspace .cr-nav .list-group-item.active{
        background:rgba(37,99,235,0.08);
        border-color:rgba(37,99,235,0.35);
        box-shadow:inset 0 0 0 1px rgba(37,99,235,0.10);
    }
    .cr-report-root.cr-validator-workspace .cr-nav-label{
        min-width:0;
        flex:1;
        align-items:flex-start;
        gap:10px;
    }
    .cr-report-root.cr-validator-workspace .cr-nav .section-right{
        display:flex;
        align-items:flex-start;
        gap:6px;
        flex:0 0 auto;
    }
    .cr-report-root.cr-validator-workspace .cr-nav-label span:last-child{
        line-height:1.2;
        font-weight:700;
        color:#0f172a;
    }
    .cr-report-root.cr-validator-workspace .cr-nav .badge{
        align-self:flex-start;
        margin-left:0;
        margin-top:2px;
        padding:3px 9px;
        font-size:10px;
        flex:0 0 auto;
        white-space:nowrap;
    }
    .cr-report-root.cr-validator-workspace .cr-main{
        min-width:0;
        display:flex;
        flex-direction:column;
        gap:16px;
    }
    .cr-report-root.cr-validator-workspace .cr-sections-scroll{
        order:1;
        max-height:none;
        overflow:visible;
        padding-right:0;
        border-radius:0;
    }
    .cr-report-root.cr-validator-workspace .cr-upload-inline{
        display:none !important;
    }
    .cr-report-root.cr-validator-workspace .cr-upload-inline h3{
        margin:0 0 10px;
        font-size:14px;
        font-weight:800;
        color:#0f172a;
    }
    .cr-report-root.cr-validator-workspace .candidate-section{
        margin-top:0 !important;
        border-radius:16px !important;
        border:1px solid rgba(203,213,225,0.95) !important;
        box-shadow:0 10px 24px rgba(15,23,42,0.04) !important;
        overflow:hidden;
    }
    .cr-report-root.cr-validator-workspace .cr-secbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:13px 16px;
        background:linear-gradient(180deg,#ffffff,#fbfdff);
        border-bottom:1px solid rgba(226,232,240,0.95);
    }
    .cr-secbar-titleblock{
        display:flex;
        align-items:flex-start;
        gap:12px;
        min-width:0;
    }
    .cr-secbar-icon{
        width:34px;
        height:34px;
        border-radius:12px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex:0 0 34px;
        background:rgba(37,99,235,0.08);
        border:1px solid rgba(37,99,235,0.14);
        color:#1d4ed8;
        font-size:11px;
        font-weight:900;
        letter-spacing:.04em;
    }
    .cr-secbar-copy{
        display:flex;
        flex-direction:column;
        min-width:0;
    }
    .cr-report-root.cr-validator-workspace .cr-secbar-title{
        font-size:14px;
        font-weight:800;
        color:#0f172a;
        line-height:1.2;
    }
    .cr-secbar-sub{
        margin-top:2px;
        font-size:11px;
        color:#64748b;
        font-weight:600;
        line-height:1.3;
    }
    .cr-report-root.cr-validator-workspace .cr-secbar-meta{
        font-size:12px;
        font-weight:800;
        color:#166534;
        text-align:right;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:6px 10px;
        border-radius:999px;
        background:rgba(34,197,94,0.12);
        border:1px solid rgba(34,197,94,0.18);
        white-space:nowrap;
    }
    .cr-report-root.cr-validator-workspace .candidate-section > .form-grid,
    .cr-report-root.cr-validator-workspace .candidate-section > div[id$="_table"]{
        margin-top:0 !important;
        padding:16px;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-layout{
        display:grid !important;
        grid-template-columns:minmax(0, 1fr);
        gap:12px !important;
        padding:12px;
        margin-top:0 !important;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-right{
        display:none !important;
    }
    .cr-validator-side{
        position:sticky;
        top:14px;
        display:flex;
        flex-direction:column;
        gap:12px;
        align-self:start;
        grid-column:3;
    }
    .cr-validator-side .cr-case-actions-card{
        margin-bottom:0;
        border:1px solid rgba(203,213,225,0.95);
        border-radius:16px;
        background:#ffffff;
        box-shadow:0 10px 24px rgba(15,23,42,0.04);
        padding:12px;
    }
    .cr-validator-side .cr-case-actions-head{
        margin-bottom:12px;
        color:#475569;
    }
    .cr-validator-side-meta{
        display:grid;
        gap:10px;
    }
    .cr-validator-side-item{
        display:flex;
        flex-direction:column;
        gap:4px;
        padding:10px 12px;
        border:1px solid rgba(226,232,240,0.95);
        border-radius:14px;
        background:#f8fafc;
    }
    .cr-validator-side-item span{
        font-size:11px;
        letter-spacing:.08em;
        text-transform:uppercase;
        color:#64748b;
        font-weight:800;
    }
    .cr-validator-side-item strong{
        font-size:14px;
        color:#0f172a;
        font-weight:800;
    }
    .cr-report-root.cr-validator-workspace .cr-docbar{
        width:auto;
        position:sticky;
        top:14px;
        align-self:start;
        max-height:calc(100vh - 42px);
        overflow:hidden;
        display:flex;
        flex-direction:column;
        grid-column:3;
    }
    .cr-report-root.cr-validator-workspace .cr-docbar-frame{
        height:420px;
        flex:0 0 auto;
    }
    .cr-report-root.cr-validator-workspace .cr-docbar-list{
        flex:1 1 auto;
        max-height:none;
        min-height:150px;
    }
    .cr-validator-action-grid{
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:8px;
    }
    .cr-validator-action-grid .cr-action-btn{
        width:100%;
        justify-content:center;
        min-height:40px;
    }
    .cr-validator-remarks-list{
        display:flex;
        flex-direction:column;
        gap:10px;
        max-height:280px;
        overflow:auto;
        padding-right:4px;
        color:#64748b;
        font-size:13px;
    }
    .cr-validator-remark{
        padding:10px 12px;
        border:1px solid rgba(226,232,240,0.95);
        border-radius:14px;
        background:#f8fafc;
    }
    .cr-validator-remark-meta{
        display:flex;
        justify-content:space-between;
        gap:10px;
        font-size:11px;
        color:#64748b;
        margin-bottom:6px;
    }
    .cr-validator-remark-text{
        font-size:12px;
        line-height:1.5;
        color:#0f172a;
        white-space:pre-wrap;
    }
    .cr-validator-remark-entry{
        margin-top:12px;
    }
    .cr-validator-remark-entry textarea{
        width:100%;
        min-height:96px;
        resize:vertical;
        border:1px solid rgba(203,213,225,0.95);
        border-radius:14px;
        padding:10px 12px;
        font-size:13px;
        color:#0f172a;
        background:#ffffff;
    }
    .cr-validator-remark-actions{
        display:flex;
        justify-content:flex-end;
        margin-top:10px;
    }
    .cr-validator-timeline{
        max-height:320px;
        overflow:auto;
        padding-right:4px;
    }
    .cr-validator-timeline .cr-flow-date{
        font-size:10px;
    }
    .cr-validator-timeline .cr-flow-card{
        max-width:none;
    }
    .cr-validator-timeline .cr-flow-list:before{
        left:10px;
        transform:none;
    }
    .cr-validator-timeline .cr-flow-item{
        padding-left:26px;
        padding-right:0 !important;
        justify-content:flex-start !important;
    }
    .cr-validator-timeline .cr-flow-dot{
        left:10px;
        transform:none;
    }
    .cr-validator-timeline .cr-flow-left .cr-flow-card,
    .cr-validator-timeline .cr-flow-right .cr-flow-card{
        margin-left:14px;
        margin-right:0;
    }
    .cr-validator-timeline .cr-flow-left .cr-flow-card:before,
    .cr-validator-timeline .cr-flow-right .cr-flow-card:before{
        left:-8px;
        right:auto;
        transform:rotate(225deg);
    }
    @media (max-width: 1400px){
        .cr-report-root.cr-validator-workspace .cr-shell.cr-layout{
            grid-template-columns:280px minmax(0, 1fr) 300px;
        }
    }
    @media (max-width: 1180px){
        .cr-report-root.cr-validator-workspace .cr-validator-case-grid{
            grid-template-columns:repeat(3, minmax(0, 1fr));
        }
        .cr-report-root.cr-validator-workspace .cr-shell.cr-layout{
            grid-template-columns:220px minmax(0, 1fr);
        }
        .cr-validator-side,
        .cr-report-root.cr-validator-workspace .cr-docbar{
            position:relative;
            top:auto;
            grid-column:1 / -1;
        }
    }
    @media (max-width: 900px){
        .cr-report-root.cr-validator-workspace{
            padding:12px;
        }
        .cr-report-root.cr-validator-workspace .cr-validator-case-grid{
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .cr-report-root.cr-validator-workspace .cr-shell.cr-layout{
            grid-template-columns:1fr;
        }
        .cr-report-root.cr-validator-workspace .cr-sidebar,
        .cr-validator-side,
        .cr-report-root.cr-validator-workspace .cr-docbar{
            position:relative;
            top:auto;
        }
        .cr-validator-action-grid{
            grid-template-columns:1fr;
        }
    }
    @media (max-width: 640px){
        .cr-report-root.cr-validator-workspace .cr-validator-case-grid{
            grid-template-columns:1fr;
        }
    }
    .cr-tl-filters{display:flex; flex-wrap:wrap; gap:6px; margin-top:10px;}
    .cr-tl-pill{border:1px solid rgba(148,163,184,0.35); background:#fff; color:#0f172a; border-radius:999px; padding:6px 10px; font-size:12px; font-weight:800; cursor:pointer; line-height:1;}
    .cr-tl-pill.active{background:rgba(59,130,246,0.12); border-color:rgba(59,130,246,0.34); color:#1d4ed8;}
    .cr-tl-body::-webkit-scrollbar{width:10px;}
    .cr-tl-body::-webkit-scrollbar-thumb{background:rgba(148,163,184,0.45); border-radius:999px; border:2px solid rgba(255,255,255,0.8);}
    .cr-tl-body::-webkit-scrollbar-track{background:transparent;}

    .cr-flow{display:flex; flex-direction:column; gap:10px;}
    .cr-flow-date{font-size:11px; font-weight:950; color:#0f172a; letter-spacing:.08em; text-transform:uppercase; margin:6px 0 4px;}
    .cr-flow-list{position:relative; padding:4px 0 4px 0;}
    .cr-flow-list:before{content:''; position:absolute; left:50%; top:0; bottom:0; width:2px; transform:translateX(-50%); background:linear-gradient(180deg, rgba(148,163,184,0.16), rgba(59,130,246,0.22), rgba(148,163,184,0.14)); border-radius:999px;}
    .cr-flow-item{position:relative; display:flex; width:100%; padding:6px 0;}
    .cr-flow-item.cr-flow-left{justify-content:flex-start; padding-right:52%;}
    .cr-flow-item.cr-flow-right{justify-content:flex-end; padding-left:52%;}
    .cr-flow-dot{position:absolute; left:50%; top:18px; width:12px; height:12px; transform:translateX(-50%); border-radius:999px; background:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,0.12); border:3px solid #fff;}
    .cr-flow-dot.cr-flow-dot-blue{background:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,0.12);}
    .cr-flow-dot.cr-flow-dot-green{background:#22c55e; box-shadow:0 0 0 4px rgba(34,197,94,0.12);}
    .cr-flow-dot.cr-flow-dot-amber{background:#f59e0b; box-shadow:0 0 0 4px rgba(245,158,11,0.12);}
    .cr-flow-dot.cr-flow-dot-red{background:#ef4444; box-shadow:0 0 0 4px rgba(239,68,68,0.12);}
    .cr-flow-card{width:100%; max-width:460px; border:1px solid rgba(148,163,184,0.20); background:linear-gradient(180deg,#ffffff,#f8fafc); border-radius:14px; padding:8px 10px; box-shadow:0 8px 14px rgba(15,23,42,0.05);}
    .cr-flow-card:before{content:''; position:absolute; width:12px; height:12px; background:linear-gradient(180deg,#ffffff,#f8fafc); border-left:1px solid rgba(148,163,184,0.20); border-bottom:1px solid rgba(148,163,184,0.20); transform:rotate(45deg); top:20px;}
    .cr-flow-left .cr-flow-card{position:relative; margin-right:14px;}
    .cr-flow-left .cr-flow-card:before{right:-8px;}
    .cr-flow-right .cr-flow-card{position:relative; margin-left:14px;}
    .cr-flow-right .cr-flow-card:before{left:-8px; transform:rotate(225deg);}
    .cr-flow-head{display:flex; justify-content:space-between; align-items:flex-start; gap:10px;}
    .cr-flow-actor{font-weight:950; color:#0f172a; font-size:12px;}
    .cr-flow-time{color:#64748b; font-size:11px; white-space:nowrap;}
    .cr-flow-badges{margin-top:6px;}
    .cr-flow-msg{margin-top:6px; color:#334155; font-size:12px; white-space:pre-wrap;}

    @media (max-width: 900px){
        .cr-flow-list:before{left:10px; transform:none;}
        .cr-flow-item{padding-left:26px;}
        .cr-flow-item.cr-flow-left,.cr-flow-item.cr-flow-right{padding-left:26px; padding-right:0; justify-content:flex-start;}
        .cr-flow-dot{left:10px; transform:none;}
        .cr-flow-left .cr-flow-card,.cr-flow-right .cr-flow-card{margin-left:14px; margin-right:0; max-width:none;}
        .cr-flow-left .cr-flow-card:before,.cr-flow-right .cr-flow-card:before{left:-8px; right:auto; transform:rotate(225deg);}
    }
    .cr-sections-scroll{border-radius:16px; overflow:auto; max-height:calc(100vh - 360px); padding-right:2px;}
    .cr-sections-scroll::-webkit-scrollbar{width:10px;}
    .cr-sections-scroll::-webkit-scrollbar-thumb{background:rgba(148,163,184,0.45); border-radius:999px; border:2px solid rgba(255,255,255,0.8);}
    .cr-sections-scroll::-webkit-scrollbar-track{background:transparent;}
    .cr-panel{opacity:0; transform:translateY(6px); transition:opacity .18s ease, transform .18s ease;}
    .cr-panel.cr-active{opacity:1; transform:translateY(0);}
    .candidate-section{scroll-margin-top:220px;}
    .cr-upload-card{border:1px solid rgba(148,163,184,0.26); background:linear-gradient(180deg,#ffffff,#f8fafc); border-radius:16px;}
    .cr-card-title{display:flex; align-items:center; gap:10px;}
    .cr-card-title .cr-ico{color:#0f172a;}
    .cr-upload-grid{display:grid; grid-template-columns: 1fr 1.35fr auto; gap:10px; align-items:end;}
    .cr-upload-help{font-size:12px; color:#64748b; margin-top:6px;}
    .cr-file-drop{border:1px solid rgba(148,163,184,0.24); border-radius:14px; padding:10px; background:rgba(255,255,255,0.75);}
    .cr-file-drop.cr-dragover{border-color:#60a5fa; box-shadow:0 0 0 3px rgba(59,130,246,0.16);}
    .cr-file-row{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
    .cr-file-btn{border:1px solid rgba(148,163,184,0.40); background:#ffffff; color:#0f172a; border-radius:12px; padding:8px 10px; font-size:12px; cursor:pointer;}
    .cr-file-meta{font-size:12px; color:#64748b;}
    .cr-file-input{display:none;}
    .cr-file-chips{margin-top:8px; display:flex; flex-wrap:wrap; gap:6px;}
    .cr-chip{background:#eef2ff; color:#1e40af; border:1px solid rgba(37,99,235,0.18); border-radius:999px; padding:4px 8px; font-size:12px;}
    .cr-upload-actions{display:flex; gap:8px; justify-content:flex-end;}
    .cr-upload-actions .btn{border-radius:12px; padding:8px 12px;}
    .cr-main .card{border-radius:16px; border:1px solid rgba(148,163,184,0.22); box-shadow:0 10px 24px rgba(15,23,42,0.06);}
    .cr-main .card h3{letter-spacing:-.01em;}
    .cr-main .table{margin-bottom:0;}
    .cr-main .table thead th{font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#64748b;}
    .cr-main .table tbody td{font-size:13px; color:#0f172a;}

    /* Prevent first-paint sidebar/section flicker: reveal after JS finalizes assigned-component view */
    .cr-report-root[data-ui-ready="0"] .cr-sidebar,
    .cr-report-root[data-ui-ready="0"] #cvComponentNav,
    .cr-report-root[data-ui-ready="0"] #crSectionsScroll{
        visibility:hidden;
    }
    .candidate-section .form-grid{gap:10px !important; margin-top:8px !important;}
    .candidate-section .form-control label{margin-bottom:4px; font-size:11px;}
    .candidate-section .form-control input,.candidate-section .form-control select,.candidate-section .form-control textarea{padding:8px 10px; border-radius:12px;}

    /* Stable layout for admin/client roles (non workflow modes) */
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid{
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px !important;
        align-items:start;
    }
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid > .form-control{
        display:flex;
        flex-direction:column;
        min-width:0;
        margin:0;
        padding:10px !important;
        border:1px solid rgba(148,163,184,0.24);
        border-radius:12px;
        background:#fff;
        box-shadow:none;
    }
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid > .form-control label{
        margin:0 0 4px;
        font-size:11px;
        font-weight:800;
        color:#475569;
    }
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid > .form-control input,
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid > .form-control textarea,
    .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid > .form-control select{
        width:100%;
        min-width:0;
        border:1px solid rgba(148,163,184,0.28);
        background:#f8fafc;
        box-shadow:none;
    }
    @media (max-width: 900px){
        .cr-report-root:not(.cr-role-verifier):not(.cr-role-validator):not(.cr-role-db_verifier) .candidate-section .form-grid{
            grid-template-columns:1fr;
        }
    }

    /* Match Basic/Contact-style sections to the same iOS-like key/value look used by table sections */
    .cr-report-root.cr-role-verifier .candidate-section .form-grid,
    .cr-report-root.cr-role-validator .candidate-section .form-grid,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid{
        display:block;
        border:1px solid rgba(148,163,184,0.22);
        border-radius:12px;
        background:#fff;
        padding:0 !important;
        overflow:hidden;
    }
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control{
        display:grid;
        grid-template-columns:220px 1fr;
        gap:12px;
        align-items:center;
        margin:0;
        padding:10px 12px !important;
        border-bottom:1px solid rgba(148,163,184,0.18);
        background:transparent !important;
    }
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control:last-child,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control:last-child,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control:last-child{border-bottom:0;}
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control label,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control label,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control label{
        margin:0;
        font-size:12px;
        font-weight:900;
        color:#334155;
        letter-spacing:.01em;
    }
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control input,
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control textarea,
    .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control select,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control input,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control textarea,
    .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control select,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control input,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control textarea,
    .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control select{
        padding:0 !important;
        border:0 !important;
        background:transparent !important;
        font-size:13px;
        font-weight:800;
        color:#0f172a;
    }
    @media (max-width: 900px){
        .cr-report-root.cr-role-verifier .candidate-section .form-grid .form-control,
        .cr-report-root.cr-role-validator .candidate-section .form-grid .form-control,
        .cr-report-root.cr-role-db_verifier .candidate-section .form-grid .form-control{
            grid-template-columns:1fr;
            gap:4px;
        }
    }

    /* Per-section evidence/action/upload block (used by all component sections) */
    .cr-comp-tools{margin-top:8px; border:1px solid rgba(148,163,184,0.22); border-radius:12px; padding:8px; background:#fff;}
    #section-basic .cr-comp-tools{margin-top:8px; padding:8px;}
    .form-grid > .cr-comp-tools{grid-column:1 / -1;}
    .cr-comp-tools-top{display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;}
    .cr-comp-tools-title{display:block; font-size:11px; font-weight:950; letter-spacing:.08em; text-transform:uppercase; color:#0f172a;}
    .cr-comp-evidence{margin-top:6px;}
    .cr-comp-upload-row{display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; padding-top:10px; border-top:1px solid rgba(148,163,184,0.18);}
    .cr-comp-upload-label{font-size:11px; font-weight:900; color:#475569; min-width:120px;}
    .cr-comp-file{max-width:200px; width:100%;}
    .cr-comp-actions-host{display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; padding:8px; border:1px solid rgba(148,163,184,0.22); border-radius:10px; background:#fff;}
    .cr-comp-left > div[id$="_table"]{margin-top:0 !important;}
    .cr-comp-left > .form-grid{margin-top:0 !important;}
    .cr-kv2-wrap{margin-bottom:8px;}
    #cv_basic_table .cr-kv2-wrap,
    #cv_identification_table .cr-kv2-wrap,
    #cv_education_table .cr-kv2-wrap,
    #cv_employment_table .cr-kv2-wrap{
        border:1px solid rgba(148,163,184,0.22);
        border-radius:12px;
        background:#fff;
        padding:8px;
    }
    .cr-kv2-grid{display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); column-gap:0; row-gap:0; border-left:1px solid rgba(148,163,184,0.20); border-right:1px solid rgba(148,163,184,0.20);}
    .cr-kv2-cell{position:relative; padding:6px 8px 8px; background:transparent; min-width:0;}
    .cr-kv2-cell:nth-child(2n+1){border-right:1px solid rgba(148,163,184,0.20);}
    .cr-kv2-cell:after{content:''; position:absolute; left:8px; right:8px; bottom:0; height:1px; background:rgba(148,163,184,0.20);}
    .cr-kv2-cell:nth-last-child(-n+2):after{display:none;}
    .cr-kv2-k{font-size:11px; font-weight:900; color:#475569; margin-bottom:1px;}
    .cr-kv2-v{font-size:13px; color:#0f172a; min-width:0; overflow-wrap:break-word; word-break:normal; white-space:normal;}
    .cr-record-tabs{display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;}
    .cr-record-tab{
        border:1px solid rgba(148,163,184,0.28);
        background:#fff;
        color:#0f172a;
        border-radius:999px;
        padding:7px 12px;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
        line-height:1;
    }
    .cr-record-tab:hover{background:rgba(59,130,246,0.06); border-color:rgba(59,130,246,0.28);}
    .cr-record-tab.active{background:rgba(37,99,235,0.10); border-color:rgba(37,99,235,0.34); color:#1d4ed8;}
    .cr-record-panel{display:none;}
    .cr-record-panel.active{display:block;}
    .cr-doc-uploadbox{
        border:1px solid rgba(148,163,184,0.22);
        border-radius:12px;
        background:#f8fafc;
        padding:8px;
        margin-top:4px;
    }
    .cr-doc-uploadrow{
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }
    .cr-doc-uploadbtn{
        border:1px solid rgba(148,163,184,0.32);
        background:#ffffff;
        color:#334155;
        border-radius:10px;
        padding:6px 10px;
        font-size:11px;
        font-weight:800;
        line-height:1;
    }
    .cr-doc-uploadname{
        display:inline-flex;
        align-items:center;
        gap:8px;
        width:100%;
        min-width:0;
        max-width:100%;
        border:1px solid rgba(37,99,235,0.18);
        background:rgba(255,255,255,0.98);
        color:#1d4ed8;
        border-radius:10px;
        padding:6px 10px;
        font-size:12px;
        font-weight:800;
        text-decoration:none;
    }
    .cr-doc-uploadname i{font-style:normal; opacity:.9;}
    .cr-doc-uploadname span{
        flex:1 1 auto;
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-tools{
        margin-top:10px;
        border:0;
        border-top:1px solid rgba(226,232,240,0.95);
        border-radius:0;
        padding:12px 0 0;
        background:transparent;
        box-shadow:none;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-tools-title{
        font-size:12px;
        color:#334155;
        letter-spacing:.06em;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-evidence{
        margin-top:8px;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-evidence [data-comp-docs]{
        display:flex;
        flex-direction:column;
        gap:8px;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-upload-row{
        margin-top:10px;
        padding-top:10px;
        border-top:1px solid rgba(226,232,240,0.95);
        justify-content:flex-start;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-upload-label{
        min-width:auto;
        margin-right:4px;
    }
    .cr-report-root.cr-validator-workspace .cr-comp-file{
        max-width:240px;
    }

    /* Force modal backdrop cleanup - prevents unclickable screen */
    .modal-backdrop{display:none !important;}
    .modal-backdrop.fade{display:none !important;}
    .modal-backdrop.show{display:none !important;}
    body.modal-open{overflow:auto !important; padding-right:0 !important;}
    body.modal-open .modal{overflow-y:auto !important;}
    html.modal-open{overflow:auto !important; padding-right:0 !important;}

    .cr-report-root.cr-role-verifier #section-reference .form-grid,
    .cr-report-root.cr-role-validator #section-reference .form-grid,
    .cr-report-root.cr-role-db_verifier #section-reference .form-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:10px !important;
        border:1px solid rgba(148,163,184,0.22);
        border-radius:12px;
        background:#fff;
        padding:8px !important;
        overflow:visible;
    }
    .cr-report-root.cr-role-verifier #section-reference .form-grid .form-control,
    .cr-report-root.cr-role-validator #section-reference .form-grid .form-control,
    .cr-report-root.cr-role-db_verifier #section-reference .form-grid .form-control{
        display:block;
        border:0;
        border-bottom:1px solid rgba(148,163,184,0.20);
        border-radius:0;
        padding:6px 8px !important;
        margin:0;
        background:transparent !important;
    }
    .cr-report-root.cr-role-verifier #section-reference .form-grid .form-control label,
    .cr-report-root.cr-role-validator #section-reference .form-grid .form-control label,
    .cr-report-root.cr-role-db_verifier #section-reference .form-grid .form-control label{margin:0 0 4px; font-size:11px; font-weight:900; color:#475569;}

    @media (max-width: 900px){
        .cr-comp-upload-row{flex-direction:column; align-items:stretch;}
        .cr-comp-upload-label{min-width:0;}
        .cr-comp-file{max-width:none;}
        .cr-kv2-grid{grid-template-columns:1fr;}
        .cr-kv2-cell:nth-child(2n+1){border-right:0;}
        .cr-kv2-cell:nth-last-child(-n+2):after{display:block;}
        .cr-kv2-cell:last-child:after{display:none;}
        .cr-report-root.cr-role-verifier #section-reference .form-grid,
        .cr-report-root.cr-role-validator #section-reference .form-grid,
        .cr-report-root.cr-role-db_verifier #section-reference .form-grid{grid-template-columns:1fr;}
    }

    .cr-secbar{display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-radius:16px; background:#0b1220; color:#fff; border:1px solid rgba(15,23,42,0.75);}
    .cr-secbar-title{display:flex; align-items:center; gap:8px; font-size:13px; font-weight:900; letter-spacing:.01em; margin:0;}
    .cr-secbar-meta{font-size:12px; font-weight:900; color:#86efac; white-space:nowrap;}
    .cr-upload-inline{border:1px solid rgba(148,163,184,0.22); border-radius:14px; background:linear-gradient(180deg,#ffffff,#f8fafc); padding:10px; margin-bottom:10px;}
    .cr-upload-inline h3{margin:0; font-size:12px; font-weight:950; letter-spacing:.08em; text-transform:uppercase; color:#0f172a;}
    .cr-upload-inline .card-subtitle{display:none;}
    .cr-upload-inline .cr-upload-grid{grid-template-columns: 140px 1fr auto; gap:8px;}
    .cr-upload-inline .cr-upload-help{font-size:11px; margin-top:4px;}
    .cr-upload-inline .cr-file-drop{padding:8px; border-radius:12px;}
    .cr-upload-inline .cr-file-btn{padding:7px 10px; border-radius:10px;}
    .cr-upload-inline .cr-file-meta{font-size:11px;}
    .cr-upload-inline .cr-chip{font-size:11px;}
    .cr-upload-inline #cvUploadBtn{padding:8px 12px; border-radius:12px;}

    .cr-remarks{border:1px dashed rgba(148,163,184,0.35); background:rgba(255,255,255,0.6); border-radius:12px; padding:10px; margin-top:10px;}
    .cr-remarks label{font-size:11px; margin-bottom:4px; color:#334155;}
    .cr-remarks textarea{padding:8px 10px; border-radius:12px;}
    .cr-chip{display:inline-flex; align-items:center; gap:8px;}
    .cr-chip .cr-chip-x{border:0; background:transparent; color:#1e40af; font-weight:900; cursor:pointer; padding:0; line-height:1;}

    .cr-report-root .table-scroll .table{min-width:100% !important;}
    .cr-report-root .table td{white-space:normal;}
    .cr-report-root .table td:last-child,.cr-report-root .table th:last-child{white-space:normal;}
    #cvUploadCard .card-subtitle{margin-top:4px; font-size:12px; color:#64748b;}
    #cvUploadCard h3{font-size:14px; font-weight:900;}
    #cvUploadCard label{font-size:12px; color:#334155;}
    #cvUploadBtn{background:#2563eb; border-color:#2563eb;}
    #cvUploadBtn:hover{filter:brightness(0.96);}
    .cr-hero .cr-stat{backdrop-filter: blur(8px);}
    @media (max-width: 1100px){
        .cr-shell{flex-direction:column;}
        .cr-sidebar{width:auto; position:relative; top:auto;}
        .cr-docbar{width:auto; position:relative; top:auto;}
        .cr-upload-grid{grid-template-columns:1fr;}
        .cr-sections-scroll{max-height:none; overflow:visible;}
        .cr-report-root.cr-role-validator .cr-shell{padding-left:0;}
        .cr-report-root.cr-role-validator .cr-sidebar{
            position:relative;
            top:auto;
            height:auto;
            overflow:visible;
        }
    }

    .cr-print .cr-sidebar{display:none;}
    .cr-print .cr-upload-card{display:none;}
    .cr-print .cr-actions{display:none;}
    .cr-print .cr-shell{display:none;}
    .cr-print .cr-sections-scroll{display:none;}
    .cr-print .cr-hero{display:none;}
    .cr-pdf{display:none;}
    .cr-print .cr-pdf{display:block;}
    .cr-pdf-page{background:#fff; border:1px solid rgba(15,23,42,0.15); box-shadow:0 10px 18px rgba(15,23,42,0.08); border-radius:10px; padding:18px; margin:0 auto 14px; max-width: 900px;}
    .cr-pdf-header{display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding-bottom:10px; border-bottom:2px solid #111827;}
    .cr-pdf-brand{display:flex; align-items:center; gap:10px;}
    .cr-pdf-logo{width:52px; height:52px; border-radius:10px; display:grid; place-items:center; background:#111827; color:#fff; font-weight:900; font-size:18px;}
    .cr-pdf-title{font-size:16px; font-weight:900; margin:0; color:#0f172a;}
    .cr-pdf-sub{font-size:11px; color:#475569; margin:2px 0 0;}
    .cr-pdf-meta{font-size:11px; color:#0f172a; text-align:right;}
    .cr-pdf-meta b{display:inline-block; min-width:110px; color:#334155;}
    .cr-pdf-section-title{font-size:13px; font-weight:900; margin:14px 0 8px; color:#0f172a; padding:6px 10px; background:#f1f5f9; border:1px solid rgba(148,163,184,0.45); border-radius:8px;}
    .cr-pdf-grid{display:grid; grid-template-columns: 1fr 1fr; gap:10px;}
    .cr-pdf-kv{border:1px solid rgba(148,163,184,0.45); border-radius:10px; padding:10px;}
    .cr-pdf-kv .k{font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.06em;}
    .cr-pdf-kv .v{font-size:12px; color:#0f172a; font-weight:700; margin-top:2px; word-break:break-word;}
    .cr-pdf-docs{display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:10px;}
    .cr-pdf-doc{border:1px solid rgba(148,163,184,0.45); border-radius:12px; padding:10px;}
    .cr-pdf-doc h4{margin:0 0 6px; font-size:12px; font-weight:900; color:#0f172a;}
    .cr-pdf-doc small{display:block; color:#64748b; font-size:10px;}
    .cr-pdf-thumb{margin-top:8px; border:1px solid rgba(148,163,184,0.5); border-radius:10px; overflow:hidden; background:#f8fafc;}
    .cr-pdf-thumb img{display:block; width:100%; height:auto;}
    .cr-pdf-cover{min-height: 860px; display:flex; flex-direction:column; justify-content:space-between;}
    .cr-pdf-cover .cr-pdf-cover-title{font-size:22px; font-weight:1000; margin:0; color:#0f172a;}
    .cr-pdf-cover .cr-pdf-cover-note{font-size:11px; color:#334155; line-height:1.5;}
    .cr-pdf-footer{margin-top:12px; display:flex; justify-content:space-between; font-size:10px; color:#64748b; border-top:1px solid rgba(148,163,184,0.35); padding-top:8px;}
    @media print {
        body{background:#fff !important;}
        .top-header,.sidebar,.app-footer{display:none !important;}
        .card{box-shadow:none !important; border:none !important;}
        .cr-pdf-page{box-shadow:none !important; border:none !important; border-radius:0 !important; max-width:none !important; margin:0 !important; page-break-after:always;}
        .cr-pdf-page:last-child{page-break-after:auto;}
        .cr-pdf-docs{grid-template-columns: 1fr 1fr;}
    }
    @media print {
        .cr-actions{display:none !important;}
        .cr-sidebar{display:none !important;}
        .cr-upload-card{display:none !important;}
        .cr-sections-scroll{max-height:none !important; overflow:visible !important;}
        .card{box-shadow:none !important;}
        
    }


.qa-case-review-mode .cr-compnav,
.qa-case-review-mode .cr-secbar,
.qa-case-review-mode .cr-secbar-meta {
    display: none !important;
}

/* QA mode: keep hero visible like validator/verifier workspace */
.qa-case-review-mode .cr-hero {
    display: block !important;
    padding: 8px 12px !important;
    margin-top: 10px !important;
}

/* Keep action buttons visible */
.qa-case-review-mode .cr-actions {
    display: flex !important;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
}


/* Section wrapper */
.qa-case-review-mode .candidate-section {
    padding: 0 !important;
    margin-bottom: 16px;
    border: 1px solid rgba(148,163,184,0.22);
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
}

/* Simple section header */
/* .qa-case-review-mode .candidate-section::before {
    content: attr(id);
    display: block;
    padding: 10px 12px;
    font-size: 13px;
    font-weight: 900;
    color: #0f172a;
    background: #f8fafc;
    border-bottom: 1px solid rgba(148,163,184,0.25);
    text-transform: capitalize;
} */

/* QA-only section header */
.qa-case-review-mode .candidate-section > .qa-section-head {
    padding: 10px 12px;
    font-size: 13px;
    font-weight: 900;
    color: #0f172a;
    background: #f8fafc;
    border-bottom: 1px solid rgba(148,163,184,0.25);
}

/* Kill horizontal tables */
/* .qa-case-review-mode table {
    display: none !important;
} */

/* Hide only CV data tables */
.qa-case-review-mode #cv_basic_table table,
.qa-case-review-mode #cv_identification_table table,
.qa-case-review-mode #cv_education_table table,
.qa-case-review-mode #cv_employment_table table {
    display: none !important;
}

/* Vertical KV layout */
.qa-case-review-mode .kv-vertical {
    display: block;
}

.qa-case-review-mode .kv-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid rgba(148,163,184,0.18);
    box-sizing: border-box;
}

.qa-case-review-mode .kv-row:last-child {
    border-bottom: 0;
}

.qa-case-review-mode .kv-label {
    font-size: 11px;
    font-weight: 900;
    color: #475569;
}

.qa-case-review-mode .kv-value {
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
    word-break: break-word;
}

/* QA mode: make Contact + Reference match the same key/value UI */
.qa-case-review-mode #section-contact .form-grid,
.qa-case-review-mode #section-reference .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    column-gap: 0;
    row-gap: 0;
    border: 1px solid rgba(148,163,184,0.22);
    border-radius: 12px;
    background: #fff;
    padding: 8px !important;
    overflow: hidden;
}

.qa-case-review-mode #section-contact .form-grid .form-control,
.qa-case-review-mode #section-reference .form-grid .form-control {
    position: relative;
    display: block;
    margin: 0;
    padding: 6px 8px 8px !important;
    border-radius: 0 !important;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
}

.qa-case-review-mode #section-contact .form-grid .form-control:nth-child(2n+1),
.qa-case-review-mode #section-reference .form-grid .form-control:nth-child(2n+1) {
    border-right: 1px solid rgba(148,163,184,0.20) !important;
}

.qa-case-review-mode #section-contact .form-grid .form-control::after,
.qa-case-review-mode #section-reference .form-grid .form-control::after {
    content: '';
    position: absolute;
    left: 8px;
    right: 8px;
    bottom: 0;
    height: 1px;
    background: rgba(148,163,184,0.20);
}

.qa-case-review-mode #section-contact .form-grid .form-control:nth-last-child(-n+2)::after,
.qa-case-review-mode #section-reference .form-grid .form-control:nth-last-child(-n+2)::after {
    display: none;
}

.qa-case-review-mode #section-contact .form-grid .form-control label,
.qa-case-review-mode #section-reference .form-grid .form-control label {
    margin: 0 0 2px;
    font-size: 11px;
    font-weight: 900;
    color: #475569;
}

.qa-case-review-mode #section-contact .form-grid .form-control input,
.qa-case-review-mode #section-contact .form-grid .form-control textarea,
.qa-case-review-mode #section-contact .form-grid .form-control select,
.qa-case-review-mode #section-reference .form-grid .form-control input,
.qa-case-review-mode #section-reference .form-grid .form-control textarea,
.qa-case-review-mode #section-reference .form-grid .form-control select {
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
    box-shadow: none !important;
    min-height: 0 !important;
    height: auto !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #0f172a !important;
}

/* Mobile safety */
@media (max-width: 900px) {
    .qa-case-review-mode .kv-row {
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .qa-case-review-mode #section-contact .form-grid,
    .qa-case-review-mode #section-reference .form-grid {
        grid-template-columns: 1fr;
    }

    .qa-case-review-mode #section-contact .form-grid .form-control,
    .qa-case-review-mode #section-reference .form-grid .form-control {
        border-right: 0 !important;
    }

    .qa-case-review-mode #section-contact .form-grid .form-control:nth-last-child(-n+2)::after,
    .qa-case-review-mode #section-reference .form-grid .form-control:nth-last-child(-n+2)::after {
        display: block;
    }

    .qa-case-review-mode #section-contact .form-grid .form-control:last-child::after,
    .qa-case-review-mode #section-reference .form-grid .form-control:last-child::after {
        display: none;
    }
}
/* QA Case Review iframe-only layout fix */
.qa-case-review-mode .cr-shell{
    display:flex;
    flex-direction:row;
    flex-wrap:nowrap;
    align-items:flex-start;
    gap:12px;
}

.qa-case-review-mode .cr-sidebar{
    flex:0 0 250px;
    width:250px;
    min-width:250px;
    max-width:250px;
    position:sticky;
    top:12px;
}

.qa-case-review-mode .cr-main{
    flex:1 1 auto;
    min-width:0;
}

/* Parent QA page already provides right-side remarks/timeline.
   Hide iframe utility column to match validator/verifier content width. */
.qa-case-review-mode .cr-right{
    display:none !important;
}

.qa-case-review-mode .cr-sections-scroll{
    max-height:none;
    overflow:visible;
    padding-right:2px;
}

/* Override generic <=1100px stack rule only for QA case review mode */
@media (max-width: 1100px){
    .qa-case-review-mode .cr-shell{
        flex-direction:row !important;
        align-items:flex-start;
    }
    .qa-case-review-mode .cr-sidebar{
        width:230px;
        min-width:230px;
        max-width:230px;
        position:sticky;
        top:12px;
    }
    .qa-case-review-mode .cr-main{
        min-width:0;
    }
}

/* Mobile fallback */
@media (max-width: 700px){
    .qa-case-review-mode .cr-shell{
        flex-direction:column !important;
    }
    .qa-case-review-mode .cr-sidebar{
        width:auto;
        min-width:0;
        max-width:none;
        position:relative;
        top:auto;
    }
    .qa-case-review-mode .cr-sections-scroll{
        max-height:none;
        overflow:visible;
    }
}

/* =========================
   QA Remarks – Bottom Input (QA Case Review iframe mode only)
   ========================= */

.qa-case-review-mode .cr-remarksbar {
    display: flex;
    flex-direction: column;
}

/* scrollable remarks list */
.qa-case-review-mode .qa-remarks-list {
    flex: 1 1 auto;
    overflow-y: auto;
    padding-right: 4px;
    margin-bottom: 10px;
}

/* bottom fixed input area */
.qa-case-review-mode .qa-comment-box {
    border-top: 1px solid rgba(148,163,184,0.25);
    padding-top: 8px;
    background: #fff;
}

/* input row */
.qa-case-review-mode .qa-comment-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

/* input styling */
.qa-case-review-mode .qa-input {
    width: 100%;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid rgba(148,163,184,0.35);
    font-size: 12px;
    font-weight: 600;
}

.qa-case-review-mode .qa-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.15);
}

/* add button */
.qa-case-review-mode .qa-btn {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
    font-weight: 800;
    padding: 7px 14px;
    border-radius: 10px;
    white-space: nowrap;
}

.qa-case-review-mode .qa-btn:hover {
    filter: brightness(0.95);
}

.cr-comp-file {
    font-size: 12px;
    max-width: 160px;
}

.cr-comp-file::-webkit-file-upload-button {
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #0f172a;
    cursor: pointer;
}

.cr-comp-file::-webkit-file-upload-button:hover {
    background: #e2e8f0;
}

/* Split pane document verification workspace */
.cr-splitpane-overlay{
    position:fixed;
    inset:0;
    z-index:2100;
    display:none;
    background:rgba(15,23,42,0.58);
    padding:14px;
}
.cr-splitpane-overlay.open{display:flex;}
.cr-splitpane-modal{
    width:100%;
    height:100%;
    background:#f8fafc;
    border-radius:14px;
    border:1px solid rgba(148,163,184,0.35);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.cr-splitpane-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 14px;
    border-bottom:1px solid rgba(148,163,184,0.30);
    background:#fff;
}
.cr-splitpane-title{font-size:13px; font-weight:900; color:#0f172a;}
.cr-splitpane-context{font-size:11px; color:#64748b; margin-top:2px;}
.cr-splitpane-close{
    border:1px solid rgba(148,163,184,0.45);
    background:#fff;
    border-radius:10px;
    padding:7px 10px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}
.cr-splitpane-close:hover{background:#f8fafc;}
.cr-splitpane-body{
    flex:1;
    min-height:0;
    display:grid;
    grid-template-columns: 1.4fr 1fr;
    gap:0;
}
.cr-splitpane-overlay.has-upload-preview .cr-splitpane-body{
    grid-template-columns: 1fr 1fr;
}
.cr-splitpane-pane{
    min-height:0;
    overflow:auto;
    padding:12px;
}
.cr-splitpane-pane + .cr-splitpane-pane{
    border-left:1px solid rgba(148,163,184,0.28);
    background:#fff;
}
.cr-splitpane-pane h4{
    margin:0 0 8px;
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#475569;
}
.cr-splitpane-doc{
    width:100%;
    min-height:58vh;
    border:1px solid rgba(148,163,184,0.3);
    border-radius:12px;
    background:#fff;
    overflow:hidden;
}
.cr-splitpane-doc iframe{
    width:100%;
    height:68vh;
    border:0;
}
.cr-splitpane-doc img{
    width:100%;
    height:68vh;
    object-fit:contain;
    background:#fff;
}
.cr-splitpane-doc-empty{
    padding:16px;
    color:#64748b;
    font-size:12px;
}
.cr-splitpane-drop{
    border:1px dashed rgba(37,99,235,0.40);
    border-radius:12px;
    padding:12px;
    background:rgba(37,99,235,0.04);
}
.cr-splitpane-drop.dragover{
    background:rgba(37,99,235,0.10);
    border-color:rgba(37,99,235,0.70);
}
.cr-splitpane-drop input[type="file"]{display:none;}
.cr-splitpane-droprow{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
.cr-splitpane-filebtn{
    border:1px solid rgba(37,99,235,0.42);
    background:#2563eb;
    color:#fff;
    border-radius:10px;
    padding:7px 11px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}
.cr-splitpane-filemeta{font-size:12px; color:#334155; font-weight:700;}
.cr-splitpane-reason{
    margin-top:10px;
}
.cr-splitpane-reason label{
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:#475569;
    display:block;
    margin-bottom:6px;
}
.cr-splitpane-reason textarea{
    width:100%;
    min-height:84px;
    border:1px solid rgba(148,163,184,0.36);
    border-radius:10px;
    padding:9px 10px;
    font-size:12px;
    resize:vertical;
}
.cr-splitpane-actions{
    display:flex;
    gap:8px;
    justify-content:flex-end;
    align-items:center;
    padding:10px 14px;
    border-top:1px solid rgba(148,163,184,0.30);
    background:#fff;
}
.cr-splitpane-actions .cr-action-btn{min-width:98px;}
.cr-splitpane-message{
    margin-right:auto;
    font-size:12px;
    font-weight:700;
    color:#334155;
    max-width:50%;
}
.cr-splitpane-message.error{color:#b91c1c;}
.cr-splitpane-message.success{color:#065f46;}
@media (max-width: 960px){
    .cr-splitpane-overlay{padding:8px;}
    .cr-splitpane-body{grid-template-columns:1fr;}
    .cr-splitpane-pane + .cr-splitpane-pane{border-left:0; border-top:1px solid rgba(148,163,184,0.28);}
    .cr-splitpane-doc iframe,.cr-splitpane-doc img{height:45vh;}
}

/* Draggable modeless PDF viewer */
#pdfViewer{
    position:fixed;
    top:76px;
    right:18px;
    width:min(560px, calc(100vw - 32px));
    height:min(78vh, 760px);
    z-index:2200;
    display:none;
    border:1px solid rgba(148,163,184,0.35);
    border-radius:14px;
    background:#fff;
    box-shadow:0 22px 48px rgba(15,23,42,0.28);
    overflow:hidden;
}
#pdfViewer .pv-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:9px 11px;
    background:linear-gradient(180deg,#f8fafc,#f1f5f9);
    border-bottom:1px solid rgba(148,163,184,0.30);
    cursor:move;
    user-select:none;
}
#pdfViewer .pv-title{font-size:12px; font-weight:900; color:#0f172a;}
#pdfViewer .pv-sub{font-size:11px; color:#64748b; margin-top:1px;}
#pdfViewer .pv-close{
    border:1px solid rgba(148,163,184,0.45);
    background:#fff;
    border-radius:9px;
    padding:5px 9px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}
#pdfViewer .pv-close:hover{background:#f8fafc;}
#pdfViewer .pv-body{
    display:flex;
    flex-direction:column;
    height:calc(100% - 48px);
}
#pdfViewer iframe{
    width:100%;
    height:100%;
    min-height:360px;
    border:0;
    background:#fff;
}
#pdfViewer .pv-actions{
    border-top:1px solid rgba(148,163,184,0.30);
    padding:8px 10px;
    background:#fff;
}
#pdfViewer .pv-reason{
    width:100%;
    min-height:58px;
    border:1px solid rgba(148,163,184,0.38);
    border-radius:9px;
    padding:8px 10px;
    font-size:12px;
    resize:vertical;
}
#pdfViewer .pv-actrow{
    margin-top:8px;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
#pdfViewer .pv-msg{
    font-size:12px;
    font-weight:700;
    color:#334155;
    margin-right:auto;
}
#pdfViewer .pv-msg.error{color:#b91c1c;}
#pdfViewer .pv-msg.success{color:#065f46;}
@media (max-width: 900px){
    #pdfViewer{
        top:66px;
        right:8px;
        left:8px;
        width:auto;
        height:72vh;
    }
}

.viewer-content {
    display: flex;
    height: 100%;
}

.viewer-left {
    width: 100%;
}

.viewer-right {
    width: 40%;
    border-left: 1px solid #ddd;
    padding: 10px;
}

.viewer-content.split .viewer-left {
    width: 60%;
}

#pdfViewer {
    position: fixed !important;
    top: 80px;
    left: 100px;
    transform: none !important; /* VERY IMPORTANT */
    width: 900px;
    height: 600px;
    z-index: 99999;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
}

#pdfViewer {
    position: fixed;
    top: 80px;
    left: 100px;
    width: 900px;
    height: 600px;
    background: #fff;
    z-index: 99999;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
    resize: both;
    overflow: hidden;
}

#pdfViewerContent {
    display: flex;
    height: calc(100% - 50px);
}

#pdfViewerFrame {
    width: 100%;
    height: 100%;
    border: none;
}

#pdfViewerContent.split #pdfViewerFrame {
    width: 50%;
}

#uploadPane {
    display: none;
    width: 50%;
    height: 100%;
    border-left: 1px solid #ddd;
}

#dropZone {
    border: 2px dashed #ccc;
    height: 40%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

#uploadPreview {
    height: 60%;
}

#cvSplitPaneOverlay .cr-splitpane-container {
    resize: both;
    overflow: auto;
    min-width: 600px;
    min-height: 400px;
}

.cv-docviewer-overlay{
    position:fixed;
    inset:0;
    display:none;
    z-index:9999;
    background:rgba(15,23,42,0.45);
    pointer-events:none;
}
.cv-docviewer-overlay.open{
    display:block;
    pointer-events:auto;
}
.cv-docviewer-modal{
    position:fixed;
    top:72px;
    left:80px;
    width:min(1040px, calc(100vw - 140px));
    height:min(78vh, 760px);
    min-width:620px;
    min-height:420px;
    resize:none;
    overflow:hidden;
    z-index:10000;
    pointer-events:auto;
    border:1px solid rgba(148,163,184,0.35);
    border-radius:14px;
    background:#fff;
    box-shadow:0 24px 56px rgba(15,23,42,0.3);
    display:flex;
    flex-direction:column;
}
.cv-docviewer-modal.is-maximized{
    inset:0;
    width:auto !important;
    height:auto !important;
    resize:none;
    border-radius:0;
}
.cv-docviewer-modal.is-minimized{
    height:50px !important;
    min-height:50px !important;
    resize:none;
}
.cv-docviewer-modal.is-minimized .cv-docviewer-split,
.cv-docviewer-modal.is-minimized .cv-docviewer-footer{
    display:none;
}
.cv-docviewer-header{
    position:sticky;
    top:0;
    z-index:10;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:linear-gradient(180deg,#f8fafc,#eef2f7);
    border-bottom:1px solid rgba(148,163,184,0.3);
    cursor:move;
    user-select:none;
}
.cv-docviewer-title{
    font-size:12px;
    font-weight:900;
    color:#0f172a;
}
.cv-docviewer-actions{
    display:flex;
    gap:6px;
}
.cv-docviewer-btn{
    border:1px solid rgba(148,163,184,0.45);
    background:#fff;
    border-radius:8px;
    min-width:30px;
    height:28px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
    color:#0f172a;
}
.cv-docviewer-btn.danger{
    border-color:rgba(239,68,68,0.4);
    color:#b91c1c;
}
.cv-docviewer-split{
    flex:1;
    min-height:0;
    display:flex;
    height:100%;
}
.cv-docviewer-pane{
    min-height:0;
    min-width:0;
    flex:1 1 0;
    height:100%;
    border-right:0;
    background:#fff;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
}
.cv-docviewer-pane object,
.cv-docviewer-pane iframe{
    width:100%;
    height:100%;
    border:0;
}
.cv-docviewer-pane img{
    width:100%;
    height:100%;
    object-fit:contain;
    background:#fff;
}
.cv-docviewer-empty{
    padding:14px;
    font-size:12px;
    color:#64748b;
}
.cv-docviewer-upload{
    display:none;
    flex:1 1 0;
    height:100%;
    border-left:1px solid rgba(148,163,184,0.26);
    background:#f8fafc;
    min-width:0;
}
.cv-docviewer-upload .cv-docviewer-pane{
    flex:1 1 0;
    height:100%;
}
.cv-docviewer-upload-head{
    display:flex;
    justify-content:flex-end;
    padding:6px;
}
.cv-docviewer-upload-remove{
    border:1px solid rgba(239,68,68,0.45);
    background:#fff;
    color:#b91c1c;
    border-radius:8px;
    padding:4px 8px;
    font-size:11px;
    font-weight:800;
    cursor:pointer;
}
.cv-docviewer-split.is-split > .cv-docviewer-pane,
.cv-docviewer-split.is-split > .cv-docviewer-upload{
    flex:1 1 0;
}
.cv-docviewer-split.is-split .cv-docviewer-upload{
    display:flex;
    flex-direction:column;
}
.cv-docviewer-footer{
    position:relative;
    z-index:5;
    border-top:1px solid rgba(148,163,184,0.25);
    padding:8px 10px;
    background:#fff;
    display:flex;
    justify-content:flex-end;
}
.cv-docviewer-upload-btn{
    border:1px solid rgba(37,99,235,0.42);
    background:#2563eb;
    color:#fff;
    border-radius:8px;
    padding:6px 10px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}
#cvDocViewerUploadInput{display:none;}
.cv-docviewer-resize{
    position:absolute;
    z-index:12;
}
.cv-docviewer-resize.right{
    top:0;
    right:0;
    width:8px;
    height:100%;
    cursor:e-resize;
}
.cv-docviewer-resize.bottom{
    left:0;
    bottom:0;
    width:100%;
    height:8px;
    cursor:s-resize;
}
.cv-docviewer-resize.corner{
    right:0;
    bottom:0;
    width:14px;
    height:14px;
    cursor:se-resize;
}

/* ===========================
   Connected Enterprise Cockpit
   (validator/verifier workspace only)
   =========================== */
.page-content{overflow:hidden;}
.cr-report-root.cr-validator-workspace{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    box-shadow:none;
    border-radius:12px;
}
.cr-report-root.cr-validator-workspace .cr-shell.cr-layout.cr-page{
    display:grid;
    grid-template-columns:260px minmax(0,1fr) 320px;
    gap:0;
    margin-top:16px;
    align-items:start;
    border-top:1px solid #e5e7eb;
}
.cr-report-root.cr-validator-workspace .cr-sections{
    grid-column:1;
    position:sticky;
    top:12px;
    max-height:calc(100vh - 120px);
    overflow-y:auto;
    border:0 !important;
    border-right:1px solid #e5e7eb !important;
    border-radius:0 !important;
    box-shadow:none !important;
    background:#fff !important;
    padding:16px !important;
}
.cr-report-root.cr-validator-workspace .cr-content{
    grid-column:2;
    min-width:0;
    overflow-y:auto;
    max-height:calc(100vh - 120px);
    border:0;
    background:#fff;
    padding:0;
}
.cr-report-root.cr-validator-workspace .cr-right{
    grid-column:3;
    min-width:0;
    overflow-y:auto;
    max-height:calc(100vh - 120px);
    border-left:1px solid #e5e7eb;
    background:#fff;
    padding:0;
}
.cr-report-root.cr-validator-workspace .cr-main{
    gap:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-review-actionbar{
    position:sticky;
    top:0;
    z-index:30;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:12px 16px;
    border-bottom:1px solid #e5e7eb;
    background:#fff;
}
.cr-report-root.cr-validator-workspace #crReviewActionbar{
    display:none !important; /* keep JS-bound original buttons in DOM, but hidden */
}
.cr-report-root.cr-validator-workspace .cr-review-actionbar-title{
    font-size:12px;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#6b7280;
    font-weight:800;
}
.cr-report-root.cr-validator-workspace .cr-secbar{
    align-items:center !important;
}
.cr-report-root.cr-validator-workspace .cr-secbar-meta{
    display:none !important; /* remove TAT badge from section header */
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions{
    margin-left:auto;
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
    max-width:60%;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action{
    height:30px;
    padding:0 10px;
    border-radius:8px;
    font-size:11px;
    font-weight:700;
    border:1px solid #d1d5db;
    background:#fff;
    color:#374151;
    white-space:nowrap;
    line-height:1;
    cursor:pointer;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action:disabled{
    opacity:.5;
    cursor:not-allowed;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action.need-docs{
    background:#fff;
    border-color:#d1d5db;
    color:#374151;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action.hold{
    background:#fef3c7;
    border-color:#f59e0b;
    color:#92400e;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action.reject{
    background:#dc2626;
    border-color:#b91c1c;
    color:#fff;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action.approve{
    background:#16a34a;
    border-color:#15803d;
    color:#fff;
}
.cr-report-root.cr-validator-workspace .cr-secbar-actions .cr-sec-action.reopen{
    background:#eef2ff;
    border-color:#6366f1;
    color:#3730a3;
}
.cr-report-root.cr-validator-workspace .cr-lock-state-hint{
    margin-top:4px;
    display:block;
    font-size:11px;
    line-height:1.3;
    font-weight:700;
}
.cr-report-root.cr-validator-workspace .cr-lock-state-hint.is-locked{color:#b45309;}
.cr-report-root.cr-validator-workspace .cr-lock-state-hint.is-reopened{color:#4338ca;}
.cr-report-root.cr-validator-workspace .cr-lock-state-hint.is-relocked{color:#0f766e;}
@media (max-width: 1180px){
    .cr-report-root.cr-validator-workspace .cr-secbar-actions{
        max-width:100%;
    }
}
.cr-report-root.cr-validator-workspace .cr-sections-scroll{
    border:0 !important;
    border-radius:0 !important;
    box-shadow:none !important;
    background:#fff !important;
    padding:16px !important;
    max-height:none !important;
    overflow:visible !important;
}
.cr-report-root.cr-validator-workspace .candidate-section{
    margin:0 !important;
    border:1px solid #e5e7eb !important;
    border-radius:10px !important;
    box-shadow:none !important;
    background:#fff !important;
}
.cr-report-root.cr-validator-workspace .candidate-section + .candidate-section{
    margin-top:16px !important;
}

/* Center Review Worksheet Redesign (content area only) */
.cr-report-root.cr-validator-workspace .cr-content .cr-sections-scroll{
    padding:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section{
    border:1px solid #e5e7eb !important;
    border-radius:10px !important;
    background:#fff !important;
    box-shadow:none !important;
    margin-top:12px !important;
    overflow:hidden;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-secbar{
    border:0 !important;
    border-bottom:1px solid #e5e7eb !important;
    border-radius:0 !important;
    background:#fff !important;
    padding:12px 16px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-secbar-title{
    font-size:15px !important;
    font-weight:800 !important;
    color:#111827 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-secbar-sub{
    font-size:12px !important;
    color:#6b7280 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-secbar-actions{
    max-width:none !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-wrap{
    border:0 !important;
    border-radius:0 !important;
    padding:0 !important;
    margin:0 !important;
    background:#fff !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-grid{
    border:0 !important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr) !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell{
    border-right:0 !important;
    padding:10px 16px !important;
    border-bottom:1px solid #eef2f7 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell:after{
    display:none !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-k{
    font-size:11px !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    color:#6b7280 !important;
    font-weight:700 !important;
    margin-bottom:4px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-v{
    font-size:13px !important;
    font-weight:700 !important;
    color:#0f172a !important;
}

/* Single-record sheet rows */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-grid{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:0 !important;
    border-top:0 !important;
    margin-top:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-control{
    border:0 !important;
    border-radius:0 !important;
    box-shadow:none !important;
    padding:10px 16px !important;
    margin:0 !important;
    border-bottom:1px solid #eef2f7 !important;
    background:#fff !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-control label{
    margin:0 0 4px 0 !important;
    font-size:11px !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    color:#6b7280 !important;
    font-weight:700 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-control input,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-control textarea,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-control select{
    border:0 !important;
    border-radius:0 !important;
    background:transparent !important;
    padding:0 !important;
    min-height:auto !important;
    font-size:13px !important;
    color:#0f172a !important;
    font-weight:700 !important;
}

/* Multi-record worksheet with tabbed records and split detail feel */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-record-tabs{
    margin:0 !important;
    padding:8px 12px !important;
    border-bottom:1px solid #e5e7eb !important;
    background:#f8fafc !important;
    gap:8px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-record-tab{
    border:1px solid #dbe3ee !important;
    background:#fff !important;
    border-radius:8px !important;
    padding:5px 10px !important;
    font-size:12px !important;
    font-weight:700 !important;
    color:#334155 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-record-tab.active{
    border-color:#2563eb !important;
    color:#1e3a8a !important;
    background:#eff6ff !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-record-panel{
    margin:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-kv2-wrap{
    display:grid;
    grid-template-columns:minmax(0,1.1fr) minmax(260px,.9fr);
    column-gap:16px;
    padding:12px 16px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-kv2-wrap > div:first-child{
    grid-column:1 / -1;
    margin-bottom:8px !important;
    font-size:12px !important;
    color:#475569 !important;
    font-weight:800 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-kv2-grid{
    grid-column:1 / -1;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-kv2-cell:has(.cr-doc-uploadbox){
    background:#f9fafb !important;
    border:1px solid #e5e7eb !important;
    border-radius:8px !important;
    margin-top:2px;
}

/* Evidence / remark area as clean footer row */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-remarks{
    margin:0 !important;
    border-top:1px solid #e5e7eb !important;
    border-left:0 !important;
    border-right:0 !important;
    border-bottom:0 !important;
    border-radius:0 !important;
    background:#fcfcfd !important;
    padding:12px 16px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-remarks label{
    font-size:11px !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    color:#6b7280 !important;
}

/* Compact documents utility row: reduce oversized upload/file blocks */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadbox{
    border:0 !important;
    border-radius:0 !important;
    background:transparent !important;
    padding:0 !important;
    margin:0 !important;
    box-shadow:none !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadrow{
    display:flex !important;
    align-items:center !important;
    gap:6px !important;
    flex-wrap:wrap !important;
    min-height:24px;
    border:0 !important;
    border-radius:0 !important;
    padding:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    margin:0 !important;
    border-bottom:1px solid #eef2f7 !important;
    padding-bottom:4px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadbtn{
    padding:2px 7px !important;
    height:20px !important;
    border-radius:999px !important;
    font-size:10px !important;
    line-height:1.2 !important;
    background:transparent !important;
    color:#475569 !important;
    border:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadname{
    min-height:20px !important;
    padding:0 !important;
    border-radius:0 !important;
    border:0 !important;
    font-size:12px !important;
    line-height:1.2 !important;
    display:inline-flex !important;
    align-items:center !important;
    gap:5px !important;
    max-width:100%;
    background:transparent !important;
    color:#0f172a !important;
    text-decoration:none !important;
    flex:1 1 auto;
    min-width:0;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadname span{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:280px;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadname i{
    font-style:normal !important;
    font-size:0 !important;
    width:16px;
    height:16px;
    display:inline-block;
    border-radius:4px;
    background:#dbeafe;
    position:relative;
    flex:0 0 auto;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-doc-uploadname i::before{
    content:"";
    position:absolute;
    left:4px;
    top:3px;
    width:8px;
    height:10px;
    border:1.5px solid #2563eb;
    border-top-width:3px;
    border-radius:1px;
    box-sizing:border-box;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-file-actions{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-left:auto;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-file-action{
    font-size:11px;
    color:#2563eb;
    text-decoration:none;
    padding:1px 4px;
    border-radius:4px;
    border:0;
    background:transparent;
    line-height:1.2;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-file-action:hover{
    background:#eff6ff;
}

/* Identification uploaded file as supporting inline metadata row (no standalone container) */
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-kv2-cell:has(.cr-doc-uploadname){
    grid-column:1 / -1 !important;
    padding:6px 16px 10px !important;
    background:transparent !important;
    border-bottom:1px solid #eef2f7 !important;
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-kv2-cell:has(.cr-doc-uploadname) .cr-kv2-k{
    margin-bottom:3px !important;
    color:#64748b !important;
    font-size:10px !important;
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-doc-uploadbox{
    display:contents !important; /* remove outer uploaded-file wrapper box entirely */
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-doc-uploadrow{
    border:0 !important;
    background:transparent !important;
    border-radius:0 !important;
    padding:0 !important;
    min-height:22px !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-doc-uploadbtn{
    display:none !important; /* hide tag chip, keep only metadata row */
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-doc-uploadname{
    flex:1 1 auto;
    min-width:0;
}
.cr-report-root.cr-validator-workspace .cr-content #cv_identification_table .cr-file-actions{
    margin-left:auto;
    gap:8px;
}

/* Evidence upload as slim footer toolbar */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools{
    padding:8px 12px !important;
    background:#f8fafc !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools .cr-tools-head{
    margin:0 0 6px 0 !important;
    font-size:11px !important;
    color:#64748b !important;
    letter-spacing:.04em;
    text-transform:uppercase;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools .cr-tools-actions{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    flex-wrap:wrap !important;
    margin:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools input[type="file"]{
    font-size:12px !important;
    padding:2px !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools .btn,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools button{
    height:30px !important;
    padding:0 12px !important;
    border-radius:7px !important;
    font-size:12px !important;
    font-weight:700 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools .cr-tools-note{
    margin-top:4px !important;
    font-size:11px !important;
    color:#64748b !important;
}

@media (max-width: 1100px){
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-multi-record .cr-kv2-wrap{
        grid-template-columns:1fr !important;
    }
}
@media (max-width: 860px){
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-grid,
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section.cr-single-record .form-grid{
        grid-template-columns:1fr !important;
    }
}

/* Unified 3-column center worksheet grid across all components */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-grid{
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    gap:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .form-grid{
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    gap:0 !important;
}

/* Default cell rhythm */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .form-control{
    padding:10px 16px !important;
    border-bottom:1px solid #eef2f7 !important;
}

/* Medium fields can span two columns (document/file style cells) */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell:has(.cr-doc-uploadbox){
    grid-column:span 2;
}

/* Long fields and narrative sections span full width */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell:has(.cr-doc-uploadbox) + .cr-kv2-cell:has(.cr-doc-uploadbox){
    grid-column:1 / -1;
}
.cr-report-root.cr-validator-workspace .cr-content #section-contact .form-control:has(#cv_contact_current_address),
.cr-report-root.cr-validator-workspace .cr-content #section-contact .form-control:has(#cv_contact_permanent_address),
.cr-report-root.cr-validator-workspace .cr-content #section-socialmedia .form-control:has(#cv_social_content),
.cr-report-root.cr-validator-workspace .cr-content #section-ecourt .form-control:has(#cv_ecourt_comments),
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-remarks,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-comp-tools{
    grid-column:1 / -1 !important;
}

/* Stabilize dense single-record sections in shared report (validator/verifier/qa). */
#section-socialmedia .form-grid,
#section-ecourt .form-grid,
#section-reports .form-grid{
    grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    gap:0 !important;
}
#section-socialmedia .form-control,
#section-ecourt .form-control,
#section-reports .form-control{
    min-width:0;
}
#section-socialmedia .form-control input,
#section-ecourt .form-control input,
#section-reports .form-control input,
#section-socialmedia .form-control textarea,
#section-ecourt .form-control textarea,
#section-reports .form-control textarea{
    white-space:normal;
    word-break:normal;
    overflow-wrap:break-word;
}
/* Social media URLs are long/unbroken, allow aggressive wrapping only there. */
#section-socialmedia .cr-kv2-v,
#section-socialmedia .cr-kv2-v span{
    min-width:0;
    white-space:normal !important;
    overflow-wrap:anywhere !important;
    word-break:break-word !important;
}
#section-socialmedia .form-control:has(#cv_social_content),
#section-ecourt .form-control:has(#cv_ecourt_comments),
#section-reports .form-control:has(#cvRemarksReports){
    grid-column:1 / -1 !important;
}
@media (max-width: 860px){
    #section-socialmedia .form-grid,
    #section-ecourt .form-grid,
    #section-reports .form-grid{
        grid-template-columns:1fr !important;
    }
}

/* Keep labels above values consistently */
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .form-control label,
.cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-k{
    display:block;
    margin-bottom:4px !important;
}

/* Responsive behavior */
@media (max-width: 1280px){
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-grid,
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .form-grid{
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 860px){
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-grid,
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .form-grid{
        grid-template-columns:1fr !important;
    }
    .cr-report-root.cr-validator-workspace .cr-content .candidate-section .cr-kv2-cell:has(.cr-doc-uploadbox){
        grid-column:1 / -1 !important;
    }
}
.cr-report-root.cr-validator-workspace .cr-secbar{
    border-radius:10px 10px 0 0 !important;
    border:0 !important;
    border-bottom:1px solid #e5e7eb !important;
    background:#fcfcfd !important;
    padding:12px 14px !important;
}
.cr-report-root.cr-validator-workspace .candidate-section > .form-grid,
.cr-report-root.cr-validator-workspace .candidate-section > div[id$="_table"]{
    padding:16px !important;
    margin-top:0 !important;
}
.cr-report-root.cr-validator-workspace .cr-kv2-wrap{
    border:1px solid #e5e7eb !important;
    border-radius:10px !important;
    box-shadow:none !important;
}

/* Component list density + active state */
.cr-report-root.cr-validator-workspace .cr-nav{gap:8px !important;}
.cr-report-root.cr-validator-workspace .cr-nav .list-group-item{
    border:1px solid #e5e7eb !important;
    border-radius:10px !important;
    padding:10px 12px !important;
    min-height:52px !important;
    background:#fff !important;
}
.cr-report-root.cr-validator-workspace .cr-nav .list-group-item.active{
    border-color:#2563eb !important;
    background:rgba(37,99,235,0.06) !important;
    box-shadow:inset 3px 0 0 #2563eb !important;
}

/* Utility right rail as connected tabbed zone */
.cr-report-root.cr-validator-workspace .cr-utility-panel{
    border:0 !important;
    border-radius:0 !important;
    box-shadow:none !important;
    padding:0 !important;
    margin:0 !important;
    background:#fff !important;
}
.cr-report-root.cr-validator-workspace .cr-util-tabs{
    position:sticky;
    top:0;
    z-index:5;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    margin:0;
    border-bottom:1px solid #e5e7eb;
    background:#fff;
}
.cr-report-root.cr-validator-workspace .cr-util-tabs .nav-item{margin:0;}
.cr-report-root.cr-validator-workspace .cr-util-tabs .nav-link{
    width:100%;
    border:0;
    border-right:1px solid #e5e7eb;
    border-radius:0;
    padding:10px 8px;
    font-size:12px;
    font-weight:700;
    color:#6b7280;
    background:#fff;
}
.cr-report-root.cr-validator-workspace .cr-util-tabs .nav-item:last-child .nav-link{
    border-right:0;
}
.cr-report-root.cr-validator-workspace .cr-util-tabs .nav-link.active{
    color:#0f172a;
    background:#f8fafc;
    box-shadow:inset 0 -2px 0 #2563eb;
}
.cr-report-root.cr-validator-workspace .cr-util-content{
    padding:16px;
}
.cr-report-root.cr-validator-workspace .cr-util-scroll{
    max-height:360px;
    overflow:auto;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:10px;
    background:#f9fafb;
}

/* Dynamic tools block style */
.cr-comp-tools{
    margin-top:20px !important;
    padding:14px !important;
    border-radius:10px !important;
    background:#f9fafb !important;
    border:1px solid #e5e7eb !important;
    box-shadow:none !important;
}

/* Action hierarchy */
.cr-report-root.cr-validator-workspace #cvValidatorActionApprove{
    background:#16a34a !important;
    border:1px solid #15803d !important;
    color:#fff !important;
}
.cr-report-root.cr-validator-workspace #cvValidatorActionReject{
    background:#dc2626 !important;
    border:1px solid #b91c1c !important;
    color:#fff !important;
}
.cr-report-root.cr-validator-workspace #cvValidatorActionHold{
    background:#f3f4f6 !important;
    border:1px solid #d1d5db !important;
    color:#111827 !important;
}
.cr-report-root.cr-validator-workspace #cvValidatorActionInsufficient{
    background:#ffffff !important;
    border:1px solid #d1d5db !important;
    color:#374151 !important;
}

/* Keep only integrated utility rail on right for this cockpit */
.cr-report-root.cr-validator-workspace .cr-docbar{
    display:none !important;
}

/* Compact enterprise density */
.cr-report-root.cr-validator-workspace .cr-case-actions-head,
.cr-report-root.cr-validator-workspace .cr-sidebar-title{
    font-size:11px !important;
    letter-spacing:.08em !important;
    text-transform:uppercase !important;
    color:#6b7280 !important;
    font-weight:800 !important;
}

@media (max-width: 1280px){
    .cr-report-root.cr-validator-workspace .cr-shell.cr-layout.cr-page{
        grid-template-columns:230px minmax(0,1fr) 300px;
    }
}
@media (max-width: 1080px){
    .cr-report-root.cr-validator-workspace .cr-shell.cr-layout.cr-page{
        grid-template-columns:220px minmax(0,1fr);
    }
    .cr-report-root.cr-validator-workspace .cr-right{
        grid-column:1 / -1;
        border-left:0;
        border-top:1px solid #e5e7eb;
        max-height:none;
    }
}
@media (max-width: 860px){
    .cr-report-root.cr-validator-workspace .cr-shell.cr-layout.cr-page{
        grid-template-columns:1fr;
    }
    .cr-report-root.cr-validator-workspace .cr-sections{
        position:relative;
        top:auto;
        max-height:none;
        border-right:0 !important;
        border-bottom:1px solid #e5e7eb !important;
    }
    .cr-report-root.cr-validator-workspace .cr-content,
    .cr-report-root.cr-validator-workspace .cr-right{
        max-height:none;
        overflow-y:visible;
    }
}
@media (max-width: 900px){
    .cv-docviewer-modal{
        top:12px;
        left:12px;
        width:calc(100vw - 24px);
        height:calc(100vh - 24px);
        min-width:0;
        min-height:0;
        resize:none;
    }
}
</style>
<?php
$workspaceRoles = in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true);
$enableWorkspaceClass = $workspaceRoles && !$isPrint && (!$isEmbed || $role === 'qa' || $role === 'team_lead');
?>
<div class="card cr-report-root cr-role-<?php echo htmlspecialchars($role); ?><?php echo $isPrint ? ' cr-print' : ''; ?><?php echo $enableWorkspaceClass ? ' cr-validator-workspace' : ''; ?>" data-ui-ready="<?php echo $isPrint ? '1' : '0'; ?>">
    <!-- <h3>Candidate Report</h3>
    <p class="card-subtitle">Individual candidate report with quick navigation across all verification sections.</p> -->

    <div id="cvTopMessage" style="display:none; margin-top:10px;"></div>

<?php if (in_array($role, ['verifier', 'validator', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
    <div class="modal fade" id="cvMailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Workflow Communication</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cvMailMessage" style="display:none; margin-bottom:10px;"></div>
                    <div style="display:grid; grid-template-columns:320px 1fr; gap:12px;">
                        <div style="border:1px solid rgba(148,163,184,.28); border-radius:12px; padding:10px;">
                            <div style="font-size:12px; font-weight:900; color:#334155; margin-bottom:8px;">Actions</div>
                            <div id="cvCommActionCards" style="display:flex; flex-direction:column; gap:8px;"></div>
                            <hr style="margin:10px 0;">
                            <div style="font-size:12px; font-weight:900; color:#334155; margin-bottom:8px;">Insufficiency Checklist</div>
                            <div id="cvCommChecklist" style="max-height:220px; overflow:auto; border:1px solid rgba(148,163,184,.24); border-radius:10px; padding:8px; background:#f8fafc;"></div>
                            <div style="margin-top:8px;">
                                <label style="font-size:12px; font-weight:700;">Candidate Deadline</label>
                                <select id="cvCommDeadline" class="form-control">
                                    <option value="24h">Within 24 hours</option>
                                    <option value="48h">Within 48 hours</option>
                                    <option value="72h">Within 72 hours</option>
                                    <option value="5d">Within 5 days</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-rows:auto auto 1fr auto; gap:10px;">
                            <div class="form-grid" style="margin-top:0;">
                                <div class="form-control" style="grid-column:1/-1;">
                                    <label>To Email *</label>
                                    <input type="text" id="cvMailToEmail" placeholder="recipient@example.com">
                                </div>
                                <div class="form-control" style="grid-column:1/-1;">
                                    <label>Subject</label>
                                    <input type="text" id="cvMailSubject" placeholder="(auto from workflow action)">
                                </div>
                            </div>
                            <div class="form-control" style="grid-column:1/-1;">
                                <label>Additional Notes</label>
                                <textarea id="cvCommNotes" rows="3" placeholder="Add operational notes, mismatch context, or instructions."></textarea>
                            </div>
                            <div class="form-control" style="grid-column:1/-1;">
                                <label>Preview</label>
                                <div id="cvMailPreview" style="position:sticky; top:0; border:1px solid rgba(148,163,184,0.35); border-radius:12px; padding:10px; background:#fff; min-height:180px; max-height:320px; overflow:auto;"></div>
                            </div>
                            <div class="form-control" style="grid-column:1/-1;">
                                <label>Recent Communications</label>
                                <div id="cvCommHistory" style="border:1px solid rgba(148,163,184,0.26); border-radius:10px; padding:8px; max-height:160px; overflow:auto; background:#f8fafc;"></div>
                            </div>
                        </div>
                    </div>
                    <select id="cvMailTemplateSelect" style="display:none;"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-secondary" id="cvCommSaveDraftBtn">Save Draft</button>
                    <button type="button" class="btn btn-outline-primary" id="cvCommReuseLastBtn">Reuse Last</button>
                    <button type="button" class="btn btn-outline-primary" id="cvCommResendLastBtn">Resend Last</button>
                    <button type="button" class="btn" id="cvMailSendBtn">Send</button>
                </div>
            </div>
        </div>

    </div>

    <?php if (in_array($role, ['validator', 'verifier', 'qa'], true)): ?>
    <div class="modal fade" id="cvVerificationMailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cvVerificationMailTitle">Send Verification Mail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cvVerificationMailMessage" style="display:none; margin-bottom:10px;"></div>
                    <div class="form-grid" style="margin-top:0;">
                        <div class="form-control" style="grid-column:1/-1;">
                            <label>Recipient Email *</label>
                            <input type="text" id="cvVerificationMailTo" placeholder="recipient@example.com">
                        </div>
                        <div class="form-control" style="grid-column:1/-1;">
                            <label>Subject</label>
                            <input type="text" id="cvVerificationMailSubject" placeholder="Verification request">
                        </div>
                        <div class="form-control" style="grid-column:1/-1;">
                            <label>Message</label>
                            <textarea id="cvVerificationMailBody" rows="7" placeholder="Verification request message..."></textarea>
                        </div>
                        <div class="form-control" style="grid-column:1/-1;">
                            <label>Remarks (Optional)</label>
                            <textarea id="cvVerificationMailRemarks" rows="2" placeholder="Add optional remarks for audit trail."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="cvVerificationMailSendBtn">Send</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="cvCorrectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Candidate Correction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cvCorrectionMessage" style="display:none; margin-bottom:10px;"></div>
                    <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Select components</div>
                    <div id="cvCorrectionComponents" style="display:grid; gap:8px; margin-bottom:10px;"></div>
                    <div class="mb-2">
                        <label for="cvCorrectionReason" style="font-size:12px; color:#475569;">Reason</label>
                        <textarea id="cvCorrectionReason" class="form-control" rows="3" placeholder="Enter correction reason"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="cvCorrectionSendBtn">Send Correction Request</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

    <div class="cr-hero cr-validator-case-strip" style="margin-top:10px;">
        <div class="cr-hero-layout">
            <!-- <div class="cr-hero-row cr-hero-row-copy">
                <div class="cr-hero-head">
                    <div class="cr-validator-case-copy">
                        <h2 class="cr-hero-title">Candidate Review Workspace</h2>
                        <div class="cr-hero-sub">Review submitted details, evidence, and section-level decisions.</div>
                        <span id="cvHeaderClient" style="display:none;">-</span>
                    </div>
                </div>
            </div> -->

            <div class="cr-hero-row cr-hero-row-kpis">
                <div class="cr-stat-row cr-validator-case-grid">
                    <div class="cr-stat">
                        <div class="cr-avatar" id="cvHeaderAvatar" aria-label="Candidate photo">
                            <span class="cr-avatar-fallback" id="cvHeaderAvatarFallback">NA</span>
                        </div>
                    </div>
                    <div class="cr-stat"><b>Candidate</b><span id="cvHeaderCandidate"></span></div>
                    <div class="cr-stat"><b>Application</b><span id="cvHeaderAppId"></span></div>
                    <div class="cr-stat"><b>Status</b><span id="cvHeaderStatus"></span></div>
                    <div class="cr-stat"><b>TAT</b><span id="cvHeaderTat">-</span></div>
                    <div class="cr-stat"><b>Reviewed</b><span id="cvHeaderReviewed">0 / 0 sections</span></div>
                </div>
            </div>

            <div class="cr-hero-row cr-hero-row-actions">
                <div class="cr-hero-toolbar">
                    <div class="cr-actions cr-actions-primary">
                        <?php if (in_array($role, ['verifier', 'validator', 'qa', 'gss_admin', 'client_admin', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                            <button type="button" class="cr-action-btn" id="cvOpenCorrectionModal">Request Candidate Correction</button>
                        <?php endif; ?>
                    </div>
                    <div class="cr-actions cr-actions-secondary">
                        <?php if (in_array($role, ['verifier', 'validator', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                            <button type="button" class="cr-action-btn" id="cvOpenMailModal">Mail</button>
                        <?php endif; ?>
                        <?php if (in_array($role, ['verifier', 'validator', 'qa', 'gss_admin', 'client_admin', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                            <button type="button" class="cr-action-btn" id="cvResendCandidateAccessBtn" style="display:none;">Resend Candidate Access</button>
                        <?php endif; ?>
                        <?php if ($backToListHref !== '' && !$isPrint && !$isEmbed): ?>
                            <a href="<?= htmlspecialchars($backToListHref) ?>" class="cr-action-btn">Back To List</a>
                        <?php endif; ?>
                        <?php if ($role === 'verifier' && !$isPrint && !$isEmbed): ?>
                            <button type="button" class="cr-action-btn" id="cvPrintLetterBtn">Print Letter</button>
                        <?php endif; ?>
                        <?php if ($role === 'client_admin' && !$isPrint && !$isEmbed): ?>
                            <button type="button" class="cr-action-btn cr-dark" id="cvEscalateTlBtn">Escalate To Team Lead</button>
                            <button type="button" class="cr-action-btn cr-danger" id="cvEscalateGssBtn">Escalate To GSS Admin</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cr-resend-helper">
                    <span id="cvResendMetaBadge" style="display:none;">Resend info</span>
                </div>
            </div>
        </div>
    </div>

    <div class="cr-compnav" id="cvComponentNav" aria-label="Components">
        <div class="cr-compnav-title">Components</div>
        <div id="cvComponentNavItems" style="display:flex; gap:8px; flex-wrap:wrap;"></div>
    </div>
    <!-- <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
    <div class="cr-review-tabs" id="cvReviewTabs" aria-label="Review Sections">
        <button type="button" class="cr-review-tab" data-review-section="id">Identification</button>
        <button type="button" class="cr-review-tab" data-review-section="education">Education</button>
        <button type="button" class="cr-review-tab" data-review-section="employment">Employment</button>
    </div>
    <?php endif; ?> -->

    <div class="cr-shell cr-layout cr-page">
        <aside class="cr-sidebar cr-validator-nav cr-sections">
            <div class="cr-sidebar-title">Sections</div>
            <div class="cr-nav" style="font-size:13px;">
                <button type="button" class="list-group-item section-row active" data-section="basic" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 1-4-4H8a4 4 0 0 1-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <span class="section-title">Basic Details</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeBasic">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="id" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z" />
                            <path d="M8 9h4" />
                             <path d="M8 17h6" />
                        </svg>
                        <span class="section-title">Identification</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeId">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="contact" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M22 16.92V21a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 1 4.18 2 2 0 0 1 3 2h4.09a2 2 0 0 1 2 1.72c.12.86.3 1.7.54 2.5a2 2 0 0 1-.45 2.11L8 9.91a16 16 0 0 0 6 6l1.58-1.18a2 2 0 0 1 2.11-.45c.8.24 1.64.42 2.5.54A2 2 0 0 1 22 16.92z" />
                        </svg>
                        <span class="section-title">Contact Information</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeContact">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="education" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M22 10L12 5 2 10l10 5 10-5z" />
                            <path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5" />
                        </svg>
                        <span class="section-title">Education Details</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeEducation">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="employment" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 7V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1" />
                            <path d="M3 7h18v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z" />
                            <path d="M16 11a4 4 0 0 1-8 0" />
                        </svg>
                        <span class="section-title">Employment Details</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeEmployment">-</span></span>
                </button>


                

                <button type="button" class="list-group-item section-row" data-section="reference" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15a4 4 0 0 1-4 4H7l-4 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8z" />
                            <path d="M7 8h10" />
                            <path d="M7 12h8" />
                        </svg>
                        <span class="section-title">Reference</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeReference">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="socialmedia" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M8 12h8" />
                            <path d="M12 8v8" />
                        </svg>
                        <span class="section-title">Social Media</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeSocialmedia">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="ecourt" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z" />
                            <path d="M9 12h6" />
                        </svg>
                        <span class="section-title">E-Court</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeEcourt">-</span></span>
                </button>
                <button type="button" class="list-group-item section-row" data-section="reports" style="text-align:left;">
                    <span class="cr-nav-label section-left">
                        <svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 17v-6" />
                            <path d="M12 17V7" />
                            <path d="M15 17v-4" />
                            <path d="M4 21h16" />
                        </svg>
                        <span class="section-title">Reports</span>
                    </span>
                    <span class="section-right"><span class="badge status-badge bg-secondary" id="cvNavBadgeReports">-</span></span>
                </button>
            </div>
        </aside>
        <main class="cr-main cr-content">
            <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                <div class="cr-upload-inline">
                    <h3>Upload Documents</h3>

                    <div id="cvUploadMessage" style="display:none; margin-bottom:10px;"></div>

                    <div class="cr-upload-grid">
                        <div class="form-control">
                            <label>Document Type</label>
                            <select id="cvUploadDocType" name="doc_type">
                                <option value="general">General</option>
                                <option value="basic">Basic</option>
                                <option value="id">Identification</option>
                                <option value="contact">Contact</option>
                                <option value="address">Address</option>
                                <option value="education">Education</option>
                                <option value="employment">Employment</option>
                                <option value="reference">Reference</option>
                                <option value="socialmedia">Social Media</option>
                                <option value="ecourt">E-Court</option>
                                <option value="reports">Reports</option>
                            </select>
                            <div class="cr-upload-help" id="cvUploadHelp">Allowed: PDF, JPG, JPEG, PNG, WEBP (max 10MB each).</div>
                        </div>

                        <div class="form-control">
                            <label>Files</label>
                            <div class="cr-file-drop" id="cvFileDrop">
                                <div class="cr-file-row">
                                    <label class="cr-file-btn" for="cvUploadFiles">Choose Files</label>
                                    <div class="cr-file-meta" id="cvFileMeta">or drag &amp; drop here</div>
                                    <input class="cr-file-input" id="cvUploadFiles" type="file" name="files[]" multiple accept=".pdf,image/*">
                                </div>
                                <div class="cr-file-chips" id="cvFileChips"></div>
                            </div>
                        </div>

                        <div class="cr-upload-actions">
                            <button type="button" class="btn" id="cvUploadBtn">Upload</button>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <div style="font-size:12px; font-weight:950; letter-spacing:.08em; text-transform:uppercase; color:#0f172a;">Uploaded Documents</div>
                        <div id="cvUploadedDocs"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                <div class="cr-review-actionbar" id="crReviewActionbar">
                    <div class="cr-review-actionbar-title">Section Actions</div>
                    <div class="cr-validator-action-grid">
                        <button type="button" class="cr-action-btn" id="cvValidatorActionInsufficient">Need Docs</button>
                        <button type="button" class="cr-action-btn cr-dark" id="cvValidatorActionHold">Hold</button>
                        <button type="button" class="cr-action-btn cr-danger" id="cvValidatorActionReject">Reject</button>
                        <button type="button" class="cr-action-btn cr-ok" id="cvValidatorActionApprove">Approve</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cr-sections-scroll" id="crSectionsScroll">
                <?php if ($isPrint): ?>
                    <div class="card cr-panel" style="margin-bottom:12px;">
                        <h3 style="margin-top:0;">All Fields</h3>
                        <div id="cvPrintAllFields" style="margin-top:10px;"></div>
                    </div>
                    <div class="card cr-panel" style="margin-bottom:12px;">
                        <h3 style="margin-top:0;">All Uploaded Documents</h3>
                        <div id="cvPrintAllDocs" style="margin-top:10px;"></div>
                    </div>
                <?php endif; ?>
                <div class="card candidate-section cr-panel cr-single-record" id="section-basic" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">BD</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Basic Details</div>
                                <!-- <div class="cr-secbar-sub">Candidate submitted personal information</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatBasic"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_basic_table" style="margin-top:10px;"></div>
                    <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-remarks">
                            <label>Comments / Remarks</label>
                            <textarea id="cvRemarksBasic" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                <button type="button" class="btn btn-sm" id="cvSaveRemarksBasic">Save</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card candidate-section cr-panel cr-multi-record" id="section-id" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">ID</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Identification</div>
                                <!-- <div class="cr-secbar-sub">Government ID details and supporting documents</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatId"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_identification_table" style="margin-top:10px;"></div>
                    <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-remarks">
                            <label>Comments / Remarks</label>
                            <textarea id="cvRemarksId" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                <button type="button" class="btn btn-sm" id="cvSaveRemarksId">Save</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card candidate-section cr-panel cr-single-record" id="section-contact" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">CT</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Contact Information</div>
                                <!-- <div class="cr-secbar-sub">Current and permanent address information</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatContact"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-grid" style="margin-top:10px;">
                        <div class="form-control">
                            <label>Current Address</label>
                            <input type="text" id="cv_contact_current_address" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Permanent Address</label>
                            <input type="text" id="cv_contact_permanent_address" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Proof Type</label>
                            <input type="text" id="cv_contact_proof_type" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Proof File</label>
                            <input type="text" id="cv_contact_proof_file" value="" disabled>
                        </div>

                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                            <div class="form-control" style="grid-column:1/-1;">
                                <div class="cr-remarks">
                                    <label>Comments / Remarks</label>
                                    <textarea id="cvRemarksContact" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                        <button type="button" class="btn btn-sm" id="cvSaveRemarksContact">Save</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card candidate-section cr-panel cr-multi-record" id="section-education" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">ED</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Education Details</div>
                                <!-- <div class="cr-secbar-sub">Academic history, institutions, and certificates</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatEducation"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <?php if (in_array($role, ['validator', 'verifier', 'qa'], true)): ?>
                            <button type="button" class="cr-sec-action" data-mail-component="education" data-mail-label-send="Send Mail" data-mail-label-resend="Resend Mail">Send Mail</button>
                            <?php endif; ?>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_education_table" style="margin-top:10px;"></div>
                    <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-remarks">
                            <label>Comments / Remarks</label>
                            <textarea id="cvRemarksEducation" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                <button type="button" class="btn btn-sm" id="cvSaveRemarksEducation">Save</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card candidate-section cr-panel cr-multi-record" id="section-employment" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">EM</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Employment Details</div>
                                <!-- <div class="cr-secbar-sub">Employer history, tenure, and proof documents</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatEmployment"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <?php if (in_array($role, ['validator', 'verifier', 'qa'], true)): ?>
                            <button type="button" class="cr-sec-action" data-mail-component="employment" data-mail-label-send="Send Mail" data-mail-label-resend="Resend Mail">Send Mail</button>
                            <?php endif; ?>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_employment_table" style="margin-top:10px;"></div>
                    <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-remarks">
                            <label>Comments / Remarks</label>
                            <textarea id="cvRemarksEmployment" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                <button type="button" class="btn btn-sm" id="cvSaveRemarksEmployment">Save</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card candidate-section cr-panel cr-single-record" id="section-reference" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">RF</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Reference</div>
                                <!-- <div class="cr-secbar-sub">Professional reference and relationship details</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatReference"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-grid" style="margin-top:10px;">
                        <div class="form-control">
                            <label>Name</label>
                            <input type="text" id="cv_reference_name" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Designation</label>
                            <input type="text" id="cv_reference_designation" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Company</label>
                            <input type="text" id="cv_reference_company" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Mobile</label>
                            <input type="text" id="cv_reference_mobile" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Email</label>
                            <input type="text" id="cv_reference_email" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Relationship</label>
                            <input type="text" id="cv_reference_relationship" value="" disabled>
                        </div>
                        <div class="form-control">
                            <label>Years Known</label>
                            <input type="text" id="cv_reference_years_known" value="" disabled>
                        </div>

                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                            <div class="form-control" style="grid-column:1/-1;">
                                <div class="cr-remarks">
                                    <label>Comments / Remarks</label>
                                    <textarea id="cvRemarksReference" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                        <button type="button" class="btn btn-sm" id="cvSaveRemarksReference">Save</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card candidate-section cr-panel cr-single-record" id="section-socialmedia" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">SM</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Social Media</div>
                                <!-- <div class="cr-secbar-sub">Online profile details shared by the candidate</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatSocialmedia"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_socialmedia_table" style="margin-top:10px;"></div>
                    <div style="display:none;">
                        <input type="text" id="cv_social_linkedin_url" value="" disabled>
                        <input type="text" id="cv_social_facebook_url" value="" disabled>
                        <input type="text" id="cv_social_instagram_url" value="" disabled>
                        <input type="text" id="cv_social_twitter_url" value="" disabled>
                        <input type="text" id="cv_social_other_url" value="" disabled>
                        <input type="text" id="cv_social_consent_bgv" value="" disabled>
                        <input type="text" id="cv_social_content" value="" disabled>
                    </div>
                </div>

                <div class="card candidate-section cr-panel cr-single-record" id="section-ecourt" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">EC</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">E-Court</div>
                                <!-- <div class="cr-secbar-sub">Court-related declarations and supporting evidence</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatEcourt"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_ecourt_table" style="margin-top:10px;"></div>
                    <div style="display:none;">
                        <input type="text" id="cv_ecourt_current_address" value="" disabled>
                        <input type="text" id="cv_ecourt_permanent_address" value="" disabled>
                        <input type="text" id="cv_ecourt_evidence_document" value="" disabled>
                        <input type="text" id="cv_ecourt_period_from_date" value="" disabled>
                        <input type="text" id="cv_ecourt_period_to_date" value="" disabled>
                        <input type="text" id="cv_ecourt_period_duration_years" value="" disabled>
                        <input type="text" id="cv_ecourt_dob" value="" disabled>
                        <input type="text" id="cv_ecourt_comments" value="" disabled>
                    </div>
                </div>

                <div class="card candidate-section cr-panel cr-single-record" id="section-reports" style="margin-top:12px; display:none;">
                    <div class="cr-secbar">
                        <div class="cr-secbar-titleblock">
                            <div class="cr-secbar-icon">RP</div>
                            <div class="cr-secbar-copy">
                                <div class="cr-secbar-title">Reports</div>
                                <!-- <div class="cr-secbar-sub">Submission milestones and authorization details</div> -->
                            </div>
                        </div>
                        <div class="cr-secbar-meta" id="cvSectionTatReports"></div>
                        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="cr-secbar-actions" aria-label="Section Actions">
                            <button type="button" class="cr-sec-action need-docs" data-proxy-action="insufficient_documents">Need Docs</button>
                            <button type="button" class="cr-sec-action hold" data-proxy-action="hold">Hold</button>
                            <button type="button" class="cr-sec-action reject" data-proxy-action="reject">Reject</button>
                            <button type="button" class="cr-sec-action approve" data-proxy-action="approve">Approve</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="cv_reports_table" style="margin-top:10px;"></div>
                    <div style="display:none;">
                        <input type="text" id="cv_app_submitted_at" value="" disabled>
                        <input type="text" id="cv_auth_signature" value="" disabled>
                        <input type="text" id="cv_auth_file_name" value="" disabled>
                        <input type="text" id="cv_auth_uploaded_at" value="" disabled>
                    </div>
                    <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
                        <div class="form-control" style="grid-column:1/-1; margin-top:10px;">
                            <div class="cr-remarks">
                                <label>Comments / Remarks</label>
                                <textarea id="cvRemarksReports" rows="2" placeholder="Enter comments..." style="width:100%; resize:vertical;"></textarea>
                                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                                    <button type="button" class="btn btn-sm" id="cvSaveRemarksReports">Save</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
            <div id="cr-evidence-container"></div>
        </main>

        <?php if (in_array($role, ['validator', 'verifier', 'qa', 'team_lead'], true) && !$isPrint && !$isEmbed): ?>
            <aside class="cr-validator-side cr-right" aria-label="Review Utility Panel">
                <div class="cr-case-actions-card cr-utility-panel">
                    <ul class="nav nav-tabs cr-util-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#crUtilNotes" type="button" role="tab">Notes</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#crUtilReplies" type="button" role="tab">Replies</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#crUtilAudit" type="button" role="tab">Audit</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#crUtilTimeline" type="button" role="tab">Timeline</button>
                        </li>
                    </ul>
                    <div class="tab-content cr-util-content">
                        <div class="tab-pane fade show active cr-util-notes" id="crUtilNotes" role="tabpanel">
                            <div id="cvValidatorRemarksPanel" class="cr-validator-remarks-list">No remarks yet.</div>
                            <div class="cr-validator-remark-entry">
                                <textarea id="cvValidatorRemarkText" rows="4" placeholder="Enter section remark..."></textarea>
                                <div class="cr-validator-remark-actions">
                                    <button type="button" class="btn btn-sm" id="cvValidatorRemarkSave">Save Remark</button>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="crUtilReplies" role="tabpanel">
                            <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
                                <button
                                    type="button"
                                    id="cvRepliesSyncBtn"
                                    title="Refresh replies"
                                    aria-label="Refresh replies"
                                    style="width:34px; height:34px; border-radius:999px; border:1px solid #cbd5e1; background:#fff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 4px 14px rgba(37,99,235,0.08); cursor:pointer;"
                                >
                                    <span aria-hidden="true" style="font-size:16px; line-height:1; font-weight:700;">↻</span>
                                </button>
                            </div>
                            <div id="emailReplies" class="cr-util-scroll">
                                <p style="color:#888; margin:0;">No replies yet</p>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="crUtilAudit" role="tabpanel">
                            <div class="cr-util-scroll" id="cvValidatorAuditTrail">No audit trail yet.</div>
                        </div>
                        <div class="tab-pane fade" id="crUtilTimeline" role="tabpanel">
                            <div id="cvValidatorTimeline" class="cr-validator-timeline">No activity yet.</div>
                            <div style="margin-top:10px;">
                                <div style="font-weight:700; color:#0f172a; margin-bottom:6px;">Correction History</div>
                                <div id="cvCorrectionHistory" class="cr-util-scroll" style="max-height:220px;">No correction history yet.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        <?php endif; ?>

        <!-- <?php if (in_array($role, ['qa', 'verifier', 'validator', 'db_verifier'], true) && !$isPrint && !$isEmbed): ?>
            <aside class="cr-remarksbar" aria-label="Remarks">
                <div class="cr-remarksbar-title">
                    <span>Remarks</span>
                    <div class="qa-filter-wrap">
                        <button class="btn btn-sm qa-btn qa-btn-light qa-filter-btn" id="qaRemarksFilterBtn" type="button">
                            Filter: <span id="qaRemarksFilterLabel">All</span>
                        </button>
                        <div class="qa-filter-menu" id="qaRemarksFilterMenu">
                            <button class="qa-filter-item active" data-filter="all">All</button>
                            <button class="qa-filter-item" data-filter="general">General</button>
                            <button class="qa-filter-item" data-filter="basic">Basic</button>
                            <button class="qa-filter-item" data-filter="identification">Identification</button>
                            <button class="qa-filter-item" data-filter="address">Address</button>
                            <button class="qa-filter-item" data-filter="employment">Employment</button>
                            <button class="qa-filter-item" data-filter="education">Education</button>
                            <button class="qa-filter-item" data-filter="reference">Reference</button>
                            <button class="qa-filter-item" data-filter="documents">Documents</button>
                        </div>
                    </div>
                </div>
                <div id="cvRemarksPanel" class="qa-remarks-list"></div>
                <div class="qa-comment-box">
                    <div class="qa-comment-row">
                        <input id="qaCommentText" class="qa-input" type="text" placeholder="Type a remark..." />
                        <button class="btn btn-sm qa-btn" id="qaCommentAddBtn" type="button">Add</button>
                    </div>
                </div>
            </aside>
        <?php endif; ?> -->

        <?php if (($role === 'validator' || $role === 'verifier') && !$isPrint && !$isEmbed): ?>
            <aside class="cr-docbar">
                <div class="cr-docbar-title">Resume / Documents</div>
                <div class="cr-docbar-frame" id="cvDocPreviewFrameHost">
                    <div style="padding:10px; color:#64748b; font-size:12px;">No document selected.</div>
                </div>
                <div class="cr-docbar-list" id="cvDocPreviewList"></div>
            </aside>
        <?php endif; ?>
    </div>

    <?php if ($role !== 'validator'): ?>
        <div class="modal fade" id="cvUploadModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                    <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,0.25);">
                        <h5 class="modal-title" style="font-size:14px; font-weight:900;">Upload Documents</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="card cr-upload-card" id="cvUploadCard" style="margin-bottom:0; box-shadow:none;">
                            <p class="card-subtitle">Upload verification documents (multiple images / PDF).</p>

                            <div id="cvUploadMessage" style="display:none; margin-bottom:10px;"></div>

                            <div class="cr-upload-grid">
                                <div class="form-control">
                                    <label>Document Type</label>
                                    <select id="cvUploadDocType" name="doc_type">
                                        <option value="general">General</option>
                                        <option value="basic">Basic</option>
                                        <option value="id">Identification</option>
                                        <option value="contact">Contact</option>
                                        <option value="address">Address</option>
                                        <option value="education">Education</option>
                                        <option value="employment">Employment</option>
                                        <option value="reference">Reference</option>
                                        <option value="socialmedia">Social Media</option>
                                        <option value="ecourt">E-Court</option>
                                        <option value="reports">Reports</option>
                                    </select>
                                    <div class="cr-upload-help" id="cvUploadHelp">Allowed: PDF, JPG, JPEG, PNG, WEBP (max 10MB each).</div>
                                </div>

                                <div class="form-control">
                                    <label>Files</label>
                                    <div class="cr-file-drop" id="cvFileDrop">
                                        <div class="cr-file-row">
                                            <label class="cr-file-btn" for="cvUploadFiles">Choose Files</label>
                                            <div class="cr-file-meta" id="cvFileMeta">or drag &amp; drop here</div>
                                            <input class="cr-file-input" id="cvUploadFiles" type="file" name="files[]" multiple accept=".pdf,image/*">
                                        </div>
                                        <div class="cr-file-chips" id="cvFileChips"></div>
                                    </div>
                                </div>

                                <div class="cr-upload-actions">
                                    <button type="button" class="btn" id="cvUploadBtn">Upload</button>
                                </div>
                            </div>

                            <div style="margin-top:12px;">
                                <h3 style="margin-top:0; font-size:14px;">Uploaded Documents</h3>
                                <div id="cvUploadedDocs"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="cvTimelineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,0.25);">
                    <h5 class="modal-title" style="font-size:14px; font-weight:900; display:flex; align-items:center; gap:10px;">
                        <span>Timeline</span>
                        <span class="badge bg-secondary" id="cvMiniTimelineCount">-</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="cr-case-actions-card" id="cvCaseActionsCard">
                        <div class="cr-case-actions-head">Case Actions</div>
                        <div class="cr-case-actions-row">
                            <button type="button" class="cr-action-btn" id="cvActionInsufficient">Insufficient Documents</button>
                            <button type="button" class="cr-action-btn cr-dark" id="cvActionHold">Hold</button>
                            <button type="button" class="cr-action-btn cr-danger" id="cvActionReject">Reject</button>
                            <button type="button" class="cr-action-btn cr-ok" id="cvActionApprove">Approve</button>
                            <button type="button" class="cr-action-btn cr-danger" id="cvActionStopBgv">Stop BGV</button>
                        </div>
                    </div>
                    <div class="cr-tl-filters" id="cvMiniTimelineFilters" style="display:flex; flex-wrap:wrap; gap:6px;">
                        <button type="button" class="cr-tl-pill active" data-tl-section="all">All</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="basic">Basic</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="id">ID</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="contact">Contact</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="education">Education</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="employment">Employment</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="reference">Reference</button>
                        <button type="button" class="cr-tl-pill" data-tl-section="reports">Reports</button>
                    </div>
                    <div id="cvTimeline" style="margin-top:12px;"></div>
                    <div style="margin-top:16px; border-top:1px solid rgba(148,163,184,0.25); padding-top:12px;">
                        <h6 style="margin:0 0 8px 0; font-size:13px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                            <span>Email Replies</span>
                            <span class="badge bg-secondary" id="cvEmailRepliesCount">0</span>
                        </h6>
                        <div id="cvEmailReplies" style="display:flex; flex-direction:column; gap:8px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (in_array($role, ['validator', 'verifier', 'qa'], true) && !$isPrint && !$isEmbed): ?>
    <div id="cvDocViewerOverlay" class="cv-docviewer-overlay" aria-hidden="true">
        <div id="cvDocViewerModal" class="cv-docviewer-modal" role="dialog" aria-modal="true" aria-label="Document viewer">
            <div id="cvDocViewerHeader" class="cv-docviewer-header">
                <div class="cv-docviewer-title">Document Viewer</div>
                <div class="cv-docviewer-actions">
                    <button type="button" id="cvDocViewerMinimize" class="cv-docviewer-btn" title="Minimize">_</button>
                    <button type="button" id="cvDocViewerMaximize" class="cv-docviewer-btn" title="Maximize">□</button>
                    <button type="button" id="cvDocViewerClose" class="cv-docviewer-btn danger" title="Close">✕</button>
                </div>
            </div>
            <div id="cvDocViewerSplit" class="cv-docviewer-split">
                <div id="cvDocViewerCandidatePane" class="cv-docviewer-pane">
                    <div class="cv-docviewer-empty">No document selected.</div>
                </div>
                <div class="cv-docviewer-upload">
                    <div class="cv-docviewer-upload-head">
                        <button type="button" id="cvDocViewerUploadRemove" class="cv-docviewer-upload-remove">Remove</button>
                    </div>
                    <div id="cvDocViewerUploadPane" class="cv-docviewer-pane">
                        <div class="cv-docviewer-empty">No uploaded document.</div>
                    </div>
                </div>
            </div>
            <div class="cv-docviewer-footer">
                <button type="button" id="cvDocViewerUploadBtn" class="cv-docviewer-upload-btn">Upload Document</button>
                <input type="file" id="cvDocViewerUploadInput" accept=".pdf,image/*">
            </div>
            <div id="cvDocViewerResizeRight" class="cv-docviewer-resize right" aria-hidden="true"></div>
            <div id="cvDocViewerResizeBottom" class="cv-docviewer-resize bottom" aria-hidden="true"></div>
            <div id="cvDocViewerResizeCorner" class="cv-docviewer-resize corner" aria-hidden="true"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isPrint): ?>
    <?php if (!$isPrint): ?>
    <div class="modal fade" id="cvActionConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,0.25);">
                    <h5 class="modal-title" id="cvActionConfirmTitle" style="font-size:14px; font-weight:900;">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cvActionConfirmText" style="font-size:13px; color:#334155;">Are you sure?</div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(148,163,184,0.20);">
                    <button type="button" class="btn btn-sm" id="cvActionConfirmNo" data-bs-dismiss="modal">No</button>
                    <button type="button" class="btn btn-sm btn-danger" id="cvActionConfirmYes">Yes</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isPrint): ?>
    <div class="modal fade" id="cvVerifierOverrideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,0.25);">
                    <h5 class="modal-title" id="cvVerifierOverrideTitle" style="font-size:14px; font-weight:900;">Reason Required</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label for="cvVerifierOverrideText" style="font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">Reason</label>
                    <textarea id="cvVerifierOverrideText" rows="4" placeholder="Enter reason..." style="width:100%; resize:vertical; border:1px solid rgba(148,163,184,0.30); border-radius:10px; padding:8px 10px;"></textarea>
                    <div id="cvVerifierOverrideError" style="display:none; margin-top:8px; font-size:12px; color:#b91c1c;"></div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(148,163,184,0.20);">
                    <button type="button" class="btn btn-sm" id="cvVerifierOverrideCancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="cvVerifierOverrideSubmit">Submit</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPrint): ?>
        <div class="cr-pdf" id="cvPdfRoot" style="margin-top:10px;">
            <div class="cr-pdf-page cr-pdf-cover">
                <div>
                    <div class="cr-pdf-header">
                        <div class="cr-pdf-brand">
                            <div class="cr-pdf-logo">GSS</div>
                            <div>
                                <h1 class="cr-pdf-title">Background Verification Report</h1>
                                <div class="cr-pdf-sub">Generated from VATI GSS</div>
                            </div>
                        </div>
                        <div class="cr-pdf-meta" id="cvPdfCoverMeta"></div>
                    </div>

                    <div style="margin-top:18px;">
                        <h2 class="cr-pdf-cover-title" id="cvPdfCoverCandidate"></h2>
                        <div style="margin-top:10px;" class="cr-pdf-cover-note" id="cvPdfCoverNote"></div>
                    </div>
                </div>
                <div class="cr-pdf-footer">
                    <span id="cvPdfCoverFooterLeft"></span>
                    <span>Confidential</span>
                </div>
            </div>

            <div class="cr-pdf-page">
                <div class="cr-pdf-header">
                    <div class="cr-pdf-brand">
                        <div class="cr-pdf-logo">GSS</div>
                        <div>
                            <h1 class="cr-pdf-title">Interim Background Check Report</h1>
                            <div class="cr-pdf-sub">Executive summary</div>
                        </div>
                    </div>
                    <div class="cr-pdf-meta" id="cvPdfSummaryMeta"></div>
                </div>

                <div class="cr-pdf-section-title">Candidate Summary</div>
                <div class="cr-pdf-grid" id="cvPdfSummaryGrid"></div>

                <div class="cr-pdf-section-title">Executive Summary</div>
                <div id="cvPdfExecutive"></div>
                <div class="cr-pdf-footer">
                    <span>VATI GSS</span>
                    <span>Interim Report</span>
                </div>
            </div>

            <div class="cr-pdf-page">
                <div class="cr-pdf-header">
                    <div class="cr-pdf-brand">
                        <div class="cr-pdf-logo">GSS</div>
                        <div>
                            <h1 class="cr-pdf-title">Checklist of Documents</h1>
                            <div class="cr-pdf-sub">Submitted by candidate & verification team</div>
                        </div>
                    </div>
                    <div class="cr-pdf-meta" id="cvPdfChecklistMeta"></div>
                </div>
                <div id="cvPdfChecklist"></div>
                <div class="cr-pdf-footer">
                    <span>VATI GSS</span>
                    <span>Checklist</span>
                </div>
            </div>

            <div class="cr-pdf-page">
                <div class="cr-pdf-header">
                    <div class="cr-pdf-brand">
                        <div class="cr-pdf-logo">GSS</div>
                        <div>
                            <h1 class="cr-pdf-title">All Fields</h1>
                            <div class="cr-pdf-sub">Every captured field across sections</div>
                        </div>
                    </div>
                    <div class="cr-pdf-meta" id="cvPdfAllFieldsMeta"></div>
                </div>
                <div id="cvPrintAllFields" style="margin-top:10px;"></div>
                <div class="cr-pdf-footer">
                    <span>VATI GSS</span>
                    <span>All Fields</span>
                </div>
            </div>

            <div class="cr-pdf-page">
                <div class="cr-pdf-header">
                    <div class="cr-pdf-brand">
                        <div class="cr-pdf-logo">GSS</div>
                        <div>
                            <h1 class="cr-pdf-title">Uploaded Documents</h1>
                            <div class="cr-pdf-sub">Candidate + verifier uploads</div>
                        </div>
                    </div>
                    <div class="cr-pdf-meta" id="cvPdfDocsMeta"></div>
                </div>
                <div class="cr-pdf-section-title">All Uploaded Documents</div>
                <div id="cvPrintAllDocs" style="margin-top:10px;"></div>
                <div class="cr-pdf-section-title">Grouped Documents</div>
                <div id="cvPdfDocsGrouped" class="cr-pdf-docs"></div>
                <div class="cr-pdf-footer">
                    <span>VATI GSS</span>
                    <span>Documents</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();

if ($isEmbed) {
    require_once __DIR__ . '/../../config/env.php';
    ?><!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo htmlspecialchars('Candidate Report'); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/style.css')); ?>">
        <style>
            body{background:#f8fafc;}
            html,body{overflow-x:hidden;}
            .top-header,.sidebar,.app-footer{display:none !important;}
            .app-shell,.main{padding:0 !important; margin:0 !important;}
        </style>
    </head>
    <body>
    <script>
        window.APP_BASE_URL = <?php echo json_encode(app_base_url()); ?>;
        window.VR_GROUP = <?php echo json_encode($group); ?>;
        window.ALLOWED_SECTIONS = <?php echo json_encode($allowedSections); ?>;
        window.CURRENT_ROLE = <?php echo json_encode($role); ?>;
    </script>
    <?php echo $content; ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="<?php echo htmlspecialchars(app_url('/js/includes/date_utils.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(app_url('/js/modules/shared/candidate_report.js?v=' . $candidateReportJsVersion)); ?>"></script>
    </body>
    </html>
    <?php
    exit;
}

render_layout('Candidate Report', $roleLabel, $menu, $content);
?>
<script>
    window.APP_BASE_URL = <?php echo json_encode(app_base_url()); ?>;
    window.ALLOWED_SECTIONS = <?php echo json_encode($allowedSections); ?>;
    window.CURRENT_ROLE = <?php echo json_encode($role); ?>;
</script>

<script>
    window.VR_GROUP = <?php echo json_encode($group); ?>;
</script>

<script>
(function () {
    function enhanceInlineFileRows(root) {
        var scope = root || document;
        var links = scope.querySelectorAll('.cr-content .cr-doc-uploadname[href]');
        if (!links.length) return;
        links.forEach(function (a) {
            if (!a || a.dataset.enhancedFileRow === '1') return;
            a.dataset.enhancedFileRow = '1';
            var href = String(a.getAttribute('href') || '').trim();
            if (!href) return;

            var row = a.closest('.cr-doc-uploadrow');
            if (!row) return;
            if (row.querySelector('.cr-file-actions')) return;

            var actions = document.createElement('span');
            actions.className = 'cr-file-actions';

            var preview = document.createElement('button');
            preview.type = 'button';
            preview.className = 'cr-file-action';
            preview.textContent = 'Preview';
            preview.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                try {
                    a.click(); // uses existing custom modal viewer flow via js-cv-doc-view handlers
                } catch (_e) {}
            });
            actions.appendChild(preview);

            var down = document.createElement('a');
            down.className = 'cr-file-action';
            down.href = href;
            down.setAttribute('download', '');
            down.textContent = 'Download';
            actions.appendChild(down);

            row.appendChild(actions);
        });
    }

    function normalizeRecordTabLabels(root) {
        var scope = root || document;
        var tabs = scope.querySelectorAll('.cr-content .cr-record-tab');
        if (!tabs.length) return;
        tabs.forEach(function (tab) {
            var txt = String(tab.textContent || '').trim();
            if (!txt) return;
            var m = txt.match(/^(ID|Education|Employment|Reference)\s+(\d+)$/i);
            if (!m) return;
            var prefix = m[1];
            var n = m[2];
            if (/^id$/i.test(prefix)) prefix = 'ID';
            else if (/^education$/i.test(prefix)) prefix = 'Education';
            else if (/^employment$/i.test(prefix)) prefix = 'Employment';
            else if (/^reference$/i.test(prefix)) prefix = 'Reference';
            tab.textContent = prefix + n;
        });
    }

    function initSectionActionProxy() {
        var root = document.querySelector('.cr-report-root.cr-validator-workspace');
        if (!root) return;

        var actionToId = {
            insufficient_documents: 'cvValidatorActionInsufficient',
            hold: 'cvValidatorActionHold',
            reject: 'cvValidatorActionReject',
            approve: 'cvValidatorActionApprove'
        };
        var actionToLabel = {
            insufficient_documents: 'Need Docs',
            hold: 'Hold',
            reject: 'Reject',
            approve: 'Approve'
        };

        function getCanonicalButton(action) {
            var id = actionToId[String(action || '').toLowerCase().trim()];
            return id ? document.getElementById(id) : null;
        }

        function syncProxyButtons() {
            var proxies = document.querySelectorAll('.cr-sec-action[data-proxy-action]');
            if (!proxies.length) return;
            proxies.forEach(function (btn) {
                var action = String(btn.getAttribute('data-proxy-action') || '').toLowerCase().trim();
                var canonical = getCanonicalButton(action);
                // Keep proxy actions always available so validators can update decisions later.
                btn.style.display = '';
                btn.disabled = false;
            });
        }

        document.addEventListener('click', async function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.cr-sec-action[data-proxy-action]') : null;
            if (!btn) return;
            e.preventDefault();
            var action = String(btn.getAttribute('data-proxy-action') || '').toLowerCase().trim();
            if (!action) return;

            // Always prefer the core action handler so reason/confirm dialog logic stays intact.
            if (typeof window.__CR_RUN_ACTION === 'function') {
                try {
                    await window.__CR_RUN_ACTION(action, actionToLabel[action] || action, {
                        targetStage: String(btn.getAttribute('data-target-stage') || '').toLowerCase().trim()
                    });
                } catch (_e) {}

                // Do not auto-open mail modal after section actions.
                // Users can open Communication manually from the Mail button.
                return;
            }

            // Absolute fallback if action runtime has not been initialized yet.
            var canonical = getCanonicalButton(action);
            if (canonical) {
                canonical.click();
            }
        });

        Object.keys(actionToId).forEach(function (key) {
            var canonical = getCanonicalButton(key);
            if (!canonical) return;
            if (canonical.dataset.proxyObserved === '1') return;
            canonical.dataset.proxyObserved = '1';
            try {
                var obs = new MutationObserver(function () { syncProxyButtons(); });
                obs.observe(canonical, { attributes: true, attributeFilter: ['disabled', 'style', 'class'] });
            } catch (_e) {}
        });

        document.addEventListener('cv:section-changed', syncProxyButtons);
        document.addEventListener('cv:record-tab-changed', function () {
            syncProxyButtons();
            normalizeRecordTabLabels();
            enhanceInlineFileRows();
        });
        syncProxyButtons();
        normalizeRecordTabLabels();
        enhanceInlineFileRows();
        setTimeout(syncProxyButtons, 250);
        setTimeout(normalizeRecordTabLabels, 250);
        setTimeout(enhanceInlineFileRows, 250);
        setTimeout(syncProxyButtons, 1000);
        setTimeout(normalizeRecordTabLabels, 1000);
        setTimeout(enhanceInlineFileRows, 1000);

        try {
            var obs = new MutationObserver(function () {
                normalizeRecordTabLabels(root);
                enhanceInlineFileRows(root);
            });
            var watchIds = ['cv_identification_table', 'cv_education_table', 'cv_employment_table'];
            watchIds.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) obs.observe(el, { childList: true, subtree: true });
            });
        } catch (_e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSectionActionProxy);
    } else {
        initSectionActionProxy();
    }
})();
</script>

<?php if (in_array($role, ['validator', 'verifier'], true) && !$isPrint && !$isEmbed && $fullscreen): ?>
<script>
    try { document.body.classList.add('cr-fullscreen-validator'); } catch (e) {}
</script>
<?php endif; ?>
