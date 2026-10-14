<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Authentik\AprovisionarEnAuthentik;
use App\Services\Authentik\ErrorDeAuthentik;
use App\Services\Authentik\ResultadoAprovisionamiento;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Lo que pasó en Authentik; solo vive en esta petición. */
    private ?ResultadoAprovisionamiento $authentik = null;

    /**
     * Se guarda en SISPAM y se le crea la cuenta en Authentik, con la
     * contraseña que se escribió, para que pueda entrar. Si Authentik no la
     * acepta, no se guarda nada: un usuario que existe en SISPAM pero no puede
     * entrar es peor que un error que se ve.
     */
    protected function handleRecordCreation(array $data): Model
    {
        // La contraseña va para Authentik, nunca a la tabla `users`.
        $clave = Arr::pull($data, 'clave_authentik');

        try {
            return DB::transaction(function () use ($data, $clave): Model {
                $usuario = parent::handleRecordCreation($data);
                // Si ya tenía cuenta allá, los roles elegidos se suman a los suyos.
                $this->authentik = app(AprovisionarEnAuthentik::class)
                    ->asegurar($usuario, roles: AprovisionarEnAuthentik::ROLES_AGREGAR, clave: filled($clave) ? $clave : null);

                return $usuario;
            });
        } catch (ErrorDeAuthentik $e) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title('No se creó el usuario')
                ->body($e->getMessage().' No se guardó nada en SISPAM.')
                ->send();

            $this->halt();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->authentik?->notificacion($this->getRecord()) ?? parent::getCreatedNotification();
    }
}
