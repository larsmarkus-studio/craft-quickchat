# Quickchat for Craft CMS

A tiny live chat for the beta of a new project: visitors chat from a widget, you answer from one
page in the control panel. Built on [Datastar](https://data-star.dev) and server-sent events, it runs
inside your Craft install, with no third-party service and no cookies.

- Craft CMS 5.6+, PHP 8.2+
- Datastar comes along as `putyourlightson/craft-datastar-module`; the Datastar plugin is optional
- Your web server must pass streamed responses through unbuffered (see [SSE and buffering](#sse-and-buffering))

## Installation

Until the first release is on Packagist, add the repository to your project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/larsmarkus-studio/craft-quickchat.git" }
]
```

```bash
composer require larsmarkus-studio/craft-quickchat
php craft plugin/install quickchat
```

Put the launcher in your layout, before `</body>`:

```twig
{{ craft.quickchat.widget() }}
```

Then set office hours, the support email and the texts under **Settings → Quickchat**, or in
`config/quickchat.php` (every property of `src/models/Settings.php`, multi-environment aware).

## What it does

- **Office hours**: outside them, visitors see your support email instead of the chat. Open
  conversations stay readable and writable, with an "offline" note.
- **Optional email** before the first message, so you can follow up after the visitor leaves.
- **Live both ways**: one SSE stream per open chat; the CP dashboard updates itself too.
- **Unread badge** on the CP nav item, **resolve / reopen** per conversation. A visitor
  writing in a resolved chat reopens it.
- **Mail per new conversation** to `notifyEmail` (optional).
- **Multi-site**: each chat stores the site it started on, the CP shows it on one shared page,
  and visitor texts follow the site's language (`translations/<lang>/quickchat.php`, Dutch included).
- **Page URL** the chat started on, shown in the CP.

## Footprint

- On page load: the launcher button, a few lines of inline CSS and `datastar.js`, only on pages
  with the widget. Load your own Datastar (e.g. Pro)? Set `registerScript => false` in `config/datastar.php`.
- On the first click: the panel and its stylesheet arrive over SSE. The stylesheet is Bootstrap 5.3
  cut down to modal, close button, buttons and form controls, scoped under `.qc` with `--qc-*`
  variables, so it never touches your own CSS (~4 KB gzipped).
- No JavaScript besides Datastar. Signals live under `qc`, so they can't clash with yours.
- Accent colour and launcher font: set `--qc-accent` and `--qc-launcher-font` on `.qc` in your own CSS.

## Privacy

Quickchat sets no cookies. The conversation key is a random token in the visitor's `localStorage`
and is sent in POST bodies only, never in URLs. The visitor endpoints skip CSRF on purpose: CSRF
protects cookie-based sessions, and these endpoints have none. Starting a chat is limited to one per
IP per 30 seconds and messages to one per second (through Craft's cache, where the IP sits hashed for 30 seconds; nothing about the visitor is stored besides what they type).

## SSE and buffering

Every SSE request stays open for `streamSeconds` (30) and polls the database once a second, then
the browser opens the next one. Each open chat therefore holds one PHP worker: fine for a beta,
not for a busy shop.

If messages arrive in bursts at the end of those 30 seconds, something buffers the response.
On Apache with PHP-FPM (DDEV's default), add `.ddev/apache/sse-flush.conf`:

```apache
<Proxy "unix:/var/run/php/php-fpm.sock|fcgi://localhost">
    ProxySet flushpackets=on
</Proxy>
```

Nginx needs nothing: the response sends `X-Accel-Buffering: no`.

## Tests

```bash
php tests/hours.php                                                   # office-hours logic, no Craft needed
npm install && QUICKCHAT_URL=https://my-site.ddev.site/ npm run e2e   # Playwright, against a page with the widget
```

The e2e test checks that the stylesheet loads lazily, that a message round-trips over SSE and
survives a reload, and that no quickchat response sets a cookie. Run it locally or on staging: it
leaves an `e2e …` conversation behind.

## Development

`npm install && npm run build` recompiles `src/web/quickchat.css` from `build/quickchat.scss`.
The compiled file is committed, so installing the plugin needs no Node.

Design decisions are in [`docs/decisions`](docs/decisions).
