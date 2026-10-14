<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\FileUpload;

/**
 * Un `FileUpload` cuyo editor de imágenes **solo gira**.
 *
 * El editor de Filament (Cropper.js) trae recorte, zoom, mover, voltear y los
 * campos de posición y tamaño. Para la foto de una fórmula sobra todo menos
 * girar: el orientador solo necesita enderezar la hoja que salió acostada.
 *
 * Y el recorte no solo sobraba, **dañaba la foto**: el recuadro nace del
 * tamaño de la imagen y no se gira con ella, así que al girar una foto
 * apaisada se guardaba solo la franja que quedaba dentro del recuadro y se
 * perdía media hoja. Sin recuadro, Cropper guarda la imagen completa.
 *
 * Tres piezas:
 *
 * 1. Los botones: solo los de girar (`getImageEditorActions()`), y después de
 *    cada giro la hoja se vuelve a encajar en el visor para verla completa.
 * 2. Al cargar cada imagen (`ready` de Cropper, que sube hasta el componente)
 *    se quita el recuadro y se apagan el arrastre y el zoom. Esas opciones las
 *    lee Cropper en cada gesto, así que cambiarlas ya creado el editor vale.
 * 3. Los campos de posición, tamaño y rotación se esconden con CSS
 *    (`.sispam-solo-giro` en `sispam-tema.css`). Se esconden y no se quitan:
 *    el JS de Filament les escribe en cada cambio y fallaría sin ellos.
 */
class FileUploadSoloGiro extends FileUpload
{
    /**
     * Sin recuadro de recorte, sin arrastre y sin zoom. Filament lo escapa
     * para el atributo HTML y el navegador lo devuelve tal cual.
     */
    private const AL_CARGAR_LA_IMAGEN = "editor.clear(); editor.setDragMode('none'); "
        .'Object.assign(editor.options, { zoomable: false, zoomOnTouch: false, zoomOnWheel: false })';

    /**
     * Encaja la hoja girada en el visor, centrada. Sin esto, una foto vertical
     * girada queda más ancha que la pantalla del celular y se ve cortada
     * (solo en pantalla: lo que se guarda es la imagen completa).
     */
    private const ENCAJAR = '(() => { const c = editor.getContainerData(), d = editor.getCanvasData(), '
        .'e = Math.min(c.width / d.width, c.height / d.height), w = d.width * e; '
        .'editor.setCanvasData({ left: (c.width - w) / 2, top: (c.height - d.height * e) / 2, width: w }) })()';

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageEditor();

        $this->extraAttributes(['class' => 'sispam-solo-giro'], merge: true);

        $this->extraAlpineAttributes(['x-on:ready' => self::AL_CARGAR_LA_IMAGEN], merge: true);
    }

    /**
     * Solo girar a la izquierda y a la derecha, con las etiquetas e íconos de
     * Filament.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function getImageEditorActions(string $iconSizeClasses): array
    {
        $giros = array_filter(
            parent::getImageEditorActions($iconSizeClasses)['transform'],
            fn (array $accion): bool => str_starts_with($accion['alpineClickHandler'], 'editor.rotate('),
        );

        return [
            'girar' => array_values(array_map(
                fn (array $accion): array => [
                    ...$accion,
                    'alpineClickHandler' => $accion['alpineClickHandler'].'; '.self::ENCAJAR,
                ],
                $giros,
            )),
        ];
    }
}
