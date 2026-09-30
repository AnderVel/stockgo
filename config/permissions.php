<?php

return [

    'roles' => [

        'Operador de Almacén' => [
            'products.view',

            'inventory.view',
            'inventory.picking',

            'orders.view',
            'orders.surtir',
        ],

        'Administrador' => [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            'inventory.view',
            'inventory.picking',
            'inventory.move',
            'inventory.adjust',

            'orders.view',
            'orders.create',
            'orders.update',
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
        ],

    ],

];
