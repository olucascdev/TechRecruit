<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$campaigns = $campaigns ?? [];
$skills = $skills ?? [];
$states = $states ?? [];
$candidateStatuses = $candidateStatuses ?? [];
$automationTypes = $automationTypes ?? [];
$formData = $formData ?? [];
$errorMessage = $errorMessage ?? null;
$audienceEstimate = $audienceEstimate ?? null;

$campaignStatusClass = static function (string $status): string {
    return match ($status) {
        'queued' => 'primary',
        'sending' => 'warning text-dark',
        'completed' => 'success',
        'cancelled' => 'dark',
        default => 'secondary',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Campanhas WhatsApp</h1>
        <p class="text-muted mb-0">Monte o segmento, gere a fila inicial e acompanhe o disparo e a triagem.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Nova campanha</h2>
                        <p class="text-muted mb-0 small">Cria a campanha, snapshot dos destinatarios, fila de mensagens e, se aplicavel, sessao do bot de triagem.</p>
                    </div>
                    <?php if ($audienceEstimate !== null): ?>
                        <span class="badge text-bg-light border">
                            Estimativa: <?= $escape($audienceEstimate) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($errorMessage !== null): ?>
                    <div class="alert alert-danger"><?= $escape($errorMessage) ?></div>
                <?php endif; ?>

                <form method="post" action="/campaigns" class="row g-3">
                    <div class="col-12">
                        <label for="name" class="form-label">Nome da campanha</label>
                        <input
                            type="text"
                            class="form-control"
                            id="name"
                            name="name"
                            value="<?= $escape($formData['name'] ?? '') ?>"
                            placeholder="Ex.: VSAT Nordeste - Abril"
                            required
                        >
                    </div>
                    <div class="col-12">
                        <label for="automation_type" class="form-label">Modo da automacao</label>
                        <select id="automation_type" name="automation_type" class="form-select">
                            <?php foreach ($automationTypes as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= ($formData['automation_type'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= $escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="skill" class="form-label">Skill</label>
                        <select id="skill" name="skill" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($skills as $skill): ?>
                                <option value="<?= $escape($skill) ?>" <?= ($formData['skill'] ?? '') === $skill ? 'selected' : '' ?>>
                                    <?= $escape($skill) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="state" class="form-label">Estado</label>
                        <select id="state" name="state" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($states as $state): ?>
                                <option value="<?= $escape($state) ?>" <?= ($formData['state'] ?? '') === $state ? 'selected' : '' ?>>
                                    <?= $escape($state) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="status" class="form-label">Status atual do candidato</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($candidateStatuses as $status): ?>
                                <option value="<?= $escape($status) ?>" <?= ($formData['status'] ?? '') === $status ? 'selected' : '' ?>>
                                    <?= $escape(ucwords(str_replace('_', ' ', $status))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="recipient_limit" class="form-label">Limite de destinatarios</label>
                        <input
                            type="number"
                            min="1"
                            max="5000"
                            class="form-control"
                            id="recipient_limit"
                            name="recipient_limit"
                            value="<?= $escape($formData['recipient_limit'] ?? '') ?>"
                            placeholder="Opcional"
                        >
                    </div>
                    <div class="col-12">
                        <label for="search" class="form-label">Busca complementar</label>
                        <input
                            type="text"
                            class="form-control"
                            id="search"
                            name="search"
                            value="<?= $escape($formData['search'] ?? '') ?>"
                            placeholder="Nome, CPF, telefone ou e-mail"
                        >
                    </div>
                    <div class="col-12">
                        <label for="message_template" class="form-label">Script base</label>
                        <textarea
                            id="message_template"
                            name="message_template"
                            class="form-control"
                            rows="7"
                            required
                        ><?= $escape($formData['message_template'] ?? '') ?></textarea>
                        <div class="form-text">Placeholders disponiveis: <code>{first_name}</code> e <code>{full_name}</code>.</div>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary">Criar campanha e montar fila</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h5 mb-0">Campanhas recentes</h2>
                    <span class="text-muted small"><?= $escape(count($campaigns)) ?> campanha(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Campanha</th>
                            <th>Modo</th>
                            <th>Status</th>
                            <th>Publico</th>
                            <th>Fila</th>
                            <th>Resposta</th>
                            <th class="text-end">Acao</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($campaigns === []): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">Nenhuma campanha criada ainda.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($campaigns as $campaign): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= $escape($campaign['name']) ?></div>
                                        <div class="text-muted small"><?= $escape($campaign['created_at']) ?> · por <?= $escape($campaign['created_by']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light border">
                                            <?= $escape(($automationTypes[$campaign['automation_type'] ?? 'broadcast'] ?? ($campaign['automation_type'] ?? 'broadcast'))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $campaignStatusClass((string) $campaign['status']) ?>">
                                            <?= $escape(ucwords(str_replace('_', ' ', (string) $campaign['status']))) ?>
                                        </span>
                                    </td>
                                    <td><?= $escape($campaign['audience_count']) ?></td>
                                    <td>
                                        <div class="small">Pendentes: <?= $escape($campaign['pending_count']) ?></div>
                                        <div class="small text-muted">Enviadas: <?= $escape($campaign['sent_count']) ?></div>
                                    </td>
                                    <td><?= $escape($campaign['responded_count']) ?></td>
                                    <td class="text-end">
                                        <a href="/campaigns/<?= $escape($campaign['id']) ?>" class="btn btn-sm btn-outline-primary">Detalhes</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
