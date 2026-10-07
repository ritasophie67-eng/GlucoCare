<?php
// Page shell (top). Set $page_title and optionally $layout = 'auth' before including.

require_once __DIR__ . '/auth.php';

$page_title = $page_title ?? APP_NAME;
$layout = $layout ?? 'app';
$user = current_user();
$flash = take_flash();
$currentScript = ltrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    <?php if (!empty($use_charts)): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body class="layout-<?= e($layout) ?>">

<?php if ($layout === 'auth'): ?>
<div class="auth-wrap">
    <aside class="auth-hero">
        <div class="brand brand-light"><i class="bi bi-droplet-half"></i> <?= e(APP_NAME) ?></div>
        <div class="auth-hero-body">
            <h1>Smarter diabetes care, shared between patient and doctor.</h1>
            <p>Log your readings at home. Your care team gets clear, explainable alerts when it matters.</p>
            <ul class="hero-points">
                <li><i class="bi bi-shield-check"></i> Role-based, private by design</li>
                <li><i class="bi bi-person-check"></i> Doctors see only their own patients</li>
                <li><i class="bi bi-journal-check"></i> Every action is audited</li>
            </ul>
        </div>
        <div class="auth-hero-foot"><?= e(HOSPITAL_NAME) ?> · Sentema Road, Kampala</div>
    </aside>
    <main class="auth-panel">
        <div class="auth-card">
<?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> shadow-sm"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php else: ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= BASE_URL ?>"><i class="bi bi-droplet-half"></i> <?= e(APP_NAME) ?></a>
        <div class="sidebar-hospital"><?= e(HOSPITAL_NAME) ?></div>
        <nav class="sidebar-nav">
            <?php foreach (nav_items($user['role']) as [$path, $label, $icon]): ?>
                <a href="<?= BASE_URL . $path ?>" class="<?= $currentScript !== '' && str_ends_with($currentScript, $path) ? 'active' : '' ?>">
                    <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <a href="<?= BASE_URL ?>change_password.php"><i class="bi bi-key"></i><span>Change password</span></a>
            <a href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-left"></i><span>Sign out</span></a>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="btn btn-icon d-lg-none" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu">
                <i class="bi bi-list fs-4"></i>
            </button>
            <div class="topbar-title"><?= e($page_title) ?></div>
            <div class="topbar-user">
                <div class="avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
                <div class="d-none d-sm-block lh-sm">
                    <div class="fw-semibold small"><?= e($user['full_name']) ?></div>
                    <span class="role-pill role-<?= e($user['role']) ?>"><?= e($user['role']) ?></span>
                </div>
            </div>
        </header>
        <main class="content">
<?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
<?php endif; ?>
<?php endif; ?>
