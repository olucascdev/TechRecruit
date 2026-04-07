<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use PDO;
use TechRecruit\Database;

final class FieldTechFormController extends Controller
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function show(): void
    {
        $currentPath = '/cadastro-tecnico';
        require __DIR__ . '/../Views/field_tech_form/show.php';
    }

    public function store(): void
    {
        $errors = [];
        $data   = $_POST;

        // --- Validação ---
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
            $empty = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && count($value) === 0);

            if ($empty) {
                $errors[$field] = "O campo \"{$label}\" é obrigatório.";
            }
        }

        $cpfClean = preg_replace('/\D/', '', (string) ($data['cpf'] ?? ''));
        if (!isset($errors['cpf']) && strlen($cpfClean) !== 11) {
            $errors['cpf'] = 'CPF inválido. Informe 11 dígitos.';
        }

        $cnpjClean = '';
        if (!empty($data['cnpj'])) {
            $cnpjClean = preg_replace('/\D/', '', (string) $data['cnpj']);
            if (strlen($cnpjClean) !== 14) {
                $errors['cnpj'] = 'CNPJ inválido. Informe 14 dígitos.';
            }
        }

        if (!isset($errors['emails'])) {
            $emailList = array_filter(array_map('trim', preg_split('/[\n,;]+/', (string) ($data['emails'] ?? '')) ?: []));
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

        // --- Persistência ---
        $this->pdo->beginTransaction();

        try {
            // Verifica CPF duplicado
            $checkStmt = $this->pdo->prepare(
                'SELECT id FROM recruit_candidates WHERE cpf = :cpf LIMIT 1'
            );
            $checkStmt->execute(['cpf' => $cpfClean]);
            $existing = $checkStmt->fetchColumn();

            if ($existing !== false) {
                $_SESSION['form_errors'] = ['cpf' => 'Este CPF já está cadastrado.'];
                $_SESSION['form_data']   = $data;
                $this->pdo->rollBack();
                header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico'));
                exit;
            }

            // Insere candidato
            $stmt = $this->pdo->prepare(
                'INSERT INTO recruit_candidates (
                    full_name, cpf, status, source,
                    birth_date, rg, cnpj, company_name, issues_invoice,
                    full_address, equipment_list, transport_modes,
                    availability_days, service_cities,
                    bank_name, bank_agency, bank_account,
                    bank_holder_name, bank_holder_doc, pix_key,
                    notes
                ) VALUES (
                    :full_name, :cpf, :status, :source,
                    :birth_date, :rg, :cnpj, :company_name, :issues_invoice,
                    :full_address, :equipment_list, :transport_modes,
                    :availability_days, :service_cities,
                    :bank_name, :bank_agency, :bank_account,
                    :bank_holder_name, :bank_holder_doc, :pix_key,
                    :notes
                )'
            );

            $equipamentos  = implode(', ', (array) ($data['equipamentos']   ?? []));
            $deslocamento  = implode(', ', (array) ($data['deslocamento']   ?? []));
            $disponibilidade = implode(', ', (array) ($data['disponibilidade'] ?? []));

            $stmt->execute([
                'full_name'        => trim((string) $data['nome_completo']),
                'cpf'              => $cpfClean,
                'status'           => 'interested',
                'source'           => 'field_tech_form',
                'birth_date'       => trim((string) ($data['data_nascimento'] ?? '')),
                'rg'               => trim((string) ($data['rg'] ?? '')),
                'cnpj'             => $cnpjClean !== '' ? $cnpjClean : null,
                'company_name'     => trim((string) ($data['nome_empresa'] ?? '')) ?: null,
                'issues_invoice'   => ($data['emite_nota_fiscal'] ?? '') === 'sim' ? 1 : 0,
                'full_address'     => trim((string) ($data['endereco'] ?? '')),
                'equipment_list'   => $equipamentos,
                'transport_modes'  => $deslocamento,
                'availability_days'=> $disponibilidade,
                'service_cities'   => trim((string) ($data['cidades_atendimento'] ?? '')),
                'bank_name'        => trim((string) ($data['banco'] ?? '')),
                'bank_agency'      => trim((string) ($data['agencia'] ?? '')),
                'bank_account'     => trim((string) ($data['conta'] ?? '')),
                'bank_holder_name' => trim((string) ($data['nome_favorecido'] ?? '')) ?: null,
                'bank_holder_doc'  => preg_replace('/\D/', '', (string) ($data['cpf_cnpj_favorecido'] ?? '')),
                'pix_key'          => trim((string) ($data['pix'] ?? '')),
                'notes'            => trim((string) ($data['conhecimentos'] ?? '')),
            ]);

            $candidateId = (int) $this->pdo->lastInsertId();

            // Contatos: telefones
            $phones = array_filter(array_map('trim', preg_split('/[\n,;]+/', (string) ($data['telefones'] ?? '')) ?: []));
            $firstPhone = true;
            foreach ($phones as $phone) {
                $this->insertContact($candidateId, 'phone', $phone, $firstPhone);
                $firstPhone = false;
            }

            // Contatos: e-mails
            $emails = array_filter(array_map('trim', preg_split('/[\n,;]+/', (string) ($data['emails'] ?? '')) ?: []));
            $firstEmail = true;
            foreach ($emails as $email) {
                $this->insertContact($candidateId, 'email', $email, $firstEmail);
                $firstEmail = false;
            }

            // Skills: conhecimentos (cada linha vira uma skill)
            $skills = array_filter(array_map('trim', explode("\n", (string) ($data['conhecimentos'] ?? ''))));
            foreach ($skills as $skill) {
                if ($skill !== '') {
                    $this->insertSkill($candidateId, $skill);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[FieldTechForm] ' . $e->getMessage());
            $_SESSION['form_errors'] = ['_global' => 'Erro ao salvar o cadastro. Tente novamente.'];
            $_SESSION['form_data']   = $data;
            header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico'));
            exit;
        }

        $_SESSION['form_success'] = true;
        header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico/obrigado'));
        exit;
    }

    public function thanks(): void
    {
        $currentPath = '/cadastro-tecnico';
        require __DIR__ . '/../Views/field_tech_form/thanks.php';
    }

    private function insertContact(int $candidateId, string $type, string $value, bool $isPrimary): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_contacts (candidate_id, type, value, is_primary)
             VALUES (:candidate_id, :type, :value, :is_primary)'
        );
        $stmt->execute([
            'candidate_id' => $candidateId,
            'type'         => $type,
            'value'        => $value,
            'is_primary'   => $isPrimary ? 1 : 0,
        ]);
    }

    private function insertSkill(int $candidateId, string $skill): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_skills (candidate_id, skill, level)
             VALUES (:candidate_id, :skill, :level)'
        );
        $stmt->execute([
            'candidate_id' => $candidateId,
            'skill'        => mb_strtoupper(trim($skill)),
            'level'        => 'nao_informado',
        ]);
    }
}
