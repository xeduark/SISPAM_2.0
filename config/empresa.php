<?php

// Datos de la empresa para documentos impresos (orden de entrega).
return [
    'nombre' => env('EMPRESA_NOMBRE', 'COMITÉ DE ESTUDIOS MÉDICOS'),
    'nit' => env('EMPRESA_NIT', '900294794-5'),
    'logo' => env('EMPRESA_LOGO', 'https://res.cloudinary.com/dbhbuhjum/image/upload/v1775485317/descarga_moi2yv.png'),
    // Todos los pacientes vienen de la consulta de afiliados de Savia.
    'eps' => env('EMPRESA_EPS', 'SAVIA SALUD EPS'),
];
