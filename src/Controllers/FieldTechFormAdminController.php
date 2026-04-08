<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Models\FieldTechFormConfigModel;
use Throwable;

final class FieldTechFormAdminController extends Controller
{
    private FieldTechFormConfigModel $configModel;

    public function __construct(?FieldTechFormConfigModel $configModel = null)
    {
        $this->requireAdmin();
        $this->configModel = $configModel ?? new FieldTechFormConfigModel();
    }

    public function index(): void
    {
        $schema = $this->configModel->getResolvedSchema();

        $this->render('management/field_tech_form', [
            'fieldSchema' => $schema['fields'],
            'sectionLabels' => $schema['sections'],
        ], 'Formulário de Técnico');
    }

    public function update(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/management/forms/field-tech');
        }

        try {
            $labels = is_array($_POST['labels'] ?? null) ? $_POST['labels'] : [];
            $sections = is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [];
            $requiredRaw = is_array($_POST['required_fields'] ?? null) ? $_POST['required_fields'] : [];
            $requiredByField = [];

            foreach ($requiredRaw as $fieldName) {
                if (is_string($fieldName) && trim($fieldName) !== '') {
                    $requiredByField[$fieldName] = true;
                }
            }

            $operator = $this->resolveOperator();
            $this->configModel->getResolvedSchema();
            $this->configModel->updateSections($sections, $operator);
            $this->configModel->updateFields($labels, $requiredByField, $operator);

            $this->setFlash('success', 'Configuração do formulário atualizada com sucesso.');
        } catch (Throwable $exception) {
            error_log('[FieldTechFormAdminController] ' . $exception->getMessage());
            $this->setFlash('error', 'Não foi possível atualizar a configuração do formulário.');
        }

        $this->redirect('/management/forms/field-tech');
    }
}
