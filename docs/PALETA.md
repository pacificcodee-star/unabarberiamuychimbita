# Paleta de color — Una Barbería Muy Chimbita

> Dirección de arte: **latón bruñido sobre carbón cálido**.
> Un solo sistema de color para toda la web, sacado del emblema real (`#C9A227`)
> y de la fotografía del local (nogal, cuero, luz ámbar).

---

## 1. Por qué esta paleta

El sitio tenía **tres paletas peleando** en el mismo archivo:

| Momento | Fondo | Acento |
| --- | --- | --- |
| Identidad cinematográfica original (`cinema.css` §1) | azul noche `#0f2233` | azul acero `#a9c4d4` |
| Corrección intermedia (`cinema.css` §19.1) | azul medio `#1b3a57` | rojo `#b8362a` |
| Sistema final (`cinema.css` §25) | negro neutro `#0c0c0d` | latón apagado `#a68a64` |

El resultado era incoherente: un fondo **frío** bajo una fotografía **cálida** (madera,
cuero, luz ámbar), un dorado turbio que no coincidía con el oro del logo y un rojo usado
a la vez como acento, como texto y como fondo del pie de página.

La paleta nueva parte de dos verdades del negocio:

1. **El emblema.** `public/images/logo-una-barberia.svg` usa un único color: `#C9A227`.
   Ese es el acento de detalle, sin reinterpretarlo.
2. **La foto de portada.** `public/images/portada-barberia.jpg` está iluminada en cálido.
   El fondo debía acompañarla, no contradecirla.

Referencias de la colección Awwwards (lujo, belleza y hospitalidad; no hay barberías):

- **Venetian Nail Spa** — latón `#B69F64` sobre neutro profundo.
- **Villa Aurelia** — piedra `#B8A58A` sobre café `#2A221B`.
- **Cartier** — rojo de marca reservado a la acción, nunca al fondo.
- **Heirest** — oxblood profundo como color de marca, no como color de página.

La lección común: **un solo color de marca para la acción** y el resto al servicio del
material y la fotografía. Aquí ese color es el latón del emblema; el rojo del poste queda
como **señal** (puntúa los rótulos, avisa de lo que está cerrado), no como botón.

---

## 2. Paleta

### Superficies (tema oscuro)

| Token | Hex | Papel |
| --- | --- | --- |
| `--bg` | `#0C0A08` | Fondo de página: carbón cálido, casi negro. |
| `--surface` | `#14110D` | Tarjetas, paneles, campos de formulario. |
| `--surface-2` | `#1C1813` | Superficie elevada (hover, tarjetas anidadas). |
| `--surface-3` | `#282218` | Pie de página y separadores de bloque. |

### Texto

| Token | Hex | Papel |
| --- | --- | --- |
| `--text` | `#F3EBDD` | Texto principal, hueso cálido. |
| `--text-muted` | `#C0B4A2` | Texto de lectura secundario. |
| `--text-subtle` | `#9A8E7C` | Metadatos, notas al pie de tarjeta. |

### Detalle y acción: latón del emblema

| Token | Hex | Papel |
| --- | --- | --- |
| `--gold` | `#C9A227` | Rótulos, iconos, líneas, foco y cursivas de titular. |
| `--gold-hover` | `#E0BE4E` | Estado hover del latón de detalle. |
| `--gold-deep` | `#8A6A1B` | Sombra y degradados del latón. |
| `--gold-ink` | `#6A4E10` | Latón legible **sobre papel**. |
| `--accent-rgb` | `201, 162, 39` | Versión RGB para degradados y halos. |
| `--gold-soft` | `rgba(201,162,39,.10)` | Relleno tenue de avisos y pestañas activas. |
| `--gold-line` | `rgba(201,162,39,.36)` | Filetes y bordes. |
| `--gold-glow` | `rgba(201,162,39,.34)` | Halo de foco. |
| `--button-gold` | `#B28D27` | Relleno del botón principal: un latón más profundo que el de detalle, para que una superficie grande no deslumbre. |
| `--button-gold-hover` | `#C19C32` | Hover de ese botón. |

### Señal: rojo del poste

| Token | Hex | Papel |
| --- | --- | --- |
| `--brand-red` | `#B32533` | Puntos de rótulo y marcas de sección. |
| `--brand-red-hover` | `#C8384A` | Hover de esas señales. |
| `--brand-red-deep` | `#7E1622` | Presión y bordes. |
| `--brand-red-text` | `#E4706A` | Rojo legible sobre carbón (cerrado / error). |
| `--brand-rgb` | `179, 37, 51` | Versión RGB para halos y focos. |

### Papel (secciones claras)

| Token | Hex | Papel |
| --- | --- | --- |
| `--paper` | `#F1E9DB` | Fondo de la sección clara de cierre. |
| `--paper-2` | `#E6DCCA` | Papel secundario. |
| `--ink` | `#14110D` | Texto sobre papel. |
| `--ink-muted` | `#5A5348` | Texto de lectura sobre papel. |

### Estado

| Token | Hex | Papel |
| --- | --- | --- |
| `--star` | `#F2C24D` | Estrellas de reseña. |
| `--success` / `--success-ink` | `#5FAE7F` / `#1D6A42` | Abierto / confirmado. |
| `--danger` / `--danger-ink` | `#E07A6A` / `#A3321F` | Cerrado / error. |

---

## 3. Contrastes verificados

Calculados con la fórmula WCAG 2.2 (luminancia relativa, umbral 4.5:1 para texto normal
y 3:1 para texto grande, iconos y formas).

| Combinación | Ratio | Criterio |
| --- | --- | --- |
| `--text` sobre `--bg` | **16.69:1** | AAA |
| `--text` sobre `--surface` | **15.90:1** | AAA |
| `--text` sobre `--surface-3` | **13.31:1** | AAA |
| `--text-muted` sobre `--bg` | **9.69:1** | AAA |
| `--text-subtle` sobre `--bg` | **6.15:1** | AA |
| `--gold` sobre `--bg` | **8.17:1** | AAA |
| `--gold` sobre `--surface` | **7.78:1** | AAA |
| `--gold-hover` sobre `--bg` | **10.96:1** | AAA |
| `--ink` sobre `--gold` (detalle) | **7.78:1** | AAA |
| `--ink` sobre `--button-gold` (botón) | **6.03:1** | AA |
| `--ink` sobre `--button-gold-hover` | **7.23:1** | AAA |
| `--button-gold` sobre `--bg` (forma) | **6.34:1** | AA (no texto) |
| Blanco sobre `--brand-red` | **6.50:1** | AA |
| `--brand-red-text` sobre `--bg` | **6.42:1** | AA |
| `--brand-red` sobre `--bg` (solo formas) | **3.04:1** | AA (no texto) |
| `--ink` sobre `--paper` | **15.61:1** | AAA |
| `--ink-muted` sobre `--paper` | **6.30:1** | AA |
| `--gold-ink` sobre `--paper` | **6.43:1** | AA |
| `--brand-red` sobre `--paper` | **5.39:1** | AA |
| Blanco sobre `--surface-3` (pie) | **15.76:1** | AAA |
| `--star` sobre `--bg` | **11.86:1** | AAA |

Ninguna combinación de texto queda por debajo de AA.

---

## 4. Reglas de uso

1. **El latón es la voz de la marca.** Rótulos, iconos, filetes, foco, las palabras en
   cursiva de los titulares y el botón principal. Es el mismo oro del emblema, así que
   dentro de la web hace de firma y de llamada a la acción.
2. **El rojo es señal, no acción.** Puntúa los rótulos y comunica estados (cerrado / error).
   Nunca es fondo de sección, botón ni texto de párrafo.
3. **El carbón es material.** Sin azules, sin negros puros: todos los grises llevan una
   pizca de ámbar para convivir con la fotografía.
4. **Sobre papel manda la tinta.** En la sección clara el texto va en `--ink` / `--ink-muted`
   / `--gold-ink` y el botón principal cambia a tinta: el latón sobre yeso solo alcanza
   2.6:1 y no se distingue como forma.
5. **Estados con significado conservan su color.** Verde para abierto/confirmado, rojo para
   cerrado/error. No son decoración.

---

## 5. Dónde vive el color

El color tiene **una sola fuente de verdad**: el bloque `:root` de la sección 25 al final de
`public/css/cinema.css`. `cinema.css` se carga después de `site.css`, así que ese bloque
gana sobre cualquier declaración anterior.

Los tokens duplicados en `public/css/site.css` (§1 y el bloque de respaldo del inicio)
existen solo como respaldo y **deben llevar los mismos valores**. Si cambias un color,
cámbialo en los dos archivos.

También se actualizaron:

- `resources/views/layouts/public.blade.php` — `theme-color`, insignia de avisos e iconos
  SVG de medios de pago (`#C9A227`).
- `public/manifest.webmanifest` — `theme_color` y `background_color` (`#0C0A08`).
- `public/css/admin-theme.css` — el panel del equipo usa la misma familia de rojo
  (`#B32533` / `#C8384A` / `#7E1622`) para que el logo se vea igual en toda la app.

### Mapa de nombres heredados

El sitio usa nombres antiguos que se conservan para no tocar cientos de reglas:

| Nombre heredado | Hoy significa |
| --- | --- |
| `--gold`, `--gold-hover`, `--gold-deep` | Latón del emblema (no oro genérico). |
| `--accent-rgb` | Latón en RGB, para halos y degradados. |
| `--brand-red*` | Rojo del poste, reservado a la acción. |
| `--x-accent`, `--x-ink`, `--x-cream` | Alias del inicio: latón, tinta y hueso. |
| `--footer-bg` | `--surface-3`, el tono del pie. |

---

## 6. Lo que se eliminó

- Azul noche `#0f2233` y azul acero `#a9c4d4` del tema cinematográfico original.
- Azul medio `#1b3a57` y sus superficies `#234766` / `#2c5576` / `#3a6a8c`.
- Azul de los paneles de barberos `#14304a`, `#102638`, `#123238` (y el violeta `#2b2233`).
- Latón turbio `#a68a64` y sus derivados `#c2a57c`, `#7d6545`, `#5e4b33`.
- Ámbar anaranjado `#e2a84f` del bloque de respaldo del inicio.
- Rojos sueltos `#b8362a`, `#d04536`, `#cf4334`, `#a02e25`, `#b23428`, `#c8402f`, `#c03a2d`,
  `#d0453a`, sustituidos por la familia `--brand-red`.
- Neones rojo/azul del fondo de la página de barberos: hoy son rojo tenue y latón.
- Estrellas `#f6c453` y `#e2a84f`: hoy `--star`, calibrado sobre el carbón.

## 7. Cómo comprobarlo

```bash
# El color solo debe declararse en el bloque final de cinema.css (sección 25)
rg -n "^\s*--(bg|surface|text|gold|brand|paper|ink|star)" public/css/cinema.css

# No deben quedar colores del tema anterior
rg -n "#(0f2233|152c40|1c374e|28475f|1b3a57|234766|2c5576|3a6a8c|14304a|102638|2b2233|123238|a68a64|a9c4d4|e2a84f)" public/css
```
