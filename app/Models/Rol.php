<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la matriz de permisos. El nombre es el del grupo en Authentik.
 */
class Rol extends Model
{
    use Auditable;

    /** Cambiar permisos es de lo más delicado del sistema: queda todo registrado. */
    public const CAMPOS_AUDITADOS = ['nombre', 'permisos'];

    public const ETIQUETA_AUDITORIA = 'rol';

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
            'acciones' => [
                'usar' => 'Usar',
                // Abrir la orden médica ya cargada: es un dato de salud, así que
                // se concede aparte de poder cargarla.
                'ver_orden' => 'Ver la orden médica',
            ],
        ],
        'sedes' => [
            'nombre' => 'Sedes',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
        'usuarios' => [
            'nombre' => 'Usuarios',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
        'tickets' => [
            'nombre' => 'Tickets',
            'acciones' => [
                'ver' => 'Ver',
                'alistar' => 'Alistar (capturar medicamentos)',
                'anular' => 'Anular',
            ],
        ],
        'turnos' => [
            'nombre' => 'Llamado de turnos',
            'acciones' => [
                'ver' => 'Ver la sala',
                'llamar' => 'Llamar turnos',
                // Decir que el paciente no apareció saca su turno de la
                // espera, así que se concede aparte de poder llamar.
                'ausente' => 'Marcar que no se presentó',
            ],
        ],
        'colas' => [
            'nombre' => 'Colas y ventanillas',
            'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'],
        ],
        'auditoria' => [
            'nombre' => 'Auditoría',
            // Solo se consulta: una auditoría no se crea, ni se edita, ni se borra.
            'acciones' => ['ver' => 'Ver'],
        ],
        'entrega' => [
            'nombre' => 'Entrega',
            'acciones' => [
                'ver' => 'Ver',
                'atender' => 'Atender',
                'domicilio' => 'Gestionar domicilio',
                'reportes' => 'Reportes',
            ],
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

    public function descripcionParaAuditoria(): string
    {
        return "el rol «{$this->nombre}»";
    }
}
