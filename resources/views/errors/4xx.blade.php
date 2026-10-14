@include('errors.plantilla', [
    'codigo' => $exception->getStatusCode(),
    'titulo' => 'No se pudo completar la solicitud',
    'mensaje' => 'Vuelve al inicio e inténtalo de nuevo.',
])
