<?php

declare(strict_types=1);

$renderTailwindHead = static function (): void {
    $assetCandidates = [
        dirname(__DIR__, 3) . '/public/assets/app.css',
        dirname(__DIR__, 3) . '/assets/app.css',
    ];
    $assetPath = null;

    foreach ($assetCandidates as $candidate) {
        if (is_file($candidate)) {
            $assetPath = $candidate;
            break;
        }
    }

    $assetVersion = is_string($assetPath) ? (string) filemtime($assetPath) : 'dev';
    $assetUrl = isset($url) && is_callable($url) ? $url('/assets/app.css') : '/assets/app.css';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
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
            var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            window.TechRecruit = Object.assign(window.TechRecruit || {}, {
                csrfToken: csrfToken,
                csrfHeaders: function (headers) {
                    var nextHeaders = Object.assign({}, headers || {});

                    if (csrfToken !== '') {
                        nextHeaders['X-CSRF-Token'] = csrfToken;
                    }

                    return nextHeaders;
                }
            });

            if (csrfToken !== '') {
                document.querySelectorAll('form').forEach(function (form) {
                    var method = (form.getAttribute('method') || 'get').toLowerCase();

                    if (method !== 'post' || form.querySelector('input[name="_token"]')) {
                        return;
                    }

                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = '_token';
                    input.value = csrfToken;
                    form.appendChild(input);
                });
            }

            function setTargetState(target, shouldOpen) {
                target.classList.toggle('hidden', !shouldOpen);

                if (target.id === 'appSidebar' && window.innerWidth < 1024) {
                    document.body.classList.toggle('overflow-hidden', shouldOpen);
                }
            }

            document.querySelectorAll('[data-nav-toggle]').forEach(function (button) {
                var targetId = button.getAttribute('data-nav-toggle');
                var target = targetId ? document.getElementById(targetId) : null;

                if (!target) {
                    return;
                }

                button.addEventListener('click', function () {
                    var shouldOpen = target.classList.contains('hidden');

                    setTargetState(target, shouldOpen);

                    document.querySelectorAll('[data-nav-toggle="' + targetId + '"]').forEach(function (toggleButton) {
                        toggleButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                    });
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

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                var sidebar = document.getElementById('appSidebar');

                if (!sidebar || sidebar.classList.contains('hidden') || window.innerWidth >= 1024) {
                    return;
                }

                setTargetState(sidebar, false);

                document.querySelectorAll('[data-nav-toggle="appSidebar"]').forEach(function (toggleButton) {
                    toggleButton.setAttribute('aria-expanded', 'false');
                });
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth >= 1024) {
                    document.body.classList.remove('overflow-hidden');
                }
            });
        });
    </script>
    <?php
};
