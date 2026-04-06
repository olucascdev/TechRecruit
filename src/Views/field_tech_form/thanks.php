<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro Enviado – W13</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
<?php
if (empty($_SESSION['form_success'])) {
    header('Location: ' . \TechRecruit\Support\AppUrl::relative('/cadastro-tecnico'));
    exit;
}
unset($_SESSION['form_success']);
?>
<div class="max-w-md w-full bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
    <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
    </div>
    <h1 class="text-xl font-bold text-gray-900 mb-2">Cadastro enviado!</h1>
    <p class="text-sm text-gray-600">Recebemos suas informações. Em breve nossa equipe entrará em contato.</p>
    <p class="text-xs text-gray-400 mt-4">Equipe W13</p>
</div>
</body>
</html>
