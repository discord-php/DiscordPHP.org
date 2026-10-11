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
| `index.html`, `libraries.html`, `ecosystem.html`, `guides.html`, `community.html`, `newsletter.html`, `404.html` | The pages |
| `uml/index.html` | The main classes (generated), a REST request and a gateway event (hand-written, checked against DiscordPHP) |
| `uml/architecture.json` | Which classes and members the class diagram shows, and which classes the sequence diagrams' participants are |
| `uml/routes.html`, `uml/events.html` | The generated maps, drawn in the browser from `data/routes.json` |
| `assets/` | One stylesheet and a few ES modules. Diagrams are drawn with [Mermaid](https://mermaid.js.org/) from jsDelivr. |
| `data/routes.json` | The route and event maps (generated) |
| `data/ecosystem.json` | The shared project catalog and newsletter tag taxonomy used by both sites |
| `data/versions.json` | Legacy latest-release badges for any `data-version` elements (generated) |
| `data/releases.json` | The latest release and Composer compatibility for each catalog project (generated) |
| `data/newsletter.json` | The shared, tagged newsletter editions (written by the newsletter bot) |
| `newsletter.xml` | The generated RSS feed, with newsletter tags as categories |
| `tools/routes-uml.php` | Generates `data/routes.json` |
| `tools/architecture-uml.php` | Draws the class diagram and its package table on `uml/index.html`, and checks the UML pages' other diagrams and prose against DiscordPHP |
| `tools/versions.php` | Generates release and compatibility metadata, plus any legacy version badges |
| `tools/newsletter-feed.php` | Generates the shared tagged RSS feed |
| `tools/check-diagrams.php` | Draws every diagram through [mermaid.ink](https://mermaid.ink) and fails on any Mermaid rejects |

## Working on the site

```sh
composer serve
```

serves it at <http://localhost:8080> (or run `php -S localhost:8080` without Composer). Everything uses
relative links, so the site works at a domain's root and under `/DiscordPHP.org/` on github.io alike.

### Link previews

The home, library, release, guide, community, newsletter and UML pages contain static Discord component embeds and Open
Graph/Twitter fallback tags in their heads. The preview uses the existing DiscordPHP organization
logo (`assets/images/discordphp.png`, copied from its GitHub avatar), the site's accent color and
page-specific links. These display-only previews require no bot or interaction handler. The 404 page
is excluded because an error response should not unfurl.

After changing a page title, description or preview link, regenerate the marked head blocks:

```sh
php tools/build-previews.php
php tools/test-previews.php
php tools/check-previews.php
```

`tools/preview-payload.php` serializes HTML-safe JSON and validates the supported display component
subset, the 3,000-byte encoded payload, 40-component total (including accessories and root), ten
gallery items across the whole payload, and HTTP(S) links. The focused tests include boundary and
invalid payload cases. The page checker validates server-rendered head placement, canonical URLs,
fallback metadata and local preview targets. CI runs it against the exact staged `_site` artifact.
It does not contact Discord or prove crawler reachability, fetch timing or live rendering; those
require a later deployed preview check. Contract sources:
[Component Embeds](https://docs.discord.com/developers/link-previews/component-embeds) and
[Link Previews](https://docs.discord.com/developers/link-previews/overview).

### Diagnosing custom-domain redirects

Run `php tools/check-redirects.php` for read-only deployed header checks, or supply a known site URL
such as `https://discordphp.org/guides.html`. It uses HEAD requests, follows at most five redirects,
allows only the site's three known hosts, keeps TLS verification enabled and limits each chain to
ten seconds. It rejects loops, HTTPS downgrades, non-2xx responses and terminal pages outside the
canonical HTTPS domain. It does not validate a GET body, image fetches or Discord rendering. Run
`php tools/test-redirects.php` for injected offline fixtures; CI runs only those offline tests.

On October 10, 2026, public HTTPS for the apex and `www` redirected through Cloudflare to
`https://discord-php.github.io/DiscordPHP.org/`, which GitHub redirected to `http://discordphp.org/`.
The Pages source was `gh-pages` `/`, with custom domain `discordphp.org` and HTTPS enforcement off.
Its deployed commit was `e04ed407e3e6934813468ad37a2c9026e8edfa3a` (site source `a377a41`). Direct
GitHub Pages origin HTTP with the custom-domain Host returned 200; origin HTTPS failed certificate
hostname verification. The deployed HTML had no redirect markup. GitHub's DNS health reported both
public hosts proxied through Cloudflare and not HTTPS eligible. The exact Cloudflare rule and
underlying origin DNS targets require inspection in the owner's account; public DNS hides them.

The hosting correction, requiring separate authorization, is:

1. Disable the Cloudflare forwarding/redirect to the `github.io` repository URL for the apex and
   `www` (inspect Redirect Rules, Bulk Redirects, Page Rules and Workers). Keep `discordphp.org` in
   Pages settings and the existing CNAME file; GitHub's redirect back to that custom domain is expected.
2. Point apex DNS at GitHub Pages: A records `185.199.108.153`, `185.199.109.153`,
   `185.199.110.153`, `185.199.111.153`; optional AAAA records `2606:50c0:8000::153` through
   `2606:50c0:8003::153`. A flattened apex CNAME to `discord-php.github.io` is an alternative.
   Set `www` CNAME to `discord-php.github.io`, without a URL scheme or repository path. Preserve
   unrelated MX/TXT records. Start with these website records DNS-only to establish the origin.
3. Wait for Pages DNS verification and a valid custom-domain certificate, then enable Enforce HTTPS.
   Keep certificate verification enabled during acceptance. Do not switch Cloudflare to Full (strict)
   while the origin certificate is invalid. If re-enabling the proxy later, use Full (strict) with
   the valid origin certificate and keep redirects toward `https://discordphp.org`, never back to
   `github.io`. Preserve paths and queries on any `www` canonical redirect.
4. Purge obsolete redirect cache and retest apex, `www`, the default GitHub URL and a detail page:
   each must finish at a 2xx HTTPS HTML page on `discordphp.org`. Then verify Discord crawler GETs.

Sources: [GitHub custom-domain configuration](https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/managing-a-custom-domain-for-your-github-pages-site)
and [Cloudflare redirect-loop troubleshooting](https://developers.cloudflare.com/ssl/troubleshooting/too-many-redirects/).
No repository-only change repairs external redirect rules, DNS or certificate provisioning.

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

## Shared ecosystem catalog

`data/ecosystem.json` is the canonical list of DiscordPHP and Valgorithms projects. Each entry selects
the sites where it appears, its Composer package and useful next steps. `libraries.html` renders the
DiscordPHP audience and `valgorithms.com` renders the Valgorithms audience from this same file.
`ecosystem.html` shows the latest stable release and the PHP, DiscordPHP and extension constraints
from each tagged Composer release. The Valgorithms Pages workflow checks out this repository and
refreshes that release data before its nightly build.

## The newsletter

`newsletter.html` shows the tagged daily newsletter from the
[DiscordPHP-Newsletter](https://github.com/Valgorithms/DiscordPHP-Newsletter) bot, whose source code the page links to. The bot writes each edition with a
locally hosted model and DMs it to its owner for approval. Once an edition is approved, the bot commits it to
`data/newsletter.json` on `main`, and that push publishes the site as usual. Each entry has topic IDs
from `newsletterTags` in the shared catalog and looks like:

```json
{ "key": "2026-09-27", "date": "2026-09-27", "headline": "…", "intro": "…",
  "tags": ["discordphp", "releases"], "sections": [{ "title": "…", "body": "…" }],
  "signoff": "…", "published_at": "2026-09-27T18:04:00-04:00" }
```

Bodies are Discord-flavoured markdown. `assets/js/newsletter.js` escapes everything, then applies bold, italics,
inline code, bullet lists, `https` links and `owner/repo#123` references, so an edition can never add HTML to the page.
Each edition has a permalink, `newsletter.html#<key>`. The page filters by topic and
`newsletter.xml` publishes the same tagged editions as RSS for both sites.

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
