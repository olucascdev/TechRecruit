<?php

declare(strict_types=1);

use TechRecruit\Support\LabelTranslator;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$candidate = is_array($candidate ?? null) ? $candidate : [];
$portal = is_array($portal ?? null) ? $portal : null;
$portalUrl = isset($portalUrl) ? (string) $portalUrl : null;
$operations = is_array($operations ?? null) ? $operations : [];
$summary = is_array($operations['summary'] ?? null) ? $operations['summary'] : [];
$pendencies = is_array($operations['pendencies'] ?? null) ? $operations['pendencies'] : [];
$history = is_array($operations['history'] ?? null) ? $operations['history'] : [];
$documents = $portal !== null && is_array($portal['documents'] ?? null) ? $portal['documents'] : [];

$statusLabel = static fn (string $status): string => LabelTranslator::toPtBr($status);
$candidateStatus = (string) ($candidate['status'] ?? 'imported');

$candidateStatusBadge = static function (string $status): string {
    return match ($status) {
        'interested' => 'success',
        'not_interested' => 'danger',
        'awaiting_docs' => 'secondary',
        'docs_sent' => 'info text-dark',
        'under_review' => 'warning text-dark',
        'approved' => 'primary',
        'rejected' => 'dark',
        default => 'secondary',
    };
};

$portalStatus = $portal !== null ? (string) ($portal['status'] ?? 'draft') : 'draft';
$portalStatusBadge = static function (string $status): string {
    return match ($status) {
        'submitted', 'approved' => 'success',
        'under_review' => 'warning text-dark',
        'correction_requested', 'rejected', 'expired' => 'danger',
        default => 'secondary',
    };
};

$reviewActionBadge = static function (string $action): string {
    return match ($action) {
        'approve', 'document_approve' => 'success',
        'reject', 'document_reject' => 'dark',
        'request_correction', 'document_request_correction' => 'danger',
        'pendency_resolved' => 'primary',
        default => 'secondary',
    };
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="h3 mb-1">Validação operacional</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-semibold"><?= $escape($candidate['full_name'] ?? '-') ?></span>
            <span class="badge bg-<?= $candidateStatusBadge($candidateStatus) ?>">
                <?= $escape($statusLabel($candidateStatus)) ?>
            </span>
            <?php if ($portal !== null): ?>
                <span class="badge bg-<?= $portalStatusBadge($portalStatus) ?>">
                    Portal: <?= $escape($statusLabel($portalStatus)) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= $escape($url('/operations')) ?>" class="btn btn-outline-dark">Voltar para fila</a>
        <a href="<?= $escape($url('/candidates/' . ($candidate['id'] ?? ''))) ?>" class="btn btn-outline-secondary">Abrir ficha do candidato</a>
    </div>
</div>

<?php if ($portal === null): ?>
    <div class="alert alert-light border">
        Este candidato ainda nao possui portal gerado. Gere o portal na ficha do candidato para iniciar a validacao operacional.
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="border rounded p-3 h-100">
                <div class="text-muted small">Docs pendentes</div>
                <div class="fs-4 fw-semibold"><?= $escape($summary['pending_documents'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100">
                <div class="text-muted small">Docs aprovados</div>
                <div class="fs-4 fw-semibold"><?= $escape($summary['approved_documents'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100">
                <div class="text-muted small">Pendencias abertas</div>
                <div class="fs-4 fw-semibold"><?= $escape($summary['open_pendencies'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-3 h-100">
                <div class="text-muted small">Docs com correcao</div>
                <div class="fs-4 fw-semibold"><?= $escape($summary['correction_documents'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="border rounded p-3 mb-3">
                <h2 class="h6 mb-3">Link do portal</h2>
                <div class="small bg-light border rounded p-2 text-break mb-2"><?= $escape($portalUrl ?? '-') ?></div>
                <a href="<?= $escape($portalUrl ?? '#') ?>" target="_blank" class="btn btn-sm btn-outline-primary <?= $portalUrl === null ? 'disabled' : '' ?>">
                    Abrir portal publico
                </a>
            </div>

            <div class="border rounded p-3 mb-3">
                <h2 class="h6 mb-3">Observacao interna</h2>
                <form method="post" action="<?= $escape($url('/operations/candidates/' . ($candidate['id'] ?? '') . '/note')) ?>" class="row g-2">
                    <?= $csrfField ?>
                    <div class="col-12">
                        <textarea name="message" class="form-control" rows="4" placeholder="Ex.: Documento legivel, validar comprovante de residencia." required></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-outline-secondary">Salvar observacao</button>
                    </div>
                </form>
            </div>

            <div class="border rounded p-3">
                <h2 class="h6 mb-3">Decisao final do candidato</h2>
                <form method="post" action="<?= $escape($url('/operations/candidates/' . ($candidate['id'] ?? '') . '/decision')) ?>" class="row g-2">
                    <?= $csrfField ?>
                    <div class="col-12">
                        <label for="decision" class="form-label">Acao</label>
                        <select id="decision" name="decision" class="form-select" required>
                            <option value="">Selecione</option>
                            <option value="approve">Aprovar</option>
                            <option value="request_correction">Pedir correcao</option>
                            <option value="reject">Reprovar</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="decision_message" class="form-label">Observacao / motivo</label>
                        <textarea id="decision_message" name="message" class="form-control" rows="4" placeholder="Obrigatorio para correcao ou reprovacao."></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary">Registrar decisao</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="border rounded p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h6 mb-0">Analise documental</h2>
                    <span class="small text-muted">Defina as acoes e salve tudo de uma vez</span>
                </div>

                <?php if ($documents === []): ?>
                    <p class="text-muted mb-0">Nenhum documento para analisar.</p>
                <?php else: ?>
                    <form method="post" action="<?= $escape($url('/operations/candidates/' . ($candidate['id'] ?? '') . '/documents/decision')) ?>" class="row g-2">
                        <?= $csrfField ?>
                        <div class="col-12 table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>Status atual</th>
                                    <th>Nova acao</th>
                                    <th>Motivo / observacao</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= $escape($statusLabel((string) $document['document_type'])) ?></div>
                                            <div class="small text-muted"><?= $escape($document['original_name'] ?? '-') ?></div>
                                            <a href="<?= $escape($url('/portal/documents/' . $document['id'])) ?>" target="_blank" class="small">Abrir anexo</a>
                                        </td>
                                        <td><?= $escape($statusLabel((string) $document['review_status'])) ?></td>
                                        <td style="min-width: 200px;">
                                            <select name="document_decision[<?= $escape($document['id']) ?>]" class="form-select form-select-sm">
                                                <option value="">Manter</option>
                                                <option value="approve">Aprovar</option>
                                                <option value="request_correction">Pedir correcao</option>
                                                <option value="reject">Reprovar</option>
                                            </select>
                                        </td>
                                        <td style="min-width: 260px;">
                                            <input type="text" name="document_message[<?= $escape($document['id']) ?>]" class="form-control form-control-sm" placeholder="Obrigatorio em correcao/reprovacao">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-outline-primary">Salvar analises documentais</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="border rounded p-3 mb-3">
                <h2 class="h6 mb-3">Pendencias</h2>
                <?php if ($pendencies === []): ?>
                    <p class="text-muted mb-0">Nenhuma pendencia registrada.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Titulo</th>
                                <th>Status</th>
                                <th>Acao</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendencies as $pendency): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= $escape($pendency['title']) ?></div>
                                        <div class="small text-muted"><?= $escape($pendency['description'] ?: '-') ?></div>
                                    </td>
                                    <td><?= $escape($statusLabel((string) $pendency['status'])) ?></td>
                                    <td style="min-width: 240px;">
                                        <?php if (($pendency['status'] ?? '') === 'open'): ?>
                                            <form method="post" action="<?= $escape($url('/operations/pendencies/' . $pendency['id'] . '/resolve')) ?>" class="row g-2">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="candidate_id" value="<?= $escape($candidate['id'] ?? '') ?>">
                                                <div class="col-12">
                                                    <input type="text" name="message" class="form-control form-control-sm" placeholder="Observacao de resolucao">
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
                <h2 class="h6 mb-3">Historico de decisao</h2>
                <?php if ($history === []): ?>
                    <p class="text-muted mb-0">Nenhuma decisao registrada ainda.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($history as $item): ?>
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
<?php endif; ?>
