<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$users = $users ?? [];
$roles = $roles ?? [];
$statuses = $statuses ?? [];
$formData = $formData ?? ['full_name' => '', 'username' => '', 'email' => '', 'role' => 'manager'];
$errorMessage = $errorMessage ?? null;
$authUser = is_array($authUser ?? null) ? $authUser : null;
$activeUsers = array_values(array_filter(
    $users,
    static fn (array $user): bool => (string) ($user['status'] ?? '') === 'active'
));
$adminUsers = array_values(array_filter(
    $activeUsers,
    static fn (array $user): bool => (string) ($user['role'] ?? '') === 'admin'
));
$badgeClassForRole = static function (string $role): string {
    return $role === 'admin' ? 'primary' : 'secondary';
};
$badgeClassForStatus = static function (string $status): string {
    return $status === 'active' ? 'success' : 'secondary';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Usuários Internos</h1>
        <p class="text-muted mb-0">Cadastro restrito para a gestão do TechRecruit.</p>
    </div>
    <div class="inline-flex flex-wrap gap-2">
        <span class="badge bg-secondary"><?= $escape(count($users)) ?> usuário(s)</span>
        <span class="badge bg-success"><?= $escape(count($activeUsers)) ?> ativo(s)</span>
        <span class="badge bg-primary"><?= $escape(count($adminUsers)) ?> admin(s)</span>
    </div>
</div>

<?php if (is_string($errorMessage) && trim($errorMessage) !== ''): ?>
    <div class="alert alert-danger">
        <div><?= $escape($errorMessage) ?></div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="mb-4">
                    <h2 class="h5 mb-1">Novo usuário da gestão</h2>
                    <p class="text-muted mb-0">Depois do setup inicial, todo cadastro segue por aqui. Cada acesso nasce com role e status ativo.</p>
                </div>

                <form action="<?= $escape($url('/management/users')) ?>" method="post" class="row g-3">
                    <?= $csrfField ?>
                    <div class="col-12">
                        <label for="full_name" class="form-label">Nome completo</label>
                        <input
                            id="full_name"
                            name="full_name"
                            type="text"
                            class="form-control"
                            value="<?= $escape($formData['full_name'] ?? '') ?>"
                            placeholder="Nome da pessoa"
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
                            placeholder="maria.gestao"
                            required
                        >
                        <div class="form-text">Usado no login junto com o e-mail.</div>
                    </div>
                    <div class="col-12">
                        <label for="email" class="form-label">E-mail</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            class="form-control"
                            value="<?= $escape($formData['email'] ?? '') ?>"
                            placeholder="email@empresa.com"
                            required
                        >
                    </div>
                    <div class="col-12">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select" required>
                            <?php foreach ($roles as $role => $label): ?>
                                <option value="<?= $escape($role) ?>" <?= ($formData['role'] ?? '') === $role ? 'selected' : '' ?>>
                                    <?= $escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="password" class="form-label">Senha inicial</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="form-control"
                            placeholder="Mínimo de 8 caracteres"
                            minlength="8"
                            required
                        >
                        <div class="form-text">Use uma senha temporária forte e troque fora do sistema se necessário.</div>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary">Criar usuário interno</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-1">Modelo de acesso</h2>
                <p class="text-muted mb-4">O fluxo segue a base do RecargaAki: setup inicial do primeiro admin, login por username ou e-mail e gestão interna de roles.</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-3xl border border-brand-100 bg-brand-50/80 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-700">Admin</div>
                        <p class="mt-2 text-sm text-brand-900">Pode criar usuários, ajustar role/status e manter a estrutura de acesso.</p>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/90 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-700">Gestão</div>
                        <p class="mt-2 text-sm text-slate-900">Pode operar candidatos, campanhas, importações e validação usando login interno.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1">Acessos cadastrados</h2>
                <p class="text-muted mb-0">Aqui você controla role, status e acompanha o último login.</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Usuário</th>
                    <th>Login</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Último login</th>
                    <th>Criado em</th>
                    <th class="text-end">Acesso</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhum usuário interno cadastrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= $escape($user['full_name']) ?></div>
                                <?php if ($authUser !== null && (int) ($authUser['id'] ?? 0) === (int) ($user['id'] ?? 0)): ?>
                                    <div class="small text-primary">Seu usuário atual</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold">@<?= $escape($user['username'] ?? '-') ?></div>
                                <div class="small text-muted"><?= $escape($user['email']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-<?= $badgeClassForRole((string) $user['role']) ?>">
                                    <?= $escape($roles[$user['role']] ?? $user['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $badgeClassForStatus((string) $user['status']) ?>">
                                    <?= $escape($statuses[$user['status']] ?? $user['status']) ?>
                                </span>
                            </td>
                            <td><?= $escape($user['last_login_at'] ?: 'Nunca acessou') ?></td>
                            <td>
                                <div><?= $escape($user['created_at']) ?></div>
                                <div class="small text-muted">por <?= $escape($user['created_by'] ?: 'sistema') ?></div>
                            </td>
                            <td class="text-end">
                                <form action="<?= $escape($url('/management/users/' . $user['id'] . '/access')) ?>" method="post" class="flex flex-col items-stretch gap-2 md:min-w-[18rem] md:flex-row md:items-center md:justify-end">
                                    <?= $csrfField ?>
                                    <select name="role" class="form-select form-select-sm">
                                        <?php foreach ($roles as $role => $label): ?>
                                            <option value="<?= $escape($role) ?>" <?= $user['role'] === $role ? 'selected' : '' ?>>
                                                <?= $escape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="status" class="form-select form-select-sm">
                                        <?php foreach ($statuses as $status => $label): ?>
                                            <option value="<?= $escape($status) ?>" <?= $user['status'] === $status ? 'selected' : '' ?>>
                                                <?= $escape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Salvar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
