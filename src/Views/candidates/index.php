<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filters = $filters ?? ['skill' => '', 'status' => '', 'state' => '', 'search' => ''];
$candidates = $candidates ?? [];
$skills = $skills ?? [];
$states = $states ?? [];
$statuses = $statuses ?? [];
$page = $page ?? 1;
$totalPages = $totalPages ?? 1;
$total = $total ?? 0;

$statusBadge = static function (string $status): string {
    return match ($status) {
        'interested' => 'success',
        'not_interested' => 'danger',
        'under_review' => 'warning text-dark',
        'approved' => 'primary',
        'rejected' => 'dark',
        'contract_signed' => 'info text-dark',
        'imported' => 'secondary',
        default => 'secondary',
    };
};

$statusLabel = static fn (string $status): string => ucwords(str_replace('_', ' ', $status));
$buildPageUrl = static function (int $targetPage) use ($filters): string {
    $query = array_filter([
        'skill' => $filters['skill'] ?? '',
        'status' => $filters['status'] ?? '',
        'state' => $filters['state'] ?? '',
        'search' => $filters['search'] ?? '',
        'page' => $targetPage,
    ], static fn (string|int $value): bool => (string) $value !== '');

    return '/candidates' . ($query !== [] ? '?' . http_build_query($query) : '');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Candidatos</h1>
        <p class="text-muted mb-0"><?= $escape($total) ?> resultado(s) encontrado(s).</p>
    </div>
    <a href="/import" class="btn btn-outline-primary">Nova importação</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" action="/candidates" class="row g-3">
            <div class="col-md-3">
                <label for="skill" class="form-label">Skill</label>
                <select id="skill" name="skill" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($skills as $skill): ?>
                        <option value="<?= $escape($skill) ?>" <?= ($filters['skill'] ?? '') === $skill ? 'selected' : '' ?>>
                            <?= $escape($skill) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= $escape($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                            <?= $escape($statusLabel($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="state" class="form-label">Estado</label>
                <select id="state" name="state" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($states as $state): ?>
                        <option value="<?= $escape($state) ?>" <?= ($filters['state'] ?? '') === $state ? 'selected' : '' ?>>
                            <?= $escape($state) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="search" class="form-label">Busca</label>
                <input
                    type="text"
                    class="form-control"
                    id="search"
                    name="search"
                    value="<?= $escape($filters['search'] ?? '') ?>"
                    placeholder="Nome, CPF, telefone ou e-mail"
                >
            </div>
            <div class="col-md-12 d-flex gap-2 justify-content-end">
                <a href="/candidates" class="btn btn-outline-secondary">Limpar</a>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Nome</th>
                    <th>Skills</th>
                    <th>WhatsApp</th>
                    <th>Estado</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($candidates === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhum candidato encontrado para os filtros informados.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= $escape($candidate['full_name']) ?></div>
                                <div class="small text-muted"><?= $escape($candidate['cpf'] ?: '-') ?></div>
                            </td>
                            <td><?= $escape($candidate['skills'] ?: '-') ?></td>
                            <td><?= $escape($candidate['whatsapp'] ?: $candidate['phone'] ?: '-') ?></td>
                            <td><?= $escape($candidate['state'] ?: '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $statusBadge((string) $candidate['status']) ?>">
                                    <?= $escape($statusLabel((string) $candidate['status'])) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="/candidates/<?= $escape($candidate['id']) ?>" class="btn btn-sm btn-outline-primary">Detalhes</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
            <nav aria-label="Paginação de candidatos">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page <= 1 ? '#' : $escape($buildPageUrl($page - 1)) ?>">Anterior</a>
                    </li>
                    <?php for ($currentPage = 1; $currentPage <= $totalPages; $currentPage++): ?>
                        <li class="page-item <?= $currentPage === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $escape($buildPageUrl($currentPage)) ?>"><?= $escape($currentPage) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : $escape($buildPageUrl($page + 1)) ?>">Próxima</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>
