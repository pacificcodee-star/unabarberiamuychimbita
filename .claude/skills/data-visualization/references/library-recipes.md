# Library recipes

Use the library the project already has. Every recipe imports the one-argument formatters from `format.ts` (see `SKILL.md`) and applies the same rules: zero-based bars, direct labels, colorblind-safe colors, reduced motion, a text alternative. `usePrefersReducedMotion` is defined in [color-and-accessibility.md](color-and-accessibility.md).

| Library | Renders | Keyboard tooltips | Reduced motion | Resize |
| --- | --- | --- | --- | --- |
| Recharts | SVG | `accessibilityLayer` (default on in v3) | `isAnimationActive={!reduced}` | `ResponsiveContainer` in a sized parent |
| Chart.js | Canvas | No — table alternative required | `animation: false` | `responsive` + parent with fixed height |
| ECharts | Canvas or SVG | Partial — table alternative required | `animation: !reduced` | `ResizeObserver` → `chart.resize()` |
| Observable Plot | SVG | No (`tip` is pointer-only) | No animation | Re-render on width change |
| Vega-Lite | SVG or canvas | No | No animation by default | `"width": "container"` |
| D3 | Whatever you draw | Build it (see keyboard pattern) | `transition().duration(reduced ? 0 : 300)` | `ResizeObserver` → redraw |

## Recharts: line chart

```jsx
// TrendChart.jsx — direct end labels, dashed comparison series, formatted axes, reduced motion.
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { formatCompact, formatMonth, formatNumber } from './format';
import { usePrefersReducedMotion } from './usePrefersReducedMotion';

// data: [{ month: '2026-01', current: 1240, previous: 1180 }, …]; null leaves a gap.
const SERIES = [
  { key: 'current', name: 'This year', color: '#0072b2', ink: '#0072b2' },
  { key: 'previous', name: 'Last year', color: '#d55e00', ink: '#b54f00', dash: '6 4' },
];

export function TrendChart({ data, title, description }) {
  const reduced = usePrefersReducedMotion();
  const last = data.length - 1;
  return (
    <figure className="chart">
      <figcaption className="chart-title">{title}</figcaption>
      <p className="chart-desc">{description}</p>
      {data.length === 0 ? (
        <p className="chart-empty">No data for this selection.</p>
      ) : (
        <div style={{ height: 320 }}>
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 8, right: 80, bottom: 0, left: 0 }} accessibilityLayer>
              <CartesianGrid vertical={false} stroke="#e5e7eb" />
              <XAxis dataKey="month" tickFormatter={formatMonth} minTickGap={24} />
              <YAxis tickFormatter={formatCompact} width={48} />
              <Tooltip labelFormatter={formatMonth} formatter={(v, name) => [formatNumber(v), name]} />
              {SERIES.map((s) => (
                <Line key={s.key} dataKey={s.key} name={s.name} stroke={s.color} strokeDasharray={s.dash}
                  strokeWidth={2} dot={data.length === 1} isAnimationActive={!reduced}
                  label={({ index, x, y }) => (index === last
                    ? <text x={x + 6} y={y} dy="0.35em" fill={s.ink} fontSize={12}>{s.name}</text>
                    : null)} />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </figure>
  );
}
```

- `ink` is a darker text variant of the line color so the label reaches 4.5:1; the dash pattern keeps the series apart without color.
- End labels sit on the last row; if the last value may be `null`, label the last non-null index per series instead.
- Irregular time steps: pass timestamps and use `<XAxis dataKey="t" type="number" scale="time" domain={['dataMin', 'dataMax']} tickFormatter={formatDay} />`; a category axis spaces missing dates evenly and hides gaps.
- Bars: `<Bar>` with `<YAxis domain={[0, 'auto']} />` never set above zero; `<LabelList dataKey="value" position="right" formatter={formatCompact} />` for direct values.
- Add the `<details>` data table from the bar template in `SKILL.md`.

## Chart.js (v4)

```js
// bar-chart.js
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';
import { formatCompact, formatNumber } from './format';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

export function mountBarChart(canvas, { labels, values, seriesLabel }) {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const chart = new Chart(canvas, {
    type: 'bar',
    data: { labels, datasets: [{ label: seriesLabel, data: values, backgroundColor: '#0072b2' }] },
    options: {
      responsive: true,
      maintainAspectRatio: false, // the parent element sets the height
      animation: reduced ? false : { duration: 300 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatNumber(ctx.parsed.y)}` } },
      },
      scales: { y: { beginAtZero: true, ticks: { callback: (v) => formatCompact(v) } } },
    },
  });
  return () => chart.destroy();
}
```

```html
<figure class="chart">
  <figcaption id="orders-title">Orders by region</figcaption>
  <p id="orders-desc">North leads with 1,240 orders; West is lowest at 310.</p>
  <div style="position: relative; height: 320px">
    <canvas role="img" aria-labelledby="orders-title" aria-describedby="orders-desc"></canvas>
  </div>
  <!-- data table alternative here: canvas marks are invisible to assistive tech -->
</figure>
```

- Update in place (`chart.data.datasets[0].data = next; chart.update(reduced ? 'none' : undefined)`) instead of re-creating the chart.
- Horizontal bars: `indexAxis: 'y'`, then format `ctx.parsed.x` and put `beginAtZero` on `scales.x`.

## ECharts (v5)

```js
// line-chart.js
import * as echarts from 'echarts';
import { formatCompact, formatMonth, formatNumber } from './format';

export function mountLineChart(el, { months, series }) {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const chart = echarts.init(el, null, { renderer: 'svg' });
  chart.setOption({
    color: ['#0072b2', '#d55e00', '#009e73', '#cc79a7'],
    aria: { enabled: true, decal: { show: true } }, // generated description + patterns on bars/pies
    animation: !reduced,
    grid: { left: 48, right: 96, top: 16, bottom: 32 },
    tooltip: { trigger: 'axis', valueFormatter: (v) => formatNumber(v) },
    xAxis: { type: 'category', data: months, axisLabel: { formatter: (v) => formatMonth(v) } },
    yAxis: { type: 'value', axisLabel: { formatter: (v) => formatCompact(v) } },
    series: series.map((s) => ({
      type: 'line', name: s.name, data: s.values, showSymbol: s.values.length === 1,
      connectNulls: false, endLabel: { show: true, formatter: '{a}' },
    })),
  });
  const ro = new ResizeObserver(() => chart.resize());
  ro.observe(el);
  return () => { ro.disconnect(); chart.dispose(); };
}
```

- Never set `yAxis.scale: true` on bar charts — it drops zero.
- Large series: `sampling: 'lttb'` on line series, `large: true` on bar/scatter.

## Observable Plot

```js
// ranked-bars.js
import * as Plot from '@observablehq/plot';
import { formatCompact } from './format';

// data: [{ label, value }]; call again from a ResizeObserver when the width changes.
export function renderRankedBars(container, data, { title, description }) {
  const chart = Plot.plot({
    width: container.clientWidth,
    marginLeft: 120,
    marginRight: 56,
    title,
    subtitle: description,
    ariaLabel: title,
    ariaDescription: description,
    x: { grid: true, label: 'Orders', tickFormat: formatCompact },
    y: { label: null },
    marks: [
      Plot.barX(data, { x: 'value', y: 'label', sort: { y: '-x' }, fill: '#0072b2', tip: true }),
      Plot.ruleX([0]),
      Plot.text(data, { x: 'value', y: 'label', text: (d) => formatCompact(d.value), textAnchor: 'start', dx: 4 }),
    ],
  });
  container.replaceChildren(chart);
}
```

- With `title`/`subtitle` Plot returns a `<figure>`; `ariaLabel`/`ariaDescription` land on the SVG.
- Negative values: anchor their text labels at `end` with `dx: -4` (two text marks filtered by sign).

## Vega-Lite (v5)

```json
{
  "$schema": "https://vega.github.io/schema/vega-lite/v5.json",
  "description": "Monthly orders this year vs last year; this year is ahead since March.",
  "width": "container",
  "height": 300,
  "data": { "values": [
    { "month": "2026-01", "series": "This year", "value": 1240 },
    { "month": "2026-01", "series": "Last year", "value": 1180 }
  ] },
  "encoding": {
    "x": { "field": "month", "type": "temporal", "title": null, "axis": { "format": "%b %Y" } },
    "y": { "field": "value", "type": "quantitative", "title": "Orders", "axis": { "format": "", "formatType": "intlCompact" } },
    "color": { "field": "series", "type": "nominal", "legend": null, "scale": { "range": ["#0072b2", "#d55e00"] } }
  },
  "layer": [
    { "mark": { "type": "line", "strokeWidth": 2, "tooltip": true } },
    {
      "mark": { "type": "text", "align": "left", "dx": 6 },
      "encoding": {
        "x": { "aggregate": "max", "field": "month", "type": "temporal" },
        "y": { "aggregate": { "argmax": "month" }, "field": "value", "type": "quantitative" },
        "text": { "field": "series" },
        "color": { "value": "#374151" }
      }
    }
  ],
  "config": { "customFormatTypes": true, "view": { "stroke": null } }
}
```

```js
import embed from 'vega-embed';
import { formatCompact } from './format';

export const mountVegaLite = (el, spec) =>
  embed(el, spec, { actions: false, renderer: 'svg', expressionFunctions: { intlCompact: (v) => formatCompact(v) } });
```

- `description` becomes the chart's accessible description; `"width": "container"` needs a parent with a real width.
- `expressionFunctions` + `customFormatTypes` route axis labels through `Intl`; built-in d3-format `"~s"` prints `1.2G`, not `1.2B`.
- The text layer is the direct end label (`argmax` picks the value at the last month per series).

## D3 (v7)

```js
// columns.js — call on mount and from a ResizeObserver.
import * as d3 from 'd3';
import { formatCompact } from './format';

export function drawColumns(svgEl, data, { width, height = 300, title }) {
  const m = { top: 16, right: 16, bottom: 32, left: 56 };
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const lo = Math.min(0, d3.min(data, (d) => d.value) ?? 0);
  const hi = Math.max(0, d3.max(data, (d) => d.value) ?? 0);
  const x = d3.scaleBand().domain(data.map((d) => d.label)).range([m.left, width - m.right]).padding(0.2);
  const y = d3.scaleLinear().domain(lo === hi ? [0, 1] : [lo, hi]).nice().range([height - m.bottom, m.top]);

  const svg = d3.select(svgEl).attr('width', width).attr('height', height).attr('role', 'img').attr('aria-label', title);
  svg.selectAll('g.y-axis').data([null]).join('g').attr('class', 'y-axis')
    .attr('transform', `translate(${m.left},0)`).call(d3.axisLeft(y).ticks(5).tickFormat(formatCompact));
  svg.selectAll('g.x-axis').data([null]).join('g').attr('class', 'x-axis')
    .attr('transform', `translate(0,${height - m.bottom})`).call(d3.axisBottom(x).tickSizeOuter(0));
  svg.selectAll('rect.bar')
    .data(data, (d) => d.label)
    .join((enter) => enter.append('rect').attr('class', 'bar').attr('y', y(0)).attr('height', 0))
    .attr('x', (d) => x(d.label))
    .attr('width', x.bandwidth())
    .attr('fill', '#0072b2')
    .transition()
    .duration(reduced ? 0 : 300)
    .attr('y', (d) => y(Math.max(0, d.value)))
    .attr('height', (d) => Math.abs(y(d.value) - y(0)));
  svg.selectAll('line.zero').data([null]).join('line').attr('class', 'zero')
    .attr('x1', m.left).attr('x2', width - m.right).attr('y1', y(0)).attr('y2', y(0)).attr('stroke', '#6b7280');
}
```

- Key the data join (`(d) => d.label`) so updates move bars instead of reassigning them.
- Use D3 for scales, shapes and axes even inside React; let React render the elements when the chart is interactive.
