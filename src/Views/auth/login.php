<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$hasAnyUser = $hasAnyUser ?? true;
$defaultLogin = $defaultLogin ?? '';
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="mb-6">
            <p class="h6 mb-2">Entrar</p>
            <h2 class="h3 mb-2">Acesso ao backoffice</h2>
            <p class="text-muted mb-0">Use o username ou e-mail e a senha do seu usuário interno.</p>
        </div>

        <?php if (!$hasAnyUser): ?>
            <div class="alert alert-info">
                <div>
                    <div class="fw-semibold">Nenhum usuário interno encontrado.</div>
                    <div class="mt-2">Siga a mesma lógica do RecargaAki: faça o bootstrap do primeiro admin em <code>/setup</code>.</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="/setup" class="btn btn-primary btn-sm">Abrir setup inicial</a>
                    </div>
                    <div class="mt-3">Se preferir, a CLI continua disponível:</div>
                    <pre class="mt-3">php bin/create_management_user.php --name="Admin" --email="admin@empresa.com" --username="admin" --password="SENHA_SEGURA" --role=admin</pre>
                </div>
            </div>
        <?php endif; ?>

        <form action="/login" method="post" class="row g-3">
            <?= $csrfField ?>
            <div class="col-12">
                <label for="login" class="form-label">Usuário ou e-mail</label>
                <input
                    id="login"
                    name="login"
                    type="text"
                    class="form-control"
                    value="<?= $escape($defaultLogin) ?>"
                    placeholder="admin ou gestao@empresa.com"
                    autocomplete="username"
                    required
                >
            </div>
            <div class="col-12">
                <label for="password" class="form-label">Senha</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    class="form-control"
                    placeholder="Digite sua senha"
                    autocomplete="current-password"
                    required
                >
            </div>
            <div class="col-12">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Depois do setup inicial, novos acessos continuam restritos a um administrador em <code>/management/users</code>.
                </div>
            </div>
            <div class="col-12 d-grid">
                <button type="submit" class="btn btn-primary" <?= !$hasAnyUser ? 'disabled' : '' ?>>Entrar no TechRecruit</button>
            </div>
        </form>
    </div>
</div>
