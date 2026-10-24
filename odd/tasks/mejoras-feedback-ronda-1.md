# Mejoras feedback ronda 1 — Carrocerías Arodríguez SpA

Branch: `fix/feedback-ronda-1` (desde `main` @ bdb5bdb)
Fecha: 2026-10-24 · Estado: en ejecución — T4 imágenes listas; writer delegado para T1–T3, T5–T7.
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
- NO incluye: push, PR, merge, cambios a `enviar.php` ni estructura de carpetas.

## Datos canónicos
- Dirección (texto visible): `Av. Lonquén 12180, Maipú, San Bernardo Parcela 48, Pje. América, Galpón "T", 9250000 Maipú, Región Metropolitana`
- Query Maps (URL-encoded): `Av.%20Lonqu%C3%A9n%2012180%2C%20Maip%C3%BA%2C%20San%20Bernardo%20Parcela%2048%2C%20Pje.%20Am%C3%A9rica%2C%20Galp%C3%B3n%20T%2C%209250000%20Maip%C3%BA%2C%20Regi%C3%B3n%20Metropolitana`
- Embed: `https://www.google.com/maps?q=<query>&z=16&output=embed`
- Link: `https://www.google.com/maps/search/?api=1&query=<query>`
- Facebook: `https://web.facebook.com/p/Carrocer%C3%ADas-ARodriguez-SPA-61579596361830/?_rdc=1&_rdr`
- Instagram: `https://www.instagram.com/arodriguez_spa/`
- WhatsApp: `https://wa.me/56968476557`

## Tareas
- [ ] **T1 [P1]** Botones CTA del hero se descuadran en móvil con texto largo.
      Contenedor hero → `class="btn-row"`; segundo enlace → `class="btn-secundario"`
      (mover inline style a CSS). CSS: `.btn-row{display:flex;flex-wrap:wrap;gap:12px;align-items:center}`;
      `@media(max-width:640px){.btn-row{flex-direction:column;align-items:stretch}.btn-row a{text-align:center}}`.
      (Los botones de contacto de P1 desaparecen con T2, no hace falta btn-row ahí.)
- [ ] **T2 [P1,P2,P3]** Redes sociales: quitar la línea `Instagram: …` de la lista de
      contacto y quitar los botones CONTACTAR / VISITAR INSTAGRAM. Crear `.social-card`
      con 3 iconos SVG monocromos inline (Facebook, Instagram, WhatsApp) usando los
      datos canónicos. Iconos `currentColor`: claros `#111`, P2 oscura `#fff`;
      `:hover`, `:focus-visible` y `:active` → `#E30613`. Card: borde, radius 12,
      padding, flex, título pequeño "Síguenos". Botón flotante de WhatsApp se conserva.
- [ ] **T3 [P1,P2,P3,privacidad]** Mapa de Google siempre activo (sin botón):
      reemplazar `.mapa-box`+botón por `<p class="mapa-line">📍 <dirección></p>` +
      `<iframe class="mapa" src="<embed>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="…"></iframe>`.
      `mapa-link` → link canónico. Línea `Ubicación:` → dirección completa (+ Chile).
      Eliminar el IIFE JS "Mapa: se carga solo cuando la persona lo solicita" y las
      reglas CSS `.mapa-box`/`.mapa-btn` (incluidas las referencias en selectores de
      transición/hover). Verificar que exista regla `.mapa` (añadirla en P2 si falta).
      `politica-de-privacidad.html`: Domicilio → dirección completa; reescribir el
      párrafo del mapa y su viñeta (ahora el iframe carga con la página y Google
      recibe la IP al cargar; sin cookies propias de seguimiento).
- [x] **T4 [todas]** Logo con fondo transparente (HECHO, 2026-10-24): des-matizado
      blanco aplicado (alpha 255 si `min(r,g,b)<=60`; 0 si `min>=250`; rampa un-blend
      intermedia). Replicado con md5 `0fdea4da…` en `propuesta-{1,2,3}-*/img/logo.png`
      e `img/logo.png`. `logo.webp` sin referencias: no se toca. Verificado sobre
      fondo oscuro y claro. Pendiente del writer: quitar `.logo-chip` blanco en P2 y
      usar variante `logo-blanco.png` (negro→blanco, rojo intacto) SOLO en P2 para
      legibilidad sobre tema oscuro.
- [ ] **T5 [P3]** Fondo del hero menos difuminado: `hero-blur.webp` → `hero.webp`
      (existe en `propuesta-3-hibrida/img/`), mantener velo `rgba(17,17,17,.72)`.
- [ ] **T6 [index selector]** Mejorar textos explicativos de las 3 tarjetas y la
      introducción (copy claro, sin hype, explicando qué cambia entre propuestas y
      cómo revisarlas).
- [ ] **T7 [P1,P2,P3]** Homologar tamaños de texto de las secciones (feedback
      2026-10-24: "revisa, homologa, cambia el tamaño de los textos de las secciones
      de todas las presentaciones"). Misma escala tipográfica en las 3 propuestas:
      `h1{42px/1.15} h2{28px/1.2} h3{18px/1.3} p{16px/1.55}` y en
      `@media(max-width:640px){h1{30px}h2{22px}}`. Eliminar overrides duplicados
      (`.quienes h2`, `.servicios h2` en P1; `.v-card h3{17px}`→18px; dedupe
      `.hero h1`). P2 adapta colores, misma escala.

## Verificación prevista
- Readback del diff completo (orquestador).
- Capturas de navegador (desktop y ~390px móvil) de P1, P2, P3 y selector: botones
  apilados, card social visible, mapa visible sin clic, logo sin caja blanca.
- Verificación estructural del writer: greps (`mapa-box` ausente, `social-card`
  presente ×3, `hero-blur` ausente en P3, etc.).

## Ruta / topología
- T1, T2, T3, T5, T6 → delegated writer (un writer, ~8 archivos).
- T4 → inline (script PIL + verificación visual).
- Motivo writer-trigger: 2+ archivos no triviales; preparación de escritura delegada.

## Evidencia
- (por completar: commits por unidad de trabajo y resultados de verificación)
