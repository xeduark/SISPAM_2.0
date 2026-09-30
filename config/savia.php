<?php

/**
 * Servicio Consulta Afiliados — Savia Salud EPS
 * Especificación V3 (2025-03-31)
 *
 * Las credenciales viven en .env, nunca aquí.
 */

return [

    'endpoint' => env('SAVIA_ENDPOINT', 'https://intersavia-pruebas.saviasaludeps.com:8081/intersavia-webservices/rest/afiliado/consultar-afiliado'),
    'endpoint_token' => env('SAVIA_ENDPOINT_TOKEN', 'https://intersavia-pruebas.saviasaludeps.com:8081/intersavia-webservices/rest/token/generacion'),

    'username' => env('SAVIA_USERNAME'),
    'password' => env('SAVIA_PASSWORD'),
    'grant_type' => env('SAVIA_GRANT_TYPE', ''),

    // Token fijo, solo si algún día entregan uno sin credenciales.
    'token' => env('SAVIA_TOKEN'),

    'timeout' => (int) env('SAVIA_TIMEOUT', 30),
    'verify_ssl' => (bool) env('SAVIA_VERIFY_SSL', false),

    // Minutos que se reutiliza el token cuando la respuesta no trae expires_in.
    'token_minutos' => (int) env('SAVIA_TOKEN_MINUTOS', 55),

    // Registrar cada consulta en el log (quién, qué documento, qué resultado).
    'auditar' => (bool) env('SAVIA_AUDITAR', true),

    /*
    |--------------------------------------------------------------------------
    | Estados de afiliación que habilitan la atención del paciente
    |--------------------------------------------------------------------------
    | El servicio devuelve el estado como texto libre ("Activo", "ACTIVO",
    | "Retirado"...). Se compara en minúsculas, sin tildes ni espacios sobrantes.
    */
    'estados_activos' => ['activo', 'activa', 'afiliado'],

    /*
    |--------------------------------------------------------------------------
    | Color del badge del estado de afiliación en el listado de Pacientes
    |--------------------------------------------------------------------------
    | Tonos de la paleta institucional registrada en AdminPanelProvider.
    */
    'colores_estado' => [
        'activo' => 'success',
        'activa' => 'success',
        'afiliado' => 'success',
        'suspendido' => 'warning',
        'suspendida' => 'warning',
        'retirado' => 'danger',
        'retirada' => 'danger',
        'inactivo' => 'danger',
        'inactiva' => 'danger',
        'fallecido' => 'danger',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de documento admitidos (atributo tipoDocumento)
    |--------------------------------------------------------------------------
    */
    'tipos_documento' => [
        'CC' => 'CC — Cédula de ciudadanía',
        'TI' => 'TI — Tarjeta de identidad',
        'RC' => 'RC — Registro civil',
        'CE' => 'CE — Cédula de extranjería',
        'PA' => 'PA — Pasaporte',
        'PE' => 'PE — Permiso especial de permanencia',
        'CN' => 'CN — Certificado de nacido vivo',
        'MS' => 'MS — Menor sin identificación',
        'SC' => 'SC — Salvoconducto de permanencia',
        'CD' => 'CD — Carné diplomático',
        'AS' => 'AS — Adulto sin identificación',
    ],

    /*
    |--------------------------------------------------------------------------
    | Atributos del cuerpo de la petición, en el orden del documento
    |--------------------------------------------------------------------------
    */
    'campos_peticion' => [
        'tipoDocumento',
        'numeroDocumento',
        'fechaNacimiento',
        'primerNombre',
        'segundoNombre',
        'primerApellido',
        'segundoApellido',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tabla de respuestas del endpoint (sección 4 del documento)
    |--------------------------------------------------------------------------
    */
    'codigos' => [
        '0' => ['tono' => 'ok',    'texto' => 'Respuesta del caso exitoso.'],
        '-1000' => ['tono' => 'warn',  'texto' => 'Afiliado no encontrado en el sistema.'],
        '-1001' => ['tono' => 'error', 'texto' => 'Error en el cuerpo de la petición: estructura JSON inválida o POST sin payload.'],
        '-1002' => ['tono' => 'error', 'texto' => 'Error de atributo requerido: hay atributos obligatorios vacíos.'],
        '-1003' => ['tono' => 'error', 'texto' => 'Atributo sin formato correcto: caracteres no permitidos, longitud inválida o fecha que no cumple yyyy-MM-dd.'],
        '-1004' => ['tono' => 'error', 'texto' => 'Regla de negocio: si envías tipoDocumento, numeroDocumento pasa a ser obligatorio.'],
        '-1' => ['tono' => 'error', 'texto' => 'Inconsistencia de datos consultados o falla interna del servicio.'],
    ],

    'codigos_http' => [
        401 => 'No autorizado: el token no fue aceptado. La app lo renueva y reintenta una vez; si persiste, revisa SAVIA_USERNAME y SAVIA_PASSWORD, o si el servicio espera otro grant_type.',
        404 => 'Recurso no encontrado: revisa que la URL del endpoint esté completa y bien escrita.',
        405 => 'Método no permitido: el servicio solo acepta POST.',
        415 => 'Unsupported Media Type: revisa la cabecera Content-Type.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agrupación de los ~78 atributos de la respuesta, para mostrarlos legibles
    |--------------------------------------------------------------------------
    */
    'grupos' => [
        'Identificación del afiliado' => [
            'tipoDocumentoAfiliado' => 'Tipo de documento',
            'documentoAfiliado' => 'Número de documento',
            'primerNombreAfiliado' => 'Primer nombre',
            'segundoNombreAfiliado' => 'Segundo nombre',
            'primerApellidoAfiliado' => 'Primer apellido',
            'segundoApellidoAfiliado' => 'Segundo apellido',
            'fechaNacimientoAfiliado' => 'Fecha de nacimiento',
            'sexoAfiliado' => 'Sexo',
            'generoIdentificacion' => 'Género de identificación',
            'estadoCivil' => 'Estado civil',
            'discapacidad' => 'Discapacidad',
            'tipoDiscapacidad' => 'Tipo de discapacidad',
            'victimaLey1448' => 'Víctima Ley 1448',
        ],
        'Estado de la afiliación' => [
            'estadoAfiliacion' => 'Estado de afiliación',
            'regimen' => 'Régimen',
            'tipoAfiliado' => 'Tipo de afiliado',
            'modalidadSubsidio' => 'Modalidad de subsidio',
            'consecutivoBDUA' => 'Consecutivo BDUA',
            'codigoEntidad' => 'Código de entidad',
            'zonaAfiliacion' => 'Zona de afiliación',
            'fechaAfiliacionSGSSS' => 'Fecha afiliación SGSSS',
            'fechaAfiliacionEntidad' => 'Fecha afiliación entidad',
            'fechaMovilidadEntidad' => 'Fecha movilidad entidad',
            'fechaSuspension' => 'Fecha de suspensión',
            'fechaReactivacion' => 'Fecha de reactivación',
            'fechaRetiro' => 'Fecha de retiro',
            'fechaNovedad' => 'Fecha de novedad',
            'causaEstado' => 'Causa del estado',
            'codCausaEstado' => 'Código causa del estado',
            'contratoInternoAfilado' => 'Contrato interno',
            'descrLiquidacion' => 'Descripción liquidación',
            'fechaPasoSubsidiadoContributivo' => 'Paso subsidiado → contributivo',
            'fechaPasoContributivoSubsidiado' => 'Paso contributivo → subsidiado',
            'origenAfiliado' => 'Origen del afiliado',
            'codOrigenAfiliado' => 'Código origen',
        ],
        'Núcleo familiar' => [
            'tipoDocumentoCabezaFamilia' => 'Tipo doc. cabeza de familia',
            'documentoCabezaFamilia' => 'Documento cabeza de familia',
            'parentescoCabezaFamilia' => 'Parentesco',
        ],
        'Clasificación socioeconómica' => [
            'grupoPoblacional' => 'Grupo poblacional',
            'codGrupoPoblacional' => 'Código grupo poblacional',
            'codEtnia' => 'Código de etnia',
            'nivelSisben' => 'Nivel Sisbén',
            'puntajeSisben' => 'Puntaje Sisbén',
            'grupoSisben' => 'Grupo Sisbén',
            'subGrupoSisben' => 'Subgrupo Sisbén',
            'categoriaIBC' => 'Categoría IBC',
        ],
        'Residencia y contacto' => [
            'direccion' => 'Dirección',
            'barrio' => 'Barrio',
            'comuna' => 'Comuna',
            'codBarrioResidencia' => 'Código barrio residencia',
            'descripcionCiudadResidencia' => 'Ciudad de residencia',
            'codCiudadResidencia' => 'Código ciudad residencia',
            'codDepartamentoResidencia' => 'Código departamento residencia',
            'municipioAfiliacion' => 'Municipio de afiliación',
            'ciudadAfiliacion' => 'Ciudad de afiliación',
            'departamentoAfiliacion' => 'Departamento de afiliación',
            'codMunicipioAfiliacion' => 'Código municipio afiliación',
            'codDepartamentoAfiliacion' => 'Código departamento afiliación',
            'telefono' => 'Teléfono fijo',
            'telefonoMovil' => 'Teléfono móvil',
            'email' => 'Correo electrónico',
        ],
        'Nacimiento y nacionalidad' => [
            'paisNacionalidad' => 'País de nacionalidad',
            'paisNacimiento' => 'País de nacimiento',
            'departamentoNacimiento' => 'Departamento de nacimiento',
            'ciudadNacimiento' => 'Ciudad de nacimiento',
            'municipioNacimiento' => 'Municipio de nacimiento',
        ],
        'IPS y portabilidad' => [
            'codigoIPS' => 'Código IPS',
            'codigoSede' => 'Código sede',
            'ipsprimaria' => 'IPS primaria',
            'sedeIPSPrimaria' => 'Sede IPS primaria',
            'tipoPortabilidad' => 'Tipo de portabilidad',
            'ipsportabilidad' => 'IPS portabilidad',
            'sedeIPSPortabilidad' => 'Sede IPS portabilidad',
            'ciudadPortabilidad' => 'Ciudad portabilidad',
            'departamentoPortabilidad' => 'Departamento portabilidad',
            'codMunicipioPortabilidad' => 'Código municipio portabilidad',
            'codDepartamentoPortabilidad' => 'Código departamento portabilidad',
        ],
        'Observación' => [
            'observacion' => 'Observación',
        ],
    ],
];
