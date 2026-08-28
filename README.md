# Commerce_ImportMonitor

Watches the scheduled imports and supplier feeds a catalogue depends on, alerts when one is late, stuck or failing, and reconciles what the supplier says is sellable against what Magento can actually sell.

Installs on its own. The two things only the host store can know — where its import history lives and where its stock lives — are interfaces you bind.

---

## Acknowledging an alert

An acknowledge link goes out by email and it silences monitoring, so it is a
**POST** carrying an **HMAC** over the alert id, keyed on the installation's
crypt key.

That shape is deliberate. A prefetching link scanner — the kind every mail
security gateway runs — issues a GET, and gets nothing. A guessed or altered id
fails verification. And "bad signature", "unknown id" and "already
acknowledged" all return the same response, so the endpoint cannot be used to
enumerate alerts.

The Slack bot token is stored with
`Magento\Config\Model\Config\Backend\Encrypted`, not as plain text: it is a
workspace-wide credential, and `core_config_data` ends up in every database
dump.

---

## Installation

```bash
composer require commerce/module-import-monitor
bin/magento module:enable Commerce_ImportMonitor
bin/magento setup:upgrade
```

---

## Wiring

Two interfaces have no default, because only your store knows the answer. The module installs and runs without them; checks that need them report themselves as *unable to run* rather than silently reporting healthy.

```xml
<preference for="Commerce\ImportMonitor\Api\ImportTaskSourceInterface"
            type="Acme\Erp\Model\ImportTaskSource"/>
<preference for="Commerce\ImportMonitor\Api\SalableQuantityProviderInterface"
            type="Acme\Inventory\Model\SalableQuantityProvider"/>
```

Feed layout, filename pattern, search directories and status attribute codes are all `di.xml` arguments — see `etc/di.xml`.

---

## When a feed counts as missing

Suppliers drop feeds in the evening, so a naive "today's file must exist" rule alerts from midnight until the drop. The check judges by the clock:

| When | Rule |
| --- | --- |
| Before the strict hour | Today's **or** yesterday's file satisfies the check |
| From the strict hour on | Today's file is required |
| Any hour | Nothing usable for **either** day always fails — that is a stale feed |

A zero-byte match counts as "not usable yet", so a file caught mid-transfer does not alert while yesterday's is still on hand. Past the strict hour an empty file is reported *as empty* rather than as absent, because that distinction tells you the drop happened but produced nothing.

Dates resolve in the **store** timezone, which is the clock the supplier's schedule is quoted against. Using the server's UTC date rolls over at the wrong moment and produces a spurious alert every night between the two midnights.

---

## CLI

```bash
bin/magento commerce:import-monitor:check                              # report only
bin/magento commerce:import-monitor:check --alert                      # also raise and notify
bin/magento commerce:import-monitor:reconcile-salability --file=var/import/feed_20260826_2013.csv
```

Both exit non-zero when something is wrong, so an external scheduler can notice without parsing output.

---

## How alerting behaves

Monitoring that nobody reads is worse than none, because it looks like coverage.
Most of the design here is about the alert volume rather than the detection.

- **De-duplication is on a fingerprint of the fault, not the message.** A message
  describes one *sighting* and embeds timestamps and file names, so two reports of
  the same fault never match as strings — and the same problem alerts every
  fifteen minutes all night. `CheckResult` derives the fingerprint from a seed
  the check supplies, and refuses a failure that carries none, so a check cannot
  accidentally opt out of de-duplication.
- **A fault that stops being reported is closed automatically.** Alerts that are
  never resolved fill the grid with problems that have long since fixed
  themselves, and operators learn to ignore it.
- **Recording a sighting is one atomic `INSERT … ON DUPLICATE KEY UPDATE`.**
  "Has this been sent?" followed by "save it" is a check-then-act race, and the
  cron runs every fifteen minutes.
- **One message goes to all recipients.** A distribution list of ten should not
  mean ten SMTP connections per alert.
- **Timestamps resolve in the store's timezone**, so they agree with every other
  timestamp in the admin.
- **The hostname is opt-in and off by default**, because an alert body is
  published to a chat workspace and internal host naming does not need to be.

## When monitoring cannot run

- **A check that throws becomes a failure result**, and the run continues. One
  unreadable directory stopping every other check — and the system then reporting
  nothing wrong — is the worst available failure mode.
- **The CLI reports every check with its outcome**, not only the failures, so
  "ran and found nothing" is distinguishable from "did not run".
- **A threshold you set is the threshold used.** Values are validated in the form
  and honoured in the code, rather than being silently clamped to a range that
  makes a weekly job impossible to describe.

## Notification channels

Channels are an interface, and the bundled Slack one posts to `chat.postMessage`
and reads the response. A Monolog `SlackHandler` attached to a throwaway logger
is a logging handler used as a notification client: a socket per alert batch, no
visibility of the API response, and Slack's own error payloads discarded — which
matters because Slack answers HTTP 200 for most failures.

---

## Gotchas

- **Two interfaces have to be bound before this module can tell you anything.** `ImportTaskSourceInterface` and `SalableQuantityProviderInterface` are host-specific — only your store knows where its import history and its stock live. Until you bind them, placeholders stand in and **throw**, and the checks that need them report themselves as *unable to run* rather than reporting healthy. That distinction is the whole point of a monitor.

  They throw rather than returning empty data because empty data is not neutral here: no task runs reads as "every import is missing", and no salable quantities reads as "nothing in the catalogue is sellable". The option that looks safer is the one that alerts on the entire catalogue at four in the morning.

  They exist at all — rather than the interfaces simply being left unbound — because an unbound interface is not a module that installs without a feature. It is a module whose classes cannot be constructed, and since both are reached from the check list, that takes out every `bin/magento` command rather than just two checks.
- **`--file` needs a `quantity` column whenever quantity decides sellability.** It is rejected if absent, which looks strict until you see the alternative: `value()` returns null for a column that is not there, `(float) null` is `0.0`, and zero fails the positive-quantity test — so a feed whose column is called `qty` yields no sellable SKUs at all and every report describes an empty set.
- **A reconciliation that examined nothing is reported as inconclusive, not clean.** "No discrepancies" and "could not look" produce the same empty list, and the command exits non-zero for both, because a cron watching the exit code cannot tell them apart from the summary line.
- **The acknowledge link is a GET page and a POST action.** A prefetching mail gateway only ever hits the page, which changes nothing. If you rewrite the email template, keep the link pointing at `importmonitor/alerts/acknowledge` and let the page do the POST.
- **Slack answers HTTP 200 for most failures**, with `{"ok": false, "error": "..."}` in the body. Checking the status code alone reports success for `invalid_auth` and `channel_not_found`.
- **Feed dates resolve in the store timezone, not the server's.** Using UTC rolls the day over at the wrong moment and produces a spurious alert every night in the hours between the two midnights.
- **Alert de-duplication keys on a fingerprint, not the message.** If you add a check, derive its fingerprint seed from the *fault* — a task code, a file pattern — and never from a message containing a timestamp, or it will alert on every cron run. `CheckResult` computes the fingerprint from the seed in its constructor and refuses a failure that carries no seed, so a check cannot accidentally opt out of de-duplication.
- **`ProductState` distinguishes "no product row" from "a product that cannot be sold".** `new ProductState(exists: false)` is the first; anything `ProductStateMapper::fromRow()` returns is the second, even when every field in the row is empty. They report different discrepancy reasons and need different fixes, so a zeroed state is not a substitute for an absent one.

---

## Tests

```bash
M2_VENDOR=/path/to/magento/vendor php ../dev/run-tests.php -c ../dev/phpunit.xml
```

The suite runs against a real Magento installation without being installed into it. `dev/bootstrap.php` builds a PSR-4-only autoloader from that installation's composer map, which is also why it works where the host's own `vendor/autoload.php` is broken.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```
