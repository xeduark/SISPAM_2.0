<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Authentik\AprovisionarEnAuthentik;
use App\Services\Authentik\ErrorDeAuthentik;
use App\Services\Authentik\ResultadoAprovisionamiento;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Lo que pasó en Authentik; solo vive en esta petición. */
    private ?ResultadoAprovisionamiento $authentik = null;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::accionCambiarClave(Actions\Action::class),
            Actions\DeleteAction::make()
                ->hidden(fn (): bool => UserResource::esUsuarioActual($this->getRecord())),
        ];
    }

    /**
     * Solo se va a Authentik si cambiaron los roles o el documento (su
     * usuario allá). Desactivar a alguien, o corregirle el nombre, no depende
     * de que Authentik responda: bloquear a una persona tiene que funcionar
     * siempre.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $documentoAnterior = $record->documento;
        $rolesAntes = AprovisionarEnAuthentik::rolesDeSispam($record->roles);

        try {
            return DB::transaction(function () use ($record, $data, $documentoAnterior, $rolesAntes): Model {
                $record = parent::handleRecordUpdate($record, $data);

                $rolesAhora = AprovisionarEnAuthentik::rolesDeSispam($record->roles);
                sort($rolesAntes);
                sort($rolesAhora);

                if ($rolesAhora !== $rolesAntes) {
                    $this->authentik = app(AprovisionarEnAuthentik::class)
                        ->asegurar($record, $documentoAnterior, AprovisionarEnAuthentik::ROLES_EXACTOS);
                } elseif ($record->documento !== $documentoAnterior) {
                    // Solo se renombra su cuenta: sus grupos allá no se tocan.
                    $this->authentik = app(AprovisionarEnAuthentik::class)
                        ->asegurar($record, $documentoAnterior, AprovisionarEnAuthentik::ROLES_NO_TOCAR);
                }

                return $record;
            });
        } catch (ErrorDeAuthentik $e) {
            // La base volvió atrás; el modelo en memoria también tiene que
            // volver, o el siguiente intento creería que no cambió nada.
            $record->refresh();

            Notification::make()
                ->danger()
                ->persistent()
                ->title('No se guardaron los cambios')
                ->body($e->getMessage().' El usuario quedó como estaba.')
                ->send();

            $this->halt();
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        return $this->authentik?->notificacion($this->getRecord()) ?? parent::getSavedNotification();
    }
}
