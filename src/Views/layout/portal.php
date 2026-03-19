<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : null;
$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);
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
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top right, rgba(25, 135, 84, 0.15), transparent 25%),
                linear-gradient(180deg, #f7fbf8 0%, #eef5f0 100%);
        }
        .portal-shell { min-height: 100vh; display: flex; flex-direction: column; }
        main { flex: 1; }
        .portal-brand { font-weight: 700; letter-spacing: 0.04em; }
    </style>
    <?= $pageStyles ?>
</head>
<body>
<div class="portal-shell">
    <header class="border-bottom bg-white py-3 shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="portal-brand">TechRecruit Portal</div>
            <div class="text-muted small">Cadastro e documentos do candidato</div>
        </div>
    </header>

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
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
<?= $pageScripts ?>
</body>
</html>
