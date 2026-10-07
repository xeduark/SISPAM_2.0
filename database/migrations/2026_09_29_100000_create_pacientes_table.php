<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes, con los datos que devuelve el servicio Consulta Afiliados de
 * Savia Salud EPS. Los campos que se buscan, filtran u ordenan tienen columna
 * propia; el resto de la respuesta va en `datos_adicionales` para no perder
 * nada (incluidos los campos que Savia agregue y la V3 no documente).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('tipo_documento', 5);
            $table->string('numero_documento', 20);
            $table->string('primer_nombre', 60);
            $table->string('segundo_nombre', 60)->nullable();
            $table->string('primer_apellido', 60);
            $table->string('segundo_apellido', 60)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('sexo', 20)->nullable();
            $table->string('genero_identificacion', 40)->nullable();
            $table->string('estado_civil', 40)->nullable();

            // Condición de salud
            $table->string('discapacidad', 20)->nullable();
            $table->string('tipo_discapacidad', 60)->nullable();
            $table->string('victima_ley_1448', 20)->nullable();

            // Afiliación
            $table->string('estado_afiliacion', 40)->nullable();
            $table->string('regimen', 40)->nullable();
            $table->string('tipo_afiliado', 40)->nullable();
            $table->string('modalidad_subsidio', 40)->nullable();
            $table->string('causa_estado', 120)->nullable();
            $table->date('fecha_afiliacion_sgsss')->nullable();
            $table->date('fecha_afiliacion_entidad')->nullable();
            $table->date('fecha_suspension')->nullable();
            $table->date('fecha_retiro')->nullable();
            $table->string('consecutivo_bdua', 30)->nullable();
            $table->string('codigo_entidad', 20)->nullable();

            // Núcleo familiar
            $table->string('tipo_documento_cabeza_familia', 5)->nullable();
            $table->string('documento_cabeza_familia', 20)->nullable();
            $table->string('parentesco_cabeza_familia', 40)->nullable();

            // Clasificación socioeconómica
            $table->string('grupo_poblacional', 80)->nullable();
            $table->string('nivel_sisben', 20)->nullable();
            // El servicio devuelve el puntaje con formato, no como número.
            $table->string('puntaje_sisben', 20)->nullable();
            $table->string('grupo_sisben', 20)->nullable();

            // Residencia y contacto
            $table->string('direccion', 180)->nullable();
            $table->string('barrio', 80)->nullable();
            $table->string('comuna', 80)->nullable();
            $table->string('ciudad_residencia', 80)->nullable();
            $table->string('municipio_afiliacion', 80)->nullable();
            $table->string('departamento_afiliacion', 80)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('telefono_movil', 30)->nullable();
            $table->string('email', 120)->nullable();

            // IPS y portabilidad
            $table->string('codigo_ips', 20)->nullable();
            $table->string('ips_primaria', 120)->nullable();
            $table->string('sede_ips_primaria', 120)->nullable();
            $table->string('tipo_portabilidad', 40)->nullable();

            // Programas especiales y RIAS: lista de {tipo, descripcion}
            $table->json('programas')->nullable();

            // Los demás campos de la respuesta, sin duplicar las columnas de arriba
            $table->json('datos_adicionales')->nullable();

            $table->text('observacion')->nullable();

            // Control de la última consulta al servicio
            $table->string('codigo_respuesta_savia', 10)->nullable();
            $table->timestamp('consultado_en_savia_at')->nullable();

            $table->timestamps();

            // Un paciente por tipo y número de documento
            $table->unique(['tipo_documento', 'numero_documento']);

            $table->index('estado_afiliacion');
            $table->index('regimen');
            $table->index('primer_apellido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
