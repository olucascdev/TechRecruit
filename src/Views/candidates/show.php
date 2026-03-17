<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$candidate = $candidate ?? [];
$statuses = $statuses ?? [];

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
$addresses = is_array($candidate['addresses'] ?? null) ? $candidate['addresses'] : [];
$contacts = is_array($candidate['contacts'] ?? null) ? $candidate['contacts'] : [];
$skills = is_array($candidate['skills'] ?? null) ? $candidate['skills'] : [];
$history = is_array($candidate['status_history'] ?? null) ? $candidate['status_history'] : [];

$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('candidate-status-form');
    const feedback = document.getElementById('status-feedback');

    if (!form || !feedback) {
        return;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        feedback.className = 'alert d-none';
        feedback.textContent = '';

        const formData = new FormData(form);

        try {
            const response = await fetch('/candidates/status', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            feedback.className = 'alert ' + (result.success ? 'alert-success' : 'alert-danger');
            feedback.textContent = result.message;

            if (result.success) {
                window.setTimeout(function () {
                    window.location.reload();
                }, 700);
            }
        } catch (error) {
            feedback.className = 'alert alert-danger';
            feedback.textContent = 'Nao foi possivel atualizar o status.';
        }
    });
});
</script>
HTML;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $escape($candidate['full_name'] ?? '') ?></h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-<?= $statusBadge((string) ($candidate['status'] ?? 'imported')) ?>">
                <?= $escape($statusLabel((string) ($candidate['status'] ?? 'imported'))) ?>
            </span>
            <span class="text-muted">CPF: <?= $escape($candidate['cpf'] ?: '-') ?></span>
        </div>
    </div>
    <a href="/candidates" class="btn btn-outline-secondary">Voltar</a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Dados pessoais</h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Nome</dt>
                            <dd class="col-sm-7"><?= $escape($candidate['full_name'] ?? '-') ?></dd>
                            <dt class="col-sm-5">CPF</dt>
                            <dd class="col-sm-7"><?= $escape($candidate['cpf'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Status</dt>
                            <dd class="col-sm-7"><?= $escape($statusLabel((string) ($candidate['status'] ?? ''))) ?></dd>
                            <dt class="col-sm-5">Batch origem</dt>
                            <dd class="col-sm-7"><?= $escape($candidate['source_batch_id'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Criado em</dt>
                            <dd class="col-sm-7"><?= $escape($candidate['created_at'] ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Contatos</h2>
                        <?php if ($contacts === []): ?>
                            <p class="text-muted mb-0">Nenhum contato cadastrado.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($contacts as $contact): ?>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex justify-content-between gap-3">
                                            <div>
                                                <div class="fw-semibold"><?= $escape(ucfirst((string) $contact['type'])) ?></div>
                                                <div class="text-muted small"><?= $escape($contact['value']) ?></div>
                                            </div>
                                            <?php if ((int) ($contact['is_primary'] ?? 0) === 1): ?>
                                                <span class="badge bg-primary align-self-start">Principal</span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Skills</h2>
                        <?php if ($skills === []): ?>
                            <p class="text-muted mb-0">Nenhuma skill cadastrada.</p>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($skills as $skill): ?>
                                    <span class="badge text-bg-light border">
                                        <?= $escape($skill['skill']) ?> · <?= $escape($statusLabel((string) $skill['level'])) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Endereco</h2>
                        <?php if ($addresses === []): ?>
                            <p class="text-muted mb-0">Nenhum endereco cadastrado.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($addresses as $address): ?>
                                    <li class="list-group-item px-0">
                                        <div class="fw-semibold"><?= $escape($address['city']) ?> / <?= $escape($address['state']) ?></div>
                                        <div class="text-muted small"><?= $escape($address['region'] ?: 'Regiao nao informada') ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Alterar status</h2>
                <div id="status-feedback" class="d-none"></div>
                <form id="candidate-status-form">
                    <input type="hidden" name="candidate_id" value="<?= $escape($candidate['id'] ?? '') ?>">
                    <div class="mb-3">
                        <label for="new_status" class="form-label">Novo status</label>
                        <select id="new_status" name="new_status" class="form-select">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= $escape($status) ?>" <?= ($candidate['status'] ?? '') === $status ? 'selected' : '' ?>>
                                    <?= $escape($statusLabel($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Salvar status</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Historico de status</h2>
                <?php if ($history === []): ?>
                    <p class="text-muted mb-0">Nenhuma alteracao registrada.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($history as $item): ?>
                            <div class="timeline-item">
                                <div class="fw-semibold"><?= $escape($statusLabel((string) $item['to_status'])) ?></div>
                                <div class="small text-muted mb-1">
                                    De <?= $escape($statusLabel((string) $item['from_status'])) ?> · <?= $escape($item['created_at']) ?>
                                </div>
                                <div class="small">Por <?= $escape($item['changed_by']) ?></div>
                                <?php if (!empty($item['reason'])): ?>
                                    <div class="small text-muted"><?= $escape($item['reason']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
