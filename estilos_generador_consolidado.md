# Guía de estilos --- Generador de Consolidado

## Comité de Estudios Médicos

Documento de referencia de los estilos visuales utilizados actualmente
en el sistema Laravel.

------------------------------------------------------------------------

## 1. Identidad visual

El sistema utiliza una identidad institucional sobria, limpia y
profesional.

Elementos principales:

-   Comité de Estudios Médicos
-   Generador de Consolidado
-   Conversión de archivos JSON a Excel
-   Diseño administrativo y minimalista
-   Bordes suaves
-   Tarjetas con esquinas redondeadas
-   Sombras discretas
-   Tipografía sans-serif
-   Tema claro y tema oscuro

El encabezado institucional sigue esta estructura:

``` text
COMITÉ DE
ESTUDIOS
MÉDICOS

│

Generador de Consolidado
Conversión de archivos JSON a Excel
```

En las páginas administrativas el subtítulo se adapta a la sección:

``` text
Generador de Consolidado
Registro de actividad
```

------------------------------------------------------------------------

## 2. Tipografía

Familia principal:

``` css
font-family: Arial, Helvetica, sans-serif;
```

Tamaños utilizados:

  Elemento                  Tamaño
  -------------------- -----------
  Título principal           32 px
  Título de sección      25--32 px
  Título de tarjeta          23 px
  Texto principal        16--17 px
  Texto secundario       14--16 px
  Etiquetas de tabla         12 px
  Texto auxiliar         12--13 px

------------------------------------------------------------------------

## 3. Tema claro

Variables principales:

``` css
:root {
    --bg: #f3f5f7;
    --card: #ffffff;
    --card-soft: #f8fafb;

    --border: #dfe4e8;

    --text: #1f2933;
    --muted: #66727d;

    --primary: #1f4e79;
    --primary-hover: #173b5c;

    --shadow: 0 8px 25px rgba(0, 0, 0, 0.08);

    --success: #198754;
    --warning: #b7791f;
    --danger: #c53030;
}
```

Características:

-   Fondo gris muy claro.
-   Tarjetas blancas.
-   Texto oscuro.
-   Texto secundario gris.
-   Bordes gris claro.
-   Azul institucional para acciones principales.
-   Sombras suaves.

------------------------------------------------------------------------

## 4. Tema oscuro

Variables:

``` css
html[data-theme="dark"] {
    --bg: #101417;
    --card: #171d21;
    --card-soft: #1d252a;

    --border: #303a40;

    --text: #edf2f4;
    --muted: #aab5bc;

    --primary: #4d8ac4;
    --primary-hover: #619bd0;

    --shadow: 0 8px 25px rgba(0, 0, 0, 0.28);

    --success: #48bb78;
    --warning: #ecc94b;
    --danger: #fc8181;
}
```

Características:

-   Fondo oscuro.
-   Tarjetas ligeramente más claras.
-   Texto claro.
-   Bordes gris oscuro.
-   Azul luminoso para conservar el contraste.
-   Sombras más marcadas.

------------------------------------------------------------------------

## 5. Sistema global de tema

Archivo:

``` text
public/js/theme.js
```

La preferencia se guarda en `localStorage` usando:

``` javascript
json-excel-theme
```

Valores:

``` text
dark
light
```

El botón de cambio de tema utiliza:

``` html
data-theme-toggle
```

Funcionamiento:

``` text
Tema oscuro
    ↓
clic
    ↓
Tema claro
    ↓
clic
    ↓
Tema oscuro
```

La selección se conserva al navegar entre las páginas.

------------------------------------------------------------------------

## 6. Encabezado institucional

El encabezado utiliza:

``` css
.header {
    min-height: 115px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 30px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
}
```

Marca institucional:

``` css
.brand {
    font-size: 21px;
    line-height: 1.15;
    letter-spacing: 5px;
    font-weight: 500;
    color: var(--text);
    white-space: nowrap;
}
```

Separador:

``` css
.brand-divider {
    width: 1px;
    height: 58px;
    background: var(--border);
}
```

Título:

``` css
.brand-text h1 {
    margin: 0 0 5px 0;
    font-size: 32px;
    line-height: 1.1;
    font-weight: 700;
}
```

Subtítulo:

``` css
.brand-text p {
    margin: 0;
    color: var(--muted);
    font-size: 16px;
}
```

------------------------------------------------------------------------

## 7. Usuario y acciones

La zona derecha del encabezado contiene:

-   Nombre del usuario
-   Rol
-   Botón de tema
-   Cerrar sesión

Ejemplo:

``` text
Administrador
Administrador

[ 🌙 ] [ Cerrar sesión ]
```

Nombre:

``` css
.user-name {
    font-weight: 700;
    font-size: 14px;
}
```

Rol:

``` css
.user-role {
    color: var(--muted);
    font-size: 12px;
    margin-top: 3px;
}
```

------------------------------------------------------------------------

## 8. Botones

Características generales:

-   Bordes redondeados
-   Texto blanco en botones de acción
-   Peso seminegrita/negrita
-   Efecto hover
-   Transiciones suaves

### Verde --- Conversor

``` css
background: #12a84f;
```

Hover:

``` css
background: #0e9344;
```

### Morado --- Administración

``` css
background: #7c3aed;
```

Hover:

``` css
background: #6d28d9;
```

### Azul --- Registro de actividad

``` css
background: #2563eb;
```

Hover:

``` css
background: #1d4ed8;
```

### Azul institucional

``` css
background: var(--primary);
```

------------------------------------------------------------------------

## 9. Tarjetas del Dashboard

``` css
.main-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 30px;
    min-height: 265px;
    box-shadow: var(--shadow);
}
```

Características:

-   Fondo adaptable al tema
-   Borde fino
-   Radio de 16 px
-   Sombra
-   Espaciado amplio
-   Elevación ligera al pasar el cursor

Hover:

``` css
transform: translateY(-2px);
```

------------------------------------------------------------------------

## 10. Colores de las tarjetas

### Conversor

``` text
Verde
```

### Administración

``` text
Morado
```

### Registro de actividad

``` text
Azul
```

Los iconos utilizan fondos suaves relacionados con el color de cada
función.

------------------------------------------------------------------------

## 11. Distribución

Las tarjetas principales utilizan CSS Grid:

``` css
.main-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 28px;
}
```

En pantallas pequeñas:

``` css
.main-grid {
    grid-template-columns: 1fr;
}
```

------------------------------------------------------------------------

## 12. Resumen administrativo

El Dashboard muestra:

-   Total de usuarios
-   Usuarios activos
-   Usuarios inactivos
-   Administradores

Distribución:

``` css
.stats-grid {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));
    gap: 20px;
}
```

En pantallas medianas se reduce a dos columnas y en teléfonos a una.

------------------------------------------------------------------------

## 13. Registro de actividad

Vista:

``` text
resources/views/activity_logs/index.blade.php
```

La tabla se encuentra dentro de una tarjeta:

``` css
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
```

La tabla:

``` css
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}
```

El ancho mínimo permite desplazamiento horizontal en pantallas pequeñas.

------------------------------------------------------------------------

## 14. Encabezados de tabla

``` css
th {
    text-align: left;
    padding: 14px 18px;
    background: var(--card-soft);
    color: var(--muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    border-bottom: 1px solid var(--border);
}
```

Columnas:

``` text
FECHA
USUARIO
ACCIÓN
DESCRIPCIÓN
```

------------------------------------------------------------------------

## 15. Insignias de acciones

``` css
.action-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: var(--card-soft);
    border: 1px solid var(--border);
}
```

Acciones contempladas:

``` text
🔐 Login
🚪 Cierre de sesión
📊 Conversión
👤 Usuario creado
✏️ Usuario actualizado
🔄 Estado cambiado
🗑️ Usuario eliminado
```

------------------------------------------------------------------------

## 16. Detalle de actividad

Vista:

``` text
resources/views/activity_logs/show.blade.php
```

Mantiene la misma identidad visual del listado.

Puede mostrar:

-   Usuario
-   Correo electrónico
-   Fecha y hora
-   Dirección IP
-   Navegador/dispositivo
-   Descripción
-   Tipo de actividad
-   Metadatos de la conversión

La navegación utiliza:

``` text
← Registro de actividad
```

y:

``` text
Ver detalles →
```

------------------------------------------------------------------------

## 17. Bordes y radios

Borde estándar:

``` css
1px solid var(--border);
```

Radios utilizados:

``` text
7 px
8 px
9 px
12 px
14 px
16 px
999 px
```

El radio de `999px` se utiliza para insignias completamente redondeadas.

------------------------------------------------------------------------

## 18. Sombras

Tema claro:

``` css
0 8px 25px rgba(0, 0, 0, 0.08);
```

Tema oscuro:

``` css
0 8px 25px rgba(0, 0, 0, 0.28);
```

------------------------------------------------------------------------

## 19. Transiciones

Los elementos interactivos utilizan transiciones de aproximadamente
0.2--0.25 segundos:

``` css
transition:
    background-color 0.25s ease,
    border-color 0.25s ease,
    transform 0.2s ease;
```

Se aplican principalmente a:

-   Botones
-   Tarjetas
-   Cambio de tema
-   Bordes
-   Colores

------------------------------------------------------------------------

## 20. Diseño responsive

El sistema contempla:

### Pantallas medianas

Aproximadamente:

``` text
900 px
```

Se reorganizan elementos del encabezado y las cuadrículas.

### Pantallas pequeñas

Aproximadamente:

``` text
800 px
```

Se ajustan:

-   Encabezado
-   Títulos
-   Información del usuario
-   Márgenes
-   Tabla

### Teléfonos

Aproximadamente:

``` text
600 px
```

Se reducen elementos secundarios y las tarjetas pasan a una sola
columna.

------------------------------------------------------------------------

## 21. Paleta rápida

  Uso                       Color
  ------------------------- -----------
  Azul institucional        `#1f4e79`
  Azul hover                `#173b5c`
  Verde                     `#12a84f`
  Verde hover               `#0e9344`
  Morado                    `#7c3aed`
  Morado hover              `#6d28d9`
  Azul actividad            `#2563eb`
  Azul actividad hover      `#1d4ed8`
  Fondo claro               `#f3f5f7`
  Tarjeta clara             `#ffffff`
  Fondo oscuro              `#101417`
  Tarjeta oscura            `#171d21`
  Texto claro               `#1f2933`
  Texto oscuro              `#edf2f4`
  Texto secundario claro    `#66727d`
  Texto secundario oscuro   `#aab5bc`

------------------------------------------------------------------------

## 22. Archivos relacionados

Los principales archivos de estilos y presentación son:

``` text
public/js/theme.js

resources/views/dashboard.blade.php

resources/views/activity_logs/index.blade.php

resources/views/activity_logs/show.blade.php
```

El cambio de tema está centralizado en:

``` text
public/js/theme.js
```

Las vistas contienen sus estilos CSS directamente en bloques:

``` html
<style>
    ...
</style>
```

------------------------------------------------------------------------

## 23. Principios de diseño

El sistema sigue estos criterios:

1.  Consistencia visual entre todas las páginas.
2.  Identidad institucional.
3.  Jerarquía clara de información.
4.  Diseño limpio y profesional.
5.  Separación visual entre funciones.
6.  Adaptación a diferentes tamaños de pantalla.
7.  Soporte para tema claro y oscuro.
8.  Contraste suficiente para facilitar la lectura.
9.  Acciones administrativas claramente diferenciadas.
10. Navegación sencilla entre las diferentes secciones.

------------------------------------------------------------------------

## 24. Estructura visual general

``` text
┌─────────────────────────────────────────────────────────────┐
│ COMITÉ DE ESTUDIOS MÉDICOS │ Generador de Consolidado      │
│                             │ Sección actual                │
│                                           Usuario  [Tema]   │
│                                                    [Salir]  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Título de la sección                         [← Volver]    │
│  Descripción                                                 │
│                                                             │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ Contenido principal                                   │  │
│  │                                                       │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

------------------------------------------------------------------------

## 25. Mantenimiento futuro

Para conservar la identidad visual del sistema se recomienda mantener:

-   La paleta institucional.
-   El encabezado actual.
-   El sistema de tema claro/oscuro.
-   La jerarquía tipográfica.
-   Los radios de borde.
-   El sistema de sombras.
-   La estructura responsive.
-   Los colores asignados a cada módulo.

Las nuevas páginas deberían reutilizar estas mismas variables y
componentes visuales.

------------------------------------------------------------------------

**Proyecto:** Generador de Consolidado\
**Institución:** Comité de Estudios Médicos\
**Tecnología:** Laravel / Blade / CSS / JavaScript\
**Documento:** Guía de estilos visuales
