# Bomedia Quote Wizard

Asistente multi-paso de WordPress para solicitar presupuesto de máquinas (impresoras UV-LED y láseres). Brand-agnostic y multi-sitio: la misma instalación sirve para boprint.net, bomedia.net, artisjet-printers.eu y mboprinters.com configurando todo desde el panel.

## Requisitos

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 8.x activo

## Instalación

1. Sube la carpeta del plugin a `wp-content/plugins/bomedia-quote-wizard/` (o instala el `.zip` desde Plugins → Subir plugin).
2. Activa **Bomedia Quote Wizard** desde la pantalla de Plugins.
3. Asegúrate de que WooCommerce está activo.
4. Ve a **Ajustes → Bomedia Quote Wizard** para configurar las tres pestañas.

## Configuración

### AgileCRM

| Campo | Descripción |
| --- | --- |
| Domain | Subdominio de tu cuenta. Ej: `bomedia` para `bomedia.agilecrm.com`. |
| Email | Email de la cuenta. |
| REST API Key | Clave REST. Se almacena cifrada con AES-256-CBC usando una clave derivada de `AUTH_KEY`. |
| Default tags | Tags por defecto (separados por comas) que se aplican a cada lead. Ej: `web-lead, boprint`. |

Pulsa **Test connection** para validar las credenciales contra `/dev/api/users/current-user`.

### Wizard

- **Categorías Woo a incluir**: multi-select de las categorías WooCommerce existentes.
- **Pasos opcionales**: aplicación / materiales / volumen mensual con sus opciones (una por línea).
- **Idioma**: por defecto autodetecta el idioma del sitio.
- **Privacy URL**: enlace a tu política de privacidad.

### Notificaciones

- **Recipient emails**: separados por coma.
- **Subject**: admite placeholders `{nombre}`, `{empresa}`, `{producto}`.
- **File logging**: escribe a `wp-content/uploads/bqw-logs/`.

## Uso

Inserta el shortcode en cualquier página o entrada:

```
[bomedia_quote_wizard]
```

Atributos opcionales:

| Atributo | Descripción |
| --- | --- |
| `category="artisjet"` | Fuerza una categoría concreta (slug del `product_cat`). |
| `product_id="123"` | Preselecciona un producto y salta directo a contacto. |

## Auditoría

Cada envío crea una entrada en **wp-admin → Quote Leads** con:

- Datos completos del formulario
- IP, user agent y timestamp
- Estado: `sent`, `failed`, `pending_retry`
- ID de AgileCRM (si éxito)

Si AgileCRM falla (timeout u otro error), el lead queda como `failed` y puedes reintentar el envío desde la lista (acción "Retry send" en cada fila).

## Override de templates

Copia los archivos a tu tema para personalizarlos sin tocar el plugin:

```
wp-content/themes/<tu-tema>/bomedia-quote-wizard/wizard.php
wp-content/themes/<tu-tema>/bomedia-quote-wizard/thanks.php
```

## Personalización de estilos

El plugin expone una variable CSS `--bqw-primary` que puedes sobrescribir en tu tema:

```css
.bqw-wizard {
	--bqw-primary: #ff5722;
	--bqw-primary-dark: #c4391a;
}
```

## Internacionalización

Text domain: `bomedia-quote-wizard`. El archivo `.pot` se encuentra en `languages/bomedia-quote-wizard.pot`.

## Desarrollo

- Sin dependencias de Composer en runtime.
- Vanilla JS en frontend (sin React, sin jQuery).
- Namespace PHP: `Bomedia\QuoteWizard\`.

### Empaquetado para release

Cada tag `v*` en GitHub dispara la action `.github/workflows/release.yml`, que genera y adjunta `bomedia-quote-wizard.zip` al release.

## Licencia

GPL-2.0-or-later.
