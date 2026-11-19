# Chart selection

Start from the question, then check the data's cardinality against the thresholds below. If two charts fit, pick the one read by position along a common axis.

## Encoding accuracy

Readers compare these most to least accurately — put the key comparison on the highest one:

1. Position on a common aligned axis (bar end, dot, line)
2. Position on unaligned axes (small multiples)
3. Length (bars that share a baseline)
4. Angle and slope (pie, slope chart)
5. Area (bubbles, treemap)
6. Color intensity or hue

## Not a chart

| Situation | Use instead |
| --- | --- |
| One number that matters | KPI card with comparison (see [dashboard-layout.md](dashboard-layout.md)) |
| Users look up exact values | Sortable table, right-aligned tabular numbers |
| 2–3 values | A sentence or KPI cards |
| Many attributes per row, few rows | Table with inline bars or sparklines |

## By question

### Comparison across categories

| Condition | Chart |
| --- | --- |
| ≤ 8 short-labelled categories | Vertical bar, sorted descending |
| Long labels or > 8 categories | Horizontal bar, sorted descending |
| > 20 categories | Top 10–15 + "Other", or a searchable table |
| Natural order (age band, size, weekday) | Keep that order, do not sort |
| 2–3 sub-groups per category | Grouped bar; > 3 → small multiples |
| Values bunched far from zero | Dot plot with a labelled zoomed axis (bars must start at zero) |
| Actual vs. target | Bar + target tick (bullet chart) |

### Trend over time

| Condition | Chart |
| --- | --- |
| ≥ 8 time points, 1–5 series | Line |
| < 8 discrete periods, or counts per period | Column |
| 6–12 series | Highlight 1–2, gray the rest; or small multiples with a shared y-axis |
| Cumulative total of one series | Area (zero baseline) |
| Composition over time, ≤ 4 parts | Stacked column; stacked area only when the total matters more than the parts |
| Two points in time per category | Slope chart or dumbbell |
| Irregular timestamps | Continuous time axis, never evenly spaced categories |
| Inline, next to a number | Sparkline, no axes, last point marked |

Mark the current incomplete period (dashed segment, hatched bar, "partial" label). Show gaps for missing data.

### Distribution

| Condition | Chart |
| --- | --- |
| One variable, n ≥ 50 | Histogram (try 2–3 bin widths; label the bin width) |
| n < 50 | Strip/dot plot with jitter or beeswarm |
| Compare 2–10 groups | Box plot or violin with points; ridgeline for many groups |
| Skewed with long tail (latency, revenue) | Histogram on a log axis, or percentile markers (p50/p90/p99) |
| Cumulative share | ECDF (step line) |

Never replace a distribution with a single mean bar; show spread (interval, quantiles) or the raw points.

### Part-to-whole

| Condition | Chart |
| --- | --- |
| 2–5 parts, one whole, shares roughly different | Pie or donut, largest slice from 12 o'clock clockwise, labelled with % |
| > 5 parts, or parts are close in size | Sorted horizontal bar of shares |
| Compare composition across categories | 100 % stacked bar, ≤ 5 segments, consistent segment order |
| Hierarchy | Treemap (area) or icicle/sunburst (≤ 3 levels) |
| Start → changes → end | Waterfall |

Parts must sum to a meaningful whole; overlapping categories (multi-select answers) are not part-to-whole — use bars.

### Relationship

| Condition | Chart |
| --- | --- |
| Two numeric variables | Scatter; add a trend line only if it is a stated model |
| Third numeric variable | Bubble size (area, not radius) or color ramp |
| > 5k points | Hexbin, 2D density, or canvas scatter with opacity |
| Many variables | Scatter matrix or correlation heatmap (diverging, midpoint 0) |
| Two variables over time | Connected scatter, or two aligned line charts |

Never use a dual-axis line pair to imply correlation: the axis ranges alone set the visual overlap.

### Geography

| Condition | Chart |
| --- | --- |
| Rates, ratios, per-capita values by region | Choropleth, classed sequential ramp (5–7 steps) |
| Counts or totals | Proportional symbols on a neutral base map |
| Regions of very different area but equal importance | Tile/hex grid map |
| Location matters less than rank | Sorted bar instead of a map |
| Points | Dot map with clustering or density above a few thousand points |

Always normalize a choropleth (raw counts just map population). Show a legend with class breaks and a "no data" swatch distinct from the lowest class.

### Other questions

| Question | Chart |
| --- | --- |
| Flow between stages | Funnel as horizontal bars with conversion %; Sankey for multi-path flows |
| Deviation from baseline or target | Diverging bar, colors by sign plus ▲/▼ |
| Uncertainty or range | Error bars, confidence band, or gradient interval |
| Schedule / duration | Gantt-style horizontal bars on a time axis |
| Density over two categorical dimensions (weekday × hour) | Heatmap with a sequential ramp and cell labels when ≤ 100 cells |

## Axes and scales

- Bars, columns and areas: linear scale including zero. Lines, dots: domain may be zoomed; label the axis and never break it silently.
- Log scale only for positive data spanning ≥ 2 orders of magnitude, labelled "log scale", with ticks at 1-2-5 or powers of ten.
- Shared y-domain across small multiples unless the point is shape, not size — then say "independent scales".
- 3–6 ticks at round numbers; grid lines only on the value axis.
- Percentages of a whole: 0–100 % axis. Percentage changes: diverging around 0.

## Data preparation checks

- Aggregate (sum, mean, percentile) upstream; charts do not compute business metrics.
- Fill missing time buckets explicitly with `null`, so the chart shows a gap instead of connecting across it.
- Bucket timestamps in the reporting time zone; weeks start on the locale's first weekday unless the business defines otherwise.
- Keep raw values numeric; formatting happens only at render time through `format.ts`.
- Record the unit and source for every metric; put them in the axis title or a footnote.
