<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars($_SESSION['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Borg Backup Server</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css?v=<?= filemtime(__DIR__ . '/../../../public/css/style.css') ?>" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>
    <!-- Top bar -->
    <nav class="navbar navbar-expand navbar-dark topbar p-0">
        <div class="container-fluid p-0">
            <a href="/" class="navbar-brand d-flex align-items-center justify-content-center m-0 p-0 topbar-logo">
                <img src="/images/borg_icon_dark.png" alt="BBS" style="height: 36px;">
            </a>
            <span class="navbar-text fw-semibold ms-3 d-none d-sm-inline"><?= htmlspecialchars($pageTitle ?? '') ?></span>
            <span class="navbar-text fw-semibold ms-2 d-sm-none small"><?= htmlspecialchars($pageTitle ?? '') ?></span>
            <div class="d-flex align-items-center ms-auto me-2 me-md-3">
                <?php
                $notifCount = $notifCount ?? (new \BBS\Services\NotificationService())->unreadCount($_SESSION['user_id'] ?? null);
                ?>
                <a href="/notifications" class="btn btn-link position-relative me-2 me-md-3 text-white p-1">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($notifCount > 0): ?>
                    <span class="position-absolute badge rounded-pill bg-danger" style="top: 0; right: -4px; font-size: 0.6em;">
                        <?= $notifCount ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php
                $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
                $upgradeAvailable = $isAdmin ? (new \BBS\Services\UpdateService())->isUpdateAvailable() : false;
                $agentUpgradeCount = 0;
                if ($isAdmin && !$upgradeAvailable) {
                    $bundledAgentVer = null;
                    $agentFile = dirname(__DIR__, 3) . '/agent/bbs-agent.py';
                    if (file_exists($agentFile)) {
                        $h = fopen($agentFile, 'r');
                        if ($h) {
                            for ($i = 0; $i < 50 && ($ln = fgets($h)) !== false; $i++) {
                                if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $ln, $mv)) {
                                    $bundledAgentVer = $mv[1]; break;
                                }
                            }
                            fclose($h);
                        }
                    }
                    if ($bundledAgentVer) {
                        $db = \BBS\Core\Database::getInstance();
                        $agentUpgradeCount = (int)$db->fetchOne(
                            "SELECT COUNT(*) as cnt FROM agents WHERE agent_version IS NOT NULL AND agent_version != ?",
                            [$bundledAgentVer]
                        )['cnt'];
                    }
                }
                if ($upgradeAvailable): ?>
                <a href="/settings?tab=updates" class="badge bg-warning text-dark text-decoration-none me-2 me-md-3 py-2 px-2 d-none d-sm-inline-block">
                    <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade
                </a>
                <?php elseif ($agentUpgradeCount > 0): ?>
                <a href="/settings?tab=updates" class="badge bg-info text-white text-decoration-none me-2 me-md-3 py-2 px-2 d-none d-sm-inline-block">
                    <i class="bi bi-box-seam me-1"></i> Upgrade Agents
                </a>
                <?php endif; ?>
                <div class="dropdown">
                    <a class="btn btn-link text-white dropdown-toggle text-decoration-none p-1" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline ms-1"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= ucfirst($_SESSION['user_role'] ?? 'user') ?></span></li>
                        <li><a class="dropdown-item" href="/notifications"><i class="bi bi-bell me-1"></i> Notifications<?php if ($notifCount > 0): ?> <span class="badge bg-danger"><?= $notifCount ?></span><?php endif; ?></a></li>
                        <?php if ($upgradeAvailable ?? false): ?>
                        <li><a class="dropdown-item" href="/settings?tab=updates"><i class="bi bi-cloud-arrow-down me-1"></i> Upgrade Available</a></li>
                        <?php elseif (($agentUpgradeCount ?? 0) > 0): ?>
                        <li><a class="dropdown-item" href="/settings?tab=updates"><i class="bi bi-box-seam me-1"></i> Upgrade Agents (<?= $agentUpgradeCount ?>)</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-1"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="/settings"><i class="bi bi-gear me-1"></i> Settings</a></li>
                        <li>
                            <a class="dropdown-item" href="#" onclick="toggleTheme(); return false;">
                                <i class="bi bi-moon me-1" id="themeIcon"></i> <span id="themeLabel">Dark Mode</span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="d-flex">
        <!-- Sidebar (desktop only) -->
        <nav id="sidebar" class="sidebar d-none d-md-flex flex-column flex-shrink-0 text-white">
            <ul class="nav nav-pills flex-column mb-auto text-center">
                <li class="nav-item">
                    <a href="/" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2 d-block mb-1 fs-4"></i>
                        <span class="small">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/clients" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Clients' ? 'active' : '' ?>">
                        <i class="bi bi-display d-block mb-1 fs-4"></i>
                        <span class="small">Clients</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/queue" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Queue' ? 'active' : '' ?>">
                        <i class="bi bi-clock-history d-block mb-1 fs-4"></i>
                        <span class="small">Queue</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/log" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Log' ? 'active' : '' ?>">
                        <i class="bi bi-journal-text d-block mb-1 fs-4"></i>
                        <span class="small">Log</span>
                    </a>
                </li>
                <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <li class="nav-item">
                    <a href="/storage-locations" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Storage' ? 'active' : '' ?>">
                        <i class="bi bi-hdd-stack d-block mb-1 fs-4"></i>
                        <span class="small">Storage</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/settings" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Settings' ? 'active' : '' ?>">
                        <i class="bi bi-gear d-block mb-1 fs-4"></i>
                        <span class="small">Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/users" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Users' ? 'active' : '' ?>">
                        <i class="bi bi-people d-block mb-1 fs-4"></i>
                        <span class="small">Users</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="border-top p-2 text-center">
                <a href="/logout" class="nav-link sidebar-link">
                    <i class="bi bi-box-arrow-left d-block mb-1 fs-4"></i>
                    <span class="small">Logout</span>
                </a>
            </div>
        </nav>

        <!-- Main content -->
        <div class="flex-grow-1 main-content">

            <!-- Maintenance mode banner -->
            <?php
            $maintenanceMode = \BBS\Core\Database::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
            if (($maintenanceMode['value'] ?? '0') === '1'): ?>
            <div class="alert alert-warning d-flex align-items-center m-3 m-md-4 mb-0" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div>
                    <strong>Maintenance mode active</strong> — new backup jobs are paused.
                    <a href="/settings" class="alert-link ms-1">Go to Settings</a>
                </div>
            </div>
            <?php endif; ?>

            <?php $flash = $flash ?? $this->getFlash(); ?>

            <!-- Page content -->
            <div class="p-3 p-md-4">
                <?php require $viewPath . $template . '.php'; ?>
            </div>
        </div>
    </div>

    <!-- Bottom nav (mobile only) -->
    <nav class="mobile-bottom-nav d-md-none">
        <a href="/" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="/clients" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Clients' ? 'active' : '' ?>">
            <i class="bi bi-display"></i>
            <span>Clients</span>
        </a>
        <a href="/queue" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Queue' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i>
            <span>Queue</span>
        </a>
        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="/settings" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Settings' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
        <a href="/users" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Users' ? 'active' : '' ?>">
            <i class="bi bi-people"></i>
            <span>Users</span>
        </a>
        <?php else: ?>
        <a href="/log" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Log' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i>
            <span>Log</span>
        </a>
        <?php endif; ?>
    </nav>

    <!-- Confirm modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-body text-center p-4">
                    <i class="bi bi-question-circle text-warning d-block mb-3" style="font-size:2.5rem;"></i>
                    <p class="mb-0 fs-6" id="confirmMessage"></p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success px-4" id="confirmOk">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:1090;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleTheme() {
        var html = document.documentElement;
        var newTheme = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('bbs-theme', newTheme);
        updateThemeUI(newTheme);
        fetch('/profile/theme', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'theme='+newTheme, credentials:'same-origin'});
    }
    function updateThemeUI(theme) {
        var icon = document.getElementById('themeIcon');
        var label = document.getElementById('themeLabel');
        if (icon) icon.className = 'bi ' + (theme === 'dark' ? 'bi-sun' : 'bi-moon') + ' me-1';
        if (label) label.textContent = theme === 'dark' ? 'Light Mode' : 'Dark Mode';
    }
    updateThemeUI(document.documentElement.getAttribute('data-bs-theme') || 'dark');
    </script>
    <script>
    function confirmAction(message, callback, options) {
        options = options || {};
        var msgEl = document.getElementById('confirmMessage');
        var okBtn = document.getElementById('confirmOk');
        var icon = document.querySelector('#confirmModal .modal-body > i');
        msgEl.innerHTML = message.replace(/\n/g, '<br>');
        // Style the OK button based on type
        okBtn.className = 'btn px-4 btn-' + (options.btnClass || 'success');
        okBtn.textContent = options.okText || 'OK';
        // Icon
        if (options.danger) {
            icon.className = 'bi bi-exclamation-triangle-fill text-danger d-block mb-3';
        } else {
            icon.className = 'bi bi-question-circle text-warning d-block mb-3';
        }
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal'));
        // Remove old listener
        var newOk = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOk, okBtn);
        newOk.id = 'confirmOk';
        newOk.addEventListener('click', function() { modal.hide(); callback(); });
        modal.show();
    }
    // Global handler for data-confirm on forms and buttons
    document.addEventListener('submit', function(e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (!msg) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        var opts = {};
        if (form.hasAttribute('data-confirm-danger')) opts = {danger:true, btnClass:'danger', okText:'Delete'};
        confirmAction(msg, function() {
            form.removeAttribute('data-confirm');
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }, opts);
    });
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn || btn.tagName === 'FORM') return;
        var msg = btn.getAttribute('data-confirm');
        e.preventDefault();
        e.stopImmediatePropagation();
        var opts = {};
        if (btn.hasAttribute('data-confirm-danger')) opts = {danger:true, btnClass:'danger', okText:'Delete'};
        var form = btn.closest('form');
        if (form) {
            confirmAction(msg, function() {
                btn.removeAttribute('data-confirm');
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }, opts);
        } else {
            confirmAction(msg, function() { btn.removeAttribute('data-confirm'); btn.click(); }, opts);
        }
    });
    function showToast(message, type) {
        var iconColors = {success:'#2ecc71',danger:'#e74c3c',warning:'#f39c12',info:'#3498db'};
        var icons = {success:'bi-check-circle-fill',danger:'bi-x-circle-fill',warning:'bi-exclamation-triangle-fill',info:'bi-info-circle-fill'};
        var iconColor = iconColors[type] || iconColors.info;
        var icon = icons[type] || icons.info;
        var el = document.createElement('div');
        el.className = 'toast show';
        el.setAttribute('role', 'alert');
        el.style.cssText = 'max-width:400px;background:#2c3e50;color:#fff;box-shadow:0 8px 32px rgba(0,0,0,.3);border-radius:8px;overflow:hidden;';
        el.innerHTML = '<div class="d-flex align-items-center p-3">' +
            '<i class="bi '+icon+' me-2 flex-shrink-0" style="color:'+iconColor+';font-size:1.25rem;"></i>' +
            '<div class="small flex-grow-1" style="line-height:1.4;">'+message.replace(/</g,'&lt;')+'</div>' +
            '<button type="button" class="btn-close btn-close-white ms-2 flex-shrink-0" style="font-size:.6rem;" onclick="this.closest(\'.toast\').remove()"></button>' +
            '</div>';
        document.getElementById('toastContainer').appendChild(el);
        setTimeout(function(){ if(el.parentNode) el.style.transition='opacity .4s';el.style.opacity='0';setTimeout(function(){if(el.parentNode)el.remove();},400); }, 6000);
    }
    <?php if (!empty($_SESSION['user_id'])): ?>
    (function(){
        var lastCheck = new Date().toISOString().slice(0,19).replace('T',' ');
        var seen = {};
        setInterval(function(){
            fetch('/api/toasts?since='+encodeURIComponent(lastCheck),{credentials:'same-origin'})
                .then(function(r){return r.ok?r.json():null;})
                .then(function(data){
                    if(!data)return;
                    lastCheck=data.server_time;
                    (data.toasts||[]).forEach(function(t){
                        var key=t.type+':'+t.message;
                        if(!seen[key]){seen[key]=1;showToast(t.message,t.type);}
                    });
                    if(Object.keys(seen).length>200)seen={};
                })
                .catch(function(){});
        }, 8000);
    })();
    <?php endif; ?>
    </script>
    <?php if ($flash): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode($flash['message']) ?>, <?= json_encode($flash['type']) ?>); });</script>
    <?php endif; ?>
    <?php if (isset($scripts)): ?>
        <?php foreach ($scripts as $script): ?>
        <script src="<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (empty($_SESSION['timezone'])): ?>
    <script>
    (function(){
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz) fetch('/profile/detect-timezone', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'timezone='+encodeURIComponent(tz)});
        } catch(e){}
    })();
    </script>
    <?php endif; ?>
</body>
</html>
