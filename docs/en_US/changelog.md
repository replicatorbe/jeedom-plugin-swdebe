# Changelog

## 0.2.3

- An isolated failed read of swde.be is no longer logged as an error, and so
  no longer creates an entry in the message centre: it is a warning. The error
  is only raised on the third failure in a row, once, and the return to normal
  is then reported in the log.
- A call failing on the network (timeout, DNS) is retried once straight away
  before giving up.
- Default request timeout raised from 10 to 20 seconds.
- A failed read with no known state is no longer logged twice.
- The message the core posts for that error in the message centre is removed
  on the first successful read.

## 0.2.2

- New **Last check**, **Read error** and **Last error** commands: when swde.be
  stops answering, the previous state is still shown but no longer silently —
  the date of the last successful read is visible and the error stays raised
  until the next one, backoff included.
- During an error, the republished state no longer counts as a
  communication: the device timeout, set to 60 minutes when empty, reports a
  stuck read.
- Health page: age of the last successful read and last error of each
  device.

## 0.2.1

- An incident whose detail page could not be read is no longer taken for a
  finished one: end actions no longer fire by mistake, and the start alert is
  no longer replayed on the next cycle.

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
- 15 min cron (configurable, min 15 min).
- 6 h cache on incident details, 1 h backoff after a failure.
