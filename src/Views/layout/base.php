<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : null;
$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);
$pageStyles = $pageStyles ?? '';
$pageScripts = $pageScripts ?? '';
$authUser = is_array($authUser ?? null) ? $authUser : null;
$roleLabels = \TechRecruit\Models\UserModel::ROLE_LABELS;

$isActive = static function (string $targetPath) use ($currentPath): string {
    if ($targetPath === '/') {
        return $currentPath === '/' ? 'active' : '';
    }

    return str_starts_with($currentPath, $targetPath) ? 'active' : '';
};

require __DIR__ . '/_ui.php';

$navItems = [
    '/candidates' => 'Candidatos',
    '/campaigns' => 'Campanhas',
    '/operations' => 'Operações',
    '/import' => 'Importações',
];

if ($authUser !== null && ($authUser['role'] ?? null) === \TechRecruit\Models\UserModel::ROLE_ADMIN) {
    $navItems['/management/users'] = 'Usuários';
}

$roleBadgeClass = static function (?string $role): string {
    return $role === \TechRecruit\Models\UserModel::ROLE_ADMIN ? 'bg-primary' : 'bg-secondary';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | TechRecruit</title>
    <?php $renderTailwindHead(); ?>
    <?= $pageStyles ?>
</head>
<body class="app-theme">
<div class="app-shell">
    <header class="border-b border-slate-200 bg-white/90 backdrop-blur-xl">
        <div class="container py-4">
            <div class="shell-panel px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between gap-4">
                    <a href="/candidates" class="flex items-center gap-3 text-ink-950 no-underline">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-400 font-display text-sm font-bold uppercase tracking-[0.24em] text-white shadow-glow">TR</span>
                        <span>
                            <span class="block font-display text-lg font-semibold tracking-[0.18em] uppercase text-ink-950">TechRecruit</span>
                            <span class="block text-xs text-slate-500">Backoffice de recrutamento, campanha e operação</span>
                        </span>
                    </a>

                    <button
                        type="button"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 lg:hidden"
                        data-nav-toggle="mainNav"
                        aria-controls="mainNav"
                        aria-expanded="false"
                        aria-label="Alternar navegação"
                    >
                        <span class="flex flex-col gap-1.5">
                            <span class="h-0.5 w-5 rounded-full bg-current"></span>
                            <span class="h-0.5 w-5 rounded-full bg-current"></span>
                            <span class="h-0.5 w-5 rounded-full bg-current"></span>
                        </span>
                    </button>
                </div>

                <div id="mainNav" class="mt-4 hidden flex-col gap-2 border-t border-slate-200 pt-4 lg:mt-6 lg:flex lg:flex-row lg:items-center lg:justify-between lg:border-t-0 lg:pt-0">
                    <nav class="flex flex-col gap-2 lg:flex-row lg:flex-wrap">
                        <?php foreach ($navItems as $path => $label): ?>
                            <?php $active = $path === '/candidates' && $currentPath === '/' ? 'active' : $isActive($path); ?>
                            <a
                                href="<?= $escape($path) ?>"
                                class="inline-flex min-h-[44px] items-center rounded-full px-4 py-2 text-sm font-semibold transition <?= $active ? 'bg-brand-500 text-white shadow-glow' : 'text-slate-600 hover:bg-slate-100 hover:text-ink-950' ?>"
                            >
                                <?= $escape($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                        <div class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium tracking-[0.16em] text-slate-500 uppercase">
                            <?= $escape($pageTitle) ?>
                        </div>

                        <?php if ($authUser !== null): ?>
                            <div class="flex flex-col gap-3 rounded-[24px] border border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-ink-950"><?= $escape($authUser['full_name'] ?? '-') ?></div>
                                    <div class="truncate text-xs text-slate-500"><?= $escape($authUser['email'] ?? '-') ?></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="badge <?= $roleBadgeClass((string) ($authUser['role'] ?? null)) ?>">
                                        <?= $escape($roleLabels[$authUser['role']] ?? (string) ($authUser['role'] ?? '-')) ?>
                                    </span>
                                    <form action="/logout" method="post">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">Sair</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 py-8 sm:py-10">
        <div class="container">
            <?php $renderFlash($flashSuccess, 'success'); ?>
            <?php $renderFlash($flashError, 'error'); ?>

            <?= $content ?>
        </div>
    </main>

    <footer class="border-t border-slate-200 py-6">
        <div class="container">
            <div class="shell-panel px-5 py-4 text-sm text-slate-600 sm:px-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <span class="font-medium text-ink-950">TechRecruit Backoffice</span>
                    <span class="text-slate-500">Tailwind UI + PHP 8</span>
                </div>
            </div>
        </div>
    </footer>
</div>

<?php $renderUiScripts(); ?>
<?= $pageScripts ?>
</body>
</html>
