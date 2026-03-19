<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$queue = $queue ?? [];
$statusLabel = static fn (string $status): string => ucwords(str_replace('_', ' ', $status));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Fila de Analise Operacional</h1>
        <p class="text-muted mb-0">Candidatos com portal enviado e trabalho pendente para o operador.</p>
    </div>
    <span class="badge text-bg-light border"><?= $escape(count($queue)) ?> item(ns) na fila</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Candidato</th>
                    <th>Status candidato</th>
                    <th>Status portal</th>
                    <th>Docs pendentes</th>
                    <th>Pendencias abertas</th>
                    <th>Ultima revisao</th>
                    <th class="text-end">Acao</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($queue === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhum candidato aguardando analise operacional.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($queue as $item): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= $escape($item['full_name']) ?></div>
                                <div class="small text-muted">Portal atualizado em <?= $escape($item['updated_at']) ?></div>
                            </td>
                            <td><?= $escape($statusLabel((string) $item['candidate_status'])) ?></td>
                            <td><?= $escape($statusLabel((string) $item['portal_status'])) ?></td>
                            <td><?= $escape($item['pending_documents']) ?></td>
                            <td><?= $escape($item['open_pendencies']) ?></td>
                            <td><?= $escape($item['last_review_at'] ?: '-') ?></td>
                            <td class="text-end">
                                <a href="/candidates/<?= $escape($item['candidate_id']) ?>" class="btn btn-sm btn-outline-primary">Analisar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
