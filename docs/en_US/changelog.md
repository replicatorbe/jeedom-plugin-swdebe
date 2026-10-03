# Changelog

## 0.2 — built-in alerts

- New **Alerts** tab on every device: when a new incident appears on the
  monitored postal code, the plugin plays the configured actions (mobile
  notification, SMS, spoken message, lamp…). The selector is the one from
  Jeedom scenarios — any action command and any message block is usable.
- Tokens in titles and messages: `#titre#`, `#perturbation#`, `#debut#`,
  `#fin#`, `#lieu#`, `#url#`, `#equipement#`, `#cp#`.
- **Test** button: plays the alert on the latest known incident, or on a
  fictitious incident if none, to validate the channel without waiting for
  a real water cut.
- Per-device memory of already-notified incidents: the same SWDE `nodeId`
  doesn't re-trigger. On first run after install, memory initialises
  without alerting — no spam about incidents already ongoing when the
  plugin is wired up.

## 0.1 — initial release

- Monitors one or several Belgian postal codes on the SWDE network.
- Detection via the site's JSON autocomplete + search page + detail pages.
- Exposed commands: binary state, counter, title, disturbance, start/end
  dates, locality, URL, JSON details, refresh button.
- 15 min cron (configurable, min 5 min).
- 6 h cache on incident details, 1 h backoff after a failure.
