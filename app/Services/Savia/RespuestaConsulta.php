<?php

namespace App\Services\Savia;

use App\Models\User;

/**
 * Resultado de una consulta al servicio, ya interpretado.
 * Lo que la vista necesita saber, sin tener que mirar el JSON crudo.
 */
class RespuestaConsulta
{
    /**
     * Respuestas de Savia que hablan del documento consultado y se le pueden
     * mostrar tal cual a quien consulta: éxito, no encontrado y documento mal
     * escrito. Todo lo demás es un problema del servicio o de la configuración.
     */
    private const CODIGOS_PARA_QUIEN_CONSULTA = ['0', '-1000', '-1003', '-1004'];

    public function __construct(
        public readonly int $httpCode,
        public readonly ?array $json,
        public readonly string $crudo,
        public readonly array $enviado,
        public readonly int $duracionMs,
        public readonly ?string $origenToken = null,
        public readonly ?string $errorRed = null,
        public readonly ?string $errorToken = null,
    ) {}

    /** Código interno del servicio (0, -1000, -1001…), si vino. */
    public function codigo(): ?string
    {
        return isset($this->json['codigo']) ? (string) $this->json['codigo'] : null;
    }

    public function exitosa(): bool
    {
        return $this->codigo() === '0' && $this->httpCode === 200;
    }

    /**
     * Savia no contestó: tiempo agotado, conexión rechazada o un error 5xx.
     * Es lo que cuenta para pausar las consultas (`SaviaClient`).
     */
    public function esFallaDeRed(): bool
    {
        return $this->errorRed !== null || $this->httpCode >= 500;
    }

    /**
     * Algo que quien consulta no puede resolver: red, token, credenciales,
     * IP no autorizada, una respuesta que no se entiende. El detalle sirve al
     * administrador; a los demás se les dice qué hacer (`diagnosticoPara()`).
     */
    public function esFallaTecnica(): bool
    {
        if ($this->errorRed !== null || $this->errorToken !== null) {
            return true;
        }

        // Decide el código de Savia, no el HTTP: el «no encontrado» llega
        // unas veces con 200 y otras con 404.
        return ! in_array($this->codigo(), self::CODIGOS_PARA_QUIEN_CONSULTA, true);
    }

    /**
     * El diagnóstico que ve esta persona.
     *
     * El detalle técnico —el mensaje de cURL con la dirección del servicio,
     * nombres de variables del .env— solo lo ve el administrador. Los demás
     * ven qué hacer; el detalle queda en el log (`SaviaClient`).
     *
     * @return array{tono: string, titulo: string, detalle: string}
     */
    public function diagnosticoPara(?User $usuario): array
    {
        if ($usuario?->es_administrador || ! $this->esFallaTecnica()) {
            return $this->diagnostico();
        }

        return self::diagnosticoSinDetalle();
    }

    /**
     * Lo que ve quien no administra cuando Savia falla.
     *
     * @return array{tono: string, titulo: string, detalle: string}
     */
    public static function diagnosticoSinDetalle(): array
    {
        return [
            'tono' => 'error',
            'titulo' => 'Savia no respondió',
            'detalle' => 'Espera un momento y vuelve a intentarlo. Si sigue fallando, avisa a un administrador.',
        ];
    }

    /**
     * Afiliados devueltos, con los programas normalizados.
     * El servicio responde "programas" aunque la V3 documente
     * "programasEspeciales": aquí se unifica bajo un solo nombre.
     */
    public function afiliados(): array
    {
        $lista = $this->json['afiliados'] ?? [];
        if (! is_array($lista)) {
            return [];
        }

        return array_map(function (array $af) {
            $programas = [];
            foreach (['programasEspeciales', 'programas'] as $clave) {
                if (! empty($af[$clave]) && is_array($af[$clave])) {
                    $programas = $af[$clave];
                    break;
                }
            }
            unset($af['programas']);
            $af['programasEspeciales'] = $programas;

            return self::repararCodificacion($af);
        }, $lista);
    }

    /**
     * El servicio devuelve bien acentuada casi toda la respuesta, pero las
     * descripciones de los programas llegan doblemente codificadas
     * ("AtenciÃ³n domiciliaria" en vez de "Atención domiciliaria"): el UTF-8
     * original se volvió a codificar como UTF-8. Se deshace esa capa de más.
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    private static function repararCodificacion(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if (is_array($valor)) {
                $datos[$clave] = self::repararCodificacion($valor);
            } elseif (is_string($valor)) {
                $datos[$clave] = self::repararTexto($valor);
            }
        }

        return $datos;
    }

    /**
     * Solo toca el texto que trae la firma del doble encoding: "Ã" o "Â"
     * (C3 83 / C3 82) seguidos de otro carácter de la tabla latina. Un texto
     * bien codificado como "Población" (C3 B3) no cumple ese patrón y se
     * devuelve intacto.
     */
    public static function repararTexto(string $texto): string
    {
        $vueltas = 0;

        while ($texto !== '' && preg_match('/(\xC3\x83|\xC3\x82)[\xC2-\xC3][\x80-\xBF]/', $texto) && $vueltas < 3) {
            $reparado = mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');

            if ($reparado === $texto || ! mb_check_encoding($reparado, 'UTF-8')) {
                break;
            }

            $texto = $reparado;
            $vueltas++;
        }

        return $texto;
    }

    public function registros(): int
    {
        return (int) ($this->json['registros'] ?? count($this->afiliados()));
    }

    public function fechaTransaccion(): ?string
    {
        return $this->json['fechaTransaccion'] ?? null;
    }

    /**
     * Mensaje entendible según la tabla de respuestas del documento.
     *
     * @return array{tono: string, titulo: string, detalle: string}
     */
    public function diagnostico(): array
    {
        if ($this->errorToken !== null) {
            return [
                'tono' => 'error',
                'titulo' => 'No se pudo obtener el token',
                'detalle' => $this->errorToken,
            ];
        }

        if ($this->errorRed !== null) {
            $pista = '';
            if (str_contains(strtolower($this->errorRed), 'ssl') || str_contains(strtolower($this->errorRed), 'certificate')) {
                $pista = ' Si el ambiente de pruebas usa certificado autofirmado, pon SAVIA_VERIFY_SSL=false en el .env.';
            } elseif (str_contains(strtolower($this->errorRed), 'reset') || str_contains(strtolower($this->errorRed), 'timed out')) {
                $pista = ' Puede ser que la IP desde la que consultas no esté autorizada por Savia, o que la red bloquee el puerto 8081.';
            }

            return [
                'tono' => 'error',
                'titulo' => 'No se pudo contactar el servicio',
                'detalle' => $this->errorRed.'.'.$pista,
            ];
        }

        $codigo = $this->codigo();
        $tabla = config('savia.codigos');

        if ($codigo !== null && isset($tabla[$codigo])) {
            $mensajeServicio = trim((string) ($this->json['mensaje'] ?? ''));

            return [
                'tono' => $tabla[$codigo]['tono'],
                'titulo' => $mensajeServicio !== '' ? $mensajeServicio : $tabla[$codigo]['texto'],
                'detalle' => "Código interno {$codigo} · HTTP {$this->httpCode}. ".$tabla[$codigo]['texto'],
            ];
        }

        $http = config('savia.codigos_http');
        if (isset($http[$this->httpCode])) {
            return [
                'tono' => 'error',
                'titulo' => "HTTP {$this->httpCode}",
                'detalle' => $http[$this->httpCode].$this->loQueDijoElServicio(),
            ];
        }

        if ($this->httpCode === 200) {
            return ['tono' => 'ok', 'titulo' => 'Respuesta recibida', 'detalle' => 'HTTP 200.'];
        }

        return [
            'tono' => 'error',
            'titulo' => 'Respuesta no reconocida',
            'detalle' => "HTTP {$this->httpCode}. El cuerpo no corresponde a la estructura documentada.",
        ];
    }

    /**
     * Lo que respondió el servicio, para pegarlo al detalle de un error.
     *
     * Ante un 401 Savia distingue «Token inválido» de cualquier otra falla, y
     * esa diferencia es justo la que dice si hay que revisar las credenciales
     * o pedirle a Savia que autorice la IP. Sin esto, el mensaje de la app
     * manda a revisar lo que no es.
     *
     * Solo se usa en respuestas de error, donde el cuerpo es un aviso del
     * servicio y no datos del afiliado, y se recorta por si acaso.
     */
    private function loQueDijoElServicio(): string
    {
        if ($this->httpCode === 200) {
            return '';
        }

        $texto = trim(strip_tags($this->crudo));

        return $texto === '' ? '' : ' El servicio respondió: «'.mb_substr($texto, 0, 200).'».';
    }

    /** Campos que el servicio devolvió y que la V3 no documenta. */
    public function camposNoMapeados(array $afiliado): array
    {
        $conocidos = ['programasEspeciales', 'programas'];
        foreach (config('savia.grupos') as $campos) {
            $conocidos = array_merge($conocidos, array_keys($campos));
        }

        return array_diff_key($afiliado, array_flip($conocidos));
    }

    /**
     * El primer afiliado devuelto, ya con los programas normalizados,
     * o null si la consulta no trajo ninguno.
     */
    public function primerAfiliado(): ?array
    {
        return $this->afiliados()[0] ?? null;
    }

    /**
     * Mensaje corto para mostrar en una notificación, sin exponer datos del afiliado.
     */
    public function mensajeCorto(): string
    {
        $diagnostico = $this->diagnostico();

        return $diagnostico['titulo'];
    }
}
