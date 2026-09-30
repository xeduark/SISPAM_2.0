<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Resources\PacienteResource\Concerns\AvisaSobreSavia;
use App\Filament\Resources\PacienteResource\Pages;
use App\Filament\Resources\PacienteResource\ResultadoConsulta;
use App\Models\Paciente;
use App\Services\Savia\SaviaClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;
use Livewire\Component;

class PacienteResource extends Resource
{
    use ControlaPermisos;

    protected static string $modulo = 'pacientes';

    protected static ?string $model = Paciente::class;

    protected static ?string $modelLabel = 'Paciente';

    protected static ?string $pluralModelLabel = 'Pacientes';

    protected static ?string $navigationLabel = 'Pacientes';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'numero_documento';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Consulta en Savia')
                    ->description('Escribe el tipo y el número de documento, y usa el botón para traer los datos del afiliado desde Savia Salud EPS.')
                    ->schema([
                        Forms\Components\Select::make('tipo_documento')
                            ->label('Tipo de documento')
                            ->options(config('savia.tipos_documento'))
                            ->default('CC')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (string $operation, Forms\Get $get, Forms\Set $set) => static::olvidarConsultaSiCambioElDocumento($operation, $get, $set)),
                        Forms\Components\TextInput::make('numero_documento')
                            ->label('Número de documento')
                            ->required()
                            ->maxLength(20)
                            // Mismas reglas que exige el servicio (códigos -1003 y -1004).
                            ->regex('/^[A-Za-z0-9\-]+$/')
                            ->validationMessages([
                                'regex' => 'El número de documento solo admite letras, números y guion.',
                            ])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, Forms\Get $get, Forms\Set $set) => static::olvidarConsultaSiCambioElDocumento($operation, $get, $set))
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule, Forms\Get $get): Unique {
                                    $rule->where('tipo_documento', $get('tipo_documento'));

                                    // Si el documento ya está en SISPAM, el flujo actualiza ese
                                    // paciente en vez de crear otro: no es un duplicado.
                                    if (filled($id = $get('paciente_existente_id'))) {
                                        $rule->ignore($id);
                                    }

                                    return $rule;
                                },
                            )
                            ->validationAttribute('número de documento'),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('consultarEnSavia')
                                ->label('Consultar en Savia')
                                ->icon('heroicon-m-magnifying-glass')
                                ->size(ActionSize::Large)
                                ->action(function (Forms\Get $get, Forms\Set $set, Component $livewire): void {
                                    $tipo = (string) $get('tipo_documento');
                                    $numero = trim((string) $get('numero_documento'));

                                    // Los datos de una consulta anterior nunca sobreviven a una nueva.
                                    static::limpiarDatosSavia($set);

                                    if (blank($tipo) || blank($numero)) {
                                        Notification::make()
                                            ->title('Faltan datos para consultar')
                                            ->body('Escribe el tipo y el número de documento antes de consultar en Savia.')
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    $resultado = static::consultarEnSavia(
                                        $tipo,
                                        $numero,
                                        // La página de creación avisa con un modal centrado;
                                        // las demás conservan la notificación de siempre.
                                        $livewire instanceof AvisaSobreSavia ? $livewire : null,
                                    );

                                    if ($resultado === null) {
                                        return;
                                    }

                                    foreach ($resultado->atributos as $columna => $valor) {
                                        $set($columna, $valor);
                                    }

                                    // El contacto lo manda SISPAM: si el paciente ya existe se
                                    // precarga el suyo, no el de Savia.
                                    $contacto = $resultado->contactoParaElFormulario();

                                    foreach (Paciente::CAMPOS_CONTACTO as $columna) {
                                        $set($columna, $contacto[$columna] ?? null);
                                    }

                                    // Lo que reporta Savia queda de referencia bajo cada campo.
                                    foreach (Paciente::CONTACTO_SAVIA as $columna) {
                                        $set("savia_{$columna}", $resultado->contactoSavia[$columna] ?? null);
                                    }

                                    $set('paciente_existente_id', $resultado->existente?->getKey());
                                    $set('cambios_savia', $resultado->cambios());

                                    // La marca se calcula con lo que quedó en el formulario, no con
                                    // lo que se escribió, por si Savia devuelve el documento distinto.
                                    $set('documento_consultado', static::claveDocumento(
                                        $resultado->atributos['tipo_documento'] ?? $tipo,
                                        $resultado->atributos['numero_documento'] ?? $numero,
                                    ));
                                }),
                        ])
                            ->alignment(Alignment::Center)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'sispam-consulta-savia']),
                        // De qué documento son los datos cargados. No es columna de la tabla:
                        // solo viaja al guardar un paciente nuevo, donde `CreatePaciente`
                        // la usa para validar y luego la descarta.
                        Forms\Components\Hidden::make('documento_consultado')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        // El paciente ya registrado con ese documento, si lo hay: de esto
                        // depende que el formulario cree o actualice.
                        Forms\Components\Hidden::make('paciente_existente_id')
                            ->dehydrated(false),
                        // Contacto que reporta Savia, solo de referencia. Nunca se guarda.
                        ...array_map(
                            fn (string $columna): Forms\Components\Hidden => Forms\Components\Hidden::make("savia_{$columna}")
                                ->dehydrated(false),
                            array_values(Paciente::CONTACTO_SAVIA),
                        ),
                    ])
                    ->columns(2),

                // Todo lo que llena la consulta. Al crear un paciente solo aparece
                // cuando Savia devolvió al afiliado del documento que está escrito.
                Forms\Components\Group::make([
                    Forms\Components\Section::make('Cambios frente a lo registrado en SISPAM')
                        ->description('Así quedaría el paciente al guardar, según lo que Savia responde hoy.')
                        ->icon('heroicon-o-arrows-right-left')
                        ->iconColor('warning')
                        ->schema([
                            Forms\Components\Placeholder::make('cambios_savia')
                                ->hiddenLabel()
                                ->content(fn (Forms\Get $get): HtmlString => static::listaDeCambios(
                                    (array) ($get('cambios_savia') ?? []),
                                )),
                        ])
                        ->visible(fn (Forms\Get $get): bool => filled($get('cambios_savia'))),

                    Forms\Components\Wizard::make([
                        Forms\Components\Wizard\Step::make('Identificación')
                            ->description('Datos del afiliado')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\TextInput::make('primer_nombre')
                                    ->label('Primer nombre')
                                    ->required()
                                    ->maxLength(60),
                                Forms\Components\TextInput::make('segundo_nombre')
                                    ->label('Segundo nombre')
                                    ->maxLength(60),
                                Forms\Components\TextInput::make('primer_apellido')
                                    ->label('Primer apellido')
                                    ->required()
                                    ->maxLength(60),
                                Forms\Components\TextInput::make('segundo_apellido')
                                    ->label('Segundo apellido')
                                    ->maxLength(60),
                                Forms\Components\DatePicker::make('fecha_nacimiento')
                                    ->label('Fecha de nacimiento')
                                    ->displayFormat('d/m/Y')
                                    ->maxDate(now()),
                                Forms\Components\TextInput::make('sexo')
                                    ->label('Sexo')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('genero_identificacion')
                                    ->label('Género de identificación')
                                    ->maxLength(40),
                                Forms\Components\TextInput::make('estado_civil')
                                    ->label('Estado civil')
                                    ->maxLength(40),
                            ])
                            ->columns(3),

                        Forms\Components\Wizard\Step::make('Ubicación y contacto')
                            ->description('Confirmar con el paciente')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Forms\Components\Section::make('Confirmar con el paciente')
                                    ->description('El teléfono y la dirección son datos de SISPAM, no de Savia. Confírmalos en cada registro: si el medicamento no está en la sede, hay que enviarlo a domicilio.')
                                    ->icon('heroicon-o-phone')
                                    ->iconColor('warning')
                                    ->schema([
                                        Forms\Components\TextInput::make('telefono_movil')
                                            ->label('Teléfono móvil')
                                            ->tel()
                                            ->required()
                                            ->maxLength(30)
                                            ->helperText(fn (Forms\Get $get): ?string => static::referenciaSavia($get, 'telefono_movil')),
                                        Forms\Components\TextInput::make('telefono')
                                            ->label('Teléfono fijo o alterno')
                                            ->tel()
                                            ->maxLength(30)
                                            ->helperText(fn (Forms\Get $get): ?string => static::referenciaSavia($get, 'telefono')),
                                        Forms\Components\TextInput::make('ciudad_residencia')
                                            ->label('Ciudad o municipio')
                                            ->required()
                                            ->maxLength(80)
                                            ->helperText(fn (Forms\Get $get): ?string => static::referenciaSavia($get, 'ciudad_residencia')),
                                        Forms\Components\TextInput::make('direccion')
                                            ->label('Dirección')
                                            ->required()
                                            ->maxLength(180)
                                            ->columnSpan(2)
                                            ->helperText(fn (Forms\Get $get): ?string => static::referenciaSavia($get, 'direccion')),
                                        Forms\Components\TextInput::make('barrio')
                                            ->label('Barrio')
                                            ->maxLength(80)
                                            ->helperText(fn (Forms\Get $get): ?string => static::referenciaSavia($get, 'barrio')),
                                        Forms\Components\Textarea::make('indicaciones_entrega')
                                            ->label('Indicaciones de entrega')
                                            ->placeholder('Conjunto, torre, apartamento, punto de referencia…')
                                            ->helperText('Lo que necesita quien lleva el medicamento para llegar.')
                                            ->rows(2)
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        Forms\Components\Checkbox::make('contacto_confirmado')
                                            ->label('Confirmé teléfono y dirección con el paciente')
                                            ->accepted()
                                            ->validationMessages([
                                                'accepted' => 'Debes confirmar el teléfono y la dirección con el paciente antes de guardar.',
                                            ])
                                            // No es columna: lo que se guarda es la fecha y quién confirmó.
                                            ->dehydrated(false)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3),

                                // El teléfono, la dirección, el barrio y la ciudad viven en
                                // «Confirmar con el paciente»: son datos de SISPAM, no de Savia.
                                Forms\Components\Fieldset::make('Residencia según Savia')
                                    ->schema([
                                        Forms\Components\TextInput::make('comuna')
                                            ->label('Comuna')
                                            ->maxLength(80),
                                        Forms\Components\TextInput::make('municipio_afiliacion')
                                            ->label('Municipio de afiliación')
                                            ->maxLength(80),
                                        Forms\Components\TextInput::make('departamento_afiliacion')
                                            ->label('Departamento de afiliación')
                                            ->maxLength(80),
                                        Forms\Components\TextInput::make('email')
                                            ->label('Correo electrónico')
                                            ->email()
                                            ->maxLength(120),
                                    ])
                                    ->columns(4),
                            ]),

                        Forms\Components\Wizard\Step::make('Afiliación')
                            ->description('EPS, IPS y núcleo familiar')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Forms\Components\Fieldset::make('Estado de la afiliación')
                                    ->schema([
                                        Forms\Components\TextInput::make('estado_afiliacion')
                                            ->label('Estado de afiliación')
                                            ->maxLength(40),
                                        Forms\Components\TextInput::make('regimen')
                                            ->label('Régimen')
                                            ->maxLength(40),
                                        Forms\Components\TextInput::make('tipo_afiliado')
                                            ->label('Tipo de afiliado')
                                            ->maxLength(40),
                                        Forms\Components\TextInput::make('modalidad_subsidio')
                                            ->label('Modalidad de subsidio')
                                            ->maxLength(40),
                                        Forms\Components\TextInput::make('causa_estado')
                                            ->label('Causa del estado')
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('consecutivo_bdua')
                                            ->label('Consecutivo BDUA')
                                            ->maxLength(30),
                                        Forms\Components\TextInput::make('codigo_entidad')
                                            ->label('Código de entidad')
                                            ->maxLength(20),
                                        Forms\Components\DatePicker::make('fecha_afiliacion_sgsss')
                                            ->label('Fecha afiliación SGSSS')
                                            ->displayFormat('d/m/Y'),
                                        Forms\Components\DatePicker::make('fecha_afiliacion_entidad')
                                            ->label('Fecha afiliación entidad')
                                            ->displayFormat('d/m/Y'),
                                        Forms\Components\DatePicker::make('fecha_suspension')
                                            ->label('Fecha de suspensión')
                                            ->displayFormat('d/m/Y'),
                                        Forms\Components\DatePicker::make('fecha_retiro')
                                            ->label('Fecha de retiro')
                                            ->displayFormat('d/m/Y'),
                                    ])
                                    ->columns(3),

                                Forms\Components\Fieldset::make('IPS y portabilidad')
                                    ->schema([
                                        Forms\Components\TextInput::make('codigo_ips')
                                            ->label('Código IPS')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('ips_primaria')
                                            ->label('IPS primaria')
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('sede_ips_primaria')
                                            ->label('Sede IPS primaria')
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('tipo_portabilidad')
                                            ->label('Tipo de portabilidad')
                                            ->maxLength(40),
                                    ])
                                    ->columns(2),

                                Forms\Components\Fieldset::make('Núcleo familiar')
                                    ->schema([
                                        Forms\Components\Select::make('tipo_documento_cabeza_familia')
                                            ->label('Tipo doc. cabeza de familia')
                                            ->options(config('savia.tipos_documento')),
                                        Forms\Components\TextInput::make('documento_cabeza_familia')
                                            ->label('Documento cabeza de familia')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('parentesco_cabeza_familia')
                                            ->label('Parentesco')
                                            ->maxLength(40),
                                    ])
                                    ->columns(3),
                            ]),

                        Forms\Components\Wizard\Step::make('Caracterización')
                            ->description('Salud, Sisbén y programas')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema([
                                Forms\Components\Fieldset::make('Condición de salud')
                                    ->schema([
                                        Forms\Components\TextInput::make('discapacidad')
                                            ->label('Discapacidad')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('tipo_discapacidad')
                                            ->label('Tipo de discapacidad')
                                            ->maxLength(60),
                                        Forms\Components\TextInput::make('victima_ley_1448')
                                            ->label('Víctima Ley 1448')
                                            ->maxLength(20),
                                    ])
                                    ->columns(3),

                                Forms\Components\Fieldset::make('Clasificación socioeconómica')
                                    ->schema([
                                        Forms\Components\TextInput::make('grupo_poblacional')
                                            ->label('Grupo poblacional')
                                            ->maxLength(80),
                                        Forms\Components\TextInput::make('nivel_sisben')
                                            ->label('Nivel Sisbén')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('puntaje_sisben')
                                            ->label('Puntaje Sisbén')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('grupo_sisben')
                                            ->label('Grupo Sisbén')
                                            ->maxLength(20),
                                    ])
                                    ->columns(4),

                                Forms\Components\Section::make('Programas especiales y RIAS')
                                    ->schema([
                                        Forms\Components\Repeater::make('programas')
                                            ->hiddenLabel()
                                            ->schema([
                                                Forms\Components\TextInput::make('tipo')
                                                    ->label('Tipo'),
                                                Forms\Components\TextInput::make('descripcion')
                                                    ->label('Descripción'),
                                            ])
                                            ->columns(2)
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            // Los programas los define Savia, aquí solo se muestran y se guardan.
                                            ->disabled()
                                            ->dehydrated()
                                            ->default([]),
                                    ])
                                    ->collapsible()
                                    ->visible(fn (Forms\Get $get): bool => filled($get('programas'))),

                                Forms\Components\Section::make('Otros datos de Savia')
                                    ->description('Campos que devuelve el servicio y no tienen columna propia, incluidos los que la especificación V3 no documenta.')
                                    ->schema([
                                        Forms\Components\KeyValue::make('datos_adicionales')
                                            ->hiddenLabel()
                                            ->keyLabel('Campo')
                                            ->valueLabel('Valor')
                                            ->disabled()
                                            ->dehydrated()
                                            ->default([]),
                                    ])
                                    ->collapsible()
                                    ->collapsed()
                                    ->visible(fn (Forms\Get $get): bool => filled($get('datos_adicionales'))),

                                Forms\Components\Textarea::make('observacion')
                                    ->label('Observación')
                                    ->rows(3),
                            ]),

                        // Toma de datos del orientador. Cada carga queda como un soporte del
                        // paciente (ver `guardarSoporte`); el ticket se genera más adelante.
                        Forms\Components\Wizard\Step::make('Orientación')
                            ->description('Orden médica y alto costo')
                            ->icon('heroicon-o-document-arrow-up')
                            ->schema([
                                Forms\Components\Radio::make('alto_costo_oncologico')
                                    ->label('¿Paciente o medicamento de alto costo / oncológico?')
                                    ->boolean('Sí', 'No')
                                    ->inline()
                                    ->required(),
                                // Sin `capture`: en el celular el mismo botón ofrece la cámara
                                // o los archivos del dispositivo.
                                Forms\Components\FileUpload::make('orden_medica')
                                    ->label('Orden médica')
                                    ->helperText('Toma una foto con la cámara o carga la imagen o el PDF desde el dispositivo. Máximo 10 MB.')
                                    ->disk('local')
                                    ->directory('soportes/ordenes-medicas')
                                    ->visibility('private')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                    ->maxSize(10240)
                                    // Al registrar la atención es obligatoria; al editar solo se
                                    // carga si hay una orden nueva.
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn (): bool => (bool) auth()->user()?->puede('orientacion.usar')),
                    ])
                        // Guardar solo aparece en el último paso y cada «Siguiente» valida los
                        // obligatorios del paso. La vista se pinta al renderizar, así los botones
                        // de la página se evalúan después de la consulta a Savia.
                        ->submitAction(view('filament.pacientes.acciones-guardado', ['livewire' => $form->getLivewire()]))
                        // Al editar los datos ya son válidos: se puede saltar a cualquier paso.
                        ->skippable(fn (string $operation): bool => $operation === 'edit'),

                    // Trazabilidad de la última consulta; los llena el botón, no el usuario.
                    Forms\Components\Hidden::make('codigo_respuesta_savia'),
                    Forms\Components\Hidden::make('consultado_en_savia_at'),
                ])
                    ->visible(fn (string $operation, Forms\Get $get): bool => $operation !== 'create'
                        || static::hayConsultaVigente($get))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_documento')
                    ->label('Documento')
                    ->description(fn (Paciente $record): string => (string) $record->tipo_documento)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre_completo')
                    ->label('Nombre')
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::buscarPorNombre($query, $search))
                    ->sortable(['primer_apellido', 'segundo_apellido', 'primer_nombre']),
                Tables\Columns\TextColumn::make('estado_afiliacion')
                    ->label('Estado de afiliación')
                    ->badge()
                    ->color(fn (?string $state): string => Paciente::colorEstado($state))
                    ->placeholder('Sin estado')
                    ->sortable(),
                Tables\Columns\TextColumn::make('regimen')
                    ->label('Régimen')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('consultado_en_savia_at')
                    ->label('Última consulta')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('primer_apellido', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('estado_afiliacion')
                    ->label('Estado de afiliación')
                    ->options(fn (): array => static::valoresDistintos('estado_afiliacion')),
                Tables\Filters\SelectFilter::make('regimen')
                    ->label('Régimen')
                    ->options(fn (): array => static::valoresDistintos('regimen')),
            ])
            ->actions([
                Tables\Actions\Action::make('actualizarDesdeSavia')
                    ->label('Actualizar desde Savia')
                    ->icon('heroicon-m-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Actualizar desde Savia')
                    ->modalDescription('Se vuelve a consultar el servicio y los datos del paciente se reemplazan con lo que responda Savia.')
                    ->modalSubmitActionLabel('Consultar')
                    ->action(function (Paciente $record): void {
                        $resultado = static::consultarEnSavia($record->tipo_documento, $record->numero_documento);

                        if ($resultado === null) {
                            return;
                        }

                        // Solo los datos de Savia: el teléfono y la dirección
                        // confirmados con el paciente no se tocan.
                        $record->update($resultado->atributos);
                    }),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Identificación del afiliado')
                    ->schema([
                        Infolists\Components\TextEntry::make('documento_completo')
                            ->label('Documento'),
                        Infolists\Components\TextEntry::make('nombre_completo')
                            ->label('Nombre completo'),
                        Infolists\Components\TextEntry::make('fecha_nacimiento')
                            ->label('Fecha de nacimiento')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('sexo')->placeholder('—'),
                        Infolists\Components\TextEntry::make('genero_identificacion')
                            ->label('Género de identificación')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('estado_civil')
                            ->label('Estado civil')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('discapacidad')->placeholder('—'),
                        Infolists\Components\TextEntry::make('tipo_discapacidad')
                            ->label('Tipo de discapacidad')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('victima_ley_1448')
                            ->label('Víctima Ley 1448')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Estado de la afiliación')
                    ->schema([
                        Infolists\Components\TextEntry::make('estado_afiliacion')
                            ->label('Estado de afiliación')
                            ->badge()
                            ->color(fn (?string $state): string => Paciente::colorEstado($state))
                            ->placeholder('Sin estado'),
                        Infolists\Components\TextEntry::make('regimen')
                            ->label('Régimen')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('tipo_afiliado')
                            ->label('Tipo de afiliado')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('modalidad_subsidio')
                            ->label('Modalidad de subsidio')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('causa_estado')
                            ->label('Causa del estado')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('consecutivo_bdua')
                            ->label('Consecutivo BDUA')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('codigo_entidad')
                            ->label('Código de entidad')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('fecha_afiliacion_sgsss')
                            ->label('Fecha afiliación SGSSS')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('fecha_afiliacion_entidad')
                            ->label('Fecha afiliación entidad')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('fecha_suspension')
                            ->label('Fecha de suspensión')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('fecha_retiro')
                            ->label('Fecha de retiro')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Núcleo familiar')
                    ->schema([
                        Infolists\Components\TextEntry::make('tipo_documento_cabeza_familia')
                            ->label('Tipo doc. cabeza de familia')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('documento_cabeza_familia')
                            ->label('Documento cabeza de familia')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('parentesco_cabeza_familia')
                            ->label('Parentesco')
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Infolists\Components\Section::make('Clasificación socioeconómica')
                    ->schema([
                        Infolists\Components\TextEntry::make('grupo_poblacional')
                            ->label('Grupo poblacional')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('nivel_sisben')
                            ->label('Nivel Sisbén')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('puntaje_sisben')
                            ->label('Puntaje Sisbén')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('grupo_sisben')
                            ->label('Grupo Sisbén')
                            ->placeholder('—'),
                    ])
                    ->columns(4)
                    ->collapsible(),

                Infolists\Components\Section::make('Contacto confirmado con el paciente')
                    ->description('Datos de SISPAM. Las consultas a Savia no los modifican.')
                    ->icon('heroicon-o-phone')
                    ->schema([
                        Infolists\Components\TextEntry::make('telefono_movil')
                            ->label('Teléfono móvil')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('telefono')
                            ->label('Teléfono fijo o alterno')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('ciudad_residencia')
                            ->label('Ciudad o municipio')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('direccion')
                            ->label('Dirección')
                            ->placeholder('—')
                            ->columnSpan(2),
                        Infolists\Components\TextEntry::make('barrio')->placeholder('—'),
                        Infolists\Components\TextEntry::make('indicaciones_entrega')
                            ->label('Indicaciones de entrega')
                            ->placeholder('Sin indicaciones')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('contacto_confirmado_at')
                            ->label('Confirmado el')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Sin confirmar')
                            ->badge()
                            ->color(fn (?string $state): string => filled($state) ? 'success' : 'warning'),
                        Infolists\Components\TextEntry::make('contactoConfirmadoPor.nombre_completo')
                            ->label('Confirmado por')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Residencia según Savia')
                    ->schema([
                        Infolists\Components\TextEntry::make('comuna')->placeholder('—'),
                        Infolists\Components\TextEntry::make('municipio_afiliacion')
                            ->label('Municipio de afiliación')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('departamento_afiliacion')
                            ->label('Departamento de afiliación')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('email')
                            ->label('Correo electrónico')
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(),

                Infolists\Components\Section::make('IPS y portabilidad')
                    ->schema([
                        Infolists\Components\TextEntry::make('codigo_ips')
                            ->label('Código IPS')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('ips_primaria')
                            ->label('IPS primaria')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('sede_ips_primaria')
                            ->label('Sede IPS primaria')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('tipo_portabilidad')
                            ->label('Tipo de portabilidad')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Infolists\Components\Section::make('Programas especiales y RIAS')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('programas')
                            ->hiddenLabel()
                            ->schema([
                                Infolists\Components\TextEntry::make('tipo')
                                    ->label('Tipo'),
                                Infolists\Components\TextEntry::make('descripcion')
                                    ->label('Descripción'),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn (Paciente $record): bool => filled($record->programas)),

                Infolists\Components\Section::make('Otros datos de Savia')
                    ->description('Campos que devuelve el servicio y no tienen columna propia.')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('datos_adicionales')
                            ->hiddenLabel()
                            ->keyLabel('Campo')
                            ->valueLabel('Valor'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Paciente $record): bool => filled($record->datos_adicionales)),

                Infolists\Components\Section::make('Observación')
                    ->schema([
                        Infolists\Components\TextEntry::make('observacion')
                            ->hiddenLabel()
                            ->placeholder('Sin observaciones'),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Infolists\Components\Section::make('Última consulta a Savia')
                    ->schema([
                        Infolists\Components\TextEntry::make('consultado_en_savia_at')
                            ->label('Fecha de la consulta')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('Nunca'),
                        Infolists\Components\TextEntry::make('codigo_respuesta_savia')
                            ->label('Código de respuesta')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Consulta el servicio y devuelve los atributos listos para el modelo, o
     * null cuando no se pudo traer al afiliado. En cualquier caso notifica al
     * usuario en español; nunca lanza una excepción a la página.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Consulta el documento en Savia y prepara todo lo que necesita la pantalla:
     * los datos de afiliación, el contacto que reporta el servicio y el paciente
     * si ya estaba registrado. Devuelve null cuando no se pudo traer al afiliado.
     *
     * En cualquier caso avisa al usuario en español; nunca lanza una excepción
     * a la página.
     */
    public static function consultarEnSavia(
        string $tipoDocumento,
        string $numeroDocumento,
        ?AvisaSobreSavia $avisos = null,
    ): ?ResultadoConsulta {
        $cliente = app(SaviaClient::class);

        $existente = static::pacienteRegistrado($tipoDocumento, $numeroDocumento);

        if (! $cliente->configurado()) {
            Notification::make()
                ->title('La consulta a Savia no está configurada')
                ->body('Faltan las credenciales SAVIA_USERNAME y SAVIA_PASSWORD. Comunícate con un administrador.')
                ->danger()
                ->persistent()
                ->send();

            return null;
        }

        $respuesta = $cliente->consultarAfiliado([
            'tipoDocumento' => $tipoDocumento,
            'numeroDocumento' => $numeroDocumento,
        ]);

        $afiliado = $respuesta->primerAfiliado();

        if (! $respuesta->exitosa() || $afiliado === null) {
            $diagnostico = $respuesta->diagnostico();

            // El servicio marca «no encontrado» con el código -1000; también cuenta
            // una respuesta exitosa que no trajo ningún afiliado.
            $noEncontrado = $diagnostico['tono'] === 'warn'
                || ($respuesta->exitosa() && $afiliado === null);

            if ($noEncontrado && $avisos !== null) {
                // Un paciente ya registrado que Savia deja de reconocer se avisa
                // distinto: no se toca su registro.
                $avisos->mostrarAfiliadoNoEncontrado($tipoDocumento, $numeroDocumento, $existente !== null);
            } else {
                static::notificarFallo($diagnostico, $noEncontrado);
            }

            return null;
        }

        $atributos = Paciente::atributosDesdeSavia($afiliado);
        $atributos['codigo_respuesta_savia'] = $respuesta->codigo();
        $atributos['consultado_en_savia_at'] = now();

        $estado = $atributos['estado_afiliacion'] ?? null;

        if (Paciente::estadoEsActivo($estado)) {
            Notification::make()
                ->title($existente !== null
                    ? 'Afiliado encontrado: ya está registrado en SISPAM'
                    : 'Afiliado encontrado en Savia')
                ->body($existente !== null
                    ? 'Al guardar se actualiza el paciente que ya existe. Confirma el teléfono y la dirección.'
                    : 'Confirma el teléfono y la dirección con el paciente antes de guardar.')
                ->success()
                ->send();
        } elseif ($avisos !== null) {
            $avisos->mostrarAfiliadoInactivo($estado, $atributos['causa_estado'] ?? null);
        } else {
            Notification::make()
                ->title('El afiliado no está activo en Savia')
                ->body('Savia reporta el estado «'.($estado ?: 'sin estado').'». Verifícalo antes de dispensar.')
                ->warning()
                ->persistent()
                ->send();
        }

        return new ResultadoConsulta(
            atributos: $atributos,
            contactoSavia: Paciente::contactoDesdeSavia($afiliado),
            existente: $existente,
        );
    }

    /**
     * El paciente que ya está en SISPAM con ese documento, si lo hay.
     */
    public static function pacienteRegistrado(?string $tipoDocumento, ?string $numeroDocumento): ?Paciente
    {
        if (blank($tipoDocumento) || blank($numeroDocumento)) {
            return null;
        }

        return Paciente::query()
            ->where('tipo_documento', strtoupper(trim($tipoDocumento)))
            ->where('numero_documento', trim($numeroDocumento))
            ->first();
    }

    /**
     * @param  array{tono: string, titulo: string, detalle: string}  $diagnostico
     */
    private static function notificarFallo(array $diagnostico, bool $noEncontrado): void
    {
        $esAdvertencia = $noEncontrado;

        $notificacion = Notification::make()
            ->title($esAdvertencia
                ? 'El afiliado no aparece en Savia'
                : 'No se pudo consultar en Savia')
            ->body($diagnostico['titulo'].' '.$diagnostico['detalle'])
            ->persistent();

        ($esAdvertencia ? $notificacion->warning() : $notificacion->danger())->send();
    }

    /* ------------------------------------------------------------------ *
     *  Control de la consulta: los datos mostrados siempre corresponden
     *  al documento que está escrito en el formulario.
     * ------------------------------------------------------------------ */

    /**
     * Saca del formulario la toma de datos del orientador (no son columnas del
     * paciente) y la devuelve lista para crear el soporte, o null si no hay orden.
     *
     * @return array<string, mixed>|null
     */
    public static function separarSoporte(array &$data): ?array
    {
        $orden = $data['orden_medica'] ?? null;
        $altoCosto = (bool) ($data['alto_costo_oncologico'] ?? false);
        unset($data['orden_medica'], $data['alto_costo_oncologico']);

        return blank($orden) ? null : [
            'orden_medica' => $orden,
            'alto_costo_oncologico' => $altoCosto,
            'cargado_por' => auth()->id(),
        ];
    }

    /**
     * Marca con la que se identifica de qué documento son los datos cargados.
     */
    public static function claveDocumento(?string $tipoDocumento, ?string $numeroDocumento): string
    {
        return strtoupper(trim((string) $tipoDocumento)).'|'.trim((string) $numeroDocumento);
    }

    /**
     * Si los datos que hay en el formulario vienen de consultar el documento
     * que está escrito ahora mismo.
     */
    public static function hayConsultaVigente(Forms\Get $get): bool
    {
        $marca = (string) $get('documento_consultado');

        return $marca !== ''
            && $marca === static::claveDocumento($get('tipo_documento'), $get('numero_documento'));
    }

    /**
     * Al cambiar el tipo o el número hay que volver a consultar: los datos de
     * la consulta anterior se descartan para que nunca se vean junto a un
     * documento que no les corresponde.
     */
    public static function olvidarConsultaSiCambioElDocumento(string $operation, Forms\Get $get, Forms\Set $set): void
    {
        // Solo al crear: en «editar» lo que hay en el formulario son los datos
        // del paciente guardado, no el resultado de una consulta.
        if ($operation !== 'create') {
            return;
        }

        if (! static::hayConsultaVigente($get)) {
            static::limpiarDatosSavia($set);
        }
    }

    /**
     * Vacía todos los campos que llena la consulta, menos el documento que se
     * está buscando.
     */
    public static function limpiarDatosSavia(Forms\Set $set): void
    {
        foreach (static::columnasQueLlenaSavia() as $columna) {
            $set($columna, null);
        }

        // El contacto se vuelve a precargar con la siguiente consulta.
        foreach (Paciente::CAMPOS_CONTACTO as $columna) {
            $set($columna, null);
        }

        foreach (Paciente::CONTACTO_SAVIA as $columna) {
            $set("savia_{$columna}", null);
        }

        $set('programas', []);
        $set('datos_adicionales', []);
        $set('documento_consultado', null);
        $set('paciente_existente_id', null);
        $set('cambios_savia', []);
        $set('contacto_confirmado', false);
    }

    /**
     * Lo que Savia reporta para un campo de contacto, cuando difiere de lo que
     * hay escrito. Si coincide no se muestra nada: no aporta.
     */
    public static function referenciaSavia(Forms\Get $get, string $columna): ?string
    {
        $reportado = trim((string) $get("savia_{$columna}"));
        $actual = trim((string) $get($columna));

        return ($reportado !== '' && $reportado !== $actual)
            ? "Savia reporta: {$reportado}"
            : null;
    }

    /**
     * La lista de «antes → ahora» que se muestra antes de guardar.
     *
     * @param  array<string, string>  $cambios
     */
    public static function listaDeCambios(array $cambios): HtmlString
    {
        $filas = '';

        foreach ($cambios as $etiqueta => $cambio) {
            $filas .= '<li><span class="font-semibold">'.e($etiqueta).':</span> '.e($cambio).'</li>';
        }

        return new HtmlString('<ul class="list-disc space-y-1 ps-5 text-sm">'.$filas.'</ul>');
    }

    /**
     * @return list<string>
     */
    private static function columnasQueLlenaSavia(): array
    {
        return array_merge(
            array_values(array_diff(
                Paciente::CAMPOS_SAVIA,
                ['tipo_documento', 'numero_documento'],
            )),
            ['codigo_respuesta_savia', 'consultado_en_savia_at'],
        );
    }

    /**
     * Busca por cualquiera de los cuatro campos del nombre, término por término.
     */
    private static function buscarPorNombre(Builder $query, string $search): Builder
    {
        $terminos = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terminos as $termino) {
            $query->where(function (Builder $q) use ($termino): void {
                foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $columna) {
                    $q->orWhere($columna, 'like', "%{$termino}%");
                }
            });
        }

        return $query;
    }

    /**
     * Opciones del filtro, tomadas de lo que realmente hay guardado.
     *
     * @return array<string, string>
     */
    private static function valoresDistintos(string $columna): array
    {
        return Paciente::query()
            ->whereNotNull($columna)
            ->where($columna, '!=', '')
            ->distinct()
            ->orderBy($columna)
            ->pluck($columna, $columna)
            ->all();
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->nombre_completo;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['numero_documento', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPacientes::route('/'),
            'create' => Pages\CreatePaciente::route('/create'),
            'view' => Pages\ViewPaciente::route('/{record}'),
            'edit' => Pages\EditPaciente::route('/{record}/edit'),
        ];
    }
}
