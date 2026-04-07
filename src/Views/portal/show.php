<?php

declare(strict_types=1);

use TechRecruit\Models\PortalModel;
use TechRecruit\Support\LabelTranslator;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portal = $portal ?? [];
$profile = is_array($portal['profile'] ?? null) ? $portal['profile'] : [];
$documents = is_array($portal['documents'] ?? null) ? $portal['documents'] : [];
$checklist = is_array($portal['checklist'] ?? null) ? $portal['checklist'] : [];
$portalStatus = (string) ($portal['status'] ?? 'draft');
$isReadOnly = in_array($portalStatus, ['approved', 'expired'], true);
$correctionMode = strtolower(trim((string) ($_GET['mode'] ?? ''))) === 'correction';
$termsAlreadyAccepted = !empty($portal['terms_accepted']);
$documentGroups = [];
$correctionDocumentTypes = [];

foreach ($documents as $document) {
    $type = (string) ($document['document_type'] ?? 'outro');
    $documentGroups[$type][] = $document;

    if (in_array((string) ($document['review_status'] ?? ''), ['rejected', 'correction_requested'], true)) {
        $correctionDocumentTypes[] = $type;
    }
}

$correctionDocumentTypes = array_values(array_unique($correctionDocumentTypes));

if ($correctionMode && $correctionDocumentTypes === []) {
    $correctionMode = false;
}

$documentsForUpload = PortalModel::CHECKLIST_ITEMS;

$equipmentOptions = [
    'ALICATE DE CORTE',
    'ALICATE DE CRIMPAGEM',
    'CHAVES DE FENDA E PHILIPS',
    'CONECTORES (RJ45)',
    'CONECTORES KEYSTONE (Fêmea RJ45)',
    'CABOS CONSOLE',
    'TESTADOR DE CABO',
    'MULTIMETRO',
];

$transportOptions = ['MOTO', 'CARRO', 'UBER', 'ÔNIBUS'];

$availabilityDaysOptions = [
    'DOMINGO',
    'SEGUNDA-FEIRA',
    'TERÇA-FEIRA',
    'QUARTA-FEIRA',
    'QUINTA-FEIRA',
    'SEXTA-FEIRA',
    'SÁBADO',
];

$selectedItems = static function (mixed $raw): array {
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $values = preg_split('/\s*,\s*/u', (string) $raw) ?: [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        $values
    ), static fn (string $item): bool => $item !== '')));
};

$selectedEquipments = $selectedItems($profile['equipment_list'] ?? '');
$selectedTransportModes = $selectedItems($profile['transport_modes'] ?? '');
$selectedAvailabilityDays = $selectedItems($profile['availability_days'] ?? '');

if ($correctionMode) {
    $documentsForUpload = array_filter(
        PortalModel::CHECKLIST_ITEMS,
        static fn (string $key): bool => in_array($key, $correctionDocumentTypes, true),
        ARRAY_FILTER_USE_KEY
    );
}

$value = static function (string $key, array $profile, array $portal): string {
    $profileValue = $profile[$key] ?? null;

    if (is_string($profileValue) && trim($profileValue) !== '') {
        return $profileValue;
    }

    return match ($key) {
        'full_name' => (string) ($portal['candidate_full_name'] ?? ''),
        'cpf' => (string) ($portal['candidate_cpf'] ?? ''),
        'cnpj' => (string) ($portal['cnpj'] ?? ''),
        'pix_key' => (string) ($portal['pix_key'] ?? ''),
        'rg' => '',
        'company_name' => '',
        'issues_invoice' => '',
        'full_address' => '',
        'service_cities' => '',
        'whatsapp' => (string) ($portal['whatsapp'] ?? ''),
        'email' => (string) ($portal['email'] ?? ''),
        'secondary_phone' => '',
        'state' => (string) ($portal['state'] ?? ''),
        'city' => (string) ($portal['city'] ?? ''),
        'region' => (string) ($portal['region'] ?? ''),
        'service_region' => '',
        'bank_name' => '',
        'bank_agency' => '',
        'bank_account' => '',
        'bank_holder_name' => '',
        'bank_holder_doc' => '',
        default => '',
    };
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'submitted', 'approved' => 'success',
        'under_review' => 'warning text-dark',
        'rejected', 'expired' => 'danger',
        default => 'secondary',
    };
};
?>
<div class="row justify-content-center">
    <div class="col-xl-10">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Portal de Cadastro</h1>
                <p class="text-muted mb-0"><?= $escape($portal['candidate_full_name'] ?? '') ?></p>
            </div>
            <span class="badge bg-<?= $statusClass($portalStatus) ?>">
                <?= $escape(LabelTranslator::toPtBr($portalStatus)) ?>
            </span>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <?php if ($isReadOnly): ?>
                    <div class="alert alert-info">
                        Este portal está bloqueado para novas alterações.
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h5 mb-3"><?= $correctionMode ? 'Ajuste documental solicitado' : 'Finalize seu cadastro W13 e envie os documentos' ?></h2>

                        <?php if ($correctionMode): ?>
                            <div class="alert alert-warning">
                                Identificamos documentos com pendencia. Reenvie apenas os itens solicitados abaixo para nova analise.
                            </div>
                        <?php endif; ?>

                        <form action="<?= $escape($portalFormAction ?? '') ?>" method="post" enctype="multipart/form-data" class="row g-3">
                            <?= $csrfField ?>
                            <?php if (!$correctionMode): ?>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Nome completo</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?= $escape($value('full_name', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-3">
                                    <label for="cpf" class="form-label">CPF</label>
                                    <input type="text" class="form-control" id="cpf" name="cpf" value="<?= $escape($value('cpf', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-3">
                                    <label for="cnpj" class="form-label">CNPJ / MEI</label>
                                    <input type="text" class="form-control" id="cnpj" name="cnpj" value="<?= $escape($value('cnpj', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-4">
                                    <label for="birth_date" class="form-label">Data de nascimento</label>
                                    <input type="date" class="form-control" id="birth_date" name="birth_date" value="<?= $escape((string) ($profile['birth_date'] ?? '')) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-4">
                                    <label for="rg" class="form-label">RG / Órgão expedidor</label>
                                    <input type="text" class="form-control" id="rg" name="rg" value="<?= $escape($value('rg', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-4">
                                    <label for="issues_invoice" class="form-label">Emite nota fiscal?</label>
                                    <?php $invoiceValue = strtolower(trim($value('issues_invoice', $profile, $portal))); ?>
                                    <select class="form-select" id="issues_invoice" name="issues_invoice" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                        <option value="">Selecione...</option>
                                        <option value="sim" <?= in_array($invoiceValue, ['1', 'sim', 'yes', 'true'], true) ? 'selected' : '' ?>>Sim</option>
                                        <option value="nao" <?= in_array($invoiceValue, ['0', 'nao', 'não', 'no', 'false'], true) ? 'selected' : '' ?>>Não</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="company_name" class="form-label">Nome da empresa (se houver)</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" value="<?= $escape($value('company_name', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-4">
                                    <label for="whatsapp" class="form-label">WhatsApp</label>
                                    <input type="text" class="form-control" id="whatsapp" name="whatsapp" value="<?= $escape($value('whatsapp', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-4">
                                    <label for="secondary_phone" class="form-label">Telefone secundário</label>
                                    <input type="text" class="form-control" id="secondary_phone" name="secondary_phone" value="<?= $escape($value('secondary_phone', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-4">
                                    <label for="email" class="form-label">E-mail</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?= $escape($value('email', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-12">
                                    <label for="full_address" class="form-label">Endereço completo</label>
                                    <textarea class="form-control" id="full_address" name="full_address" rows="2" <?= $isReadOnly ? 'disabled' : '' ?> required><?= $escape($value('full_address', $profile, $portal)) ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label for="pix_key" class="form-label">Chave Pix</label>
                                    <input type="text" class="form-control" id="pix_key" name="pix_key" value="<?= $escape($value('pix_key', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-4">
                                    <label for="bank_name" class="form-label">Banco</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" value="<?= $escape($value('bank_name', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-2">
                                    <label for="bank_agency" class="form-label">Agência</label>
                                    <input type="text" class="form-control" id="bank_agency" name="bank_agency" value="<?= $escape($value('bank_agency', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-2">
                                    <label for="bank_account" class="form-label">Conta</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" value="<?= $escape($value('bank_account', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label for="bank_holder_name" class="form-label">Nome do favorecido</label>
                                    <input type="text" class="form-control" id="bank_holder_name" name="bank_holder_name" value="<?= $escape($value('bank_holder_name', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="bank_holder_doc" class="form-label">CPF/CNPJ do favorecido</label>
                                    <input type="text" class="form-control" id="bank_holder_doc" name="bank_holder_doc" value="<?= $escape($value('bank_holder_doc', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-2">
                                    <label for="state" class="form-label">UF</label>
                                    <input type="text" class="form-control" id="state" name="state" maxlength="2" value="<?= $escape($value('state', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-4">
                                    <label for="city" class="form-label">Cidade</label>
                                    <input type="text" class="form-control" id="city" name="city" value="<?= $escape($value('city', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label for="region" class="form-label">Regiao</label>
                                    <input type="text" class="form-control" id="region" name="region" value="<?= $escape($value('region', $profile, $portal)) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-12">
                                    <label for="service_region" class="form-label">Região de atendimento</label>
                                    <input type="text" class="form-control" id="service_region" name="service_region" value="<?= $escape($value('service_region', $profile, $portal)) ?>" placeholder="Ex.: Grande Vitória e cidades até 150km" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-12">
                                    <label for="service_cities" class="form-label">Cidades de atendimento (até 100km)</label>
                                    <textarea class="form-control" id="service_cities" name="service_cities" rows="2" <?= $isReadOnly ? 'disabled' : '' ?> required><?= $escape($value('service_cities', $profile, $portal)) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Equipamentos disponíveis</label>
                                    <div class="row row-cols-1 row-cols-md-2 g-2">
                                        <?php foreach ($equipmentOptions as $option): ?>
                                            <div class="col">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="equipment_<?= $escape(md5($option)) ?>" name="equipment_list[]" value="<?= $escape($option) ?>" <?= in_array($option, $selectedEquipments, true) ? 'checked' : '' ?> <?= $isReadOnly ? 'disabled' : '' ?>>
                                                    <label class="form-check-label" for="equipment_<?= $escape(md5($option)) ?>"><?= $escape($option) ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Forma de deslocamento</label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php foreach ($transportOptions as $option): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="transport_<?= $escape(md5($option)) ?>" name="transport_modes[]" value="<?= $escape($option) ?>" <?= in_array($option, $selectedTransportModes, true) ? 'checked' : '' ?> <?= $isReadOnly ? 'disabled' : '' ?>>
                                                <label class="form-check-label" for="transport_<?= $escape(md5($option)) ?>"><?= $escape($option) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Dias de disponibilidade</label>
                                    <div class="row row-cols-1 row-cols-md-2 g-2">
                                        <?php foreach ($availabilityDaysOptions as $option): ?>
                                            <div class="col">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="availability_day_<?= $escape(md5($option)) ?>" name="availability_days[]" value="<?= $escape($option) ?>" <?= in_array($option, $selectedAvailabilityDays, true) ? 'checked' : '' ?> <?= $isReadOnly ? 'disabled' : '' ?>>
                                                    <label class="form-check-label" for="availability_day_<?= $escape(md5($option)) ?>"><?= $escape($option) ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="availability" class="form-label">Disponibilidade</label>
                                    <input type="text" class="form-control" id="availability" name="availability" value="<?= $escape((string) ($profile['availability'] ?? '')) ?>" placeholder="Ex.: Inicio imediato, escala 12x36, viagens nacionais" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                </div>
                                <div class="col-12">
                                    <label for="experience_summary" class="form-label">Resumo da experiencia</label>
                                    <textarea class="form-control" id="experience_summary" name="experience_summary" rows="5" <?= $isReadOnly ? 'disabled' : '' ?> required><?= $escape((string) ($profile['experience_summary'] ?? '')) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label for="notes" class="form-label">Observacoes adicionais</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" <?= $isReadOnly ? 'disabled' : '' ?>><?= $escape((string) ($profile['notes'] ?? '')) ?></textarea>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($documentsForUpload as $key => $item): ?>
                                <div class="col-md-6">
                                    <label for="<?= $escape($key) ?>" class="form-label">
                                        <?= $escape($item['label']) ?>
                                        <?php if ($item['required']): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <input type="file" class="form-control" id="<?= $escape($key) ?>" name="<?= $escape($key) ?>" accept=".pdf,.jpg,.jpeg,.png" <?= $isReadOnly ? 'disabled' : '' ?>>
                                    <?php if (($documentGroups[$key] ?? []) !== []): ?>
                                        <div class="small text-muted mt-1">
                                            Já enviado(s):
                                            <?php foreach ($documentGroups[$key] as $document): ?>
                                                <span class="d-block"><?= $escape($document['original_name']) ?> · <?= $escape($document['uploaded_at']) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!$termsAlreadyAccepted || !$correctionMode): ?>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="terms_accepted" name="terms_accepted" <?= $termsAlreadyAccepted ? 'checked' : '' ?> <?= $isReadOnly ? 'disabled' : '' ?> <?= $termsAlreadyAccepted ? '' : 'required' ?>>
                                        <label class="form-check-label" for="terms_accepted">
                                            Confirmo que as informacoes e os documentos enviados sao verdadeiros e autorizo o uso para fins de recrutamento e habilitacao operacional na W13.
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$isReadOnly): ?>
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-success"><?= $correctionMode ? 'Enviar ajustes' : 'Enviar cadastro e documentos' ?></button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Checklist documental W13</h2>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($checklist as $item): ?>
                                <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= $escape($item['label']) ?></div>
                                        <div class="small text-muted"><?= $item['required'] ? 'Obrigatório' : 'Opcional' ?></div>
                                    </div>
                                    <span class="badge text-bg-<?= $item['received'] ? 'success' : 'light' ?>">
                                        <?= $escape($item['received'] ? 'Recebido' : 'Pendente') ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Status do portal</h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-6">Status</dt>
                            <dd class="col-sm-6"><?= $escape(LabelTranslator::toPtBr($portalStatus)) ?></dd>
                            <dt class="col-sm-6">Último acesso</dt>
                            <dd class="col-sm-6"><?= $escape($portal['last_accessed_at'] ?? '-') ?></dd>
                            <dt class="col-sm-6">Enviado em</dt>
                            <dd class="col-sm-6"><?= $escape($portal['submitted_at'] ?? '-') ?></dd>
                            <dt class="col-sm-6">Versão dos termos</dt>
                            <dd class="col-sm-6"><?= $escape($portal['terms_version'] ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
