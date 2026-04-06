<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

final class FieldTechFormController extends Controller
{
    public function show(): void
    {
        $currentPath = '/cadastro-tecnico';
        require __DIR__ . '/../Views/field_tech_form/show.php';
    }

    public function store(): void
    {
        $errors = [];
        $data = $_POST;

        $required = [
            'nome_completo'       => 'Nome completo',
            'data_nascimento'     => 'Data de nascimento',
            'rg'                  => 'RG / Órgão Expedidor',
            'cpf'                 => 'CPF',
            'emite_nota_fiscal'   => 'Emite Nota Fiscal',
            'telefones'           => 'Telefones',
            'emails'              => 'E-mails',
            'endereco'            => 'Endereço completo',
            'equipamentos'        => 'Equipamentos',
            'deslocamento'        => 'Forma de deslocamento',
            'disponibilidade'     => 'Disponibilidade de horário',
            'cidades_atendimento' => 'Cidades de atendimento',
            'banco'               => 'Banco',
            'agencia'             => 'Agência',
            'conta'               => 'Conta',
            'cpf_cnpj_favorecido' => 'CPF/CNPJ do favorecido',
            'pix'                 => 'PIX',
            'conhecimentos'       => 'Conhecimentos / área de atuação',
        ];

        foreach ($required as $field => $label) {
            $value = $data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && count($value) === 0)) {
                $errors[$field] = "O campo \"{$label}\" é obrigatório.";
            }
        }

        // CPF format
        if (!isset($errors['cpf'])) {
            $cpf = preg_replace('/\D/', '', (string) ($data['cpf'] ?? ''));
            if (strlen($cpf) !== 11) {
                $errors['cpf'] = 'CPF inválido. Informe 11 dígitos.';
            }
        }

        // CNPJ format (optional)
        if (!empty($data['cnpj'])) {
            $cnpj = preg_replace('/\D/', '', (string) $data['cnpj']);
            if (strlen($cnpj) !== 14) {
                $errors['cnpj'] = 'CNPJ inválido. Informe 14 dígitos.';
            }
        }

        // Email validation
        if (!isset($errors['emails'])) {
            $emailsRaw = trim((string) ($data['emails'] ?? ''));
            $emailList = array_filter(array_map('trim', preg_split('/[\n,;]+/', $emailsRaw) ?: []));
            foreach ($emailList as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['emails'] = "E-mail inválido: {$email}";
                    break;
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data']   = $data;
            header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico'));
            exit;
        }

        // Success
        $_SESSION['form_success'] = true;
        header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico/obrigado'));
        exit;
    }

    public function thanks(): void
    {
        $currentPath = '/cadastro-tecnico';
        require __DIR__ . '/../Views/field_tech_form/thanks.php';
    }
}
