# Color and accessibility

## Palettes

Pick the palette type from the data, not from taste:

| Data | Palette | Rule |
| --- | --- | --- |
| Unordered categories | Categorical | ≤ 7 hues; group the rest as "Other" in gray |
| Ordered magnitude (0 → max) | Sequential | Light = low, dark = high on light backgrounds; one hue or perceptually uniform ramp |
| Deviation from a meaningful midpoint (0, target, average) | Diverging | Neutral midpoint exactly at that value; symmetric domain |
| One series of interest among many | Highlight | Accent color for the focus, gray (`#9ca3af`) for context |

### Categorical: Okabe–Ito (colorblind-safe)

Contrast measured against white `#ffffff` and a dark surface `#111827`. Graphical marks need ≥ 3:1 (WCAG 1.4.11), text ≥ 4.5:1.

| Color | Hex | On white | On dark | Use on light backgrounds |
| --- | --- | --- | --- | --- |
| Blue | `#0072b2` | 5.19 | 3.42 | Series 1; also safe for text |
| Vermillion | `#d55e00` | 3.87 | 4.59 | Series 2; text needs `#b54f00` (5.14) |
| Bluish green | `#009e73` | 3.42 | 5.19 | Series 3; text needs `#007a57` (5.36) |
| Reddish purple | `#cc79a7` | 3.06 | 5.80 | Series 4; text needs `#a8577f` (4.84) |
| Orange | `#e69f00` | 2.25 | 7.88 | Only with a dark outline or direct labels |
| Sky blue | `#56b4e9` | 2.31 | 7.69 | Only with a dark outline or direct labels |
| Yellow | `#f0e442` | 1.32 | 13.42 | Dark backgrounds only |

Order for light themes: blue, vermillion, bluish green, reddish purple, then orange/sky blue with outlines. Dark themes: sky blue, orange, bluish green, reddish purple, yellow, vermillion. Define the palette once as CSS custom properties (`--chart-1` … `--chart-7`) and swap per theme; never invert colors automatically.

### Sequential and diverging

| Type | Stops (low → high) |
| --- | --- |
| Sequential, single hue (blues) | `#eff3ff` `#bdd7e7` `#6baed6` `#3182bd` `#08519c` |
| Sequential, multi-hue (viridis) | `#440154` `#3b528b` `#21918c` `#5ec962` `#fde725` |
| Diverging (red–blue) | `#b2182b` `#ef8a62` `#fddbc7` `#f7f7f7` `#d1e5f0` `#67a9cf` `#2166ac` |

- Use 5–7 classed steps for maps and heatmaps so readers can match the legend; continuous ramps for dense grids.
- Light stops disappear on white: outline cells or regions with a 1 px `#ffffff`/`#6b7280` border.
- Red/green is never a diverging pair. For "good vs. bad" use blue vs. vermillion plus a sign or icon.

## Never color alone

Every distinction carried by color also needs one of:

- **Direct labels** — series name at the line end, value at the bar end.
- **Dash patterns and markers** — solid vs. `6 4` dashed lines; circle/square/triangle markers.
- **Position and order** — sorted bars, a highlighted bar placed first, a legend in the same order as the stack.
- **Texture** — pattern fills for stacked or pie segments (`<pattern>` in SVG, `aria.decal` in ECharts).
- **Signs and icons** — `+4.2 %` / `−1.5 %` with ▲/▼, not green/red numbers alone.
- **Separation** — 1–2 px white gap between adjacent segments so neighbors stay distinct in grayscale.

Check: view the chart in grayscale and with a deuteranopia/protanopia simulation (browser devtools "Emulate vision deficiencies"); every series must still be identifiable.

## Text and contrast

- Axis labels, tick labels and data labels ≥ 12 px and ≥ 4.5:1; gridlines low contrast (`#e5e7eb` light, `#374151` dark) so they recede.
- Titles state the takeaway ("Support tickets fell 18 % after the new help center"), not the encoding ("Bar chart of tickets").
- Avoid rotated tick labels; switch to horizontal bars or thin the ticks instead.

## Screen readers

### Static chart

```jsx
// A static chart is one image with a text equivalent.
export function StaticChartFrame({ id, title, description, children, table }) {
  return (
    <figure className="chart">
      <figcaption id={`${id}-title`} className="chart-title">{title}</figcaption>
      <p id={`${id}-desc`} className="chart-desc">{description}</p>
      <svg role="img" aria-labelledby={`${id}-title ${id}-desc`} width="100%" height="240">
        {children}
      </svg>
      <details className="chart-data">
        <summary>Show data</summary>
        {table}
      </details>
    </figure>
  );
}
```

- `role="img"` hides the SVG's children from assistive tech, so the title + description must carry the message and the table must carry the numbers.
- Canvas charts: put `role="img"` and `aria-label`/`aria-labelledby` on the `<canvas>`; the table is mandatory.
- Visible table instead of `<details>` when exact values matter (finance, reporting).

### Interactive chart: one tab stop, arrow keys, live region

```jsx
// useChartKeyboard.js
import { useState } from 'react';

export function useChartKeyboard(count) {
  const [active, setActive] = useState(null);
  const next = (i) => (i == null ? 0 : Math.min(i + 1, count - 1));
  const prev = (i) => (i == null ? count - 1 : Math.max(i - 1, 0));
  const moves = { ArrowRight: next, ArrowDown: next, ArrowLeft: prev, ArrowUp: prev, Home: () => 0, End: () => count - 1, Escape: () => null };
  const onKeyDown = (e) => {
    const move = moves[e.key];
    if (!move || count === 0) return;
    e.preventDefault();
    setActive(move);
  };
  return { active, setActive, onKeyDown, onBlur: () => setActive(null) };
}
```

```jsx
// ChartInteraction.jsx — marks: [{ label, value, x, y }] in SVG pixels from your scales.
import { useChartKeyboard } from './useChartKeyboard';

export function ChartInteraction({ title, marks, width, height, format, children }) {
  const { active, setActive, onKeyDown, onBlur } = useChartKeyboard(marks.length);
  const current = active == null ? null : marks[active];
  return (
    <div className="chart-interactive" tabIndex={0} role="group" aria-roledescription="chart"
      aria-label={`${title}. Use arrow keys to read values, Escape to close.`}
      onKeyDown={onKeyDown} onBlur={onBlur} onPointerLeave={() => setActive(null)} style={{ position: 'relative' }}>
      <svg width={width} height={height} aria-hidden="true">
        {children}
        {marks.map((m, i) => (
          <circle key={m.label} cx={m.x} cy={m.y} r={12} fill="transparent" onPointerEnter={() => setActive(i)} />
        ))}
        {current && <circle cx={current.x} cy={current.y} r={6} className="chart-focus-ring" />}
      </svg>
      {current && (
        <div role="tooltip" className="chart-tooltip"
          style={{ position: 'absolute', left: current.x, top: current.y, transform: 'translate(-50%, calc(-100% - 12px))' }}>
          <strong>{current.label}</strong> {format(current.value)}
        </div>
      )}
      <p aria-live="polite" className="visually-hidden">{current ? `${current.label}: ${format(current.value)}` : ''}</p>
    </div>
  );
}
```

- Hover, focus and touch all set the same `active` index, so pointer and keyboard users see the same tooltip.
- Clamp the tooltip inside the container near the edges; it must not cover the focused mark.
- Tooltips follow WCAG 1.4.13: dismissible (Escape), hoverable, persistent until dismissed or focus moves.
- Invisible hit targets (`r={12}`) make small marks usable by touch; keep ≥ 24 × 24 px.

```css
.visually-hidden { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0; }
.chart-interactive:focus-visible { outline: 2px solid var(--chart-focus, #0072b2); outline-offset: 4px; }
.chart-focus-ring { fill: none; stroke: var(--chart-text, #374151); stroke-width: 2; }
.chart-tooltip { pointer-events: none; padding: 4px 8px; border-radius: 4px; background: #111827; color: #ffffff; font: 12px system-ui, sans-serif; font-variant-numeric: tabular-nums; white-space: nowrap; }
```

## Reduced motion

```jsx
// usePrefersReducedMotion.js
import { useEffect, useState } from 'react';

const QUERY = '(prefers-reduced-motion: reduce)';

export function usePrefersReducedMotion() {
  const [reduced, setReduced] = useState(() => typeof window !== 'undefined' && window.matchMedia(QUERY).matches);
  useEffect(() => {
    const mql = window.matchMedia(QUERY);
    const onChange = () => setReduced(mql.matches);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, []);
  return reduced;
}
```

```css
@media (prefers-reduced-motion: reduce) {
  .chart *, .chart *::before, .chart *::after { transition: none !important; animation: none !important; }
}
```

- Reduced motion means data appears in its final state immediately; updates still happen, only without tweening.
- Even with motion allowed: ≤ 300 ms, animate only on user-triggered changes (filter, sort), never on polling refreshes.
