<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Borg Backup Server</title>
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
                <img src="/images/bbs-logo-small.png" alt="BBS" style="height: 36px;">
            </a>
            <span class="navbar-text fw-semibold ms-3 d-none d-sm-inline"><?= htmlspecialchars($pageTitle ?? '') ?></span>
            <span class="navbar-text fw-semibold ms-2 d-sm-none small"><?= htmlspecialchars($pageTitle ?? '') ?></span>
            <div class="d-flex align-items-center ms-auto me-2 me-md-3">
                <?php
                $notifCount = $notifCount ?? (new \BBS\Services\NotificationService())->unreadCount();
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
                $upgradeAvailable = (new \BBS\Services\UpdateService())->isUpdateAvailable();
                if ($upgradeAvailable): ?>
                <a href="/settings?tab=updates" class="badge bg-warning text-dark text-decoration-none me-2 me-md-3 py-2 px-2 d-none d-sm-inline-block">
                    <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade
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
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-1"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="/settings"><i class="bi bi-gear me-1"></i> Settings</a></li>
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
                <strong>Maintenance mode active</strong> — new backups are paused while an upgrade is in progress.
            </div>
            <?php endif; ?>

            <!-- Flash messages -->
            <?php $flash = $flash ?? $this->getFlash(); ?>
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show m-3 m-md-4 mb-0" role="alert">
                <?= htmlspecialchars($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

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
        <a href="/log" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Log' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i>
            <span>Log</span>
        </a>
        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="/settings" class="mobile-nav-item <?= ($pageTitle ?? '') === 'Settings' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (isset($scripts)): ?>
        <?php foreach ($scripts as $script): ?>
        <script src="<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
