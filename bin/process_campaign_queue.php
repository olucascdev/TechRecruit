#!/usr/bin/env php
<?php

declare(strict_types=1);

use TechRecruit\Services\CampaignService;

require dirname(__DIR__) . '/bootstrap.php';

function stderr(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

/**
 * @return array<string, string|false>
 */
function parseOptions(): array
{
    $options = getopt('', [
        'limit::',
        'campaign-id::',
        'operator::',
        'json',
        'help',
    ]);

    return is_array($options) ? $options : [];
}

function printHelp(): void
{
    echo <<<TEXT
Processador de fila do TechRecruit

Uso:
  php bin/process_campaign_queue.php [--limit=25] [--campaign-id=123] [--operator=cron] [--json]

Opcoes:
  --limit         Quantidade maxima de itens a processar no ciclo.
  --campaign-id   Processa apenas uma campanha específica.
  --operator      Identificador salvo no histórico. Padrão: cron.
  --json          Imprime o resultado em JSON.
  --help          Exibe esta ajuda.

TEXT;
}

$options = parseOptions();

if (array_key_exists('help', $options)) {
    printHelp();
    exit(0);
}

$limit = null;

if (isset($options['limit']) && $options['limit'] !== false) {
    $limit = max(1, min(500, (int) $options['limit']));
}

$campaignId = null;

if (isset($options['campaign-id']) && $options['campaign-id'] !== false) {
    $campaignId = max(1, (int) $options['campaign-id']);
}

$operator = isset($options['operator']) && $options['operator'] !== false && trim((string) $options['operator']) !== ''
    ? trim((string) $options['operator'])
    : 'cron';

$service = new CampaignService();

try {
    $result = $campaignId !== null
        ? $service->processCampaign($campaignId, $operator, $limit)
        : $service->processDueQueue($operator, $limit);

    $payload = array_merge([
        'success' => true,
        'scope' => $campaignId !== null ? 'campaign' : 'global',
        'campaign_id' => $campaignId,
        'limit' => $limit,
        'operator' => $operator,
        'processed_at' => date(DATE_ATOM),
    ], $result);

    if (array_key_exists('json', $options)) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($campaignId !== null) {
        echo sprintf(
            '[%s] Campanha %d: %d item(ns), %d enviado(s), %d falha(s), %d opt-out. Status: %s%s',
            date('Y-m-d H:i:s'),
            $campaignId,
            (int) ($payload['processed'] ?? 0),
            (int) ($payload['sent'] ?? 0),
            (int) ($payload['failed'] ?? 0),
            (int) ($payload['opt_out'] ?? 0),
            (string) ($payload['status'] ?? '-'),
            PHP_EOL
        );
        exit(0);
    }

    echo sprintf(
        '[%s] Fila global: %d campanha(s), %d item(ns), %d enviado(s), %d falha(s), %d opt-out.%s',
        date('Y-m-d H:i:s'),
        (int) ($payload['campaigns'] ?? 0),
        (int) ($payload['processed'] ?? 0),
        (int) ($payload['sent'] ?? 0),
        (int) ($payload['failed'] ?? 0),
        (int) ($payload['opt_out'] ?? 0),
        PHP_EOL
    );
    exit(0);
} catch (Throwable $exception) {
    $message = trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao processar a fila de campanhas.';

    if (array_key_exists('json', $options)) {
        echo json_encode([
            'success' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }

    stderr($message);
    exit(1);
}
