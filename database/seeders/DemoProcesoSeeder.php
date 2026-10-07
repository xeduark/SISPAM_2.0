<?php

namespace Database\Seeders;

use App\Models\Paciente;
use App\Models\Sede;
use App\Models\User;
use App\Models\Ventanilla;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use App\Services\Turnos\LlamadorDeTurnos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de DEMOSTRACIÓN para ver el proceso completo en local: pacientes,
 * tickets en la Sede Principal, fórmulas como imagen (Google Vision las lee
 * de verdad si `queue:work` está corriendo) y un turno llamado en la sala.
 *
 * Pacientes y médicos inventados. No va en DatabaseSeeder: se corre a mano.
 *
 *   php artisan db:seed --class=DemoProcesoSeeder
 */
class DemoProcesoSeeder extends Seeder
{
    /** Cada paciente: [documento, nombres, apellidos, prioridad, fórmulas]. Cada fórmula: [IPS, médico, líneas]. */
    private const PACIENTES = [
        ['70111222', ['CARLOS', 'ALBERTO'], ['GOMEZ', 'RESTREPO'], 'preferencial', [
            ['CS CISAMF', 'DRA. PAULA ANDREA RIOS', [
                'Losartan 50 mg tableta  # 120',
                'Omeprazol 20 mg capsula  # 60',
                'Rosuvastatina 40 mg + Ezetimibe 10 mg tableta  # 60',
            ]],
            // Segunda fórmula del mismo paciente: sus medicamentos no se mezclan con la primera.
            ['INSTITUTO DEL CORAZON', 'DR. JORGE IVAN MESA', [
                'Atorvastatina 40 mg tableta recubierta  # 30',
                'Acetaminofen 500 mg + Cafeina 65 mg tableta  # 60',
            ]],
        ]],
        ['43555666', ['MARTA', 'LUCIA'], ['HENAO', 'CARDONA'], 'normal', [
            ['ESE METROSALUD', 'DR. ANDRES FELIPE LOPEZ', [
                'Pregabalina 75 mg capsula  # 30',
                // A propósito: en bodega solo hay Losartan 50 mg → «Difiere concentración».
                'Losartan 25 mg tableta  # 30',
                'Levotiroxina 25 mcg tableta  # 30',
            ]],
        ]],
        ['1036777888', ['JUAN', 'DAVID'], ['OSORIO', 'VELEZ'], 'normal', [
            // A propósito: la fórmula no trae la cédula → «Cédula no encontrada».
            ['CLINICA ENVIGADO', 'DRA. SOFIA MARIN', [
                'Etoricoxib 90 mg tableta  # 10',
                'Metformina 850 mg tableta  # 90',
            ], 'sin_cedula'],
        ]],
        ['21999000', ['ROSA', 'ELENA'], ['ZAPATA', 'MUNERA'], 'preferencial', [
            ['CS CISAMF', 'DRA. PAULA ANDREA RIOS', [
                'Salbutamol 100 mcg/dosis inhalador  # 2',
                'Esomeprazol 40 mg capsula  # 30',
            ]],
        ]],
    ];

    public function run(GenerarTicket $generar, AlistarTicket $alistar, LlamadorDeTurnos $llamador): void
    {
        $sede = Sede::where('codigo', 'PRIN')->firstOrFail();
        $admin = User::where('es_administrador', true)->firstOrFail();

        $primerTicket = null;

        foreach (self::PACIENTES as [$documento, $nombres, $apellidos, $prioridad, $formulas]) {
            $paciente = Paciente::where('numero_documento', $documento)->first()
                ?? Paciente::factory()->create([
                    'numero_documento' => $documento,
                    'primer_nombre' => $nombres[0],
                    'segundo_nombre' => $nombres[1],
                    'primer_apellido' => $apellidos[0],
                    'segundo_apellido' => $apellidos[1],
                    'contacto_confirmado_por' => $admin->id,
                ]);

            $ticket = $generar->handle($paciente, $sede, $admin, ['prioridad' => $prioridad]);
            $primerTicket ??= $ticket;

            foreach ($formulas as $i => $formula) {
                $ruta = "soportes/ordenes-medicas/demo-{$ticket->numero}-".($i + 1).'.png';
                Storage::disk('local')->put($ruta, $this->imagenFormula($paciente, ...$formula));

                // Esto dispara la transcripción (Soporte::created en AppServiceProvider).
                $paciente->soportes()->create([
                    'ticket_id' => $ticket->id,
                    'orden_medica' => $ruta,
                    'alto_costo_oncologico' => false,
                    'cargado_por' => $admin->id,
                ]);
            }

            $this->command?->info("Ticket {$ticket->turno} ({$ticket->numero}) — {$paciente->nombre_completo}, ".count($formulas).' fórmula(s)');
        }

        // Farmacia alista el primero y lo llama, para que la sala tenga algo que mostrar.
        // Solo se llama lo alistado: entrega atiende `listo` y `parcial`.
        $primerTicket?->refresh();
        $alistar->handle($primerTicket, $admin, [
            ['codigo' => 'MX377-6', 'nombre' => 'LOSARTAN 50 MG TABLETA (GENFAR) (INS)', 'cantidad' => 10, 'unidad' => 'UND'],
        ]);

        $ventanilla = Ventanilla::where('sede_id', $sede->id)->first();

        if ($ventanilla !== null && $primerTicket !== null) {
            $llamador->llamar($primerTicket, $ventanilla, $admin);
            $this->command?->info("Llamado {$primerTicket->turno} a {$ventanilla->nombre}: mira /sala/PRIN");
        }
    }

    /** Una fórmula médica simple dibujada como PNG, para que Vision tenga algo real que leer. */
    private function imagenFormula(Paciente $paciente, string $ips, string $medico, array $lineas, ?string $variante = null): string
    {
        $fuente = 'C:/Windows/Fonts/arial.ttf';
        $fuenteNegrita = file_exists('C:/Windows/Fonts/arialbd.ttf') ? 'C:/Windows/Fonts/arialbd.ttf' : $fuente;

        $img = imagecreatetruecolor(1200, 900);
        $blanco = imagecolorallocate($img, 255, 255, 255);
        $negro = imagecolorallocate($img, 20, 20, 20);
        $gris = imagecolorallocate($img, 120, 120, 120);
        imagefill($img, 0, 0, $blanco);

        $y = 70;
        $escribir = function (string $texto, int $tamano = 20, bool $negrita = false, $color = null) use ($img, &$y, $fuente, $fuenteNegrita, $negro): void {
            imagettftext($img, $tamano, 0, 60, $y, $color ?? $negro, $negrita ? $fuenteNegrita : $fuente, $texto);
            $y += (int) ($tamano * 2);
        };

        $escribir($ips, 28, true);
        $escribir('FORMULA MEDICA - '.now()->format('Y-m-d'), 18, false, $gris);
        $y += 10;
        $escribir('Paciente: '.$paciente->nombre_completo, 20);
        $escribir($variante === 'sin_cedula' ? 'Documento: ver carné' : 'CC '.number_format((float) $paciente->numero_documento, 0, ',', '.'), 20);
        $escribir('Diagnóstico: I10X - E119', 20);
        $y += 20;
        $escribir('Medicamentos:', 22, true);

        foreach ($lineas as $linea) {
            $escribir($linea, 22);
            $escribir('   Tomar 1 cada 12 horas por 3 meses', 16, false, $gris);
        }

        $y += 30;
        $escribir('Médico: '.$medico.'   RM 43.152.152', 18);

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
