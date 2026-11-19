---
name: github-readme
description: Write or overhaul a GitHub repository's README and front-page presentation — a README with a one-line pitch, meaningful badges, features, a Quickstart verified against the code, usage, configuration, contributing and license; the repo "About" description (≤350 chars) and topics; a hand-written SVG banner that renders on GitHub in light and dark themes; and the 1280×640 social preview image. Use when the user asks to write, improve, rewrite, clean up or polish a README, make a repository look professional on GitHub, add a banner, header image or logo to a README, write the GitHub description or About text, suggest topics or tags, or prepare a repository for publishing or open-sourcing.
license: MIT
---

# GitHub README and repo presentation

Deliverables:

| File / field | Role |
| --- | --- |
| `README.md` | Front page: what it is, why, how to run it in under a minute |
| `.github/assets/banner.svg` | Hand-written banner at the top of the README (optional `banner-dark.svg`) |
| About → Description | One sentence, ≤350 chars, shown on the repo page, in search and in link cards |
| About → Topics | 5–10 lowercase tags that make the repo discoverable |
| Social preview | 1280×640 PNG uploaded in repository settings (SVG is not accepted) |

Everything written is traceable to the repository or to the user. The README is documentation, not marketing.

## Workflow

1. **Read the repo before writing.** Package manifests, entry points and `bin` targets, scripts (`package.json` scripts, `Makefile`, `justfile`, task runners), `.env.example`/config loaders, CI workflows, existing docs, `CHANGELOG`, `CONTRIBUTING`, `LICENSE`, examples. Fill a fact sheet: name, what it does, audience, install method, runtime versions, run/test/build commands, config keys with defaults, license SPDX id. Where each fact lives per ecosystem: [references/readme-structure.md](references/readme-structure.md#sources-of-truth).
2. **Pitch.** One sentence: what it is + for whom + the one differentiator. It becomes the README tagline and the About description.
3. **Draft** from the README skeleton below. Delete any section with nothing true to say; pick the section order for the repo type from [references/readme-structure.md](references/readme-structure.md#section-order-by-repo-type).
4. **Verify Quickstart.** Run every command in order in a clean checkout (fresh clone or `git worktree add`). If running is impossible, trace each command to the script, manifest entry or binary that implements it, and say so to the user. Prerequisite versions come from the manifest (`engines`, `requires-python`, `rust-version`, `go` directive), never from memory.
5. **Banner.** Start from the SVG template below; follow [references/svg-banner.md](references/svg-banner.md) for rendering rules, light/dark handling, validation and PNG export.
6. **Links.** Check every relative link, image path and `#anchor` with the link checker in [references/readme-structure.md](references/readme-structure.md#link-check).
7. **Metadata.** Description, topics, website and social preview per [references/repo-metadata.md](references/repo-metadata.md). Apply with `gh repo edit` only if the user asked; otherwise hand them the values.
8. **Review** with the checklist below. Report which commands were actually run and which were only traced.

## Hard rules

- Never invent features, platforms, integrations, benchmarks, users, roadmap items or compatibility claims. Unsure → leave it out or ask.
- Quickstart runs copy-paste from a fresh clone, top to bottom. No `$ ` prompts inside code blocks (they break the copy button), no `...`, no steps that silently depend on unstated tools. Fill-ins are `<angle-brackets>` and explained on the line above.
- Badges only for live, true signals: CI status, latest release/package version, license, coverage if it is tracked. One line, at most ~6. No static "made with", "awesome", visitor-count or social badges.
- In-repo links and images are relative (`docs/usage.md`, `.github/assets/banner.svg`) so they work on forks, branches and tags. Absolute URLs only for external targets.
- Every image has `alt` text that says what it shows. Text in the banner is repeated as real Markdown text below it (search, screen readers, narrow screens).
- The first screen — banner, title, pitch, badges, first paragraph — answers what it is, who it is for and why it is different.
- The License section names the SPDX id and links `LICENSE`. No `LICENSE` file → tell the user; never pick a license for them.
- Match the repo's existing voice and terminology. No hype words ("blazing", "powerful", "revolutionary", "seamless"), no emoji headings unless the repo already uses them.
- SVG banner: no external fonts, stylesheets, images or scripts, no `<foreignObject>`; system font stack ending in a generic family; explicit `width`, `height` and `viewBox`; 1280×320 (or 1280×640); legible on GitHub's light and dark canvas; under ~20 KB. GitHub serves it through `<img>`, so anything external or scripted silently disappears.
- Do not rewrite `LICENSE`, `CONTRIBUTING.md`, `CHANGELOG` or code while doing a README task; list needed changes for the user instead.

## README skeleton

Headings are the default set; keep order, delete what does not apply.

````markdown
<p align="center">
  <img src=".github/assets/banner.svg" alt="<project-name>: <one-line pitch>" width="100%">
</p>

# <project-name>

<One-line pitch: what it is, for whom, the one differentiator.>

[![CI](https://github.com/<owner>/<repo>/actions/workflows/<ci-file>.yml/badge.svg)](https://github.com/<owner>/<repo>/actions/workflows/<ci-file>.yml)
[![License](https://img.shields.io/github/license/<owner>/<repo>)](LICENSE)

<Two or three sentences: the problem, how this solves it, what it is not.>

## Features

- **<Capability>** — <one clause on what it does for the user>.
- **<Capability>** — <one clause>.
- **<Capability>** — <one clause>.

## Quickstart

Requires <runtime> <version from manifest> and <other tool>.

```sh
git clone https://github.com/<owner>/<repo>.git
cd <repo>
<install command from the manifest>
<run command from the scripts>
```

<What the user should see now: URL to open, output line, created file.>

## Usage

<The two or three most common tasks, each a short example with real output.>

```sh
<command> <args>
```

## Configuration

| Variable / option | Default | Description |
| --- | --- | --- |
| `<NAME>` | `<default from code>` | <what it changes> |

## Development

```sh
<test command>
<lint or build command>
```

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup and conventions.

## License

[<SPDX-id>](LICENSE)
````

## Banner SVG template

1280×320, opaque background, so it reads the same on GitHub's light and dark themes. Replace the two text strings; keep the title ≤ ~24 characters and the tagline ≤ ~60.

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="320" viewBox="0 0 1280 320" role="img" aria-labelledby="banner-title banner-desc">
  <title id="banner-title">project-name</title>
  <desc id="banner-desc">One-line pitch in under sixty characters</desc>
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0f172a"/>
      <stop offset="1" stop-color="#1e293b"/>
    </linearGradient>
  </defs>
  <rect width="1280" height="320" rx="24" fill="url(#bg)"/>
  <g fill="none" stroke="#38bdf8" stroke-width="2">
    <circle cx="1130" cy="60" r="150" stroke-opacity="0.35"/>
    <circle cx="1130" cy="60" r="220" stroke-opacity="0.2"/>
    <circle cx="1130" cy="60" r="290" stroke-opacity="0.1"/>
  </g>
  <rect x="80" y="96" width="56" height="6" rx="3" fill="#38bdf8"/>
  <text x="80" y="184" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="76" font-weight="700" fill="#f8fafc">project-name</text>
  <text x="80" y="238" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="30" fill="#cbd5e1">One-line pitch in under sixty characters</text>
</svg>
```

- Placeholders here are plain text, not `<angle-brackets>`, so the file stays valid XML while you edit.
- Keep `<title>` and `<desc>` in sync with the visible text.
- Validate with `xmllint --noout .github/assets/banner.svg` and check the size with `wc -c`.

## Light/dark banner

Use two files only when the banner has a transparent background or theme-specific colors. This is GitHub's documented theme switch for Markdown images:

```html
<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/banner-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/banner.svg">
    <img alt="<project-name>: <one-line pitch>" src=".github/assets/banner.svg" width="100%">
  </picture>
</p>
```

The `<img>` is the fallback for renderers without `<picture>` support (package registries, some mirrors) — point it at the variant with the widest contrast.

## Description and topics

```text
Description: <What it is> for <audience> — <differentiator>.   (aim for 60–160 chars; hard limit 350)
Website:     <docs or demo URL, only if it exists>
Topics:      <language>, <framework>, <domain>, <artifact type>, <problem solved>
```

- Topics: lowercase letters, digits and hyphens, ≤50 chars each, at most 20; prefer the established spelling (check `github.com/topics/<topic>`).
- Cover language/runtime, framework, domain, artifact type (`cli`, `library`, `api`, `template`) and the problem solved. Skip the repo name and filler like `github` or `project`.
- Full guidance and the `gh repo edit` command: [references/repo-metadata.md](references/repo-metadata.md).

## Review checklist

- [ ] Every Quickstart command was run in a clean checkout, or traced to its implementation and reported as traced.
- [ ] Every feature bullet maps to code, docs or the user's words.
- [ ] Runtime versions match the manifest; config defaults match the code.
- [ ] All relative links, image paths and anchors resolve (link checker passes).
- [ ] Badges point at this repo and show live data; none are decorative.
- [ ] Banner: valid XML, no external references, explicit size and `viewBox`, < 20 KB, readable on light and dark themes and at ~400 px wide.
- [ ] Banner text is repeated as Markdown text; every image has `alt`.
- [ ] Description ≤350 chars and matches the README pitch; 5–10 established topics.
- [ ] Social preview PNG is 1280×640, opaque, under 1 MB.
- [ ] License section matches the `LICENSE` file and the manifest's license field.
