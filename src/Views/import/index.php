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
                <form action="/import/upload" method="post" enctype="multipart/form-data" class="row g-2">
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
                                <a href="/import/result/<?= $escape($batch['id']) ?>" class="btn btn-sm btn-outline-secondary">Ver resultado</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
