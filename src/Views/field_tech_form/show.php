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

        <form method="POST" action="<?= \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico') ?>" novalidate>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">

            <!-- Dados Pessoais -->
            <p class="<?= $sectionHead ?>">Dados Pessoais</p>

            <div class="space-y-4">
                <div>
                    <label class="<?= $labelClass ?>">Nome completo <span class="text-red-500">*</span></label>
                    <input type="text" name="nome_completo" value="<?= $oldVal('nome_completo') ?>"
                        class="<?= $inputClass ?> <?= $hasError('nome_completo') ?>" autocomplete="name">
                    <?php $errMsg('nome_completo'); ?>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>">Data de nascimento <span class="text-red-500">*</span></label>
                        <input type="date" name="data_nascimento" value="<?= $oldVal('data_nascimento') ?>"
                            class="<?= $inputClass ?> <?= $hasError('data_nascimento') ?>">
                        <?php $errMsg('data_nascimento'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">RG / Órgão Expedidor <span class="text-red-500">*</span></label>
                        <input type="text" name="rg" value="<?= $oldVal('rg') ?>"
                            placeholder="Ex: 12.345.678-9 SSP/SP"
                            class="<?= $inputClass ?> <?= $hasError('rg') ?>">
                        <?php $errMsg('rg'); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>">CPF <span class="text-red-500">*</span></label>
                        <input type="text" name="cpf" value="<?= $oldVal('cpf') ?>"
                            placeholder="000.000.000-00" maxlength="14"
                            class="<?= $inputClass ?> <?= $hasError('cpf') ?>" id="cpf">
                        <?php $errMsg('cpf'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">CNPJ (se houver)</label>
                        <input type="text" name="cnpj" value="<?= $oldVal('cnpj') ?>"
                            placeholder="00.000.000/0000-00" maxlength="18"
                            class="<?= $inputClass ?> <?= $hasError('cnpj') ?>" id="cnpj">
                        <?php $errMsg('cnpj'); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>">Nome da Empresa</label>
                        <input type="text" name="nome_empresa" value="<?= $oldVal('nome_empresa') ?>"
                            class="<?= $inputClass ?> border-gray-300">
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">Emite Nota Fiscal <span class="text-red-500">*</span></label>
                        <select name="emite_nota_fiscal" class="<?= $inputClass ?> <?= $hasError('emite_nota_fiscal') ?>">
                            <option value="">Selecione...</option>
                            <option value="sim" <?= ($old['emite_nota_fiscal'] ?? '') === 'sim' ? 'selected' : '' ?>>Sim</option>
                            <option value="nao" <?= ($old['emite_nota_fiscal'] ?? '') === 'nao' ? 'selected' : '' ?>>Não</option>
                        </select>
                        <?php $errMsg('emite_nota_fiscal'); ?>
                    </div>
                </div>

                <div>
                    <label class="<?= $labelClass ?>">Telefones <span class="text-red-500">*</span></label>
                    <input type="text" name="telefones" value="<?= $oldVal('telefones') ?>"
                        placeholder="(11) 99999-9999, (11) 98888-8888"
                        class="<?= $inputClass ?> <?= $hasError('telefones') ?>">
                    <?php $errMsg('telefones'); ?>
                </div>

                <div>
                    <label class="<?= $labelClass ?>">E-mails <span class="text-red-500">*</span></label>
                    <input type="text" name="emails" value="<?= $oldVal('emails') ?>"
                        placeholder="email@exemplo.com"
                        class="<?= $inputClass ?> <?= $hasError('emails') ?>">
                    <?php $errMsg('emails'); ?>
                </div>

                <div>
                    <label class="<?= $labelClass ?>">Endereço completo (Rua, n.º, bairro, cidade, estado, CEP) <span class="text-red-500">*</span></label>
                    <textarea name="endereco" rows="2"
                        class="<?= $inputClass ?> <?= $hasError('endereco') ?>"><?= $oldVal('endereco') ?></textarea>
                    <?php $errMsg('endereco'); ?>
                </div>
            </div>

            <!-- Equipamentos -->
            <p class="<?= $sectionHead ?>">Equipamentos que você possui <span class="text-red-500">*</span></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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
            <p class="<?= $sectionHead ?>">Forma de deslocamento <span class="text-red-500">*</span></p>
            <div class="flex flex-wrap gap-4">
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
            <p class="<?= $sectionHead ?>">Disponibilidade de horário <span class="text-red-500">*</span></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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
            <p class="<?= $sectionHead ?>">Cidades de atendimento (até 100 km) <span class="text-red-500">*</span></p>
            <textarea name="cidades_atendimento" rows="3"
                placeholder="Ex: São Paulo, Guarulhos, Osasco..."
                class="<?= $inputClass ?> <?= $hasError('cidades_atendimento') ?>"><?= $oldVal('cidades_atendimento') ?></textarea>
            <?php $errMsg('cidades_atendimento'); ?>

            <!-- Dados Bancários -->
            <p class="<?= $sectionHead ?>">Dados Bancários</p>
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>">Banco <span class="text-red-500">*</span></label>
                        <input type="text" name="banco" value="<?= $oldVal('banco') ?>"
                            class="<?= $inputClass ?> <?= $hasError('banco') ?>">
                        <?php $errMsg('banco'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">Agência <span class="text-red-500">*</span></label>
                        <input type="text" name="agencia" value="<?= $oldVal('agencia') ?>"
                            class="<?= $inputClass ?> <?= $hasError('agencia') ?>">
                        <?php $errMsg('agencia'); ?>
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">Conta <span class="text-red-500">*</span></label>
                        <input type="text" name="conta" value="<?= $oldVal('conta') ?>"
                            class="<?= $inputClass ?> <?= $hasError('conta') ?>">
                        <?php $errMsg('conta'); ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="<?= $labelClass ?>">Nome do Favorecido</label>
                        <input type="text" name="nome_favorecido" value="<?= $oldVal('nome_favorecido') ?>"
                            class="<?= $inputClass ?> border-gray-300">
                    </div>
                    <div>
                        <label class="<?= $labelClass ?>">CPF/CNPJ do Favorecido <span class="text-red-500">*</span></label>
                        <input type="text" name="cpf_cnpj_favorecido" value="<?= $oldVal('cpf_cnpj_favorecido') ?>"
                            class="<?= $inputClass ?> <?= $hasError('cpf_cnpj_favorecido') ?>">
                        <?php $errMsg('cpf_cnpj_favorecido'); ?>
                    </div>
                </div>
                <div>
                    <label class="<?= $labelClass ?>">PIX <span class="text-red-500">*</span></label>
                    <input type="text" name="pix" value="<?= $oldVal('pix') ?>"
                        placeholder="Chave PIX (CPF, e-mail, telefone ou aleatória)"
                        class="<?= $inputClass ?> <?= $hasError('pix') ?>">
                    <?php $errMsg('pix'); ?>
                </div>
            </div>

            <!-- Conhecimentos -->
            <p class="<?= $sectionHead ?>">Conhecimentos / Área de Atuação <span class="text-red-500">*</span></p>
            <textarea name="conhecimentos" rows="4"
                placeholder="Descreva suas habilidades técnicas, certificações, experiências..."
                class="<?= $inputClass ?> <?= $hasError('conhecimentos') ?>"><?= $oldVal('conhecimentos') ?></textarea>
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
</script>
</body>
</html>
