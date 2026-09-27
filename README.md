# DiscordPHP.org

The website for [DiscordPHP](https://github.com/discord-php/DiscordPHP): the libraries, the guides and
references, the community, and UML diagrams of how DiscordPHP works, including a map of every Discord
REST route and gateway event to the code that handles it.

It is a static site with no build step for the pages themselves. The route and event maps are generated
data (`data/routes.json`), and GitHub Actions rebuilds them and publishes the site to the `gh-pages`
branch.

## Layout

| Path | What it is |
| --- | --- |
| `index.html`, `libraries.html`, `guides.html`, `community.html`, `newsletter.html`, `404.html` | The pages |
| `uml/index.html` | Hand-written diagrams: the main classes, a REST request, a gateway event |
| `uml/routes.html`, `uml/events.html` | The generated maps, drawn in the browser from `data/routes.json` |
| `assets/` | One stylesheet and a few ES modules. Diagrams are drawn with [Mermaid](https://mermaid.js.org/) from jsDelivr. |
| `data/routes.json` | The route and event maps (generated) |
| `data/versions.json` | The latest release of each package on the libraries page (generated) |
| `data/newsletter.json` | The published newsletter editions, which `newsletter.html` renders (written by the newsletter bot) |
| `tools/routes-uml.php` | Generates `data/routes.json` |
| `tools/versions.php` | Generates `data/versions.json` |
| `tools/check-diagrams.php` | Draws every diagram through [mermaid.ink](https://mermaid.ink) and fails on any Mermaid rejects |

## Working on the site

```sh
composer serve
```

serves it at <http://localhost:8080> (or run `php -S localhost:8080` without Composer). Everything uses
relative links, so the site works at a domain's root and under `/DiscordPHP.org/` on github.io alike.

### Regenerating the maps

`tools/routes-uml.php` reads DiscordPHP's source and Discord's OpenAPI description. It uses DiscordPHP's
own `scripts/OpenApiCheck.php`, which `composer openapi` also uses, so the map and that check always agree
about what DiscordPHP sends.

```sh
git clone https://github.com/discord-php/DiscordPHP ../DiscordPHP
composer install --working-dir=../DiscordPHP
curl -fsSL -o openapi_preview.json https://raw.githubusercontent.com/discord/discord-api-spec/main/specs/openapi_preview.json
php tools/routes-uml.php ../DiscordPHP openapi_preview.json data
php tools/check-diagrams.php data/routes.json uml/index.html
```

A fourth argument reads the source from another tree of the repository, such as a worktree at another
tag, while still using the first checkout's `vendor/`.

### Code style

The build tools follow the DiscordPHP family's php-cs-fixer rules:

```sh
composer install
composer cs
```

## The newsletter

`newsletter.html` shows the daily newsletter from the
[DiscordPHP-Newsletter](https://github.com/Valgorithms/DiscordPHP-Newsletter) bot, whose source code the page links to. The bot writes each edition with a
locally hosted model and DMs it to its owner for approval. Once an edition is approved, the bot commits it to
`data/newsletter.json` on `main`, and that push publishes the site as usual. Each entry looks like:

```json
{ "key": "2026-09-27", "date": "2026-09-27", "headline": "…", "intro": "…",
  "sections": [{ "title": "…", "body": "…" }], "signoff": "…", "published_at": "2026-09-27T18:04:00-04:00" }
```

Bodies are Discord-flavoured markdown. `assets/js/newsletter.js` escapes everything, then applies bold, italics,
inline code, bullet lists, `https` links and `owner/repo#123` references, so an edition can never add HTML to the page.
Each edition has a permalink, `newsletter.html#<key>`.

## Publishing

`.github/workflows/pages.yml` generates the maps, checks that every diagram draws, and publishes the site
to the `gh-pages` branch. It maps the latest DiscordPHP release, and runs:

- when DiscordPHP publishes a release, through a `discordphp-release` dispatch (below),
- every day, to pick up changes to Discord's OpenAPI description, and any release whose dispatch did not
  arrive,
- on every push to `main`,
- by hand, from the Actions tab. You can give a DiscordPHP branch, tag or commit to map instead of the
  latest release.

GitHub Pages serves the `gh-pages` branch. For the custom domain, add a `CNAME` file containing
`discordphp.org` to the root of this repository. The workflow copies it into each deployment, so the
domain survives every publish.

### Rebuilding when DiscordPHP is released

For the maps to update as soon as a release is published, DiscordPHP runs
`.github/workflows/site.yml` on each release. It sends this repository a `discordphp-release` dispatch
carrying the release's tag:

```sh
gh api repos/discord-php/DiscordPHP.org/dispatches -f event_type=discordphp-release -f "client_payload[tag]=$TAG"
```

Sending it needs DiscordPHP's repository secret `SITE_DISPATCH_TOKEN`. That is a fine-grained personal
access token with `discord-php` as its resource owner, access to `DiscordPHP.org` only, and the
**Contents: Read and write** permission, which is what a dispatch requires. A workflow's own
`GITHUB_TOKEN` cannot start workflows in another repository. Until the secret exists, DiscordPHP's
workflow skips the dispatch and the daily run here picks each release up within a day.

The same command, run by hand with a token, rebuilds the site for any tag.

## License

MIT. See [LICENSE](LICENSE). DiscordPHP is not affiliated with or endorsed by Discord Inc.
