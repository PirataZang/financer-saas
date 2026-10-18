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
        ],
    ],
    'settings' => [
        'name' => 'Configurações',
        'actions' => [
            'company' => 'Editar os dados da empresa',
            'appearance' => 'Personalizar a aparência do sistema',
            'finance' => 'Alterar os padrões do financeiro',
            'telegram' => 'Gerenciar o bot do Telegram (código de vínculo e contas)',
        ],
    ],
];
