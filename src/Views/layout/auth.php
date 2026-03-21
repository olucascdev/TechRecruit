<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : null;
$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);
$pageStyles = $pageStyles ?? '';
$pageScripts = $pageScripts ?? '';
$authContext = $authContext ?? 'login';

require __DIR__ . '/_ui.php';
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
<body class="app-theme">
<div class="app-shell">
    <main class="flex flex-1 items-center py-10 sm:py-14">
        <div class="container">
            <div class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
                <section class="shell-panel overflow-hidden px-6 py-8 sm:px-8 sm:py-10">
                    <div class="flex items-center gap-4">
                        <span class="inline-flex h-14 w-14 items-center justify-center rounded-3xl bg-gradient-to-br from-brand-500 via-brand-400 to-accent-500 font-display text-sm font-bold uppercase tracking-[0.28em] text-white shadow-glow">TR</span>
                        <div>
                            <p class="h6 mb-1">Acesso Interno</p>
                            <h1 class="h3 mb-0">TechRecruit Backoffice</h1>
                        </div>
                    </div>

                    <div class="mt-8 space-y-5 text-sm leading-7 text-slate-600">
                        <p>
                            <?php if ($authContext === 'setup'): ?>
                                O setup inicial replica a lógica do RecargaAki: se não existe usuário interno, o primeiro administrador é criado no navegador e o fluxo público é bloqueado depois disso.
                            <?php else: ?>
                                Este ambiente é exclusivo para usuários da gestão. Depois do bootstrap inicial, o cadastro deixa de ser público e a criação de novos acessos fica centralizada em administradores.
                            <?php endif; ?>
                        </p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-3xl border border-brand-100 bg-brand-50/80 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-700">Login</div>
                                <p class="mt-2 text-sm text-brand-900">Sessão protegida com senha hash, status ativo e autenticação por username ou e-mail.</p>
                            </div>
                            <div class="rounded-3xl border border-emerald-100 bg-emerald-50/80 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Cadastro</div>
                                <p class="mt-2 text-sm text-emerald-900">O primeiro admin nasce no setup. Depois disso, novos usuários são criados só no ambiente interno de gestão.</p>
                            </div>
                            <div class="rounded-3xl border border-slate-200 bg-slate-50/90 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-700">Roles</div>
                                <p class="mt-2 text-sm text-slate-900">`admin` gerencia acessos. `manager` opera o backoffice.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section>
                    <?php $renderFlash($flashSuccess, 'success'); ?>
                    <?php $renderFlash($flashError, 'error'); ?>
                    <?= $content ?>
                </section>
            </div>
        </div>
    </main>
</div>

<?php $renderUiScripts(); ?>
<?= $pageScripts ?>
</body>
</html>
