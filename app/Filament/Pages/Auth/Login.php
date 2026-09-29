<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Login del panel autenticando por documento de identidad en lugar de email.
 */
class Login extends BaseLogin
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getDocumentoFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getDocumentoFormComponent(): Component
    {
        return TextInput::make('documento')
            ->label('Documento de identidad')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'documento' => $data['documento'],
            'password' => $data['password'],
        ];
    }

    /**
     * La clase base asocia el error al campo `data.email`, que ya no existe en este formulario.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.documento' => __('auth.failed'),
        ]);
    }
}
