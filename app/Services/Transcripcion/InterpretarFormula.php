<?php

namespace App\Services\Transcripcion;

use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Models\Transcripcion;
use Illuminate\Support\Str;

/**
 * Convierte el texto que leyó Google Vision en una propuesta: si la cédula
 * aparece y qué medicamentos trae la fórmula, cada uno con su producto más
 * parecido del inventario.
 *
 * Es solo una propuesta: la transcriptora confirma cada línea.
 */
class InterpretarFormula
{
    /** Por debajo de esto la sugerencia sale marcada. */
    public const SIMILITUD_MINIMA = 80;

    private const UNIDADES = 'MG|MCG|G|ML|UI|%';

    public function __construct(
        private CatalogoInventarioInterface $catalogo,
    ) {}

    /**
     * Solo se afirma lo que se puede ver: si el documento del paciente está
     * en la fórmula. Una fórmula trae muchos números (registro médico,
     * teléfonos, autorizaciones), así que no encontrarlo no prueba que sea
     * de otra persona: la transcriptora lo revisa.
     */
    public function verificarCedula(string $texto, string $documento): string
    {
        // 43.152.152 o 43 152 152 → 43152152
        $sinSeparadores = preg_replace('/(?<=\d)[.,\s](?=\d{3}(?!\d))/', '', $texto);
        preg_match_all('/\d{5,12}/', (string) $sinSeparadores, $numeros);

        return in_array(ltrim($documento, '0'), array_map(fn ($n) => ltrim($n, '0'), $numeros[0]), true)
            ? Transcripcion::CEDULA_COINCIDE
            : Transcripcion::CEDULA_NO_ENCONTRADA;
    }

    /**
     * Una línea por medicamento, con la sugerencia del inventario.
     *
     * Se toma como medicamento la línea que tiene una concentración (20 mg,
     * 100 mcg, 5%) y una palabra de al menos 4 letras.
     *
     * @return list<array<string, mixed>>
     */
    public function medicamentos(string $texto): array
    {
        $items = [];

        foreach (preg_split('/\R/', $texto) as $linea) {
            $linea = trim(preg_replace('/\s+/', ' ', $linea));

            if (self::concentraciones($linea) === [] || ! preg_match('/\pL{4,}/u', $linea)) {
                continue;
            }

            // La cantidad («# 30») no es parte del nombre: se busca sin ella.
            $prescrito = trim(preg_replace('/(?:#|N[°º.]|CANT(?:IDAD)?\.?:?)\s*\d+.*$/iu', '', $linea));
            $mejor = $this->catalogo->buscar($prescrito, 1)[0] ?? null;

            $items[] = [
                'texto_prescrito' => Str::limit($linea, 250, ''),
                'concentracion' => implode(' + ', self::concentraciones($linea)),
                'cantidad_total' => self::cantidad($linea),
                'codigo_inventario' => $mejor?->codigo,
                'agrupador' => $mejor?->agrupador,
                'nombre_inventario' => $mejor?->nombre,
                'similitud' => $mejor?->similitud,
                'alertas' => self::alertas($linea, $mejor?->nombre, $mejor?->similitud),
            ];
        }

        return $items;
    }

    /**
     * Lo mismo que `medicamentos()`, pero desde la lectura ya ordenada de Gemini.
     *
     * @param  array<string, mixed>  $lectura  respuesta de GeminiClient::leerFormula()
     * @return list<array<string, mixed>>
     */
    public function medicamentosDeLectura(array $lectura): array
    {
        return array_map(function (array $m) {
            $prescrito = trim(($m['descripcion'] ?? '').' '.($m['forma_farmaceutica'] ?? ''));
            $mejor = $this->catalogo->buscar($prescrito, 1)[0] ?? null;
            $dias = is_numeric($m['duracion_dias'] ?? null) ? (int) $m['duracion_dias'] : null;
            $total = is_numeric($m['cantidad_total'] ?? null) ? (float) $m['cantidad_total'] : null;
            $meses = $dias ? max(1, (int) round($dias / 30)) : null;
            $alertas = self::alertas($prescrito, $mejor?->nombre, $mejor?->similitud);

            return [
                // Regla de exactitud: cada medicamento es de UNA fórmula de la imagen; nunca se mezclan.
                'formula' => max(1, (int) ($m['formula'] ?? 1)),
                'texto_prescrito' => Str::limit($prescrito, 250, ''),
                'concentracion' => ($m['concentracion'] ?? null) ?: (implode(' + ', self::concentraciones($prescrito)) ?: null),
                'posologia' => isset($m['posologia']) ? Str::limit((string) $m['posologia'], 250, '') : null,
                'cantidad_total' => $total,
                'duracion_dias' => $dias,
                'meses' => $meses,
                // Cuota del mes: el total repartido en los meses, hacia arriba. La transcriptora la confirma.
                'cantidad_mes' => $total && $meses ? (int) ceil($total / $meses) : null,
                'codigo_inventario' => $mejor?->codigo,
                'agrupador' => $mejor?->agrupador,
                'nombre_inventario' => $mejor?->nombre,
                'similitud' => $mejor?->similitud,
                'alertas' => $alertas,
            ];
        }, $lectura['medicamentos'] ?? []);
    }

    /**
     * @return list<string>
     */
    public static function alertas(string $prescrito, ?string $inventario, ?int $similitud): array
    {
        if ($inventario === null) {
            return ['Sin coincidencia en inventario'];
        }

        $alertas = [];

        if (! self::mismaConcentracion($prescrito, $inventario)) {
            $alertas[] = 'Difiere concentración (Rx: '.(implode(' + ', self::concentraciones($prescrito)) ?: '—')
                .' / Bodega: '.(implode(' + ', self::concentraciones($inventario)) ?: '—').')';
        }

        if ($similitud !== null && $similitud < self::SIMILITUD_MINIMA) {
            $alertas[] = "Similitud baja ({$similitud}%)";
        }

        return $alertas;
    }

    /** Se comparan como conjuntos: «40 mg + 10 mg» y «10 mg + 40 mg» son lo mismo. */
    public static function mismaConcentracion(string $a, string $b): bool
    {
        $x = self::concentraciones($a);
        $y = self::concentraciones($b);
        sort($x);
        sort($y);

        return $x === $y;
    }

    /**
     * Qué tanto se parecen dos nombres, de 0 a 100. Solo palabras: las
     * concentraciones se comparan aparte (`mismaConcentracion`).
     *
     * En los dos sentidos y sin importar el orden: cada palabra busca su pareja
     * más parecida del otro lado, y se promedian ambos lados. Así «Rosuvastatina
     * + Ezetimibe» empata con «EZETIMIBA + ROSUVASTATINA», y «Losartan» solo no
     * empata del todo con «LOSARTAN + HIDROCLOROTIAZIDA», que trae un activo de más.
     * Lo que va entre paréntesis en bodega (laboratorio, marca) no cuenta.
     *
     * ponytail: similar_text palabra por palabra, O(n·m) por producto; si el
     * catálogo real es grande, la búsqueda la hace el inventario y no esto.
     */
    public static function similitud(string $prescrito, string $inventario): int
    {
        $rx = self::palabras($prescrito);
        $bodega = self::palabras((string) preg_replace('/\([^)]*\)/', ' ', $inventario));

        if ($rx === [] || $bodega === []) {
            return 0;
        }

        return (int) round((self::cobertura($rx, $bodega) + self::cobertura($bodega, $rx)) / 2);
    }

    /**
     * Promedio de qué tan bien encuentra cada palabra de $de su pareja en $en.
     *
     * @param  list<string>  $de
     * @param  list<string>  $en
     */
    private static function cobertura(array $de, array $en): float
    {
        $total = 0.0;

        foreach ($de as $palabra) {
            $mejor = 0.0;

            foreach ($en as $candidata) {
                similar_text($palabra, $candidata, $porcentaje);
                $mejor = max($mejor, $porcentaje);
            }

            $total += $mejor;
        }

        return $total / count($de);
    }

    /**
     * «20 mg», «(500+65)mg», «100 mcg» → ['20 MG'], ['500 MG', '65 MG'], ['100 MCG']
     *
     * @return list<string>
     */
    public static function concentraciones(string $texto): array
    {
        $texto = self::normalizar($texto);
        // (500+65)MG → 500MG+65MG
        $texto = preg_replace_callback('/\(([\d.,+\s]+)\)\s*('.self::UNIDADES.')\b/', fn ($m) => implode('+', array_map(
            fn ($n) => trim($n).$m[2],
            explode('+', $m[1]),
        )), $texto);

        preg_match_all('/(\d+(?:[.,]\d+)?)\s*('.self::UNIDADES.')(?![A-Z])/', (string) $texto, $m, PREG_SET_ORDER);

        return array_values(array_unique(array_map(
            // 27,50 y 27.5 son el mismo número: sin coma decimal ni ceros sobrantes.
            fn ($x) => (str_contains($n = str_replace(',', '.', $x[1]), '.') ? rtrim(rtrim($n, '0'), '.') : $n).' '.$x[2],
            $m,
        )));
    }

    /** «#30», «Cant: 60», «N° 120» → 30, 60, 120 */
    private static function cantidad(string $linea): ?float
    {
        return preg_match('/(?:#|N[°º.]|CANT(?:IDAD)?\.?:?)\s*(\d+)/i', $linea, $m) ? (float) $m[1] : null;
    }

    public static function normalizar(string $texto): string
    {
        $texto = Str::upper(Str::ascii($texto));

        return strtr($texto, ['MILIGRAMOS' => 'MG', 'MICROGRAMOS' => 'MCG', 'MILILITROS' => 'ML']);
    }

    /** @return list<string> */
    private static function palabras(string $texto): array
    {
        // Solo palabras: los números son concentraciones o cantidades y se comparan aparte.
        preg_match_all('/[A-Z]{3,}/', self::normalizar($texto), $m);

        return array_values(array_diff($m[0], ['MCG']));
    }
}
