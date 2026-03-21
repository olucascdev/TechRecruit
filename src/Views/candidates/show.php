<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$candidate = $candidate ?? [];
$statuses = $statuses ?? [];

$statusBadge = static function (string $status): string {
    return match ($status) {
        'interested' => 'success',
        'not_interested' => 'danger',
        'awaiting_docs' => 'secondary',
        'docs_sent' => 'info text-dark',
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
$portal = is_array($portal ?? null) ? $portal : null;
$portalStatuses = $portalStatuses ?? [];
$portalUrl = $portalUrl ?? null;
$portalProfile = is_array($portal['profile'] ?? null) ? $portal['profile'] : [];
$portalChecklist = is_array($portal['checklist'] ?? null) ? $portal['checklist'] : [];
$portalDocuments = is_array($portal['documents'] ?? null) ? $portal['documents'] : [];
$triageSession = is_array($triageSession ?? null) ? $triageSession : [];
$triageCollected = is_array($triageSession['collected_data'] ?? null) ? $triageSession['collected_data'] : [];
$triagePreFilter = is_array($triageCollected['prefilter'] ?? null) ? $triageCollected['prefilter'] : [];
$triageFieldReadiness = is_array($triageCollected['field_readiness'] ?? null) ? $triageCollected['field_readiness'] : [];
$triageClassification = is_array($triageCollected['classification'] ?? null) ? $triageCollected['classification'] : [];

$portalStatusBadge = static function (string $status): string {
    return match ($status) {
        'submitted', 'approved' => 'success',
        'under_review' => 'warning text-dark',
        'correction_requested' => 'danger',
        'rejected', 'expired' => 'danger',
        default => 'secondary',
    };
};
$operations = is_array($operations ?? null) ? $operations : [];
$operationSummary = is_array($operations['summary'] ?? null) ? $operations['summary'] : [];
$operationPendencies = is_array($operations['pendencies'] ?? null) ? $operations['pendencies'] : [];
$operationHistory = is_array($operations['history'] ?? null) ? $operations['history'] : [];

$reviewActionBadge = static function (string $action): string {
    return match ($action) {
        'approve', 'document_approve' => 'success',
        'reject', 'document_reject' => 'dark',
        'request_correction', 'document_request_correction' => 'danger',
        'pendency_resolved' => 'primary',
        default => 'secondary',
    };
};

$triageStatusBadge = static function (string $status): string {
    return match ($status) {
        'approved' => 'success',
        'awaiting_validation' => 'primary',
        'needs_details' => 'warning text-dark',
        'not_interested', 'rejected_unavailable' => 'dark',
        'interested' => 'success',
        default => 'secondary',
    };
};

$classificationBadge = static function (string $status): string {
    return match ($status) {
        'approved' => 'success',
        'pending' => 'warning text-dark',
        'rejected' => 'dark',
        default => 'secondary',
    };
};
$actionIcon = static function (string $name): string {
    return match ($name) {
        'view' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/><circle cx="12" cy="12" r="3.25"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 7.5h15"/><path d="M9.75 3.75h4.5"/><path d="M6.75 7.5 7.5 19.5a1.5 1.5 0 0 0 1.5 1.5h6a1.5 1.5 0 0 0 1.5-1.5l.75-12"/><path d="M10 11.25v5.25"/><path d="M14 11.25v5.25"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 19.5 3 12l7.5-7.5"/><path d="M3.75 12h17.25"/></svg>',
        default => '',
    };
};

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
            formData.set('_token', window.TechRecruit?.csrfToken || '');

            const response = await fetch('/candidates/status', {
                method: 'POST',
                headers: window.TechRecruit?.csrfHeaders ? window.TechRecruit.csrfHeaders({
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }) : {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
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
            feedback.textContent = 'Não foi possível atualizar o status.';
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
    <div class="flex flex-nowrap items-center gap-2 overflow-x-auto pb-1">
        <a href="/candidates" class="action-icon action-icon-secondary" title="Voltar" aria-label="Voltar">
            <?= $actionIcon('back') ?>
        </a>
        <form method="post" action="/candidates/<?= $escape($candidate['id'] ?? '') ?>/delete" class="m-0" onsubmit="return confirm('Excluir este candidato e todos os dados vinculados?');">
            <?= $csrfField ?>
            <button type="submit" class="action-icon action-icon-danger" title="Excluir candidato" aria-label="Excluir candidato">
                <?= $actionIcon('delete') ?>
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <h2 class="h5 mb-1">Classificação W13</h2>
                <p class="text-muted mb-0">Leitura consolidada da triagem automática antes do portal e da operação.</p>
            </div>
            <?php if ($triageSession !== []): ?>
                <span class="badge bg-<?= $triageStatusBadge((string) ($triageSession['triage_status'] ?? 'sent')) ?>">
                    <?= $escape($statusLabel((string) ($triageSession['triage_status'] ?? 'sent'))) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($triageSession === []): ?>
            <div class="alert alert-light border mb-0">Nenhuma sessão de triagem encontrada para este candidato ainda.</div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Status preliminar</h3>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Fluxo</dt>
                            <dd class="col-sm-7"><?= $escape($triageSession['automation_status'] ?? '-') ?></dd>
                            <dt class="col-sm-5">Etapa</dt>
                            <dd class="col-sm-7"><?= $escape($statusLabel((string) ($triageSession['current_step'] ?? '-'))) ?></dd>
                            <dt class="col-sm-5">Status W13</dt>
                            <dd class="col-sm-7">
                                <?php if ($triageClassification !== []): ?>
                                    <span class="badge bg-<?= $classificationBadge((string) ($triageClassification['status'] ?? 'bank')) ?>">
                                        <?= $escape((string) ($triageClassification['status_label'] ?? 'Banco')) ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-5">Nível técnico</dt>
                            <dd class="col-sm-7"><?= $escape($triageClassification['technical_level_label'] ?? '-') ?></dd>
                            <dt class="col-sm-5">Nível campo</dt>
                            <dd class="col-sm-7"><?= $escape($triageClassification['field_level_label'] ?? '-') ?></dd>
                            <dt class="col-sm-5">Premium</dt>
                            <dd class="col-sm-7"><?= !empty($triageClassification['premium_candidate']) ? 'Sim' : 'Não' ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Pre-filtro</h3>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Cidade</dt>
                            <dd class="col-sm-7"><?= $escape(($triagePreFilter['city'] ?? '-') . ' / ' . ($triagePreFilter['state'] ?? '-')) ?></dd>
                            <dt class="col-sm-5">MEI ativo</dt>
                            <dd class="col-sm-7"><?= isset($triagePreFilter['mei_active']) ? (($triagePreFilter['mei_active'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">Notebook</dt>
                            <dd class="col-sm-7"><?= isset($triagePreFilter['has_notebook']) ? (($triagePreFilter['has_notebook'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">Console</dt>
                            <dd class="col-sm-7"><?= isset($triagePreFilter['has_console_cable']) ? (($triagePreFilter['has_console_cable'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">Disponibilidade</dt>
                            <dd class="col-sm-7"><?= isset($triagePreFilter['immediate_availability']) ? (($triagePreFilter['immediate_availability'] ?? false) ? 'Imediata' : 'Não imediata') : '-' ?></dd>
                        </dl>
                        <div class="small text-muted mt-3">Especialidades</div>
                        <div class="fw-semibold"><?= $escape(($triageClassification['service_labels'] ?? []) !== [] ? implode(', ', $triageClassification['service_labels']) : '-') ?></div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Segurança e ferramental</h3>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">ASO</dt>
                            <dd class="col-sm-7"><?= isset($triageFieldReadiness['has_aso']) ? (($triageFieldReadiness['has_aso'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">NR10</dt>
                            <dd class="col-sm-7"><?= isset($triageFieldReadiness['has_nr10']) ? (($triageFieldReadiness['has_nr10'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">NR35</dt>
                            <dd class="col-sm-7"><?= isset($triageFieldReadiness['has_nr35']) ? (($triageFieldReadiness['has_nr35'] ?? false) ? 'Sim' : 'Não') : '-' ?></dd>
                            <dt class="col-sm-5">Ferramental</dt>
                            <dd class="col-sm-7"><?= isset($triageFieldReadiness['has_complete_toolkit']) ? (($triageFieldReadiness['has_complete_toolkit'] ?? false) ? 'Completo' : 'Incompleto') : '-' ?></dd>
                        </dl>
                        <?php if (($triageClassification['missing_requirements'] ?? []) !== []): ?>
                            <div class="small text-muted mt-3">Pendências mapeadas</div>
                            <div class="small"><?= $escape(implode(', ', $triageClassification['missing_requirements'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <h2 class="h5 mb-1">Portal de cadastro e documentos</h2>
                <p class="text-muted mb-0">Link único, formulário do candidato, checklist e anexos internos. Ao gerar, o sistema tenta enviar o link por WhatsApp automaticamente.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <form method="post" action="/candidates/<?= $escape($candidate['id'] ?? '') ?>/portal/generate">
                    <?= $csrfField ?>
                    <button type="submit" class="btn btn-outline-primary">
                        <?= $portal === null ? 'Gerar e enviar portal' : 'Regenerar e reenviar portal' ?>
                    </button>
                </form>
            </div>
        </div>

        <?php if ($portal === null): ?>
            <div class="alert alert-light border mb-0">
                Nenhum portal gerado para este candidato ainda.
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                            <h3 class="h6 mb-0">Acesso</h3>
                            <span class="badge bg-<?= $portalStatusBadge((string) ($portal['status'] ?? 'draft')) ?>">
                                <?= $escape($statusLabel((string) ($portal['status'] ?? 'draft'))) ?>
                            </span>
                        </div>
                        <div class="small text-muted mb-2">Link para envio ao candidato</div>
                        <div class="small bg-light border rounded p-2 text-break mb-3"><?= $escape($portalUrl ?? '-') ?></div>
                        <dl class="row mb-3">
                            <dt class="col-sm-6">Último acesso</dt>
                            <dd class="col-sm-6"><?= $escape($portal['last_accessed_at'] ?? '-') ?></dd>
                            <dt class="col-sm-6">Enviado em</dt>
                            <dd class="col-sm-6"><?= $escape($portal['submitted_at'] ?? '-') ?></dd>
                            <dt class="col-sm-6">Termos</dt>
                            <dd class="col-sm-6"><?= !empty($portal['terms_accepted']) ? 'Aceito' : 'Pendente' ?></dd>
                        </dl>

                        <form method="post" action="/candidates/<?= $escape($candidate['id'] ?? '') ?>/portal/status" class="row g-2">
                            <?= $csrfField ?>
                            <div class="col-12">
                                <label for="portal_status" class="form-label">Status do portal</label>
                                <select id="portal_status" name="portal_status" class="form-select">
                                    <?php foreach ($portalStatuses as $status): ?>
                                        <option value="<?= $escape($status) ?>" <?= ($portal['status'] ?? '') === $status ? 'selected' : '' ?>>
                                            <?= $escape($statusLabel($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-outline-secondary">Salvar status do portal</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Checklist documental</h3>
                        <?php if ($portalChecklist === []): ?>
                            <p class="text-muted mb-0">Checklist indisponível.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($portalChecklist as $item): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold"><?= $escape($item['label']) ?></div>
                                            <div class="small text-muted"><?= $item['required'] ? 'Obrigatório' : 'Opcional' ?></div>
                                        </div>
                                        <span class="badge text-bg-<?= $item['received'] ? 'success' : 'light' ?>">
                                            <?= $escape($item['received'] ? 'Recebido' : 'Pendente') ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Cadastro preenchido</h3>
                        <?php if ($portalProfile === []): ?>
                            <p class="text-muted mb-0">O candidato ainda não enviou o formulário.</p>
                        <?php else: ?>
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Nome</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['full_name'] ?? '-') ?></dd>
                                <dt class="col-sm-5">CNPJ / MEI</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['cnpj'] ?? '-') ?></dd>
                                <dt class="col-sm-5">WhatsApp</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['whatsapp'] ?? '-') ?></dd>
                                <dt class="col-sm-5">E-mail</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['email'] ?? '-') ?></dd>
                                <dt class="col-sm-5">Pix</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['pix_key'] ?? '-') ?></dd>
                                <dt class="col-sm-5">Cidade</dt>
                                <dd class="col-sm-7"><?= $escape(($portalProfile['city'] ?? '-') . ' / ' . ($portalProfile['state'] ?? '-')) ?></dd>
                                <dt class="col-sm-5">Disponibilidade</dt>
                                <dd class="col-sm-7"><?= $escape($portalProfile['availability'] ?? '-') ?></dd>
                            </dl>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-1">
                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Resumo profissional</h3>
                        <?php if ($portalProfile === []): ?>
                            <p class="text-muted mb-0">Sem experiência registrada no portal.</p>
                        <?php else: ?>
                            <p class="mb-2"><?= nl2br($escape($portalProfile['experience_summary'] ?? '-')) ?></p>
                            <?php if (!empty($portalProfile['notes'])): ?>
                                <div class="small text-muted"><?= nl2br($escape($portalProfile['notes'])) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-3">Anexos internos</h3>
                        <?php if ($portalDocuments === []): ?>
                            <p class="text-muted mb-0">Nenhum documento enviado ainda.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Arquivo</th>
                                        <th>Data</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($portalDocuments as $document): ?>
                                        <tr>
                                            <td><?= $escape($statusLabel((string) $document['document_type'])) ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= $escape($document['original_name']) ?></div>
                                                <div class="small text-muted"><?= $escape($document['review_status']) ?></div>
                                            </td>
                                            <td><?= $escape($document['uploaded_at']) ?></td>
                                            <td class="text-end">
                                                <div class="inline-flex flex-nowrap items-center justify-end gap-2">
                                                    <a href="/portal/documents/<?= $escape($document['id']) ?>" target="_blank" class="action-icon action-icon-sm action-icon-primary" title="Visualizar anexo" aria-label="Visualizar anexo">
                                                        <?= $actionIcon('view') ?>
                                                    </a>
                                                    <form method="post" action="/portal/documents/<?= $escape($document['id']) ?>/delete" class="m-0" onsubmit="return confirm('Excluir este anexo?');">
                                                        <?= $csrfField ?>
                                                        <input type="hidden" name="candidate_id" value="<?= $escape($candidate['id'] ?? '') ?>">
                                                        <button type="submit" class="action-icon action-icon-sm action-icon-danger" title="Excluir anexo" aria-label="Excluir anexo">
                                                            <?= $actionIcon('delete') ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($portal !== null): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <h2 class="h5 mb-1">Validação operacional</h2>
                    <p class="text-muted mb-0">Fila, decisões, pendências e histórico da análise humana.</p>
                </div>
                <a href="/operations" class="btn btn-outline-dark">Ver fila operacional</a>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Docs pendentes</div>
                        <div class="fs-4 fw-semibold"><?= $escape($operationSummary['pending_documents'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Docs aprovados</div>
                        <div class="fs-4 fw-semibold"><?= $escape($operationSummary['approved_documents'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Pendências abertas</div>
                        <div class="fs-4 fw-semibold"><?= $escape($operationSummary['open_pendencies'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Docs com correção</div>
                        <div class="fs-4 fw-semibold"><?= $escape($operationSummary['correction_documents'] ?? 0) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="border rounded p-3 mb-4">
                        <h3 class="h6 mb-3">Observação interna</h3>
                        <form method="post" action="/operations/candidates/<?= $escape($candidate['id'] ?? '') ?>/note" class="row g-2">
                            <?= $csrfField ?>
                            <div class="col-12">
                                <textarea name="message" class="form-control" rows="4" placeholder="Ex.: Documento legível, validar comprovante de residência." required></textarea>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-outline-secondary">Salvar observação</button>
                            </div>
                        </form>
                    </div>

                    <div class="border rounded p-3">
                        <h3 class="h6 mb-3">Decisão do candidato</h3>
                        <form method="post" action="/operations/candidates/<?= $escape($candidate['id'] ?? '') ?>/decision" class="row g-2">
                            <?= $csrfField ?>
                            <div class="col-12">
                                <label for="decision" class="form-label">Ação</label>
                                <select id="decision" name="decision" class="form-select" required>
                                    <option value="">Selecione</option>
                                    <option value="approve">Aprovar</option>
                                    <option value="request_correction">Pedir correção</option>
                                    <option value="reject">Reprovar</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="decision_message" class="form-label">Observação / motivo</label>
                                <textarea id="decision_message" name="message" class="form-control" rows="4" placeholder="Obrigatório para correção ou reprovação."></textarea>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-primary">Registrar decisão</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="border rounded p-3 mb-4">
                        <h3 class="h6 mb-3">Análise documental</h3>
                        <?php if ($portalDocuments === []): ?>
                            <p class="text-muted mb-0">Nenhum documento para analisar.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                    <tr>
                                        <th>Documento</th>
                                        <th>Status</th>
                                        <th>Ação</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($portalDocuments as $document): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= $escape($statusLabel((string) $document['document_type'])) ?></div>
                                                <div class="small text-muted"><?= $escape($document['original_name']) ?></div>
                                                <a href="/portal/documents/<?= $escape($document['id']) ?>" target="_blank" class="small">Abrir anexo</a>
                                            </td>
                                            <td><?= $escape($statusLabel((string) $document['review_status'])) ?></td>
                                            <td style="min-width: 320px;">
                                                <form method="post" action="/operations/documents/<?= $escape($document['id']) ?>/decision" class="row g-2">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="candidate_id" value="<?= $escape($candidate['id'] ?? '') ?>">
                                                    <div class="col-md-5">
                                                        <select name="decision" class="form-select form-select-sm" required>
                                                            <option value="">Ação</option>
                                                            <option value="approve">Aprovar</option>
                                                            <option value="request_correction">Pedir correção</option>
                                                            <option value="reject">Reprovar</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-7">
                                                        <input type="text" name="message" class="form-control form-control-sm" placeholder="Motivo / observação">
                                                    </div>
                                                    <div class="col-12 d-grid">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Salvar análise</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="border rounded p-3 mb-4">
                        <h3 class="h6 mb-3">Pendências</h3>
                        <?php if ($operationPendencies === []): ?>
                            <p class="text-muted mb-0">Nenhuma pendencia registrada.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                    <tr>
                                        <th>Título</th>
                                        <th>Status</th>
                                        <th>Ação</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($operationPendencies as $pendency): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= $escape($pendency['title']) ?></div>
                                                <div class="small text-muted"><?= $escape($pendency['description'] ?: '-') ?></div>
                                            </td>
                                            <td><?= $escape($statusLabel((string) $pendency['status'])) ?></td>
                                            <td style="min-width: 240px;">
                                                <?php if (($pendency['status'] ?? '') === 'open'): ?>
                                                    <form method="post" action="/operations/pendencies/<?= $escape($pendency['id']) ?>/resolve" class="row g-2">
                                                        <?= $csrfField ?>
                                                        <input type="hidden" name="candidate_id" value="<?= $escape($candidate['id'] ?? '') ?>">
                                                        <div class="col-12">
                                                            <input type="text" name="message" class="form-control form-control-sm" placeholder="Observação de resolução">
                                                        </div>
                                                        <div class="col-12 d-grid">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">Resolver</button>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="small text-muted">
                                                        Resolvida por <?= $escape($pendency['resolved_by'] ?: '-') ?> em <?= $escape($pendency['resolved_at'] ?: '-') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="border rounded p-3">
                        <h3 class="h6 mb-3">Histórico de decisão</h3>
                        <?php if ($operationHistory === []): ?>
                            <p class="text-muted mb-0">Nenhuma decisão registrada ainda.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($operationHistory as $item): ?>
                                    <div class="timeline-item">
                                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                                            <div class="fw-semibold">
                                                <span class="badge bg-<?= $reviewActionBadge((string) $item['action']) ?> me-2">
                                                    <?= $escape($statusLabel((string) $item['action'])) ?>
                                                </span>
                                                <?= $escape($item['document_type'] ? $statusLabel((string) $item['document_type']) : 'Cadastro') ?>
                                            </div>
                                            <div class="small text-muted"><?= $escape($item['created_at']) ?></div>
                                        </div>
                                        <div class="small mb-1">Por <?= $escape($item['created_by']) ?></div>
                                        <?php if (!empty($item['message'])): ?>
                                            <div class="small text-muted"><?= nl2br($escape($item['message'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
                        <h2 class="h5 mb-3">Endereço</h2>
                        <?php if ($addresses === []): ?>
                            <p class="text-muted mb-0">Nenhum endereco cadastrado.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($addresses as $address): ?>
                                    <li class="list-group-item px-0">
                                        <div class="fw-semibold"><?= $escape($address['city']) ?> / <?= $escape($address['state']) ?></div>
                                        <div class="text-muted small"><?= $escape($address['region'] ?: 'Região não informada') ?></div>
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
                    <?= $csrfField ?>
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
                <h2 class="h5 mb-3">Histórico de status</h2>
                <?php if ($history === []): ?>
                    <p class="text-muted mb-0">Nenhuma alteração registrada.</p>
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
