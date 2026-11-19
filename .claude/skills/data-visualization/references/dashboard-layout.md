# Dashboard layout

A dashboard answers a small set of recurring questions for one audience. Write them down first (3–7); every panel maps to one of them, and panels that answer none are cut.

## Hierarchy

| Row | Content | Why |
| --- | --- | --- |
| 1 | Global controls: date range, comparison period, filters, "updated at" | Every number below depends on them |
| 2 | 3–5 KPI cards | The answer at a glance |
| 3 | Primary trend (full width or 2/3) | How the headline KPI got here |
| 4 | Breakdowns (2–3 panels side by side) | Where the change comes from |
| 5 | Detail table, exportable | Exact values and drill-down |

- Most important top-left; reading flows left → right, top → bottom.
- One date range and one comparison period for the whole page, shown in the header. A panel that deviates says so in its title.
- Same metric = same name, unit, format and color on every panel.
- Put filters that apply to everything in the header, panel-specific options inside the panel.
- ≤ 9 panels above the fold equivalent; more questions → tabs or a second page.

## KPI card

Every KPI has a comparison (previous period, target or benchmark) and knows whether up is good.

```jsx
// KpiCard.jsx
import { formatDelta } from './format';

function Sparkline({ values }) {
  const pts = values.filter(Number.isFinite);
  if (pts.length < 2) return null;
  const min = Math.min(...pts);
  const span = Math.max(...pts) - min || 1;
  const w = 100;
  const h = 32;
  const points = pts.map((v, i) => `${(i / (pts.length - 1)) * w},${h - 2 - ((v - min) / span) * (h - 4)}`).join(' ');
  return (
    <svg className="kpi-spark" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" aria-hidden="true">
      <polyline points={points} fill="none" stroke="currentColor" strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

const ARROW = { up: '▲', down: '▼', flat: '–' };
const TONE_TEXT = { good: 'improvement', bad: 'decline', neutral: 'no change' };

export function KpiCard({ label, value, previous, format, goodDirection = 'up', comparisonLabel = 'vs previous period', trend, footnote }) {
  const comparable = Number.isFinite(value) && Number.isFinite(previous) && previous !== 0;
  const change = comparable ? (value - previous) / Math.abs(previous) : null;
  const dir = change == null || change === 0 ? 'flat' : change > 0 ? 'up' : 'down';
  const tone = dir === 'flat' ? 'neutral' : dir === goodDirection ? 'good' : 'bad';
  return (
    <section className="kpi">
      <h3 className="kpi-label">{label}</h3>
      <p className="kpi-value">{format(value)}</p>
      {change == null ? (
        <p className="kpi-delta kpi-neutral">No comparison available</p>
      ) : (
        <p className={`kpi-delta kpi-${tone}`}>
          <span aria-hidden="true">{ARROW[dir]} </span>
          {formatDelta(change)} <span className="kpi-context">{comparisonLabel}</span>
          <span className="visually-hidden"> ({TONE_TEXT[tone]})</span>
        </p>
      )}
      {trend && <Sparkline values={trend} />}
      {footnote && <p className="kpi-footnote">{footnote}</p>}
    </section>
  );
}
```

```jsx
// Usage
<div className="kpi-row">
  <KpiCard label="Revenue" value={48210} previous={45300} format={formatCurrency('USD', true)} trend={revenueByDay} />
  <KpiCard label="Churn rate" value={0.031} previous={0.028} format={formatPercent} goodDirection="down" />
  <KpiCard label="p95 latency" value={212} previous={240} format={formatUnit('millisecond')} goodDirection="down" footnote="Partial day" />
</div>
```

```css
.kpi-row { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); }
.kpi { display: grid; gap: 4px; padding: 16px; border: 1px solid var(--chart-grid, #e5e7eb); border-radius: 8px; }
.kpi-label { margin: 0; font-size: 0.875rem; font-weight: 500; color: var(--chart-text, #374151); }
.kpi-value { margin: 0; font-size: 2rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.1; }
.kpi-delta { margin: 0; font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.kpi-good { color: #0072b2; }
.kpi-bad { color: #b54f00; }
.kpi-neutral, .kpi-context, .kpi-footnote { color: #6b7280; }
.kpi-spark { width: 100%; height: 32px; color: var(--chart-1, #0072b2); }
```

- Delta is relative change against the comparison value; a previous value of 0 or missing shows "No comparison available", never `Infinity %`.
- Rates and percentages: consider showing the change in percentage points (`+0.3 pp`) instead of relative change; label which one it is.
- Good/bad uses blue/vermillion plus arrow and hidden text — never green/red alone.
- The sparkline scales with `viewBox` + `preserveAspectRatio="none"`; `vector-effect="non-scaling-stroke"` keeps the line width constant. It has no labels, so it is `aria-hidden` and the card text carries the meaning.

## Grid

```css
.dashboard { display: grid; gap: 16px; grid-template-columns: repeat(12, minmax(0, 1fr)); }
.dashboard > .kpi-row, .panel--full { grid-column: 1 / -1; }
.panel { grid-column: span 6; min-width: 0; container-type: inline-size; }
.panel--primary { grid-column: span 8; }
.panel--side { grid-column: span 4; }
@media (max-width: 64rem) { .panel, .panel--primary, .panel--side { grid-column: 1 / -1; } }
@container (max-width: 30rem) { .chart-data summary { font-size: 0.875rem; } .chart-desc { font-size: 0.875rem; } }
```

- `min-width: 0` stops charts from forcing their grid track wider than the viewport.
- Container queries (`container-type: inline-size`) let a chart adapt to its panel, not the window: fewer ticks, horizontal bars, stacked legend.
- Give each chart area a fixed height per breakpoint so loading → ready does not shift the layout.

## Panel states

```jsx
// Panel.jsx — status: 'loading' | 'error' | 'empty' | 'ready'
import { formatDateTime } from './format';

export function Panel({ title, status, onRetry, updatedAt, className = '', children }) {
  return (
    <section className={`panel ${className}`} aria-busy={status === 'loading'}>
      <header className="panel-head">
        <h2 className="panel-title">{title}</h2>
        {updatedAt && <p className="panel-meta">Updated {formatDateTime(updatedAt)}</p>}
      </header>
      <div className="panel-body">
        {status === 'loading' && <div className="panel-skeleton"><span className="visually-hidden">Loading {title}</span></div>}
        {status === 'error' && (
          <p role="alert">
            This chart could not be loaded. <button type="button" onClick={onRetry}>Retry</button>
          </p>
        )}
        {status === 'empty' && <p className="chart-empty">No data for this range. Try a longer period or clear filters.</p>}
        {status === 'ready' && children}
      </div>
    </section>
  );
}
```

```css
.panel-body { min-height: 320px; }
.panel-skeleton { height: 320px; border-radius: 8px; background: var(--chart-grid, #e5e7eb); }
@media (prefers-reduced-motion: no-preference) {
  .panel-skeleton { animation: panel-pulse 1.5s ease-in-out infinite; }
}
@keyframes panel-pulse { 50% { opacity: 0.5; } }
```

| State | Show | Never |
| --- | --- | --- |
| Loading | Skeleton the size of the chart | Spinner that collapses the layout; stale data without a marker |
| Empty | Why it is empty + what to change | Empty axes, a flat line at zero |
| Error | Plain message + retry; other panels keep working | Whole page error for one failed query |
| Partial | Last bucket marked "partial"; KPI footnote | Comparing a partial period to a full one without saying so |
| Stale | "Updated <time>" in the header or panel | Silent caching |

## Performance

| Situation | Approach |
| --- | --- |
| > 2k SVG marks | Canvas renderer, or aggregate |
| Long time series (> ~1 point per pixel) | Downsample to ~1–2 points per pixel (LTTB or min/max per bucket), server-side if possible |
| Many panels | Lazy-render below the fold (`IntersectionObserver`); one request per panel, fetched in parallel |
| Resize storms | Batch `ResizeObserver` updates to one per animation frame; memoize scales on `(data, width)` |
| Live data | Throttle redraws to ≥ 1 s; keep axes stable between ticks; no transition on each tick |

## Interaction

- Filters and date range persist in the URL so views are shareable and survive reload.
- Cross-filtering (click a bar to filter the page) needs a visible "active filter" chip with a clear button.
- Drill-down goes to a detail table or page; tooltips are not a drill-down.
- Export: CSV of the exact data behind each panel, with units and the active filters in the file name or header row.
