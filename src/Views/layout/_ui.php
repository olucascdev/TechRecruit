<?php

declare(strict_types=1);

$renderTailwindHead = static function (): void {
    $assetPath = dirname(__DIR__, 3) . '/public/assets/app.css';
    $assetVersion = is_file($assetPath) ? (string) filemtime($assetPath) : 'dev';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php
};

$renderFlash = static function (?string $message, string $tone) use ($escape): void {
    if ($message === null || trim($message) === '') {
        return;
    }

    $classes = match ($tone) {
        'success' => 'alert alert-success alert-dismissible',
        'error' => 'alert alert-danger alert-dismissible',
        default => 'alert alert-info alert-dismissible',
    };
    ?>
    <div class="<?= $classes ?>" role="alert">
        <div><?= $escape($message) ?></div>
        <button type="button" class="btn-close" data-dismiss="alert" aria-label="Fechar"></button>
    </div>
    <?php
};

$renderUiScripts = static function (): void {
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-nav-toggle]').forEach(function (button) {
                var targetId = button.getAttribute('data-nav-toggle');
                var target = targetId ? document.getElementById(targetId) : null;

                if (!target) {
                    return;
                }

                button.addEventListener('click', function () {
                    var shouldOpen = target.classList.contains('hidden');

                    target.classList.toggle('hidden');
                    button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                });
            });

            document.addEventListener('click', function (event) {
                var closeButton = event.target.closest('[data-dismiss="alert"]');

                if (!closeButton) {
                    return;
                }

                var alertNode = closeButton.closest('.alert');

                if (alertNode) {
                    alertNode.remove();
                }
            });
        });
    </script>
    <?php
};
