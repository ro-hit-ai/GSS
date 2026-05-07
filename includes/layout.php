<?php
// Simple shared layout function
function render_layout(string $title, string $roleLabel, array $menu, string $content): void {
    require_once __DIR__ . '/../config/env.php';
    // Determine current module and script for sidebar active state
    $scriptPath    = $_SERVER['SCRIPT_NAME'] ?? '';
    $currentScript = basename($scriptPath);
    $parts         = explode('/', trim($scriptPath, '/'));
    $currentModule = $parts[count($parts) - 2] ?? '';
    $currentQuery  = $_GET ?? [];

    $resolveTarget = function (string $itemHref) use ($currentModule): array {
        $path = $itemHref;
        $query = '';
        $parsed = parse_url($itemHref);
        if (is_array($parsed)) {
            $path = (string)($parsed['path'] ?? $itemHref);
            $query = (string)($parsed['query'] ?? '');
        }

        if (strpos($path, '../') === 0) {
            $relative = substr($path, 3);
            $hrefParts = explode('/', trim($relative, '/'));
            $targetModule = $hrefParts[0] ?? '';
            $targetScript = $hrefParts[count($hrefParts) - 1] ?? '';
        } else {
            $targetModule = $currentModule;
            $targetScript = basename($path);
        }
        return [$targetModule, $targetScript, $query];
    };

    $isHrefActive = function (string $href) use ($resolveTarget, $currentModule, $currentScript, $currentQuery): bool {
        [$targetModule, $targetScript, $targetQuery] = $resolveTarget($href);
        if (!($targetModule === $currentModule && $targetScript === $currentScript)) return false;

        $targetQuery = trim((string)$targetQuery);
        if ($targetQuery === '') return true;

        $expected = [];
        parse_str($targetQuery, $expected);
        if (!is_array($expected) || !$expected) return true;

        foreach ($expected as $k => $v) {
            $cur = $currentQuery[$k] ?? null;
            if (is_array($v)) {
                if (!is_array($cur)) return false;
                foreach ($v as $vv) {
                    if (!in_array($vv, $cur, true)) return false;
                }
                continue;
            }
            if ((string)$cur !== (string)$v) return false;
        }

        return true;
    };

    $hideSidebar = strtolower(trim($roleLabel)) === 'candidate';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?> - VATI GSS</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/style.css')); ?>">
        <?php if ($hideSidebar): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/login.css')); ?>">
        <?php endif; ?>
    </head>
    <body<?php echo $hideSidebar ? ' class="candidate-auth"' : ''; ?>>
    <script>
        window.APP_BASE_URL = <?php echo json_encode(app_base_url()); ?>;
    </script>
    <script>
        window.AUTH_USER_ID = <?php
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            echo json_encode(!empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0);
        ?>;
        window.AUTH_ROLE_LABEL = <?php echo json_encode($roleLabel); ?>;
    </script>
    <script>
        (function () {
            try {
                var stored = window.localStorage ? window.localStorage.getItem('gss_sidebar_collapsed') : null;
                if (stored === '1') {
                    document.body.classList.add('sidebar-collapsed');
                }
            } catch (e) {
                // ignore storage errors
            }
        })();
    </script>
    <?php if (!$hideSidebar): ?>
    <header class="top-header">
        <div class="top-header-left">
            <div class="brand-mark"><img class="brand-logo" src="<?php echo htmlspecialchars(app_url('/assets/img/gss-logo.svg')); ?>" alt="GSS"></div>
            <div class="brand-text">
                <span class="brand-title">VATI GSS</span>
                <span class="brand-subtitle">Verification Platform</span>
            </div>
            <button type="button" class="btn-toggle-sidebar" id="sidebarToggle" aria-label="Toggle navigation"></button>
        </div>
        <div class="top-header-right">
            <span class="role-pill"><?php echo htmlspecialchars($roleLabel); ?></span>
            <a class="link" href="<?php echo htmlspecialchars(app_url('/index.php?switch=1')); ?>">Switch Role</a>
            <?php if (!empty($_SESSION['auth_user_id'])): ?>
                <button type="button" class="btn btn-sm btn-logout" id="logoutBtn" data-logout-url="<?php echo htmlspecialchars(app_url('/logout.php')); ?>">Logout</button>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>

    <?php if (!$hideSidebar): ?>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-section sidebar-title">Navigation</div>
            <div class="sidebar-section" style="font-size:12px; color:#9ca3af; margin-bottom:6px;">
                <span style="display:inline-flex; align-items:center; gap:6px;">
                    <span class="status-dot" aria-hidden="true"></span>
                    <span><?php echo htmlspecialchars($roleLabel); ?> view</span>
                </span>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($menu as $item):
                    $label = (string)($item['label'] ?? '');
                    $children = $item['children'] ?? null;
                    $isGroup = is_array($children) && count($children) > 0;

                    if (!$isGroup) {
                        $itemHref = (string)($item['href'] ?? '');
                        $isActive = $itemHref !== '' ? $isHrefActive($itemHref) : false;
                        ?>
                        <a href="<?php echo htmlspecialchars($itemHref); ?>"<?php echo $isActive ? ' class="active"' : ''; ?>>
                            <span class="sidebar-icon-pill" style="width:26px; height:26px; border-radius:999px; background:rgba(148,163,184,0.18); display:inline-flex; align-items:center; justify-content:center; font-size:11px; color:#6b7280; text-transform:uppercase;">
                                <?php echo strtoupper(substr($label, 0, 2)); ?>
                            </span>
                            <span class="sidebar-label"><?php echo htmlspecialchars($label); ?></span>
                        </a>
                        <?php
                        continue;
                    }

                    $groupKey = (string)($item['key'] ?? preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($label)));
                    $groupActive = false;
                    foreach ($children as $child) {
                        $childHref = (string)($child['href'] ?? '');
                        if ($childHref !== '' && $isHrefActive($childHref)) {
                            $groupActive = true;
                            break;
                        }
                    }
                    ?>
                    <div class="sidebar-group" data-group="<?php echo htmlspecialchars($groupKey); ?>"<?php echo $groupActive ? ' data-active="1"' : ''; ?> style="margin-bottom:4px;">
                        <button type="button" class="sidebar-group-toggle<?php echo $groupActive ? ' active' : ''; ?>" data-group-toggle="<?php echo htmlspecialchars($groupKey); ?>">
                            <span class="sidebar-icon-pill" style="width:26px; height:26px; border-radius:999px; background:rgba(148,163,184,0.18); display:inline-flex; align-items:center; justify-content:center; font-size:11px; color:#6b7280; text-transform:uppercase;">
                                <?php echo strtoupper(substr($label, 0, 2)); ?>
                            </span>
                            <span class="sidebar-label"><?php echo htmlspecialchars($label); ?></span>
                            <span class="sidebar-group-caret" aria-hidden="true" style="margin-left:auto; font-size:12px; color:#64748b;">▸</span>
                        </button>
                        <div class="sidebar-group-children" data-group-children="<?php echo htmlspecialchars($groupKey); ?>">
                            <?php foreach ($children as $child):
                                $childLabel = (string)($child['label'] ?? '');
                                $childHref = (string)($child['href'] ?? '');
                                $childActive = $childHref !== '' ? $isHrefActive($childHref) : false;
                                ?>
                                <a class="sidebar-child-link<?php echo $childActive ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($childHref); ?>">
                                    <span class="sidebar-label"><?php echo htmlspecialchars($childLabel); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-section" style="margin-top:auto; font-size:11px; color:#9ca3af;">
                <div>Environment: Staging</div>
                <div style="opacity:.8;">Quick access only</div>
            </div>
        </aside>
        <main class="main">
            <!-- <?php if ($currentScript !== 'candidate_report.php'): ?>
                <div class="page-header">
                    <div>
                        <div class="page-breadcrumb">Home / <?php echo htmlspecialchars($roleLabel); ?></div>
                        <h1><?php echo htmlspecialchars($title); ?></h1>
                    </div>
                </div>
            <?php endif; ?> -->
            <?php echo $content; ?>
        </main>
    </div>
    <footer class="app-footer">
        <span>  <?php echo date('Y'); ?> VATI GSS. All rights reserved.</span>
        <span>Environment: Staging</span>
    </footer>
    <?php else: ?>
        <?php echo $content; ?>
    <?php endif; ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <?php if (!empty($_SESSION['auth_user_id'])): ?>
        <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                    <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,0.25);">
                        <h5 class="modal-title" style="font-size:14px; font-weight:700;">Confirm logout</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="font-size:13px; color:#334155;">
                        You will be signed out from this session.
                    </div>
                    <div class="modal-footer" style="border-top:1px solid rgba(148,163,184,0.25);">
                        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
                        <a class="btn btn-sm btn-logout" id="logoutConfirmBtn" data-logout-url="<?php echo htmlspecialchars(app_url('/logout.php')); ?>" href="<?php echo htmlspecialchars(app_url('/logout.php')); ?>" style="border-radius:10px; text-decoration:none;">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script src="<?php echo htmlspecialchars(app_url('/js/includes/date_utils.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(app_url('/js/includes/dialog.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(app_url('/js/includes/layout.js')); ?>"></script>
    <script>
        (function () {
            var host = null;
            function ensureHost() {
                if (host && document.body.contains(host)) return host;
                if (!document.getElementById('gss-global-toast-style')) {
                    var st = document.createElement('style');
                    st.id = 'gss-global-toast-style';
                    st.textContent =
                        '.gss-global-toast-host{position:fixed;top:16px;right:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;max-width:min(420px,calc(100vw - 24px));}' +
                        '.gss-global-toast{border-radius:10px;padding:11px 14px;font-size:13px;font-weight:700;border:1px solid transparent;box-shadow:0 10px 25px rgba(2,6,23,.15);opacity:0;transform:translateY(-8px);transition:all .18s ease;}' +
                        '.gss-global-toast.show{opacity:1;transform:translateY(0);}' +
                        '.gss-global-toast.success{background:#ecfdf3;color:#065f46;border-color:#a7f3d0;}' +
                        '.gss-global-toast.danger,.gss-global-toast.error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}' +
                        '.gss-global-toast.warning{background:#fffbeb;color:#92400e;border-color:#fde68a;}' +
                        '.gss-global-toast.info{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe;}';
                    document.head.appendChild(st);
                }
                host = document.createElement('div');
                host.className = 'gss-global-toast-host';
                document.body.appendChild(host);
                return host;
            }

            function normalizeType(v) {
                var t = String(v || '').toLowerCase().trim();
                if (t.indexOf('danger') !== -1 || t.indexOf('error') !== -1) return 'danger';
                if (t.indexOf('warning') !== -1 || t.indexOf('warn') !== -1) return 'warning';
                if (t.indexOf('success') !== -1) return 'success';
                return 'info';
            }

            function showToast(message, type) {
                var msg = String(message || '').trim();
                if (!msg) return;
                var h = ensureHost();
                var item = document.createElement('div');
                item.className = 'gss-global-toast ' + normalizeType(type);
                item.textContent = msg;
                h.appendChild(item);
                requestAnimationFrame(function () { item.classList.add('show'); });
                setTimeout(function () {
                    item.classList.remove('show');
                    setTimeout(function () {
                        if (item.parentNode) item.parentNode.removeChild(item);
                    }, 220);
                }, 3000);
            }

            function isMessageBlock(el) {
                if (!el || el.nodeType !== 1) return false;
                var id = String(el.id || '').toLowerCase();
                var cls = String(el.className || '').toLowerCase();
                if (!id && !cls) return false;
                var idHint = id.indexOf('message') !== -1 || id.indexOf('msg') !== -1;
                var classHint = cls.indexOf('alert') !== -1 || cls.indexOf('message') !== -1;
                return idHint || classHint;
            }

            function consumeBlock(el) {
                if (!isMessageBlock(el)) return;
                var txt = String(el.textContent || '').trim();
                if (!txt) return;
                var sig = txt + '|' + String(el.className || '');
                if (el.dataset.toastSig !== sig) {
                    el.dataset.toastSig = sig;
                    showToast(txt, el.className || '');
                }
                el.style.display = 'none';
                el.textContent = '';
            }

            function scanAll() {
                var nodes = document.querySelectorAll('[id*="Message"], [id*="message"], .alert, .message');
                nodes.forEach(function (el) { consumeBlock(el); });
            }

            window.GSS_TOAST = window.GSS_TOAST || {
                show: function (message, type) { showToast(message, type || 'info'); }
            };

            var mo = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.type === 'childList') {
                        m.addedNodes.forEach(function (n) {
                            if (n && n.nodeType === 1) {
                                if (isMessageBlock(n)) consumeBlock(n);
                                if (n.querySelectorAll) {
                                    n.querySelectorAll('[id*="Message"], [id*="message"], .alert, .message').forEach(function (el) {
                                        consumeBlock(el);
                                    });
                                }
                            }
                        });
                    } else if (m.type === 'attributes') {
                        consumeBlock(m.target);
                    } else if (m.type === 'characterData') {
                        if (m.target && m.target.parentElement) consumeBlock(m.target.parentElement);
                    }
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
                scanAll();
                mo.observe(document.body, {
                    subtree: true,
                    childList: true,
                    characterData: true,
                    attributes: true,
                    attributeFilter: ['class', 'style']
                });
            });
        })();
    </script>
    <?php if (!empty($currentModule) && !empty($currentScript)): 
        $scriptBase = pathinfo($currentScript, PATHINFO_FILENAME);
        $pageJsPath = app_url("/js/modules/{$currentModule}/{$scriptBase}.js");
        $pageJsDiskPath = realpath(__DIR__ . "/../js/modules/{$currentModule}/{$scriptBase}.js");
        $pageJsVersion = ($pageJsDiskPath && is_file($pageJsDiskPath)) ? (string)filemtime($pageJsDiskPath) : '';
        $pageJsSrc = $pageJsVersion ? ($pageJsPath . '?v=' . $pageJsVersion) : $pageJsPath;
    ?>
        <script src="<?php echo htmlspecialchars($pageJsSrc); ?>"></script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
