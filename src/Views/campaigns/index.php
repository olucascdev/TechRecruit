<?php

declare(strict_types=1);

use TechRecruit\Support\LabelTranslator;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$campaigns = $campaigns ?? [];
$skills = $skills ?? [];
$states = $states ?? [];
$candidateStatuses = $candidateStatuses ?? [];
$automationTypes = $automationTypes ?? [];
$formData = $formData ?? [];
$errorMessage = $errorMessage ?? null;
$audienceEstimate = $audienceEstimate ?? null;
$defaultBatchLimit = max(1, (int) ($defaultBatchLimit ?? 25));
$autoProcessIntervalSeconds = max(5, (int) ($autoProcessIntervalSeconds ?? 15));

$campaignStatusClass = static function (string $status): string {
    return match ($status) {
        'queued' => 'primary',
        'sending' => 'warning text-dark',
        'completed' => 'success',
        'cancelled' => 'dark',
        default => 'secondary',
    };
};
$statusLabel = static fn (string $status): string => LabelTranslator::toPtBr($status);
$actionIcon = static function (string $name): string {
    return match ($name) {
        'view' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/><circle cx="12" cy="12" r="3.25"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 7.5h15"/><path d="M9.75 3.75h4.5"/><path d="M6.75 7.5 7.5 19.5a1.5 1.5 0 0 0 1.5 1.5h6a1.5 1.5 0 0 0 1.5-1.5l.75-12"/><path d="M10 11.25v5.25"/><path d="M14 11.25v5.25"/></svg>',
        default => '',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Campanhas WhatsApp</h1>
        <p class="text-muted mb-0">Monte o segmento, gere a fila inicial e acompanhe o disparo e a triagem.</p>
    </div>
    <form method="post" action="<?= $escape($url('/campaigns/process-due')) ?>" class="d-flex flex-wrap align-items-end gap-2">
        <?= $csrfField ?>
        <div>
            <label for="global_batch_limit" class="form-label small mb-1">Lote global</label>
            <input
                type="number"
                min="1"
                max="500"
                class="form-control"
                id="global_batch_limit"
                name="batch_limit"
                value="<?= $escape($defaultBatchLimit) ?>"
            >
        </div>
        <label for="auto_process_global" class="inline-flex min-h-[46px] w-full items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 sm:mb-0 sm:w-auto sm:min-w-[190px] sm:shrink-0 sm:self-end">
            <input
                class="form-check-input"
                type="checkbox"
                value="1"
                id="auto_process_global"
                data-auto-process-toggle="global"
            >
            <span class="small whitespace-nowrap text-slate-700">
                Auto a cada <?= $escape($autoProcessIntervalSeconds) ?>s
            </span>
        </label>
        <button type="submit" class="btn btn-outline-primary">Processar fila pendente</button>
        <div class="small text-muted w-100 min-h-[20px] leading-5" data-auto-process-status="global">
            Use esta opção se quiser deixar a tela rodando como agendador interno no navegador.
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Nova campanha</h2>
                        <p class="text-muted mb-0 small">Cria a campanha, snapshot dos destinatários, fila de mensagens e, se aplicável, sessão do bot de triagem.</p>
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

                <form method="post" action="<?= $escape($url('/campaigns')) ?>" class="row g-3">
                    <?= $csrfField ?>
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
                        <label for="automation_type" class="form-label">Modo da automação</label>
                        <select id="automation_type" name="automation_type" class="form-select">
                            <?php foreach ($automationTypes as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= ($formData['automation_type'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= $escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="skill" class="form-label">Habilidade</label>
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
                                    <?= $escape($statusLabel($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="recipient_limit" class="form-label">Limite de destinatários</label>
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
                        <div class="form-text">Mensagem inicial enviada ao lead. Ao responder "1", o bot envia o link do formulário automaticamente.</div>
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
                            <th>Público</th>
                            <th>Fila</th>
                            <th>Resposta</th>
                            <th class="text-end">Ação</th>
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
                                            <?= $escape($statusLabel((string) $campaign['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= $escape($campaign['audience_count']) ?></td>
                                    <td>
                                        <div class="small">Pendentes: <?= $escape($campaign['pending_count']) ?></div>
                                        <div class="small text-muted">Enviadas: <?= $escape($campaign['sent_count']) ?></div>
                                    </td>
                                    <td><?= $escape($campaign['responded_count']) ?></td>
                                    <td class="text-end">
                                        <div class="inline-flex flex-nowrap items-center justify-end gap-2">
                                            <a href="<?= $escape($url('/campaigns/' . $campaign['id'])) ?>" class="action-icon action-icon-sm action-icon-primary" title="Ver detalhes" aria-label="Ver detalhes">
                                                <?= $actionIcon('view') ?>
                                            </a>
                                            <form method="post" action="<?= $escape($url('/campaigns/' . $campaign['id'] . '/delete')) ?>" class="m-0" onsubmit="return confirm('Excluir esta campanha e toda a fila vinculada?');">
                                                <?= $csrfField ?>
                                                <button type="submit" class="action-icon action-icon-sm action-icon-danger" title="Excluir campanha" aria-label="Excluir campanha">
                                                    <?= $actionIcon('delete') ?>
                                                </button>
                                            </form>
                                        </div>
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
<?php
$processDueAction = $url('/campaigns/process-due');
$processDueEndpoint = $url('/campaigns/process-due/run');
$pageScripts = <<<HTML
<script>
(() => {
    const form = document.querySelector('form[action="{$processDueAction}"]');
    const toggle = document.querySelector('[data-auto-process-toggle="global"]');
    const statusNode = document.querySelector('[data-auto-process-status="global"]');

    if (!form || !toggle || !statusNode) {
        return;
    }

    const batchInput = form.querySelector('input[name="batch_limit"]');
    const endpoint = '{$processDueEndpoint}';
    const intervalMs = {$autoProcessIntervalSeconds} * 1000;
    const storageKey = 'techrecruit:auto-process:global';
    let timerId = null;
    let busy = false;

    const setStatus = (message, className = 'text-muted') => {
        statusNode.className = `small w-100 \${className}`;
        statusNode.textContent = message;
    };

    const scheduleNext = () => {
        window.clearTimeout(timerId);

        if (!toggle.checked) {
            return;
        }

        timerId = window.setTimeout(runCycle, intervalMs);
    };

    const persistToggle = () => {
        window.localStorage.setItem(storageKey, toggle.checked ? '1' : '0');
    };

    async function runCycle() {
        if (!toggle.checked) {
            return;
        }

        if (document.visibilityState !== 'visible') {
            setStatus('Auto-processamento pausado em aba oculta.', 'text-muted');
            scheduleNext();
            return;
        }

        if (busy) {
            scheduleNext();
            return;
        }

        busy = true;
        setStatus('Processando lote global...', 'text-primary');

        try {
            const payload = new URLSearchParams();
            payload.set('batch_limit', batchInput && batchInput.value !== '' ? batchInput.value : String({$defaultBatchLimit}));
            payload.set('_token', window.TechRecruit?.csrfToken || '');

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: window.TechRecruit?.csrfHeaders ? window.TechRecruit.csrfHeaders({
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }) : {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload.toString()
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Falha ao processar a fila global.');
            }

            setStatus(
                `Último lote: \${result.processed} item(ns), \${result.sent} enviado(s), \${result.failed} falha(s), \${result.opt_out} descadastro(s) em \${result.campaigns} campanha(s).`,
                'text-success'
            );

            if (Number(result.processed || 0) > 0) {
                window.setTimeout(() => window.location.reload(), 1200);
                return;
            }
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Falha inesperada no auto-processamento.', 'text-danger');
        } finally {
            busy = false;
            scheduleNext();
        }
    }

    toggle.checked = window.localStorage.getItem(storageKey) === '1';

    if (toggle.checked) {
        setStatus('Auto-processamento ativo. A tela vai verificar a fila periodicamente.', 'text-success');
        runCycle();
    }

    toggle.addEventListener('change', () => {
        persistToggle();

        if (toggle.checked) {
            setStatus('Auto-processamento ativo. Primeiro lote sera executado agora.', 'text-success');
            runCycle();
            return;
        }

        window.clearTimeout(timerId);
        busy = false;
        setStatus('Auto-processamento pausado.', 'text-muted');
    });
})();
</script>
HTML;
?>
