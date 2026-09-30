<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El teléfono y la dirección dejan de ser datos de Savia y pasan a ser datos
 * propios de SISPAM: el personal los confirma con el paciente cada vez, porque
 * cuando el medicamento no está en la sede hay que enviarlo a domicilio.
 *
 * Se guarda quién confirmó y cuándo, para poder responder por esa entrega.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            // Conjunto, torre, punto de referencia: lo que hace falta para llegar.
            $table->string('indicaciones_entrega', 255)->nullable()->after('email');

            $table->timestamp('contacto_confirmado_at')->nullable()->after('consultado_en_savia_at');
            $table->foreignId('contacto_confirmado_por')
                ->nullable()
                ->after('contacto_confirmado_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contacto_confirmado_por');
            $table->dropColumn(['indicaciones_entrega', 'contacto_confirmado_at']);
        });
    }
};
