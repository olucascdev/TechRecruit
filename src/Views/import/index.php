<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$batches = $batches ?? [];
$statusClass = static function (string $status): string {
    return match ($status) {
        'done' => 'success',
        'processing' => 'warning text-dark',
        'failed' => 'danger',
        default => 'secondary',
    };
};
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
        <h1 class="h3 mb-1">Importações</h1>
        <p class="text-muted mb-0">Importe planilhas de técnicos em formato Excel.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <h2 class="h5 mb-1">Nova importação</h2>
                <p class="text-muted mb-0">Arquivos aceitos: `.xlsx` e `.xls`, com tamanho máximo de 10MB.</p>
            </div>
            <div class="col-lg-5">
                <form action="<?= $escape($url('/import/upload')) ?>" method="post" enctype="multipart/form-data" class="row g-2">
                    <?= $csrfField ?>
                    <div class="col-12">
                        <input type="file" class="form-control" name="excel_file" accept=".xlsx,.xls" required>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary">Importar planilha</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Histórico de lotes</h2>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Importados</th>
                    <th>Erros</th>
                    <th>Data</th>
                    <th class="text-end">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($batches === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhuma importação registrada.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= $escape($batch['filename']) ?></td>
                            <td>
                                <span class="badge bg-<?= $statusClass((string) $batch['status']) ?>">
                                    <?= $escape($batch['status']) ?>
                                </span>
                            </td>
                            <td><?= $escape($batch['total_rows']) ?></td>
                            <td><?= $escape($batch['imported_rows']) ?></td>
                            <td><?= $escape($batch['error_rows']) ?></td>
                            <td><?= $escape($batch['created_at']) ?></td>
                            <td class="text-end">
                                <div class="inline-flex flex-nowrap items-center justify-end gap-2">
                                    <a href="<?= $escape($url('/import/result/' . $batch['id'])) ?>" class="action-icon action-icon-sm action-icon-primary" title="Ver resultado" aria-label="Ver resultado">
                                        <?= $actionIcon('view') ?>
                                    </a>
                                    <form method="post" action="<?= $escape($url('/import/' . $batch['id'] . '/delete')) ?>" class="m-0" onsubmit="return confirm('Excluir este lote de importação? Os candidatos importados serão mantidos.');">
                                        <?= $csrfField ?>
                                        <button type="submit" class="action-icon action-icon-sm action-icon-danger" title="Excluir lote" aria-label="Excluir lote">
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
