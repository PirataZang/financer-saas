<?php

/*
| Source of truth for every permission in the system.
| Key format: "{module}.{action}". After editing by hand, run `php artisan permission sync`;
| `php artisan permission create|update|delete` edits this file and the database together.
*/

return [
    'dashboard' => [
        'name' => 'Dashboard',
        'actions' => [
            'view' => 'Visualizar o dashboard',
        ],
    ],
    'users' => [
        'name' => 'Usuários',
        'actions' => [
            'view' => 'Visualizar usuários',
            'create' => 'Cadastrar usuários',
            'update' => 'Editar usuários',
            'delete' => 'Excluir usuários',
            'permissions' => 'Gerenciar permissões de usuários',
        ],
    ],
    'customers' => [
        'name' => 'Clientes',
        'actions' => [
            'view' => 'Visualizar clientes',
            'create' => 'Cadastrar clientes',
            'update' => 'Editar clientes',
            'delete' => 'Excluir clientes',
        ],
    ],
    'projects' => [
        'name' => 'Projetos',
        'actions' => [
            'view' => 'Visualizar projetos de clientes',
            'create' => 'Cadastrar projetos de clientes',
            'update' => 'Editar projetos de clientes (dados e etapas)',
            'delete' => 'Excluir projetos de clientes',
        ],
    ],
    'announcements' => [
        'name' => 'Avisos do portal',
        'actions' => [
            'view' => 'Visualizar avisos do portal do cliente',
            'create' => 'Publicar avisos no portal do cliente',
            'update' => 'Editar avisos do portal do cliente',
            'delete' => 'Excluir avisos do portal do cliente',
        ],
    ],
    'finance' => [
        'name' => 'Financeiro',
        'actions' => [
            'dashboard' => 'Visualizar o dashboard financeiro',
            'export' => 'Exportar dados financeiros (Excel)',
            'category.view' => 'Visualizar categorias financeiras',
            'category.create' => 'Cadastrar categorias financeiras',
            'category.update' => 'Editar categorias financeiras',
            'category.delete' => 'Excluir categorias financeiras',
            'transaction.view' => 'Visualizar transações',
            'transaction.create' => 'Cadastrar transações',
            'transaction.update' => 'Editar transações',
            'transaction.delete' => 'Excluir transações',
            'goal.view' => 'Visualizar metas',
            'goal.create' => 'Cadastrar metas',
            'goal.update' => 'Editar metas',
            'goal.delete' => 'Excluir metas',
        ],
    ],
    'documents' => [
        'name' => 'Documentos',
        'actions' => [
            'view' => 'Visualizar templates de documento',
            'create' => 'Criar templates de documento',
            'update' => 'Editar templates de documento',
            'delete' => 'Excluir templates de documento',
            'emit' => 'Emitir documentos em PDF',
        ],
    ],
    'imports' => [
        'name' => 'Importação',
        'actions' => [
            'view' => 'Visualizar importações e seus erros',
            'create' => 'Importar dados por planilha (cria e atualiza registros)',
        ],
    ],
    'settings' => [
        'name' => 'Configurações',
        'actions' => [
            'company' => 'Editar os dados da empresa',
            'appearance' => 'Personalizar a aparência do sistema',
            'finance' => 'Alterar os padrões do financeiro',
            'telegram' => 'Gerenciar o bot do Telegram (código de vínculo e contas)',
            'portal' => 'Configurar o portal do cliente (link e dados de pagamento)',
            'integrations' => 'Gerenciar integrações (conectar, sincronizar e remover o Mercado Pago)',
        ],
    ],
];
