# Copilot Instructions

## Reutilizacion de utilidades
- Antes de crear funciones de apoyo (fechas, texto, sanitizacion, formateo), revisar y reutilizar `scripts/php/utils.php`.
- Si hace falta una nueva utilidad transversal, agregarla en `scripts/php/utils.php` en lugar de duplicarla en templates o endpoints.
- Mantener las plantillas HTML/PHP enfocadas en renderizado y delegar transformaciones de texto/fecha a utilidades reutilizables.
