# Amazon Link Monitor for TYPO3

Finds every Amazon link on your site and checks whether the linked products still
exist – as a backend module with history and as dashboard widgets. Runs entirely in
PHP on ordinary hosting: no headless browser, no Node.js.

## How links are checked

The check never creates an affiliate click:

1. **Short links** (`amzn.to`, `a.co`, `amzn.eu`) are resolved at the short link
   service without following the redirect to Amazon.
2. **Links with an ASIN** are verified in batches of ten through the
   **Amazon Creators API** (`getItems`) when API access is configured. A product
   that exists but is not available through the API (`ItemNotAccessible`) is
   reported as *not via API* so you can check it manually.
3. **All other links** (search, store pages – or everything without API access) are
   requested from Amazon without affiliate parameters. Amazon often answers
   automated requests with bot protection; such answers are reported as
   *unknown*, never as broken.

Status values: `ok`, `broken`, `warning` (not via API), `unknown`.

## Features

- Crawls all pages of the XML sitemap (incl. sitemap index), extracts Amazon links
  with link text, surrounding text, ASIN, affiliate ID and number of occurrences
- Groups links by page, broken pages first; filter, search, expandable details
- History of all check runs and a log of status changes (broken, OK again, removed)
- Pages that disappear from the sitemap are cleaned up
- Dashboard widgets: status, broken links, history
- Light/dark mode following the TYPO3 backend, English and German

## Requirements

- TYPO3 14.3 LTS (`typo3/cms-backend`, `typo3/cms-dashboard`)
- PHP 8.2+ with `sodium` and `dom`
- `typo3/cms-scheduler` recommended

## Installation

```bash
composer require derspe/amazon-link-monitor
vendor/bin/typo3 extension:setup
```

Open **Sites → Amazon Link Monitor**, check the settings and click
**Check links now**. Settings are stored per site; only administrators can change
them. The credential secret is stored encrypted.

### Settings

| Setting | Default |
|---|---|
| Amazon domains | amazon.de, amazon.com, amazon.co.uk, amazon.fr, amazon.it, amazon.es, amazon.nl, amazon.at, amzn.to, amzn.eu, a.co |
| Sitemap URL | `sitemap.xml` of the site base |
| Creators API | credential ID/secret, partner tag, marketplace, credential version, token URL and API base URL of your region |

### Scheduler

Add an *Execute console commands* task with `amazon-link-monitor:check`
(for example daily). A check is processed in time-boxed steps; if the time limit
(`--time-limit`, default 240 seconds) is reached, the next run continues where the
previous one stopped.

## Development

```bash
composer install
vendor/bin/phpunit
```

The UI uses plain ES modules with Lit and Chart.js as shipped by the TYPO3 core and
the dashboard extension – there is no build step.

## License

GPL-2.0-or-later
