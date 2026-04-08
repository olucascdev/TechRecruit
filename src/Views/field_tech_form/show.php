<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha Cadastral Técnico de Campo Freelancer 2026 – W13</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        input.error, select.error, textarea.error { border-color: #dc2626 !important; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen py-8 px-4">
<?php
$escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$errors  = $_SESSION['form_errors'] ?? [];
$old     = $_SESSION['form_data']   ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

$formSchema = is_array($formSchema ?? null) ? $formSchema : ['fields' => [], 'sections' => []];
$fieldsSchema = is_array($formSchema['fields'] ?? null) ? $formSchema['fields'] : [];
$sectionsSchema = is_array($formSchema['sections'] ?? null) ? $formSchema['sections'] : [];

$fieldLabel = static fn (string $field, string $fallback): string => (string) (($fieldsSchema[$field]['label'] ?? '') !== ''
    ? $fieldsSchema[$field]['label']
    : $fallback);
$isRequired = static fn (string $field, bool $fallback = false): bool => isset($fieldsSchema[$field])
    ? (bool) ($fieldsSchema[$field]['required'] ?? false)
    : $fallback;
$sectionLabel = static fn (string $section, string $fallback): string => (string) (($sectionsSchema[$section] ?? '') !== ''
    ? $sectionsSchema[$section]
    : $fallback);
$requiredMark = static fn (string $field, bool $fallback = false): string => $isRequired($field, $fallback)
    ? ' <span class="text-red-500">*</span>'
    : '';

$hasError = static fn (string $f): string => isset($errors[$f]) ? 'border-red-500' : 'border-gray-300';
$oldVal   = static fn (string $f): string => $escape($old[$f] ?? '');
$errMsg   = static function (string $f) use ($errors, $escape): void {
    if (isset($errors[$f])) {
        echo '<p class="text-red-600 text-xs mt-1">' . $escape($errors[$f]) . '</p>';
    }
};

$inputClass  = 'w-full rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
$labelClass  = 'block text-sm font-medium text-gray-700 mb-1';
$sectionHead = 'text-base font-semibold text-gray-800 border-b border-gray-200 pb-1 mb-4 mt-6';

$equipamentos = [
    'ALICATE DE CORTE',
    'ALICATE DE CRIMPAGEM',
    'CHAVES DE FENDA E PHILIPS',
    'CONECTORES (RJ45)',
    'CONECTORES KEYSTONE (Fêmea RJ45)',
    'CABOS CONSOLE',
    'TESTADOR DE CABO',
    'MULTIMETRO',
];

$deslocamentos = ['MOTO', 'CARRO', 'UBER', 'ÔNIBUS'];

$diasSemana = [
    'DOMINGO', 'SEGUNDA-FEIRA', 'TERÇA-FEIRA', 'QUARTA-FEIRA',
    'QUINTA-FEIRA', 'SEXTA-FEIRA', 'SÁBADO',
];

$oldEquip  = (array) ($old['equipamentos']   ?? []);
$oldDeslo  = (array) ($old['deslocamento']   ?? []);
$oldDisp   = (array) ($old['disponibilidade'] ?? []);

$csrfToken = \TechRecruit\Security\Csrf::token();
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 md:p-8">

        <!-- Header -->
        <div class="text-center mb-8">
            <p class="text-xs font-semibold tracking-widest text-blue-600 uppercase mb-1">W13</p>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 leading-tight">
                Ficha Cadastral<br>Técnico de Campo Freelancer 2026
            </h1>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6 text-sm text-red-700">
            <?= isset($errors['_global']) ? $escape($errors['_global']) : 'Corrija os campos destacados antes de enviar.' ?>
        </div>
        <?php endif; ?>

        <form id="field-tech-form" method="POST" action="<?= \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico') ?>" novalidate>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">

            <!-- Dados Pessoais -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_personal', 'Dados Pessoais')) ?></p>

            <div class="space-y-4">
                <div>
                    <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('nome_completo', 'Nome completo')) ?><?= $requiredMark('nome_completo', true) ?></label>
                    <input type="text" name="nome_completo" value="<?= $oldVal('nome_completo') ?>"
                        class="<?= $inputClass ?> <?= $hasError('nome_completo') ?>" autocomplete="name" <?= $isRequired('nome_completo', true) ? 'required' : '' ?>>
                    <?php $errMsg('nome_completo'); ?>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('data_nascimento', 'Data de nascimento')) ?><?= $requiredMark('data_nascimento', true) ?></label>
                        <input type="date" name="data_nascimento" value="<?= $oldVal('data_nascimento') ?>"
                            class="<?= $inputClass ?> <?= $hasError('data_nascimento') ?>" <?= $isRequired('data_nascimento', true) ? 'required' : '' ?>>
                        <?php $errMsg('data_nascimento'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('rg', 'RG / Órgão Expedidor')) ?><?= $requiredMark('rg', true) ?></label>
                        <input type="text" name="rg" value="<?= $oldVal('rg') ?>"
                            placeholder="Ex: 12.345.678-9 SSP/SP"
                            class="<?= $inputClass ?> <?= $hasError('rg') ?>" <?= $isRequired('rg', true) ? 'required' : '' ?>>
                        <?php $errMsg('rg'); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('cpf', 'CPF')) ?><?= $requiredMark('cpf', true) ?></label>
                        <input type="text" name="cpf" value="<?= $oldVal('cpf') ?>"
                            placeholder="000.000.000-00" maxlength="14"
                            class="<?= $inputClass ?> <?= $hasError('cpf') ?>" id="cpf" <?= $isRequired('cpf', true) ? 'required' : '' ?>>
                        <?php $errMsg('cpf'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('cnpj', 'CNPJ (se houver)')) ?><?= $requiredMark('cnpj') ?></label>
                        <input type="text" name="cnpj" value="<?= $oldVal('cnpj') ?>"
                            placeholder="00.000.000/0000-00" maxlength="18"
                            class="<?= $inputClass ?> <?= $hasError('cnpj') ?>" id="cnpj">
                        <?php $errMsg('cnpj'); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('nome_empresa', 'Nome da Empresa')) ?><?= $requiredMark('nome_empresa') ?></label>
                        <input type="text" name="nome_empresa" value="<?= $oldVal('nome_empresa') ?>"
                            class="<?= $inputClass ?> border-gray-300">
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('emite_nota_fiscal', 'Emite Nota Fiscal')) ?><?= $requiredMark('emite_nota_fiscal', true) ?></label>
                        <select name="emite_nota_fiscal" class="<?= $inputClass ?> <?= $hasError('emite_nota_fiscal') ?>" <?= $isRequired('emite_nota_fiscal', true) ? 'required' : '' ?>>
                            <option value="">Selecione...</option>
                            <option value="sim" <?= ($old['emite_nota_fiscal'] ?? '') === 'sim' ? 'selected' : '' ?>>Sim</option>
                            <option value="nao" <?= ($old['emite_nota_fiscal'] ?? '') === 'nao' ? 'selected' : '' ?>>Não</option>
                        </select>
                        <?php $errMsg('emite_nota_fiscal'); ?>
                    </div>
                </div>

                <div>
                    <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('telefones', 'Telefones')) ?><?= $requiredMark('telefones', true) ?></label>
                    <input type="text" name="telefones" value="<?= $oldVal('telefones') ?>"
                        placeholder="(11) 99999-9999, (11) 98888-8888"
                        class="<?= $inputClass ?> <?= $hasError('telefones') ?>" <?= $isRequired('telefones', true) ? 'required' : '' ?>>
                    <?php $errMsg('telefones'); ?>
                </div>

                <div>
                    <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('emails', 'E-mails')) ?><?= $requiredMark('emails', true) ?></label>
                    <input type="text" name="emails" value="<?= $oldVal('emails') ?>"
                        placeholder="email@exemplo.com"
                        class="<?= $inputClass ?> <?= $hasError('emails') ?>" <?= $isRequired('emails', true) ? 'required' : '' ?>>
                    <?php $errMsg('emails'); ?>
                </div>

                <div>
                    <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('endereco', 'Endereço completo (Rua, n.º, bairro, cidade, estado, CEP)')) ?><?= $requiredMark('endereco', true) ?></label>
                    <textarea name="endereco" rows="2"
                        class="<?= $inputClass ?> <?= $hasError('endereco') ?>" <?= $isRequired('endereco', true) ? 'required' : '' ?>><?= $oldVal('endereco') ?></textarea>
                    <?php $errMsg('endereco'); ?>
                </div>
            </div>

            <!-- Equipamentos -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_equipment', 'Equipamentos que você possui')) ?><?= $requiredMark('equipamentos', true) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" data-required-group="equipamentos" data-required-label="<?= $escape($fieldLabel('equipamentos', 'Equipamentos')) ?>" <?= $isRequired('equipamentos', true) ? 'data-group-required="1"' : '' ?>>
                <?php foreach ($equipamentos as $eq): ?>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="equipamentos[]" value="<?= $escape($eq) ?>"
                        <?= in_array($eq, $oldEquip, true) ? 'checked' : '' ?>
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <?= $escape($eq) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php $errMsg('equipamentos'); ?>

            <!-- Deslocamento -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_transport', 'Forma de deslocamento')) ?><?= $requiredMark('deslocamento', true) ?></p>
            <div class="flex flex-wrap gap-4" data-required-group="deslocamento" data-required-label="<?= $escape($fieldLabel('deslocamento', 'Forma de deslocamento')) ?>" <?= $isRequired('deslocamento', true) ? 'data-group-required="1"' : '' ?>>
                <?php foreach ($deslocamentos as $d): ?>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="deslocamento[]" value="<?= $escape($d) ?>"
                        <?= in_array($d, $oldDeslo, true) ? 'checked' : '' ?>
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <?= $escape($d) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php $errMsg('deslocamento'); ?>

            <!-- Disponibilidade -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_availability', 'Disponibilidade de horário')) ?><?= $requiredMark('disponibilidade', true) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" data-required-group="disponibilidade" data-required-label="<?= $escape($fieldLabel('disponibilidade', 'Disponibilidade de horário')) ?>" <?= $isRequired('disponibilidade', true) ? 'data-group-required="1"' : '' ?>>
                <?php foreach ($diasSemana as $dia): ?>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="disponibilidade[]" value="<?= $escape($dia) ?>"
                        <?= in_array($dia, $oldDisp, true) ? 'checked' : '' ?>
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <?= $escape($dia) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php $errMsg('disponibilidade'); ?>

            <!-- Cidades -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_service_cities', 'Cidades de atendimento (até 100 km)')) ?><?= $requiredMark('cidades_atendimento', true) ?></p>
            <textarea name="cidades_atendimento" rows="3"
                placeholder="Ex: São Paulo, Guarulhos, Osasco..."
                class="<?= $inputClass ?> <?= $hasError('cidades_atendimento') ?>" <?= $isRequired('cidades_atendimento', true) ? 'required' : '' ?>><?= $oldVal('cidades_atendimento') ?></textarea>
            <?php $errMsg('cidades_atendimento'); ?>

            <!-- Dados Bancários -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_banking', 'Dados Bancários')) ?></p>
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('banco', 'Banco')) ?><?= $requiredMark('banco', true) ?></label>
                        <input type="text" name="banco" value="<?= $oldVal('banco') ?>"
                            class="<?= $inputClass ?> <?= $hasError('banco') ?>" <?= $isRequired('banco', true) ? 'required' : '' ?>>
                        <?php $errMsg('banco'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('agencia', 'Agência')) ?><?= $requiredMark('agencia', true) ?></label>
                        <input type="text" name="agencia" value="<?= $oldVal('agencia') ?>"
                            class="<?= $inputClass ?> <?= $hasError('agencia') ?>" <?= $isRequired('agencia', true) ? 'required' : '' ?>>
                        <?php $errMsg('agencia'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('conta', 'Conta')) ?><?= $requiredMark('conta', true) ?></label>
                        <input type="text" name="conta" value="<?= $oldVal('conta') ?>"
                            class="<?= $inputClass ?> <?= $hasError('conta') ?>" <?= $isRequired('conta', true) ? 'required' : '' ?>>
                        <?php $errMsg('conta'); ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('nome_favorecido', 'Nome do Favorecido')) ?><?= $requiredMark('nome_favorecido') ?></label>
                        <input type="text" name="nome_favorecido" value="<?= $oldVal('nome_favorecido') ?>"
                            class="<?= $inputClass ?> border-gray-300">
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('cpf_cnpj_favorecido', 'CPF/CNPJ do Favorecido')) ?><?= $requiredMark('cpf_cnpj_favorecido', true) ?></label>
                        <input type="text" name="cpf_cnpj_favorecido" value="<?= $oldVal('cpf_cnpj_favorecido') ?>"
                            class="<?= $inputClass ?> <?= $hasError('cpf_cnpj_favorecido') ?>" <?= $isRequired('cpf_cnpj_favorecido', true) ? 'required' : '' ?>>
                        <?php $errMsg('cpf_cnpj_favorecido'); ?>
                    </div>
                </div>
                <div>
                    <label class="<?= $labelClass ?>"><?= $escape($fieldLabel('pix', 'PIX')) ?><?= $requiredMark('pix', true) ?></label>
                    <input type="text" name="pix" value="<?= $oldVal('pix') ?>"
                        placeholder="Chave PIX (CPF, e-mail, telefone ou aleatória)"
                        class="<?= $inputClass ?> <?= $hasError('pix') ?>" <?= $isRequired('pix', true) ? 'required' : '' ?>>
                    <?php $errMsg('pix'); ?>
                </div>
            </div>

            <!-- Conhecimentos -->
            <p class="<?= $sectionHead ?>"><?= $escape($sectionLabel('section_skills', 'Conhecimentos / Área de Atuação')) ?><?= $requiredMark('conhecimentos', true) ?></p>
            <textarea name="conhecimentos" rows="4"
                placeholder="Descreva suas habilidades técnicas, certificações, experiências..."
                class="<?= $inputClass ?> <?= $hasError('conhecimentos') ?>" <?= $isRequired('conhecimentos', true) ? 'required' : '' ?>><?= $oldVal('conhecimentos') ?></textarea>
            <?php $errMsg('conhecimentos'); ?>

            <!-- Aviso -->
            <div class="mt-6 bg-amber-50 border border-amber-200 rounded-md p-3 text-sm text-amber-800">
                Não reembolsamos alimentação e estacionamentos.
            </div>

            <!-- Submit -->
            <div class="mt-6">
                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-md transition-colors text-sm">
                    Enviar Cadastro
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// CPF mask
document.getElementById('cpf').addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 11);
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    this.value = v;
});

// CNPJ mask
const cnpjEl = document.getElementById('cnpj');
if (cnpjEl) {
    cnpjEl.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 14);
        v = v.replace(/(\d{2})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1/$2');
        v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
        this.value = v;
    });
}

const fieldTechForm = document.getElementById('field-tech-form');

if (fieldTechForm) {
    fieldTechForm.addEventListener('submit', function (event) {
        const groups = Array.from(document.querySelectorAll('[data-group-required="1"]'));
        let hasGroupError = false;

        groups.forEach(function (group) {
            const checked = group.querySelectorAll('input[type="checkbox"]:checked').length;
            const label = group.getAttribute('data-required-label') || 'campo';

            const nextElement = group.nextElementSibling;
            const hasExistingError = nextElement && nextElement.classList.contains('js-group-error');

            if (hasExistingError) {
                nextElement.remove();
            }

            if (checked === 0) {
                hasGroupError = true;

                const errorNode = document.createElement('p');
                errorNode.className = 'text-red-600 text-xs mt-1 js-group-error';
                errorNode.textContent = 'O campo "' + label + '" é obrigatório.';
                group.insertAdjacentElement('afterend', errorNode);
            }
        });

        if (hasGroupError) {
            event.preventDefault();
        }
    });
}
</script>
</body>
</html>
