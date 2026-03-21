<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formData = $formData ?? [
    'full_name' => '',
    'email' => '',
    'username' => '',
];
$errorMessage = $errorMessage ?? null;
$isReady = $isReady ?? true;
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="mb-6">
            <p class="h6 mb-2">Setup inicial</p>
            <h2 class="h3 mb-2">Criar o primeiro administrador</h2>
            <p class="text-muted mb-0">Esse registro fica disponível apenas enquanto o sistema não tiver nenhum usuário interno.</p>
        </div>

        <?php if (is_string($errorMessage) && trim($errorMessage) !== ''): ?>
            <div class="alert alert-danger">
                <div class="fw-semibold">Setup indisponível</div>
                <div class="mt-2"><?= $escape($errorMessage) ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= $escape($url('/setup')) ?>" method="post" class="row g-3">
            <?= $csrfField ?>
            <div class="col-12">
                <label for="full_name" class="form-label">Nome completo</label>
                <input
                    id="full_name"
                    name="full_name"
                    type="text"
                    class="form-control"
                    value="<?= $escape($formData['full_name'] ?? '') ?>"
                    placeholder="Admin TechRecruit"
                    required
                >
            </div>
            <div class="col-12">
                <label for="email" class="form-label">E-mail</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    class="form-control"
                    value="<?= $escape($formData['email'] ?? '') ?>"
                    placeholder="admin@empresa.com"
                    autocomplete="email"
                    required
                >
            </div>
            <div class="col-12">
                <label for="username" class="form-label">Username</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    class="form-control"
                    value="<?= $escape($formData['username'] ?? '') ?>"
                    placeholder="admin.techrecruit"
                    autocomplete="username"
                    required
                >
                <div class="form-text">Use letras minúsculas, números, ponto, hífen ou underscore.</div>
            </div>
            <div class="col-12">
                <label for="password" class="form-label">Senha</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    class="form-control"
                    placeholder="Mínimo de 8 caracteres"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>
            <div class="col-12">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Depois que o primeiro admin existir, o login passa a ser feito em <code>/login</code> e novos acessos ficam restritos a <code>/management/users</code>.
                </div>
            </div>
            <div class="col-12 d-grid">
                <button type="submit" class="btn btn-primary" <?= !$isReady ? 'disabled' : '' ?>>Criar primeiro administrador</button>
            </div>
            <div class="col-12">
                <a href="<?= $escape($url('/login')) ?>" class="text-sm text-slate-600 hover:text-slate-900">Ir para o login</a>
            </div>
        </form>
    </div>
</div>
