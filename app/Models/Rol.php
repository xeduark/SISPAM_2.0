<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la matriz de permisos. El nombre es el del grupo en Authentik.
 */
class Rol extends Model
{
    protected $table = 'roles';

    /**
     * Módulos del sistema y las acciones que se pueden permitir en cada uno.
     * Un módulo nuevo se agrega aquí y aparece solo en la matriz.
     */
    public const MODULOS = [
        'pacientes' => [
            'nombre' => 'Pacientes',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
        'consultar_paciente' => [
            'nombre' => 'Consultar paciente',
            'acciones' => ['ver' => 'Ver'],
        ],
        'orientacion' => [
            'nombre' => 'Orientación (orden médica y alto costo)',
            'acciones' => ['usar' => 'Usar'],
        ],
        'sedes' => [
            'nombre' => 'Sedes',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
        'usuarios' => [
            'nombre' => 'Usuarios',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'permisos',
    ];

    protected function casts(): array
    {
        return [
            'permisos' => 'array',
        ];
    }
}
