# README structure

## Sources of truth

Read these before writing a single command. The README restates them; it never overrides them.

| Ecosystem | Manifest | Install | Run / scripts | Version requirement | License field |
| --- | --- | --- | --- | --- | --- |
| JavaScript / TypeScript | `package.json` | lockfile decides the tool: `package-lock.json` → `npm ci`, `pnpm-lock.yaml` → `pnpm install`, `yarn.lock` → `yarn install`, `bun.lock`/`bun.lockb` → `bun install` | `scripts`, `bin` | `engines`, `.nvmrc`, `.node-version`, `packageManager` | `license` |
| Python | `pyproject.toml` (or `setup.cfg`, `setup.py`) | `uv.lock` → `uv sync`, `poetry.lock` → `poetry install`, else `pip install -e .` | `[project.scripts]`, `__main__.py`, task runner | `requires-python`, `.python-version` | `license` |
| Rust | `Cargo.toml` | `cargo build` / `cargo install --path .` | `[[bin]]`, `src/main.rs` | `rust-version`, `rust-toolchain.toml` | `license` |
| Go | `go.mod` | `go install <module>@latest` / `go build ./...` | `cmd/*/main.go` | `go` directive, `toolchain` | `LICENSE` only |
| PHP | `composer.json` | `composer install` | `scripts`, `bin` | `require.php` | `license` |
| Ruby | `Gemfile`, `*.gemspec` | `bundle install` | `exe/`, `Rakefile` | `.ruby-version`, `required_ruby_version` | gemspec `license` |
| .NET | `*.csproj`, `global.json` | `dotnet restore` | `dotnet run --project <path>` | `TargetFramework`, `global.json` sdk | `PackageLicenseExpression` |
| Any | `Makefile`, `justfile`, `Taskfile.yml`, `Dockerfile`, `compose.yaml` | — | targets/services | base image tags | — |

Also read: CI workflows (the real test/build commands and supported versions matrix), `.env.example` and the config loader (every key and its default), `examples/`, and existing docs to avoid contradicting them.

## Section guide

| Section | Contains | Rules |
| --- | --- | --- |
| Banner | One `<img>` or `<picture>` | Decorative duplicate of title + pitch; optional for small libraries |
| Title | `# <project-name>` | Exact package/binary name, same casing as the manifest |
| Pitch | One sentence | What + for whom + differentiator; same text as the About description |
| Badges | ≤6 on one line | Live signals only (see below) |
| Intro | 2–3 sentences | Problem, approach, non-goals. Optional screenshot or GIF right after |
| Features | 3–7 bullets | `**Capability** — benefit`. Each maps to code. No "and more" |
| Quickstart | Prerequisites, install, first run, expected result | ≤ ~6 commands, copy-paste from a fresh clone |
| Usage | 2–3 common tasks | Real examples with real output; CLI help pasted from an actual `--help` run |
| Configuration | Table: name, default, description | Every key from the config loader; defaults from code |
| Project structure | Short tree of top-level folders | Apps and monorepos only; ≤ ~12 lines |
| Development | Test, lint, build commands | Taken from CI or scripts |
| Deployment | Target and command | Only if the repo ships deploy config |
| Contributing | 1–3 lines + link | Link `CONTRIBUTING.md` if it exists |
| License | SPDX id linked to `LICENSE` | Must match the file and the manifest |

Omit sections rather than filling them with generic text. A small library can be title, pitch, badges, install, one usage example, license.

## Section order by repo type

| Repo type | Order |
| --- | --- |
| Library / package | Pitch → Badges → Install → Usage (API example) → Features → API reference link → Contributing → License |
| CLI tool | Pitch → Badges → Install → Quickstart (one command + output) → Usage (subcommands) → Configuration → License |
| App / service | Pitch → Screenshot → Features → Quickstart (local run) → Configuration → Deployment → Development → License |
| Monorepo | Pitch → Packages table (name, path, description, version badge) → Quickstart → Development → License |
| Template / starter | Pitch → What's included → "Use this template" steps → Customize → License |
| Collection (docs, skills, lists) | Pitch → Contents table by category → How to use / install → Contributing → License |

## Badges

| Badge | URL | Link to |
| --- | --- | --- |
| CI | `https://github.com/<owner>/<repo>/actions/workflows/<file>.yml/badge.svg` | the workflow page |
| npm version | `https://img.shields.io/npm/v/<package>` | `https://www.npmjs.com/package/<package>` |
| PyPI version | `https://img.shields.io/pypi/v/<package>` | `https://pypi.org/project/<package>/` |
| crates.io version | `https://img.shields.io/crates/v/<crate>` | `https://crates.io/crates/<crate>` |
| Latest release | `https://img.shields.io/github/v/release/<owner>/<repo>` | the releases page |
| License | `https://img.shields.io/github/license/<owner>/<repo>` | `LICENSE` (relative) |

- Add a registry badge only if the package is actually published under that name.
- Add CI only if the workflow exists and runs on the default branch; add `?branch=<default-branch>` to the CI badge URL if it triggers on other branches too.
- Format: `[![<label>](<badge-url>)](<link>)`, one per line in source; they render on one line.

## Writing rules

- Second person, present tense, imperative steps ("Install", "Run"), short sentences.
- Code blocks always carry a language (`sh`, `ts`, `python`, `toml`, `json`); shell blocks contain commands only, output goes in a separate `text` block.
- Name things exactly as the code does: flag names, env vars, file names, commands.
- One screenshot or GIF beats a paragraph for UI projects; store it in `.github/assets/`, give it `alt`, keep GIFs under ~5 MB.
- No "Table of contents" for short READMEs — GitHub renders an outline menu from the headings. Add a manual one only past ~8 sections.
- Long optional detail goes in `<details><summary>…</summary>…</details>` or a `docs/` page, not in the first screen.

## GitHub rendering facts

- README lookup order: `.github/README.md`, then root `README.md`, then `docs/README.md`.
- Relative links and images resolve against the file's folder and the branch being viewed; a leading `/` resolves from the repo root.
- Heading anchors: lowercase, punctuation removed except `-` and `_`, spaces → `-`; repeats get `-1`, `-2`.
- Allowed HTML includes `<p align>`, `<img width height alt>`, `<picture>`/`<source>`, `<details>`/`<summary>`, `<kbd>`, `<sup>`/`<sub>`, `<br>`. `style` attributes, `<style>`, `<script>`, classes and iframes are stripped.
- Alerts: `> [!NOTE]`, `> [!TIP]`, `> [!IMPORTANT]`, `> [!WARNING]`, `> [!CAUTION]` on the first line of a blockquote. Use at most one or two.
- ` ```mermaid ` blocks render as diagrams on github.com but not on most package registries — do not put the only copy of key information in one.
- Package registries (npm, PyPI, crates.io) render the README too, often without `<picture>` support and with different relative-link bases; use absolute image URLs there only if the user publishes to one and wants the banner shown.

## Link check

Checks relative file links, image paths and in-page anchors. Save outside the repo (or delete after use) and run with `node check-links.mjs README.md`.

```js
// check-links.mjs — exits 1 if any relative link, image or #anchor is broken.
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const file = process.argv[2] ?? 'README.md';
const prose = readFileSync(file, 'utf8').replace(/^(```|~~~)[\s\S]*?^\1/gm, ''); // drop fenced code
const slug = (h) => h.trim().toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/g, '-');

const anchors = new Set();
const seen = new Map();
for (const [, heading] of prose.matchAll(/^#{1,6}\s+(.+?)\s*#*$/gm)) {
  const base = slug(heading);
  const n = seen.get(base) ?? 0;
  anchors.add(n ? `${base}-${n}` : base);
  seen.set(base, n + 1);
}

const text = prose.replace(/`[^`\n]*`/g, ''); // drop inline code
const targets = [...text.matchAll(/\]\(<?([^)\s>]+)>?(?:\s+"[^"]*")?\)|\b(?:src|href|srcset)="([^"]+)"/g)]
  .map((m) => m[1] ?? m[2]);

let broken = 0;
for (const target of targets) {
  if (/^[a-z][a-z0-9+.-]*:/i.test(target)) continue; // http:, https:, mailto:
  const [path, hash] = target.split('#');
  if (!path) {
    if (!anchors.has(hash)) { console.log(`missing anchor: #${hash}`); broken++; }
    continue;
  }
  const base = path.startsWith('/') ? process.cwd() : dirname(file);
  if (!existsSync(resolve(base, decodeURIComponent(path.replace(/^\//, ''))))) {
    console.log(`missing file: ${target}`);
    broken++;
  }
}
console.log(broken ? `${broken} broken link(s)` : `all ${targets.length} links ok`);
process.exit(broken ? 1 : 0);
```

Run it from the repo root. It does not check external URLs or anchors inside other files; open those by hand.
