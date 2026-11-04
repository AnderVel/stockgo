<?php

return [

    'roles' => [

        'Operador de Almacén' => [
            'products.view',
            'dashboard.view',

            'inventory.view',
            'inventory.picking',
            'inventory.receive',

            'orders.view',
            'orders.surtir',
        ],

        'Administrador' => [
            'products.view',
            'dashboard.view',
            'products.create',
            'products.update',
            'products.delete',

            'inventory.view',
            'inventory.picking',
            'inventory.move',
            'inventory.adjust',
            'inventory.receive',

            'orders.view',
            'orders.create',
            'orders.update',
            'orders.asignar',
            'orders.surtir',
            'orders.entregar',
            'orders.receive',
            'orders.cancel',
            'orders.delete',

            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'suppliers.delete',

            'clients.view',
            'clients.create',
            'clients.update',
            'clients.delete',

            'users.view',
            'users.create',
            'users.update',
            'users.reset_2fa',
            'users.delete',

            'audit.view',
        ],

        'Administrador Regular' => [
            'products.view',
            'dashboard.view',

            'inventory.view',

            'orders.view',
            'orders.aceptar',

            'suppliers.view',

            'clients.view',

            'audit.view',
        ],

    ],

];
