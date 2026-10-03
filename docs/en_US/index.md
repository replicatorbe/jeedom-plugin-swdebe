# SWDE plugin

Monitors ongoing incidents and interventions published by **SWDE** (Société
Wallonne des Eaux) for one or several Belgian postal codes, and exposes the
state as Jeedom commands.

## What it does

SWDE publishes water cuts, pressure drops and water quality issues in real
time on [swde.be](https://www.swde.be/fr/works). There is no official public
API: the plugin reads the website, detects active incidents for the
configured postal codes, and updates its commands accordingly.

## Installation

1. **Plugins → Plugin management → Add → Github**: user `replicatorbe`, repo
   `jeedom-plugin-swdebe`, branch `beta` or `master`.
2. Enable the plugin.
3. No dependencies: pure native PHP.

## Configuration

### Plugin settings

- **Request timeout**: seconds before giving up on an HTTP call to the SWDE
  site (default 10).
- **Refresh frequency**: minutes between two reads (default 15, minimum 5).

### Add a postal code

1. From the plugin page, click **Add a postal code**.
2. Give the device a name (e.g. "SWDE Naast").
3. Enter the Belgian postal code (4 digits).
4. **Test the postal code** queries the site without saving: shows how many
   active incidents are announced.
5. **Save**: commands are created, the site is read, the first state is
   stored.

## Exposed commands

| Command | Type | Content |
|---|---|---|
| **Ongoing incident** | info / binary | 1 if at least one incident is active |
| **Number of incidents** | info / numeric | active incident count |
| **Latest incident** | info / string | most recent incident title |
| **Disturbance** | info / string | kind of disturbance (cut, pressure, …) |
| **Start date** | info / string | ISO 8601 |
| **Expected end date** | info / string | ISO 8601 |
| **Locality** | info / string | commune reported by SWDE |
| **Incident URL** | info / string | direct link to the SWDE page |
| **Details (JSON)** | info / string | full structured incident list |
| **Refresh** | action | force an immediate reload |

## How it works

The plugin queries three paths of the SWDE website: the autocomplete JSON
endpoint, the works search page, and each active incident detail page. All
scraping is done through PHP's `DOMDocument` + `DOMXPath`, using the
deterministic Drupal CSS classes `field--name-field-inc-*`.

Incident details are cached for 6 hours. After an HTTP failure, the plugin
waits 1 hour before retrying, to avoid hammering the service.
