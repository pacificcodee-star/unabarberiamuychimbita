# SVG banner

## How GitHub shows it

A README image is rendered as `<img src>`. The browser then treats the SVG as a static image:

| Works | Silently ignored or blocked |
| --- | --- |
| Shapes, paths, gradients, patterns, masks, clip paths, filters | `<script>`, event handlers, `<a>` links inside the SVG |
| `<text>` with installed fonts | Web fonts: `@import`, `@font-face` with a URL, font `<link>` |
| Presentation attributes and an internal `<style>` block | External stylesheets |
| SMIL/CSS animation (keep it subtle, or skip it) | `<image href="https://…">` and any other external reference |
| `data:` URIs (avoid: size) | `<foreignObject>` HTML: renders inconsistently across browsers, rasterizers and registries; never use it |

Consequences:

- Text uses fonts already on the viewer's machine. Use a system stack ending in a generic family: `system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif` (or `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`).
- Glyph widths differ by platform by up to ~10%. Leave ≥15% free width to the right of the longest line, and anchor text at the left (`x` = start) or center (`text-anchor="middle"`), never flush right.
- A brand typeface is only possible as outlined paths (convert text to paths in a vector editor). Keep the editable text version next to it, and budget the size: outlined text is often 5–15 KB per line.
- Links belong in Markdown around the `<img>`, never inside the SVG.

## Canvas and layout

| Use | Size | Notes |
| --- | --- | --- |
| README banner | 1280×320 (4:1) | Default. Scales to ~830 px wide on desktop, ~350 px on phones |
| Tall hero | 1280×640 (2:1) | Same art can double as the social preview source |
| Social preview | 1280×640 PNG | Uploaded in settings; see [repo-metadata.md](repo-metadata.md#social-preview) |

- Always set `width`, `height` and a matching `viewBox="0 0 W H"`. Without `viewBox` it will not scale; without `width`/`height` the intrinsic size depends on the renderer (some fall back to 300×150).
- In Markdown, set `width="100%"` on the `<img>` so it fills the column; the `viewBox` keeps the ratio.
- Margins: ≥64 px on every side for text at 1280 wide.
- Type scale at 1280 wide: title 64–88 px bold, tagline 28–36 px regular. On a phone that becomes ~18–24 px and ~8–10 px — so the tagline is a bonus, the title must carry the banner, and both are repeated as Markdown text.
- Text: title ≤ ~24 characters, tagline ≤ ~60, nothing else. No feature lists, badges, version numbers or dates (they go stale).
- One accent color, one or two background tones, one simple motif (rings, grid, wave, glyph built from the project's idea). Motifs sit away from the text block.

## Light and dark themes

GitHub's page canvas is `#ffffff` in light themes and `#0d1117` in the default dark theme (dimmed and high-contrast variants differ slightly). Pick one strategy:

| Strategy | When | How |
| --- | --- | --- |
| Opaque background | Default | Full-size `<rect>` fill (the template). Text contrast is checked only against that fill; looks identical in both themes |
| Transparent, two files | Logo-style banners that should blend into the page | `banner.svg` (dark text for `#ffffff`) and `banner-dark.svg` (light text for `#0d1117`) behind a `<picture>` with `prefers-color-scheme` sources |
| Transparent, one file | Only if every color passes on both canvases | Mid-tone accent on its own; verify both ratios below |

Do not rely on `@media (prefers-color-scheme: dark)` inside the SVG: it follows the operating system setting, not the GitHub theme the viewer picked, so it can show dark text on a dark page. The `<picture>` method is the one GitHub documents for Markdown.

Rounded corners (`rx`) on an opaque background leave transparent corners; that is fine on both themes.

## Contrast

Targets: ≥4.5:1 for the tagline, ≥3:1 for the title (large text) and for meaningful shapes. Check every text color against every background it can sit on (the fill, or `#ffffff` and `#0d1117` for transparent designs).

```js
// contrast.mjs — usage: node contrast.mjs '#cbd5e1' '#1e293b'   (6-digit hex only)
const luminance = (hex) => {
  const [r, g, b] = hex.replace('#', '').match(/../g).map((h) => {
    const c = parseInt(h, 16) / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const [a, b] = process.argv.slice(2, 4).map(luminance);
const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
console.log(`${ratio.toFixed(2)}:1`, ratio >= 4.5 ? 'ok for all text' : ratio >= 3 ? 'large text only' : 'fail');
```

For gradients, check against both stops.

## Transparent two-file variant

Same geometry, colors swapped. Light file shown; the dark file changes `#0f172a` → `#f8fafc`, `#475569` → `#94a3b8`, `#0284c7` → `#38bdf8`.

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="320" viewBox="0 0 1280 320" role="img" aria-labelledby="t d">
  <title id="t">project-name</title>
  <desc id="d">One-line pitch in under sixty characters</desc>
  <g fill="none" stroke="#0284c7" stroke-width="2">
    <circle cx="1130" cy="160" r="90" stroke-opacity="0.6"/>
    <circle cx="1130" cy="160" r="130" stroke-opacity="0.3"/>
  </g>
  <text x="80" y="170" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="76" font-weight="700" fill="#0f172a">project-name</text>
  <text x="80" y="224" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="30" fill="#475569">One-line pitch in under sixty characters</text>
</svg>
```

## Size and hygiene

- Target < 20 KB; the template is ~1.5 KB. Check with `wc -c banner.svg`.
- Hand-write or clean up editor output: remove editor namespaces and metadata (`inkscape:*`, `sodipodi:*`, `<metadata>`), hidden layers, unused `<defs>`, and round coordinates to ≤2 decimals. An optimizer such as `npx svgo banner.svg` does this; re-check the result renders.
- No embedded raster images. If the design needs a photo, it should be a PNG/WebP, not an SVG.
- Unique `id`s with a short prefix if several SVGs may be inlined on one page elsewhere.
- Commit under `.github/assets/` (or the repo's existing assets folder); reference it relatively.

## Validate and preview

1. Well-formed XML: `xmllint --noout .github/assets/banner.svg` (no output = valid). Without `xmllint`: `python3 -c "import sys, xml.dom.minidom as m; m.parse(sys.argv[1])" banner.svg`.
2. No external references: search the file for `http`, `@import`, `url(` — only `xmlns` and `url(#id)` are allowed.
3. Open the file in a browser via `<img>`, not directly, to get GitHub's behavior: a scratch HTML page with `<img src="banner.svg" width="830">` and `<img … width="360">`, on a `#ffffff` and a `#0d1117` background.
4. After pushing, view the README on github.com in both the light and dark theme, and on a narrow window.

## PNG export (social preview)

Build the social preview as a 1280×640 SVG with the same rules, then rasterize:

```sh
rsvg-convert -w 1280 -h 640 social.svg -o social.png
```

Without `librsvg`, use a headless Chromium-based browser:

```sh
chromium --headless --disable-gpu --hide-scrollbars --window-size=1280,640 --screenshot=social.png social.svg
```

The screenshot path must be absolute or relative to the current directory; the binary may be `google-chrome`, `chrome` or the full path to the browser app. Check the PNG is exactly 1280×640 (`file social.png`) and under 1 MB.
