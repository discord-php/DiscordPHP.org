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
| `uml/index.html` | The main classes (generated), a REST request and a gateway event (hand-written, checked against DiscordPHP) |
| `uml/architecture.json` | Which classes and members the class diagram shows, and which classes the sequence diagrams' participants are |
| `uml/routes.html`, `uml/events.html` | The generated maps, drawn in the browser from `data/routes.json` |
| `assets/` | One stylesheet and a few ES modules. Diagrams are drawn with [Mermaid](https://mermaid.js.org/) from jsDelivr. |
| `data/routes.json` | The route and event maps (generated) |
| `data/versions.json` | The latest release of each package on the libraries page (generated) |
| `data/newsletter.json` | The published newsletter editions, which `newsletter.html` renders (written by the newsletter bot) |
| `tools/routes-uml.php` | Generates `data/routes.json` |
| `tools/architecture-uml.php` | Draws the class diagram and its package table on `uml/index.html`, and checks the UML pages' other diagrams and prose against DiscordPHP |
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

### The class diagram

The class diagram on `uml/index.html`, and the package table under it, are drawn from DiscordPHP's code
by `tools/architecture-uml.php`, between the `<!-- architecture-uml: … -->` markers. Don't edit them by
hand. `uml/architecture.json` says what to show:

- **Classes and members.** List a class and the members to show: `name()` for a method, `$name` for a
  property (declared, or a `@property` in the class docblock) and `NAME` for a constant. Parameters and
  types come from the code, so they always match the release.
- **Relations.** Written in Mermaid's arrows. An inheritance arrow (`<|--`, `<|..`) must be true in the
  code.

The two sequence diagrams stay hand-written, because what happens in what order can't be read from the
code. The tool checks them against it instead:

- every call in a message, such as `post(...)`, must be a method of a class of the participant that sends
  or receives it (`sequences` in the spec lists each participant's classes),
- `op` numbers must be the values of the `Op` constants they are named after,
- names such as `MESSAGE_CREATE` must be gateway events DiscordPHP knows,
- `Class::member` and `$discord->method()` in any UML page's prose must exist.

If anything is missing, it lists each problem and leaves the page alone, so the build fails instead of
publishing a diagram that no longer matches the release. Run it after changing the spec, or to check a
draft against another DiscordPHP version:

```sh
composer uml   # tools/architecture-uml.php ../DiscordPHP uml/architecture.json uml/index.html uml/events.html uml/routes.html
```

`--source=<tree>` reads the code from another tree of the repository, as the fourth argument of
`routes-uml.php` does.

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

`.github/workflows/pages.yml` generates the maps and the class diagram, checks the hand-written diagrams
against DiscordPHP and that every diagram draws, validates the site output, and publishes the validated
artifact to the `gh-pages` branch. Pull requests run the same build without publishing. The build job has
read-only repository permissions; only the publish job can write. It maps the latest DiscordPHP release,
and runs:

- when DiscordPHP publishes a release, through a `discordphp-release` dispatch (below),
- every day, to pick up changes to Discord's OpenAPI description, and any release whose dispatch did not
  arrive,
- on every push to `main`,
- by hand, from the Actions tab. You can give a DiscordPHP branch, tag or commit to map instead of the
  latest release.

A pull request runs the same build without publishing, so a diagram that no longer matches DiscordPHP
fails before it is merged.

GitHub Pages serves the `gh-pages` branch. The root `CNAME` file contains `discordphp.org`; the workflow
copies it into each deployment and verifies it before publishing, so the custom domain survives every
publish.

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
