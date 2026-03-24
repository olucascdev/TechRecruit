<?php

declare(strict_types=1);

namespace TechRecruit\Support;

final class LabelTranslator
{
    /** @var array<string, string> */
    private const PT_BR_MAP = [
        'imported' => 'Importado',
        'queued' => 'Na fila',
        'message_sent' => 'Mensagem enviada',
        'responded' => 'Respondeu',
        'not_interested' => 'Nao interessado',
        'interested' => 'Interessado',
        'awaiting_docs' => 'Aguardando documentos',
        'docs_sent' => 'Documentos enviados',
        'under_review' => 'Em analise',
        'approved' => 'Aprovado',
        'rejected' => 'Reprovado',
        'awaiting_contract' => 'Aguardando contrato',
        'contract_signed' => 'Contrato assinado',
        'closed' => 'Encerrado',
        'draft' => 'Rascunho',
        'sending' => 'Enviando',
        'paused' => 'Pausado',
        'completed' => 'Concluido',
        'cancelled' => 'Cancelado',
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'failed' => 'Falhou',
        'skipped' => 'Ignorado',
        'opt_out' => 'Descadastro',
        'link_sent' => 'Link enviado',
        'in_progress' => 'Em andamento',
        'submitted' => 'Enviado',
        'correction_requested' => 'Correcao solicitada',
        'expired' => 'Expirado',
        'needs_details' => 'Precisa de detalhes',
        'awaiting_validation' => 'Aguardando validacao',
        'rejected_unavailable' => 'Reprovado indisponivel',
        'open' => 'Aberta',
        'resolved' => 'Resolvida',
        'approve' => 'Aprovar',
        'reject' => 'Reprovar',
        'request_correction' => 'Solicitar correcao',
        'document_approve' => 'Documento aprovado',
        'document_reject' => 'Documento reprovado',
        'document_request_correction' => 'Documento com correcao',
        'pendency_resolved' => 'Pendencia resolvida',
        'sent' => 'Enviado',
        'reply' => 'Resposta',
        'resumed' => 'Retomado',
        'inbound' => 'Entrada',
        'outbound' => 'Saida',
        'active' => 'Ativo',
        'inactive' => 'Inativo',
        'waiting_operator' => 'Aguardando operador',
        'admin' => 'Admin',
        'manager' => 'Gestao',
        'broadcast' => 'Disparo manual',
        'triage_w13' => 'Bot de triagem W13',
        'done' => 'Concluido',
        'skill' => 'Skill',
        'status' => 'Status',
        'state' => 'Estado',
        'search' => 'Busca',
        'basic' => 'Basico',
        'intermediate' => 'Intermediario',
        'advanced' => 'Avancado',
        'expert' => 'Especialista',
        'beginner' => 'Iniciante',
    ];

    private function __construct()
    {
    }

    public static function toPtBr(?string $value): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '' || $normalized === '-') {
            return '-';
        }

        $key = mb_strtolower($normalized, 'UTF-8');

        if (isset(self::PT_BR_MAP[$key])) {
            return self::PT_BR_MAP[$key];
        }

        $humanized = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $normalized));

        if (!is_string($humanized) || trim($humanized) === '') {
            return $normalized;
        }

        return mb_convert_case(trim($humanized), MB_CASE_TITLE, 'UTF-8');
    }
}
