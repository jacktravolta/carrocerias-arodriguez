# Mejoras feedback ronda 1 — Carrocerías Arodríguez SpA

Branch: `fix/feedback-ronda-1` (desde `main` @ bdb5bdb)
Fecha: 2026-10-24 · Estado: COMPLETADA y publicada.

Nota del cliente (2026-10-24): los cambios de redes sociales son en la sección
CONTACTO; puestos los iconos y arreglado el texto, QUITAR los botones CONTACTAR /
VISITAR INSTAGRAM ("ya no tienen sentido"). Confirmado en T2.

## Objetivo
Aplicar la primera ronda de feedback del cliente sobre las 3 propuestas de landing,
el index selector y la política de privacidad.

## Alcance autorizado
- Edición de HTML/CSS en `propuesta-1-clara/`, `propuesta-2-oscura/`, `propuesta-3-hibrida/`,
  `index.html` (selector) y `politica-de-privacidad.html`.
- Reemplazo de imágenes de logo (mismo nombre de archivo, fondo transparente).
- NO incluye: PR, merge, cambios a `enviar.php` ni estructura de carpetas.

## Datos canónicos
- Dirección (texto visible): `Av. Lonquén 12180, Maipú, San Bernardo Parcela 48, Pje. América, Galpón "T", 9250000 Maipú, Región Metropolitana`
- Query Maps (URL-encoded): `Av.%20Lonqu%C3%A9n%2012180%2C%20Maip%C3%BA%2C%20San%20Bernardo%20Parcela%2048%2C%20Pje.%20Am%C3%A9rica%2C%20Galp%C3%B3n%20T%2C%209250000%20Maip%C3%BA%2C%20Regi%C3%B3n%20Metropolitana`
- Embed: `https://www.google.com/maps?q=<query>&z=16&output=embed`
- Link: `https://www.google.com/maps/search/?api=1&query=<query>`
- Facebook: `https://web.facebook.com/p/Carrocer%C3%ADas-ARodriguez-SPA-61579596361830/?_rdc=1&_rdr`
- Instagram: `https://www.instagram.com/arodriguez_spa/`
- WhatsApp: `https://wa.me/56968476557`

## Tareas (todas cerradas)
- [x] **T1 [P1]** Botones CTA del hero se apilan en móvil: `btn-row` + `btn-secundario`,
      media ≤640px apila y centra. Commit `813ca12`.
- [x] **T2 [P1,P2,P3]** En CONTACTO: línea "Instagram:" fuera de la lista, botones
      CONTACTAR/VISITAR INSTAGRAM eliminados, card `.social-card` con iconos SVG
      monocromos FB/IG/WA (claros `#111`, P2 `#fff`; hover/focus/active `#E30613`).
      Botón flotante de WhatsApp conservado. Commits `813ca12`, `4cf72cc`, `9a4ff47`.
- [x] **T3 [P1,P2,P3,privacidad]** Mapa Google siempre activo: `mapa-box`+botón →
      `mapa-line` + `<iframe class="mapa">` (embed z=16, dirección canónica); JS de
      carga por clic eliminado; `Ubicación:` con dirección completa; `politica-de-privacidad.html`
      actualizada (domicilio completo + aviso de que el mapa carga con la página).
      Commits `813ca12`, `4cf72cc`, `9a4ff47`, `03cfe34`.
- [x] **T4 [todas]** Logo transparente por des-matizado del blanco (alpha 255 si
      `min(r,g,b)<=60`; 0 si `min>=250`; rampa un-blend intermedia). Replicado en 4
      rutas (`md5 0fdea4da…`). P2 usa variante `logo-blanco.png` (negro→blanco) y sin
      `.logo-chip` para legibilidad sobre tema oscuro. `logo.webp` sin referencias: intacto.
      Commits `4cf72cc`, `a09c5fa`.
- [x] **T5 [P3]** Hero menos difuminado: `hero-blur.webp` → `hero.webp` (velo
      `rgba(17,17,17,.72)` conservado). Commit `9a4ff47`.
- [x] **T6 [index selector]** Textos de intro y 3 tarjetas reescritos. Commit `03cfe34`.
- [x] **T7 [P1,P2,P3]** Tipografía homologada: `h1 42/1.15 · h2 28/1.2 · h3 18/1.3 ·
      p 16/1.55`, móvil ≤640px `h1 30 / h2 22`; overrides duplicados eliminados.
      Commits `813ca12`, `4cf72cc`, `9a4ff47`.

## Ruta / topología
- T1, T2, T3, T5, T6, T7 → delegated writer (1 writer `general`, 8 archivos, 53 reemplazos).
- T4 → inline (script PIL + verificación visual).
- Trigger writer: 2+ archivos no triviales. ~173 líneas cambiadas (<400, sin chain PR).

## Verificación
- Writer: 8 greps en verde + `git diff --stat` (12 archivos, 71+/102-).
- Orquestador (independiente): greps ×8 re-ejecutados en verde; balance HTML OK ×5;
  llaves CSS OK ×3; diff del selector leído y conforme; spot-check grep mapa/botones ×2.
- **No ejecutable (honrado)**: render visual en navegador — no hay browser de escritorio
  conectado en esta sesión. Queda pendiente una revisión visual humana en el preview.
- RDD: off (global) → sin ciclo de review nativo; `review assess` inicial devolvió
  `high/unassessable` solo por archivos sin trackear (resuelto al commitear).

## Evidencia / publicación
- Commits (rama `fix/feedback-ronda-1`): `813ca12` P1 · `4cf72cc` P2 · `9a4ff47` P3 ·
  `a09c5fa` logo transparente · `03cfe34` selector+privacidad · `f9ae250` este documento.
- Publicación (a solicitud del cliente: "publica git"): push de la rama y fast-forward
  de `main` (historial lineal, sin merge commit). Ver hashes en `git log`.
