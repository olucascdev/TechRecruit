<?php

declare(strict_types=1);

namespace TechRecruit\Support;

final class FieldTechFormSchema
{
    /**
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return [
            'section_personal' => 'Dados Pessoais',
            'section_equipment' => 'Equipamentos que você possui',
            'section_transport' => 'Forma de deslocamento',
            'section_availability' => 'Disponibilidade de horário',
            'section_service_cities' => 'Cidades de atendimento (até 100 km)',
            'section_banking' => 'Dados Bancários',
            'section_skills' => 'Conhecimentos / Área de Atuação',
        ];
    }

    /**
     * @return array<string, array{label:string,required:bool,type:string}>
     */
    public static function fields(): array
    {
        return [
            'nome_completo' => ['label' => 'Nome completo', 'required' => true, 'type' => 'text'],
            'data_nascimento' => ['label' => 'Data de nascimento', 'required' => true, 'type' => 'date'],
            'rg' => ['label' => 'RG / Órgão Expedidor', 'required' => true, 'type' => 'text'],
            'cpf' => ['label' => 'CPF', 'required' => true, 'type' => 'text'],
            'cnpj' => ['label' => 'CNPJ (se houver)', 'required' => false, 'type' => 'text'],
            'nome_empresa' => ['label' => 'Nome da Empresa', 'required' => false, 'type' => 'text'],
            'emite_nota_fiscal' => ['label' => 'Emite Nota Fiscal', 'required' => true, 'type' => 'select'],
            'telefones' => ['label' => 'Telefones', 'required' => true, 'type' => 'text'],
            'emails' => ['label' => 'E-mails', 'required' => true, 'type' => 'text'],
            'endereco' => ['label' => 'Endereço completo (Rua, n.º, bairro, cidade, estado, CEP)', 'required' => true, 'type' => 'textarea'],
            'equipamentos' => ['label' => 'Equipamentos', 'required' => true, 'type' => 'checkbox_group'],
            'deslocamento' => ['label' => 'Forma de deslocamento', 'required' => true, 'type' => 'checkbox_group'],
            'disponibilidade' => ['label' => 'Disponibilidade de horário', 'required' => true, 'type' => 'checkbox_group'],
            'cidades_atendimento' => ['label' => 'Cidades de atendimento', 'required' => true, 'type' => 'textarea'],
            'banco' => ['label' => 'Banco', 'required' => true, 'type' => 'text'],
            'agencia' => ['label' => 'Agência', 'required' => true, 'type' => 'text'],
            'conta' => ['label' => 'Conta', 'required' => true, 'type' => 'text'],
            'nome_favorecido' => ['label' => 'Nome do Favorecido', 'required' => false, 'type' => 'text'],
            'cpf_cnpj_favorecido' => ['label' => 'CPF/CNPJ do Favorecido', 'required' => true, 'type' => 'text'],
            'pix' => ['label' => 'PIX', 'required' => true, 'type' => 'text'],
            'conhecimentos' => ['label' => 'Conhecimentos / área de atuação', 'required' => true, 'type' => 'textarea'],
        ];
    }
}
