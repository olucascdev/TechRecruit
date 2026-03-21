<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$batch = $batch ?? [];
$rows = $rows ?? [];
$duplicates = 0;
$errors = 0;
$actionIcon = static function (string $name): string {
    return match ($name) {
        'candidates' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 19.5v-.75a3.75 3.75 0 0 0-3.75-3.75h-4.5a3.75 3.75 0 0 0-3.75 3.75v.75"/><circle cx="10.5" cy="8.25" r="3.75"/><path d="M17.25 10.5a3 3 0 1 1 0 6"/><path d="M19.5 19.5v-.75a3 3 0 0 0-2.25-2.9"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 19.5 3 12l7.5-7.5"/><path d="M3.75 12h17.25"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 7.5h15"/><path d="M9.75 3.75h4.5"/><path d="M6.75 7.5 7.5 19.5a1.5 1.5 0 0 0 1.5 1.5h6a1.5 1.5 0 0 0 1.5-1.5l.75-12"/><path d="M10 11.25v5.25"/><path d="M14 11.25v5.25"/></svg>',
        default => '',
    };
};

foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'duplicate') {
        $duplicates++;
        continue;
    }

    if (($row['status'] ?? '') === 'error') {
        $errors++;
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Resultado da importação</h1>
        <p class="text-muted mb-0"><?= $escape($batch['filename'] ?? '') ?></p>
</div>
<div class="flex flex-nowrap items-center gap-2 overflow-x-auto pb-1">
        <a href="/candidates" class="action-icon action-icon-primary" title="Ver candidatos importados" aria-label="Ver candidatos importados">
            <?= $actionIcon('candidates') ?>
        </a>
        <form method="post" action="/import/<?= $escape($batch['id'] ?? '') ?>/delete" class="m-0" onsubmit="return confirm('Excluir este lote de importação? Os candidatos importados serão mantidos.');">
            <?= $csrfField ?>
            <button type="submit" class="action-icon action-icon-danger" title="Excluir lote" aria-label="Excluir lote">
                <?= $actionIcon('delete') ?>
            </button>
        </form>
        <a href="/import" class="action-icon action-icon-secondary" title="Voltar" aria-label="Voltar">
            <?= $actionIcon('back') ?>
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 text-center text-md-start">
            <div class="col-md-3">
                <div class="text-muted small">Total</div>
                <div class="fs-4 fw-semibold"><?= $escape($batch['total_rows'] ?? 0) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Importados</div>
                <div class="fs-4 fw-semibold"><?= $escape($batch['imported_rows'] ?? 0) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Erros</div>
                <div class="fs-4 fw-semibold"><?= $escape($errors) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Duplicados</div>
                <div class="fs-4 fw-semibold"><?= $escape($duplicates) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($rows !== []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">Log de linhas com erro ou duplicidade</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Linha</th>
                        <th>Motivo</th>
                        <th>Dados brutos</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= $escape($row['row_number']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= $escape(($row['status'] ?? '') === 'duplicate' ? 'Duplicado' : 'Erro') ?></div>
                                <div class="text-muted small"><?= $escape($row['error_message'] ?? '-') ?></div>
                            </td>
                            <td>
                                <pre class="small bg-light border rounded p-2 mb-0"><?= $escape(json_encode($row['raw_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
