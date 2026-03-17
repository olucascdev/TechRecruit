<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$batch = $batch ?? [];
$rows = $rows ?? [];
$duplicates = 0;
$errors = 0;

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
        <h1 class="h3 mb-1">Resultado da importacao</h1>
        <p class="text-muted mb-0"><?= $escape($batch['filename'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="/candidates" class="btn btn-primary">Ver candidatos importados</a>
        <a href="/import" class="btn btn-outline-secondary">Voltar</a>
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
