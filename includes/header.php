<?php
// Page shell (top). Set $page_title, $page_description and optionally $layout = 'auth' before including.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

$page_title = $page_title ?? APP_NAME;
$page_description = $page_description ?? '';
$layout = $layout ?? 'app';
$user = current_user();
$flash = take_flash();
$currentScript = ltrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/');

$role_meta = [
    'patient'   => ['label' => 'Patient', 'tone' => 'bg-primary/10 text-primary'],
    'clinician' => ['label' => 'Clinician', 'tone' => 'bg-success/10 text-success'],
    'admin'     => ['label' => 'Administrator', 'tone' => 'bg-slate-900/8 text-slate-700'],
];
$role = $role_meta[$user['role'] ?? 'patient'] ?? $role_meta['patient'];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f8fafc">
    <link rel="manifest" href="<?= BASE_URL ?>manifest.webmanifest">
    <link rel="icon" href="<?= BASE_URL ?>assets/img/favicon.svg" type="image/svg+xml">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/app.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/app.css') ?>" rel="stylesheet">
    <?php if (!empty($use_charts)): ?>
        <script src="<?= BASE_URL ?>assets/js/vendor/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body class="min-h-full bg-background font-sans text-foreground" data-base="<?= e(BASE_URL) ?>">
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground">Skip to main content</a>

<?php if ($layout === 'auth'): ?>
<div class="grid min-h-dvh lg:grid-cols-[1.05fr_1fr]">
    <aside class="relative hidden flex-col overflow-hidden bg-slate-950 px-12 py-12 text-white lg:flex">
        <?php // Care photo with a layered scrim so the copy stays readable on any crop. ?>
        <div class="absolute inset-0 bg-cover bg-center bg-no-repeat saturate-105" aria-hidden="true"
             style="background-image: url('<?= BASE_URL ?>assets/img/auth-hero.jpeg')"></div>
        <?php // Two scrims: a left-to-right wash keeps the copy legible, a base fade anchors the footer. ?>
        <div class="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/70 to-slate-950/15" aria-hidden="true"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-transparent to-slate-900/25" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_18%_10%,rgba(56,132,255,0.18),transparent_50%),radial-gradient(circle_at_85%_90%,rgba(16,154,106,0.16),transparent_48%)]" aria-hidden="true"></div>

        <div class="relative flex items-center gap-2.5 text-lg font-semibold tracking-tight">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-white/10 text-white ring-1 ring-inset ring-white/15 backdrop-blur"><?= icon('droplet', 'h-5 w-5') ?></span>
            <?= e(APP_NAME) ?>
        </div>

        <div class="relative my-auto max-w-lg">
            <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-medium text-sky-50 backdrop-blur">
                <?= icon('heart-pulse', 'h-3.5 w-3.5') ?> <?= e(PLATFORM_TAGLINE) ?>
            </p>
            <h1 class="text-[2.5rem] font-semibold leading-[1.14] tracking-tight drop-shadow-sm">Care that follows you, wherever you're treated.</h1>
            <p class="mt-5 max-w-md text-[0.98rem] leading-relaxed text-slate-200">
                Log readings from home and your care team sees the patterns that matter — with alerts they can explain,
                and data they know how much to trust. One record, across every hospital on the network.
            </p>
            <ul class="mt-8 space-y-3.5 text-sm text-slate-100">
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-400/20 text-emerald-200 ring-1 ring-inset ring-emerald-300/25"><?= icon('shield-check', 'h-3.5 w-3.5') ?></span>
                    <span><strong class="font-semibold">Private by design.</strong> Only your care team sees your readings.</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-sky-400/20 text-sky-200 ring-1 ring-inset ring-sky-300/25"><?= icon('sparkles', 'h-3.5 w-3.5') ?></span>
                    <span><strong class="font-semibold">Alerts you can understand.</strong> Every flag states the rule behind it and how much to trust the data.</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-amber-400/20 text-amber-200 ring-1 ring-inset ring-amber-300/25"><?= icon('users', 'h-3.5 w-3.5') ?></span>
                    <span><strong class="font-semibold">Built for networks.</strong> Your record works across hospitals, not just one.</span>
                </li>
            </ul>
        </div>

        <div class="relative flex items-center justify-between text-xs text-slate-300">
            <span class="inline-flex items-center gap-1.5"><?= icon('users', 'h-3.5 w-3.5') ?> Multi-hospital diabetes platform</span>
            <span class="rounded-full border border-white/15 bg-white/5 px-2 py-0.5 backdrop-blur">Prototype</span>
        </div>
    </aside>

    <main id="main-content" class="flex items-center justify-center bg-background px-4 py-8 sm:px-8 sm:py-10">
        <div class="w-full max-w-[27rem]">
            <div class="mb-7 flex items-center gap-2.5 text-lg font-semibold tracking-tight text-foreground lg:hidden">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground"><?= icon('droplet', 'h-5 w-5') ?></span>
                <?= e(APP_NAME) ?>
            </div>
            <div class="rounded-lg border border-border bg-card p-5 shadow-card sm:p-8">
<?php else: ?>
<div class="flex min-h-dvh">
    <div id="sidebar-backdrop" class="fixed inset-0 z-40 hidden bg-slate-950/40 backdrop-blur-[1px] lg:hidden"></div>

    <aside id="sidebar" aria-label="Primary navigation"
           class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-sidebar-border bg-gradient-to-b from-sidebar to-sidebar-deep text-sidebar-foreground transition-transform duration-200 ease-out lg:sticky lg:top-0 lg:h-dvh lg:max-w-none lg:translate-x-0">
        <div class="flex h-16 items-center justify-between border-b border-sidebar-border px-5">
            <a href="<?= BASE_URL ?>" class="flex min-w-0 items-center gap-2.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/10 text-white ring-1 ring-inset ring-white/15"><?= icon('droplet', 'h-5 w-5') ?></span>
                <span class="min-w-0">
                    <span class="block truncate text-[0.95rem] font-semibold leading-tight tracking-tight text-white"><?= e(APP_NAME) ?></span>
                    <span class="block truncate text-[0.68rem] font-medium text-sidebar-muted"><?= e(PLATFORM_TAGLINE) ?></span>
                </span>
            </a>
            <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-sidebar-muted hover:bg-white/10 hover:text-white lg:hidden"
                    data-sidebar-close aria-label="Close navigation">
                <?= icon('x', 'h-4 w-4') ?>
            </button>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 py-4">
            <p class="px-2.5 pb-1.5 text-[0.65rem] font-semibold uppercase tracking-[0.09em] text-sidebar-muted/80">Workspace</p>
            <?php foreach (nav_items($user['role']) as [$path, $label, $iconName]): ?>
                <?php $isActive = $currentScript !== '' && str_ends_with($currentScript, $path); ?>
                <a href="<?= BASE_URL . $path ?>"
                   class="group flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar <?= $isActive ? 'bg-white/10 text-white shadow-sm ring-1 ring-inset ring-white/10' : 'text-sidebar-foreground/75 hover:bg-white/5 hover:text-white' ?>"
                   <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <span class="<?= $isActive ? 'text-sky-300' : 'text-sidebar-muted group-hover:text-white' ?>"><?= icon($iconName, 'h-4 w-4') ?></span>
                    <span class="truncate"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="border-t border-sidebar-border p-3">
            <div class="mb-2 flex items-center gap-2.5 rounded-md bg-white/5 px-2.5 py-2">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/10 text-sm font-semibold text-white ring-1 ring-inset ring-white/15">
                    <?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium leading-tight text-white"><?= e($user['full_name']) ?></span>
                    <span class="block text-[0.68rem] font-medium uppercase tracking-wide text-sidebar-muted"><?= e($role['label']) ?></span>
                </span>
            </div>
            <a href="<?= BASE_URL ?>change_password.php" class="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-sidebar-foreground/80 transition-colors hover:bg-white/5 hover:text-white focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar">
                <?= icon('key-round', 'h-4 w-4 text-sidebar-muted') ?> Change password
            </a>
            <a href="<?= BASE_URL ?>logout.php" class="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-sidebar-foreground/80 transition-colors hover:bg-destructive/20 hover:text-white focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar">
                <?= icon('log-out', 'h-4 w-4 text-sidebar-muted') ?> Sign out
            </a>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-white/85 px-4 backdrop-blur-md sm:px-6 lg:px-8">
            <button id="sidebar-toggle" type="button" aria-expanded="false" aria-controls="sidebar"
                    class="grid h-9 w-9 place-items-center rounded-md border border-border text-foreground hover:bg-muted lg:hidden">
                <?= icon('menu', 'h-4 w-4') ?><span class="sr-only">Open navigation</span>
            </button>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-foreground"><?= e($page_title) ?></p>
                <p class="hidden truncate text-xs text-muted-foreground sm:block"><?= e(date('l, j F Y')) ?> · <?= e($role['label']) ?> workspace</p>
            </div>
            <span class="hidden items-center gap-1.5 rounded-full border border-border bg-white px-2.5 py-1 text-[0.68rem] font-medium text-muted-foreground md:inline-flex">
                <?= icon('activity', 'h-3.5 w-3.5 text-success') ?> Rules engine live
            </span>
            <span class="hidden rounded-full px-2.5 py-1 text-[0.68rem] font-semibold sm:inline-block <?= e($role['tone']) ?>"><?= e($role['label']) ?></span>
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary" aria-hidden="true">
                <?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?>
            </span>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-[1400px] flex-1 scroll-mt-20 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
<?php endif; ?>
