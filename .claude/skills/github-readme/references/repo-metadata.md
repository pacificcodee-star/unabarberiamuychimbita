# Repository metadata

The About sidebar and link cards are read more often than the README. Fill them from the same fact sheet and pitch.

## Description

| Rule | Detail |
| --- | --- |
| Length | Hard limit 350 characters. Aim for 60–160: search results and link cards truncate longer text |
| Shape | Noun phrase first: `<What it is> for <audience> — <differentiator>.` |
| Content | Same claim as the README pitch; no claims the README cannot back |
| Avoid | "A repo for…", "This project is…", the repo name repeated, emoji strings, version numbers, "WIP" |

Examples of shape (fill in real facts):

```text
Command-line tool that converts <input format> to <output format>, with streaming support for files larger than memory.
Self-hostable <kind of app> for small teams — one binary, SQLite storage, no external services.
<Language> library for <task> with a typed API and zero runtime dependencies.
```

## Website

Set only when a real destination exists: documentation site, live demo, package registry page. Never the repo URL itself.

## Topics

| Rule | Detail |
| --- | --- |
| Format | Lowercase letters, digits and hyphens; ≤50 characters each; at most 20 per repo |
| Count | 5–10 focused topics beat 20 loose ones |
| Spelling | Use the established form: open `https://github.com/topics/<topic>` and prefer the one with a curated page or the most repositories (`nodejs` vs `node-js`, `machine-learning` vs `ml`) |
| Avoid | The repo name, `github`, `project`, `code`, `awesome` (unless it is an awesome-list), years, versions |

Pick from each layer that applies:

| Layer | Examples |
| --- | --- |
| Language / runtime | `typescript`, `python`, `rust`, `go`, `nodejs` |
| Framework / platform | `react`, `nextjs`, `django`, `fastapi`, `tauri` |
| Artifact type | `cli`, `library`, `sdk`, `api`, `template`, `boilerplate`, `github-action`, `vscode-extension` |
| Domain | `developer-tools`, `data-visualization`, `accessibility`, `security` |
| Problem / feature | `static-site-generator`, `markdown`, `pdf`, `rate-limiting`, `self-hosted` |

Only list a framework or platform if the code depends on it.

## Applying with the GitHub CLI

Run only when the user asked you to change the repository settings; otherwise hand over the values.

```sh
gh repo edit <owner>/<repo> \
  --description "<description>" \
  --homepage "<website-url>" \
  --add-topic "<topic-1>,<topic-2>,<topic-3>"
gh repo view <owner>/<repo> --json description,homepageUrl,repositoryTopics
```

`--remove-topic` takes the same comma-separated list. Drop `--homepage` when there is no website.

## Social preview

The image shown when the repo link is shared (chat apps, social networks, search cards). Without one, GitHub generates a card from the name, description and owner avatar.

| Rule | Detail |
| --- | --- |
| Format | PNG, JPG or GIF — SVG is not accepted |
| Size | 1280×640 recommended (at least 640×320), under 1 MB |
| Background | Opaque; transparent pixels render against unpredictable backgrounds |
| Safe area | Keep title and logo inside the central ~1000×500; some cards crop the edges or show it small |
| Text | Title + pitch at most; large (title ≥ 96 px at 1280 wide) |
| Where | Repository → Settings → General → Social preview → Edit → Upload an image. Web UI only; no `gh` command for it |

Build it as a 1280×640 SVG under the banner rules and rasterize it — see [svg-banner.md](svg-banner.md#png-export-social-preview). Commit the SVG source (e.g. `.github/assets/social.svg`) so it can be regenerated; committing the PNG is optional.

## Other front-page signals

Mention to the user when missing; create only if asked:

- `LICENSE` — without it, GitHub shows no license and reuse is legally unclear.
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md` — shown in the sidebar and on the new-issue page.
- Issue and pull request templates in `.github/`.
- A release with notes, so the sidebar shows the latest version.
- Pinning the repo on the owner's profile, if it is a flagship project.
