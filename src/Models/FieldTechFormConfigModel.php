<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;
use TechRecruit\Support\FieldTechFormSchema;

final class FieldTechFormConfigModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array{fields:array<string, array{label:string,required:bool,type:string}>,sections:array<string,string>}
     */
    public function getResolvedSchema(): array
    {
        $defaults = FieldTechFormSchema::fields();
        $sections = FieldTechFormSchema::sections();
        $this->ensureDefaults($defaults, $sections);

        $rows = $this->pdo->query(
            'SELECT config_key, config_kind, label, is_required
             FROM recruit_form_field_settings
             ORDER BY id ASC'
        )->fetchAll();

        foreach ($rows as $row) {
            $key = (string) ($row['config_key'] ?? '');
            $kind = (string) ($row['config_kind'] ?? '');

            if ($kind === 'field' && isset($defaults[$key])) {
                $defaults[$key]['label'] = trim((string) ($row['label'] ?? '')) ?: $defaults[$key]['label'];
                $defaults[$key]['required'] = ((int) ($row['is_required'] ?? 0)) === 1;
            }

            if ($kind === 'section' && isset($sections[$key])) {
                $sections[$key] = trim((string) ($row['label'] ?? '')) ?: $sections[$key];
            }
        }

        return [
            'fields' => $defaults,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, bool> $requiredByField
     */
    public function updateFields(array $labels, array $requiredByField, string $updatedBy): void
    {
        $schema = FieldTechFormSchema::fields();

        $statement = $this->pdo->prepare(
            'UPDATE recruit_form_field_settings
             SET label = :label,
                 is_required = :is_required,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE config_kind = :config_kind
               AND config_key = :config_key'
        );

        foreach ($schema as $fieldKey => $fieldMeta) {
            $label = trim((string) ($labels[$fieldKey] ?? ''));
            $isRequired = (bool) ($requiredByField[$fieldKey] ?? false);

            $statement->execute([
                'label' => $label !== '' ? $label : $fieldMeta['label'],
                'is_required' => $isRequired ? 1 : 0,
                'updated_by' => $updatedBy,
                'config_kind' => 'field',
                'config_key' => $fieldKey,
            ]);
        }
    }

    /**
     * @param array<string, string> $labels
     */
    public function updateSections(array $labels, string $updatedBy): void
    {
        $defaults = FieldTechFormSchema::sections();
        $statement = $this->pdo->prepare(
            'UPDATE recruit_form_field_settings
             SET label = :label,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE config_kind = :config_kind
               AND config_key = :config_key'
        );

        foreach ($defaults as $sectionKey => $defaultLabel) {
            $label = trim((string) ($labels[$sectionKey] ?? ''));

            $statement->execute([
                'label' => $label !== '' ? $label : $defaultLabel,
                'updated_by' => $updatedBy,
                'config_kind' => 'section',
                'config_key' => $sectionKey,
            ]);
        }
    }

    /**
     * @param array<string, array{label:string,required:bool,type:string}> $defaultFields
     * @param array<string, string> $defaultSections
     */
    private function ensureDefaults(array $defaultFields, array $defaultSections): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_form_field_settings (config_key, config_kind, label, is_required, updated_by)
             VALUES (:config_key, :config_kind, :label, :is_required, :updated_by)
             ON DUPLICATE KEY UPDATE
                label = recruit_form_field_settings.label,
                is_required = recruit_form_field_settings.is_required,
                updated_by = recruit_form_field_settings.updated_by'
        );

        foreach ($defaultFields as $fieldKey => $meta) {
            $statement->execute([
                'config_key' => $fieldKey,
                'config_kind' => 'field',
                'label' => $meta['label'],
                'is_required' => $meta['required'] ? 1 : 0,
                'updated_by' => 'system',
            ]);
        }

        foreach ($defaultSections as $sectionKey => $label) {
            $statement->execute([
                'config_key' => $sectionKey,
                'config_kind' => 'section',
                'label' => $label,
                'is_required' => 0,
                'updated_by' => 'system',
            ]);
        }
    }
}
