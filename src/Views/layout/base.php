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
    '/candidates' => [
        'label' => 'Candidatos',
        'description' => 'Base, triagem e histórico',
        'icon' => 'candidates',
    ],
    '/campaigns' => [
        'label' => 'Campanhas',
        'description' => 'Disparos e automações',
        'icon' => 'campaigns',
    ],
    '/operations' => [
        'label' => 'Operações',
        'description' => 'Validação e decisões',
        'icon' => 'operations',
    ],
    '/import' => [
        'label' => 'Importações',
        'description' => 'Entrada de planilhas',
        'icon' => 'import',
    ],
    '/faq' => [
        'label' => 'FAQ',
        'description' => 'Processos e operação',
        'icon' => 'faq',
    ],
];

if ($authUser !== null && ($authUser['role'] ?? null) === \TechRecruit\Models\UserModel::ROLE_ADMIN) {
    $navItems['/management/users'] = [
        'label' => 'Usuários',
        'description' => 'Acesso, roles e gestão',
        'icon' => 'users',
    ];
}

$roleBadgeClass = static function (?string $role): string {
    return $role === \TechRecruit\Models\UserModel::ROLE_ADMIN ? 'bg-primary' : 'bg-secondary';
};

$renderNavIcon = static function (string $icon): void {
    switch ($icon) {
        case 'candidates':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="M16 19a4 4 0 0 0-8 0"></path>
                <circle cx="12" cy="11" r="3"></circle>
                <path d="M5 19a7 7 0 0 1 14 0"></path>
            </svg>
            <?php
            return;
        case 'campaigns':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="M3 11.5 20 5v14L3 12.5V11.5Z"></path>
                <path d="M7 13.5V17a2 2 0 0 0 2 2h1"></path>
                <path d="M20 9c1.5 1 1.5 5 0 6"></path>
            </svg>
            <?php
            return;
        case 'operations':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="M9 5.5h6"></path>
                <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"></path>
                <path d="m9 12 2 2 4-4"></path>
            </svg>
            <?php
            return;
        case 'import':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="M12 15V4"></path>
                <path d="m8 8 4-4 4 4"></path>
                <path d="M5 19.5h14"></path>
            </svg>
            <?php
            return;
        case 'users':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="M12 3.5 5 6.5V12c0 4.2 2.8 7.9 7 9 4.2-1.1 7-4.8 7-9V6.5l-7-3Z"></path>
                <path d="m9.75 12 1.5 1.5 3-3"></path>
            </svg>
            <?php
            return;
        case 'faq':
            ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M9.25 9.5a2.75 2.75 0 1 1 4.55 2.08c-.8.67-1.3 1.12-1.3 2.17"></path>
                <path d="M12 16.75h.01"></path>
            </svg>
            <?php
            return;
    }
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $escape($csrfToken ?? '') ?>">
    <title><?= $escape($pageTitle) ?> | TechRecruit</title>
    <?php $renderTailwindHead(); ?>
    <?= $pageStyles ?>
</head>
<body class="app-theme bg-ink-50">
<div class="min-h-screen bg-[radial-gradient(circle_at_top_right,_rgba(47,149,251,0.14),_transparent_26%),linear-gradient(180deg,_#f8fbff_0%,_#f4f7fb_55%,_#eef3f8_100%)] text-ink-950">
    <div id="appSidebar" class="fixed inset-0 z-50 hidden lg:static lg:z-auto lg:block">
        <button
            type="button"
            class="absolute inset-0 bg-ink-950/55 backdrop-blur-sm lg:hidden"
            data-nav-toggle="appSidebar"
            aria-label="Fechar menu lateral"
        ></button>

        <aside class="absolute inset-y-0 left-0 flex w-[19.5rem] max-w-[88vw] flex-col border-r border-slate-200/80 bg-white/95 px-5 py-5 shadow-2xl backdrop-blur-xl lg:fixed lg:inset-y-0 lg:left-0 lg:w-80 lg:max-w-none lg:shadow-none">
            <div class="flex items-start justify-between gap-3">
                <a href="<?= $escape($url('/candidates')) ?>" class="flex items-center gap-3 text-ink-950 no-underline">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 via-brand-400 to-accent-500 font-display text-sm font-bold uppercase tracking-[0.24em] text-white shadow-glow">TR</span>
                    <span>
                        <span class="block font-display text-lg font-semibold tracking-[0.16em] uppercase text-ink-950">TechRecruit</span>
                        <span class="block text-xs text-slate-500">Backoffice operacional</span>
                    </span>
                </a>

                <button
                    type="button"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 lg:hidden"
                    data-nav-toggle="appSidebar"
                    aria-controls="appSidebar"
                    aria-expanded="false"
                    aria-label="Fechar menu"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                        <path d="M6 6 18 18"></path>
                        <path d="m18 6-12 12"></path>
                    </svg>
                </button>
            </div>

            <nav class="mt-6 flex-1 space-y-2" aria-label="Navegação principal">
                <?php foreach ($navItems as $path => $item): ?>
                    <?php
                    $active = $path === '/candidates' && $currentPath === '/' ? 'active' : $isActive($path);
                    $linkClasses = $active
                        ? 'bg-ink-950 text-white shadow-soft ring-1 ring-ink-900/10'
                        : 'bg-transparent text-slate-600 hover:bg-slate-100 hover:text-ink-950';
                    $iconWrapClasses = $active
                        ? 'bg-white/12 text-white'
                        : 'bg-slate-100 text-slate-600 group-hover:bg-white group-hover:text-ink-950';
                    $descriptionClasses = $active ? 'text-white/72' : 'text-slate-500';
                    ?>
                    <a
                        href="<?= $escape($url($path)) ?>"
                        class="group flex min-h-[72px] items-center gap-3 rounded-[24px] px-3 py-3 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 <?= $linkClasses ?>"
                    >
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl transition <?= $iconWrapClasses ?>">
                            <?php $renderNavIcon((string) $item['icon']); ?>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold leading-5"><?= $escape($item['label']) ?></span>
                            <span class="mt-1 block text-xs leading-5 <?= $descriptionClasses ?>"><?= $escape($item['description']) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($authUser !== null): ?>
                <div class="mt-6 rounded-[28px] border border-slate-200 bg-slate-50/90 p-4 shadow-card">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-sm font-semibold uppercase tracking-[0.16em] text-brand-700 shadow-sm">
                            <?= $escape(mb_substr((string) ($authUser['full_name'] ?? 'U'), 0, 2)) ?>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-ink-950"><?= $escape($authUser['full_name'] ?? '-') ?></div>
                            <div class="truncate text-xs text-slate-500">@<?= $escape($authUser['username'] ?? '-') ?></div>
                            <div class="truncate text-xs text-slate-500"><?= $escape($authUser['email'] ?? '-') ?></div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <span class="badge <?= $roleBadgeClass((string) ($authUser['role'] ?? null)) ?>">
                            <?= $escape($roleLabels[$authUser['role']] ?? (string) ($authUser['role'] ?? '-')) ?>
                        </span>
                        <form action="<?= $escape($url('/logout')) ?>" method="post">
                            <?= $csrfField ?>
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Sair</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>

    <div class="lg:pl-80">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-white/70 bg-white/85 backdrop-blur-xl lg:hidden">
                <div class="container py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Painel</div>
                            <div class="truncate text-lg font-semibold text-ink-950"><?= $escape($pageTitle) ?></div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                            data-nav-toggle="appSidebar"
                            aria-controls="appSidebar"
                            aria-expanded="false"
                            aria-label="Abrir menu lateral"
                        >
                            <span class="flex flex-col gap-1.5">
                                <span class="h-0.5 w-5 rounded-full bg-current"></span>
                                <span class="h-0.5 w-5 rounded-full bg-current"></span>
                                <span class="h-0.5 w-5 rounded-full bg-current"></span>
                            </span>
                        </button>
                    </div>
                </div>
            </header>

            <main class="flex-1 py-6 sm:py-8 lg:py-10">
                <div class="container">
                    <div class="mb-6 hidden items-center justify-between gap-4 lg:flex">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Painel interno</div>
                            <div class="mt-1 text-lg font-semibold text-ink-950"><?= $escape($pageTitle) ?></div>
                        </div>
                        <?php if ($authUser !== null): ?>
                            <div class="inline-flex items-center gap-3 rounded-full border border-slate-200 bg-white/85 px-4 py-2 text-sm text-slate-600 shadow-sm backdrop-blur">
                                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-accent-500"></span>
                                <span class="font-medium text-ink-950"><?= $escape($authUser['full_name'] ?? '-') ?></span>
                                <span class="text-slate-400">•</span>
                                <span>@<?= $escape($authUser['username'] ?? '-') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php $renderFlash($flashSuccess, 'success'); ?>
                    <?php $renderFlash($flashError, 'error'); ?>

                    <?= $content ?>
                </div>
            </main>

            <footer class="py-6">
                <div class="container">
                    <div class="rounded-[28px] border border-white/70 bg-white/80 px-5 py-4 text-sm text-slate-600 shadow-sm backdrop-blur sm:px-6">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-ink-950">TechRecruit Backoffice</span>
                            <span class="text-slate-500">Sidebar fixa para navegação operacional contínua</span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>
</div>

<?php $renderUiScripts(); ?>
<?= $pageScripts ?>
</body>
</html>
