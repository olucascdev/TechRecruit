<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TechRecruit\Support\LabelTranslator;

final class CandidateExportService
{
    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    public function download(string $format, array $candidates, string $fileNameBase): never
    {
        $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fileNameBase) ?: 'relatorio_candidatos';

        if ($format === 'csv') {
            $this->downloadCsv($candidates, $safeBase . '.csv');
        }

        $this->downloadXlsx($candidates, $safeBase . '.xlsx');
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function downloadCsv(array $candidates, string $fileName): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $output = fopen('php://output', 'wb');

        if ($output !== false) {
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->headerRow(), ';');

            foreach ($candidates as $candidate) {
                fputcsv($output, $this->normalizeRow($candidate), ';');
            }

            fclose($output);
        }

        exit;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function downloadXlsx(array $candidates, string $fileName): never
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Candidatos');

        $sheet->fromArray($this->headerRow(), null, 'A1');

        $line = 2;
        foreach ($candidates as $candidate) {
            $sheet->fromArray($this->normalizeRow($candidate), null, 'A' . $line);
            $line++;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

        exit;
    }

    /**
     * @return list<string>
     */
    private function headerRow(): array
    {
        return [
            'ID',
            'Nome',
            'CPF',
            'Status',
            'Telefone',
            'WhatsApp',
            'E-mail',
            'Habilidades',
            'Estado',
            'Cidade',
            'Criado em',
            'Atualizado em',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function normalizeRow(array $candidate): array
    {
        return [
            (string) ($candidate['id'] ?? ''),
            (string) ($candidate['full_name'] ?? ''),
            (string) ($candidate['cpf'] ?? ''),
            LabelTranslator::toPtBr((string) ($candidate['status'] ?? '')),
            (string) ($candidate['phone'] ?? ''),
            (string) ($candidate['whatsapp'] ?? ''),
            (string) ($candidate['email'] ?? ''),
            (string) ($candidate['skills'] ?? ''),
            (string) ($candidate['state'] ?? ''),
            (string) ($candidate['city'] ?? ''),
            (string) ($candidate['created_at'] ?? ''),
            (string) ($candidate['updated_at'] ?? ''),
        ];
    }
}
