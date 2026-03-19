<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$campaign = $campaign ?? [];
$filters = is_array($campaign['segment_filters'] ?? null) ? $campaign['segment_filters'] : [];
$recipients = is_array($campaign['recipients'] ?? null) ? $campaign['recipients'] : [];
$recipientStats = is_array($campaign['recipient_stats'] ?? null) ? $campaign['recipient_stats'] : [];
$queueStats = is_array($campaign['queue_stats'] ?? null) ? $campaign['queue_stats'] : [];
$triageStats = is_array($campaign['triage_stats'] ?? null) ? $campaign['triage_stats'] : [];
$activityLogs = is_array($campaign['activity_logs'] ?? null) ? $campaign['activity_logs'] : [];
$inboundMessages = is_array($campaign['inbound_messages'] ?? null) ? $campaign['inbound_messages'] : [];
$isTriageCampaign = ($campaign['automation_type'] ?? 'broadcast') === 'triage_w13';

$campaignStatusClass = static function (string $status): string {
    return match ($status) {
        'queued' => 'primary',
        'sending' => 'warning text-dark',
        'paused' => 'secondary',
        'completed' => 'success',
        'cancelled' => 'dark',
        default => 'secondary',
    };
};

$recipientStatusClass = static function (string $status): string {
    return match ($status) {
        'responded' => 'success',
        'failed' => 'danger',
        'sent' => 'info text-dark',
        'opt_out' => 'dark',
        default => 'secondary',
    };
};

$triageStatusClass = static function (?string $status): string {
    return match ($status) {
        'interested' => 'success',
        'not_interested', 'rejected_unavailable' => 'dark',
        'needs_details' => 'warning text-dark',
        'awaiting_validation' => 'primary',
        'approved' => 'success',
        'sent' => 'info text-dark',
        default => 'secondary',
    };
};

$activityClass = static function (string $eventType): string {
    return match ($eventType) {
        'sent', 'completed' => 'success',
        'failed' => 'danger',
        'reply', 'resumed' => 'primary',
        'opt_out', 'cancelled' => 'dark',
        default => 'secondary',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <h1 class="h3 mb-0"><?= $escape($campaign['name'] ?? '') ?></h1>
            <span class="badge bg-<?= $campaignStatusClass((string) ($campaign['status'] ?? 'draft')) ?>">
                <?= $escape(ucwords(str_replace('_', ' ', (string) ($campaign['status'] ?? 'draft')))) ?>
            </span>
        </div>
        <p class="text-muted mb-0">Criada em <?= $escape($campaign['created_at'] ?? '-') ?> por <?= $escape($campaign['created_by'] ?? '-') ?></p>
        <p class="text-muted small mb-0">Modo: <?= $escape(($campaign['automation_type'] ?? 'broadcast') === 'triage_w13' ? 'Bot de triagem W13' : 'Broadcast manual') ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" action="/campaigns/<?= $escape($campaign['id'] ?? 0) ?>/process">
            <button type="submit" class="btn btn-primary">Processar fila</button>
        </form>
        <form method="post" action="/campaigns/<?= $escape($campaign['id'] ?? 0) ?>/pause">
            <button type="submit" class="btn btn-outline-secondary">Pausar</button>
        </form>
        <form method="post" action="/campaigns/<?= $escape($campaign['id'] ?? 0) ?>/resume">
            <button type="submit" class="btn btn-outline-success">Retomar</button>
        </form>
        <form method="post" action="/campaigns/<?= $escape($campaign['id'] ?? 0) ?>/cancel">
            <button type="submit" class="btn btn-outline-danger">Cancelar</button>
        </form>
        <a href="/campaigns" class="btn btn-outline-dark">Voltar</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Publico capturado</div>
                <div class="fs-3 fw-semibold"><?= $escape($campaign['audience_count'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Fila pendente</div>
                <div class="fs-3 fw-semibold"><?= $escape($queueStats['pending_jobs'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Enviados</div>
                <div class="fs-3 fw-semibold"><?= $escape($recipientStats['sent_recipients'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Respondidos</div>
                <div class="fs-3 fw-semibold"><?= $escape($recipientStats['responded_recipients'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Opt-out</div>
                <div class="fs-3 fw-semibold"><?= $escape($recipientStats['opt_out_recipients'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Falhas</div>
                <div class="fs-3 fw-semibold"><?= $escape($queueStats['failed_jobs'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($isTriageCampaign): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Interessados</div>
                    <div class="fs-3 fw-semibold"><?= $escape($triageStats['interested_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Nao interessados</div>
                    <div class="fs-3 fw-semibold"><?= $escape($triageStats['not_interested_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Mais detalhes</div>
                    <div class="fs-3 fw-semibold"><?= $escape($triageStats['needs_details_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Aguardando validacao</div>
                    <div class="fs-3 fw-semibold"><?= $escape($triageStats['awaiting_validation_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Fallback operador</div>
                    <div class="fs-3 fw-semibold"><?= $escape($triageStats['operator_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Segmentacao aplicada</h2>
                <?php if ($filters === []): ?>
                    <p class="text-muted mb-0">Sem filtros especificos. A campanha usou toda a base elegivel com contato valido.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($filters as $label => $value): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                                <span class="text-muted"><?= $escape(ucwords(str_replace('_', ' ', (string) $label))) ?></span>
                                <span class="fw-semibold text-end"><?= $escape($value) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Script base</h2>
                <pre class="small bg-light border rounded p-3 mb-0"><?= $escape($campaign['message_template'] ?? '') ?></pre>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Simular retorno inbound</h2>
                <form method="post" action="/campaigns/<?= $escape($campaign['id'] ?? 0) ?>/reply" class="row g-3">
                    <div class="col-12">
                        <label for="campaign_recipient_id" class="form-label">Destinatario</label>
                        <select id="campaign_recipient_id" name="campaign_recipient_id" class="form-select" required>
                            <option value="">Selecione</option>
                            <?php foreach ($recipients as $recipient): ?>
                                <option value="<?= $escape($recipient['id']) ?>">
                                    <?= $escape($recipient['candidate_name_snapshot']) ?> · <?= $escape($recipient['destination_contact']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="message_body" class="form-label">Mensagem recebida</label>
                        <textarea id="message_body" name="message_body" class="form-control" rows="4" required></textarea>
                        <div class="form-text">
                            <?php if ($isTriageCampaign): ?>
                                Exemplos: `1`, `2`, `3`, `SIM` ou a qualificacao completa do tecnico.
                            <?php else: ?>
                                Exemplos: `sim tenho interesse`, `nao tenho interesse`, `sair da lista`.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-outline-primary">Registrar retorno</button>
                    </div>
                </form>
                <?php if ($isTriageCampaign): ?>
                    <div class="border rounded bg-light p-3 mt-3 small">
                        Endpoint inbound para WhatsGW: <code>POST /triage/inbound</code><br>
                        Campos aceitos: <code>campaign_id</code> + <code>campaign_recipient_id</code> ou <code>contact</code>, e <code>message_body</code>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h5 mb-0">Destinatarios da campanha</h2>
                    <span class="text-muted small"><?= $escape(count($recipients)) ?> registro(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Candidato</th>
                            <th>Contato</th>
                            <th>Status campanha</th>
                            <th>Status fila</th>
                            <th>Status candidato</th>
                            <?php if ($isTriageCampaign): ?>
                                <th>Triagem</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($recipients === []): ?>
                            <tr>
                                <td colspan="<?= $isTriageCampaign ? '6' : '5' ?>" class="text-center text-muted py-4">Nenhum destinatario capturado nesta campanha.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recipients as $recipient): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= $escape($recipient['candidate_name_snapshot']) ?></div>
                                        <div class="text-muted small">Base: <?= $escape($recipient['candidate_status_snapshot']) ?></div>
                                    </td>
                                    <td><?= $escape($recipient['destination_contact']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $recipientStatusClass((string) $recipient['status']) ?>">
                                            <?= $escape(ucwords(str_replace('_', ' ', (string) $recipient['status']))) ?>
                                        </span>
                                    </td>
                                    <td><?= $escape($recipient['queue_status'] ?? '-') ?></td>
                                    <td><?= $escape(ucwords(str_replace('_', ' ', (string) ($recipient['current_candidate_status'] ?? '-')))) ?></td>
                                    <?php if ($isTriageCampaign): ?>
                                        <td>
                                            <?php if (!empty($recipient['triage_session_id'])): ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-<?= $triageStatusClass($recipient['triage_status'] ?? null) ?>">
                                                        <?= $escape(ucwords(str_replace('_', ' ', (string) ($recipient['triage_status'] ?? 'sent')))) ?>
                                                    </span>
                                                </div>
                                                <div class="small text-muted">Step: <?= $escape($recipient['triage_step'] ?? '-') ?></div>
                                                <div class="small text-muted">Fluxo: <?= $escape($recipient['triage_automation_status'] ?? '-') ?></div>
                                                <?php $qualification = is_array($recipient['collected_data']['qualification'] ?? null) ? $recipient['collected_data']['qualification'] : []; ?>
                                                <?php if ($qualification !== []): ?>
                                                    <div class="small mt-1">
                                                        <?= $escape($qualification['city'] ?? '-') ?>/<?= $escape($qualification['state'] ?? '-') ?> · Tel <?= $escape($qualification['phone'] ?? '-') ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($recipient['needs_operator'])): ?>
                                                    <div class="small text-danger mt-1">Operador: <?= $escape($recipient['fallback_reason'] ?? 'pendente') ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Sem sessao ainda</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h5 mb-0">Retornos inbound</h2>
                    <span class="text-muted small"><?= $escape(count($inboundMessages)) ?> registro(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Candidato</th>
                            <th>Mensagem</th>
                            <th>Intencao</th>
                            <th>Recebido em</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($inboundMessages === []): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">Nenhum retorno registrado ainda.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inboundMessages as $inbound): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= $escape($inbound['candidate_name']) ?></div>
                                        <div class="text-muted small"><?= $escape($inbound['source_contact']) ?></div>
                                    </td>
                                    <td><?= $escape($inbound['message_body']) ?></td>
                                    <td><?= $escape($inbound['parsed_intent']) ?></td>
                                    <td><?= $escape($inbound['received_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h5 mb-0">Trilha operacional</h2>
                    <span class="text-muted small"><?= $escape(count($activityLogs)) ?> evento(s)</span>
                </div>

                <?php if ($activityLogs === []): ?>
                    <p class="text-muted mb-0">Nenhum evento operacional registrado ainda.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($activityLogs as $log): ?>
                            <div class="timeline-item">
                                <div class="d-flex justify-content-between gap-3 flex-wrap">
                                    <div class="fw-semibold">
                                        <span class="badge bg-<?= $activityClass((string) $log['event_type']) ?> me-2">
                                            <?= $escape($log['event_type']) ?>
                                        </span>
                                        <?= $escape($log['candidate_name'] ?? 'Campanha') ?>
                                    </div>
                                    <div class="small text-muted"><?= $escape($log['created_at']) ?></div>
                                </div>
                                <div class="small text-muted mb-1">
                                    Direcao: <?= $escape($log['direction']) ?>
                                </div>
                                <?php if (!empty($log['message_body'])): ?>
                                    <div class="small"><?= $escape($log['message_body']) ?></div>
                                <?php endif; ?>
                                <?php if (($log['metadata'] ?? []) !== []): ?>
                                    <pre class="small bg-light border rounded p-2 mt-2 mb-0"><?= $escape(json_encode($log['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
