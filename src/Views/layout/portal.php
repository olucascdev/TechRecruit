<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : null;
$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);
$pageStyles = $pageStyles ?? '';
$pageScripts = $pageScripts ?? '';

require __DIR__ . '/_ui.php';
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
<body class="portal-theme">
<div class="portal-shell">
    <header class="py-6">
        <div class="container">
            <div class="shell-panel-light px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-4">
                        <span class="inline-flex h-14 w-14 items-center justify-center rounded-3xl bg-gradient-to-br from-brand-500 to-brand-400 font-display text-sm font-bold uppercase tracking-[0.24em] text-white shadow-glow">TR</span>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-brand-600">Portal do Candidato</div>
                            <div class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink-950">Cadastro e documentos W13</div>
                            <p class="mt-2 max-w-2xl text-sm text-slate-600">Preencha seus dados, envie os arquivos solicitados e acompanhe o status do processo de validação em uma única tela.</p>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Ambiente seguro para envio de informações e anexos.
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 py-6 sm:py-8">
        <div class="container">
            <?php $renderFlash($flashSuccess, 'success'); ?>
            <?php $renderFlash($flashError, 'error'); ?>

            <?= $content ?>
        </div>
    </main>

    <footer class="pb-8">
        <div class="container">
            <div class="text-center text-sm text-slate-500">
                TechRecruit Portal · Tailwind UI + PHP 8
            </div>
        </div>
    </footer>
</div>

<?php $renderUiScripts(); ?>
<?= $pageScripts ?>
</body>
</html>
