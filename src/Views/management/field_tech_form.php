<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldSchema = is_array($fieldSchema ?? null) ? $fieldSchema : [];
$sectionLabels = is_array($sectionLabels ?? null) ? $sectionLabels : [];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Formulário de Cadastro Técnico</h1>
        <p class="text-muted mb-0">Edite os títulos e defina obrigatoriedade dos campos exibidos no cadastro público.</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form action="<?= $escape($url('/management/forms/field-tech')) ?>" method="post" class="row g-4">
            <?= $csrfField ?>

            <div class="col-12">
                <h2 class="h5 mb-3">Títulos das caixas (seções)</h2>
                <div class="row g-3">
                    <?php foreach ($sectionLabels as $sectionKey => $sectionLabel): ?>
                        <div class="col-md-6">
                            <label for="section_<?= $escape($sectionKey) ?>" class="form-label"><?= $escape($sectionKey) ?></label>
                            <input
                                id="section_<?= $escape($sectionKey) ?>"
                                type="text"
                                class="form-control"
                                name="sections[<?= $escape($sectionKey) ?>]"
                                value="<?= $escape($sectionLabel) ?>"
                            >
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <h2 class="h5 mb-3">Campos</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Campo</th>
                            <th>Título exibido</th>
                            <th class="text-center">Obrigatório</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fieldSchema as $fieldKey => $meta): ?>
                            <tr>
                                <td>
                                    <code><?= $escape($fieldKey) ?></code>
                                    <div class="small text-muted">Tipo: <?= $escape($meta['type'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="labels[<?= $escape($fieldKey) ?>]"
                                        value="<?= $escape($meta['label'] ?? '') ?>"
                                    >
                                </td>
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        name="required_fields[]"
                                        value="<?= $escape($fieldKey) ?>"
                                        <?= !empty($meta['required']) ? 'checked' : '' ?>
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Salvar configurações</button>
            </div>
        </form>
    </div>
</div>
