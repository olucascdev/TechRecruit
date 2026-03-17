<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : null;
$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);

$isActive = static function (string $targetPath) use ($currentPath): string {
    if ($targetPath === '/') {
        return $currentPath === '/' ? 'active' : '';
    }

    return str_starts_with($currentPath, $targetPath) ? 'active' : '';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | TechRecruit</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <style>
        body { min-height: 100vh; background: #f8f9fa; }
        .app-shell { min-height: 100vh; display: flex; flex-direction: column; }
        main { flex: 1; }
        .navbar-brand { font-weight: 700; letter-spacing: 0.03em; }
        .timeline { position: relative; padding-left: 1.5rem; }
        .timeline::before {
            content: "";
            position: absolute;
            left: 0.45rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .timeline-item { position: relative; padding-bottom: 1.5rem; }
        .timeline-item::before {
            content: "";
            position: absolute;
            left: -1.08rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background: #0d6efd;
        }
    </style>
    <?= $pageStyles ?>
</head>
<body>
<div class="app-shell">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="/candidates">TechRecruit</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Alternar navegacao">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive('/candidates') ?: ($currentPath === '/' ? 'active' : '') ?>" href="/candidates">Candidatos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive('/import') ?>" href="/import">Importacoes</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="py-4">
        <div class="container">
            <?php if ($flashSuccess !== null): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $escape($flashSuccess) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?php if ($flashError !== null): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $escape($flashError) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </div>
    </main>

    <footer class="border-top bg-white py-3">
        <div class="container text-muted small d-flex justify-content-between">
            <span>TechRecruit Backoffice</span>
            <span>Bootstrap 5 + PHP 8</span>
        </div>
    </footer>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
<?= $pageScripts ?>
</body>
</html>
