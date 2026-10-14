@include('errors.plantilla', [
    'codigo' => $exception->getStatusCode(),
    'titulo' => 'Algo falló en SISPAM',
    'mensaje' => 'El error quedó registrado. Vuelve a intentarlo; si sigue pasando, avisa a un administrador con la hora que aparece aquí.',
])
