# Granite Grok — Player Missing Incident Post-Mortem

**Date:** 2026-05-04 / 2026-05-05
**Partner:** granitegrok (https://granitegrok.com)
**Severity:** Customer-reported player completely absent from articles
**Final state:** Resolved at v4.4.6, player rendering on every article via JS footer fallback

---

## What the customer reported

Steve MacDonald (Granite Grok) replied to a v4.5.2 update push from Rahul:

> "Followed the instructions. Now none of my articles have insta-read audio players on them."

This kicked off a multi-hour debugging session that surfaced **four separate bugs**, each of which masked the others. Fixing only one at a time produced no visible improvement, which made the loop frustrating until the layers were peeled apart.

---

## Symptoms

1. No `<div class="instaread-player-slot">` appeared in the HTML of any granitegrok.com article.
2. The remote player JS (`https://player.instaread.co/js/instaread.granitegrok.js`) loaded fine — so the script was running but had no slot to attach to.
3. Telemetry emails appeared to claim plugin updates were succeeding (`4.4.0 → 4.4.0`, then `4.4.1 → 4.4.2`), but the page never rendered any new behavior.
4. Manually triggering update webhooks sometimes produced telemetry from `wp-test.instaread.co` only, not from `granitegrok.com`.

The combination made the actual root cause hard to see — every individual signal was contradictory.

---

## What we initially assumed (and why each assumption was wrong)

### Assumption 1 — Bad config

The `target_selector` had been changed from `""` to `".entry-content"` in commit `8628994` on May 4 by another engineer. This **was** broken because the WordPress `the_content` filter only ever sees the inner content (no wrapper div) — so `.entry-content` will never match.

**Why this wasn't the only issue:** Reverting `target_selector` to `""` (matching the v4.2.6/v4.2.8 pattern that had previously worked) didn't fix the player. Three more layers were hiding underneath.

### Assumption 2 — Auto-update mechanism is broken

When manual webhook triggers showed telemetry from wp-test.instaread.co but not from granitegrok.com, we assumed granitegrok.com simply wasn't receiving update notifications.

**Why this was misleading:** The audio-processor server discovers partner sites by querying `SELECT DISTINCT site_url FROM plugin_telemetry WHERE partner_id = ?`. Granitegrok had been silent for a while, so when versions shipped, only wp-test (which had recent telemetry) got pinged. Triggering granitegrok.com directly via curl bypassed this and worked fine.

### Assumption 3 — Telemetry is reliable

The very first email said `4.3.0 → 4.3.0` from granitegrok.com — same version on both sides. We initially read this as "the update fired but didn't change anything," which is impossible. The real reason was a bug we'll cover below.

### Assumption 4 — `the_content` filter must run on this article

We saw `<p>` tags in the rendered HTML and assumed `the_content` (which runs `wpautop`) had fired, therefore our hook must have fired too. **This turned out to be wrong** — the GeneratePress theme (or some plugin in the stack) was outputting the post body in a way that bypassed our filter, even though `wpautop` had still wrapped paragraphs.

---

## Root causes (the four bugs)

### Bug 1 — Wrong target_selector for WordPress

**File:** [partners/granitegrok/config.json](../partners/granitegrok/config.json)

The selector was set to `".entry-content"` with `"fallback_injection": false`. WordPress's `the_content` filter only receives the inner article body — `.entry-content` is the theme wrapper added afterward. The selector never matches inside `the_content`, fallback was disabled, so injection silently skipped.

**Fix:** Reverted to `"target_selector": ""` + `"insert_position": "prepend"`. Empty selector triggers a fast-path (see [core/instaread-core.php:1293-1302](../core/instaread-core.php#L1293-L1302)) that directly prepends `$player_html . $content` without selector matching. This is the WordPress-native pattern documented in [docs/partner-config-reference.md](partner-config-reference.md).

### Bug 2 — Telemetry reported wrong version numbers

**File:** [core/instaread-core.php](../core/instaread-core.php) — `on_plugin_updated()` and `trigger_auto_update_now()`

When `upgrader_process_complete` fires mid-request, WordPress has just replaced plugin files on disk — but PHP doesn't reload classes mid-request. So `$this->plugin_version` (read from in-memory `$this->partner_config`) still holds the **pre-upgrade** version. The telemetry code did:

```php
$old_version = get_option(self::VERSION_OPTION_KEY, '0');  // unreliable, rarely written
$this->send_telemetry('update', $old_version, $this->plugin_version);  // both stale
```

`get_option(VERSION_OPTION_KEY)` was only ever updated in an activation path, almost never in production. And `$this->plugin_version` was the in-memory pre-upgrade value. Result: telemetry emails like `4.3.0 → 4.3.0` instead of the real change.

**Fix:** New `read_version_from_disk()` helper re-reads `config.json` from disk after install. `on_plugin_updated()` now uses `$this->plugin_version` as the authoritative pre-upgrade version (it IS the old code) and the freshly-read file content as the new version. Both call sites also `update_option(VERSION_OPTION_KEY, $new_version)` so future heartbeats stay accurate. See [core/instaread-core.php:508-547](../core/instaread-core.php#L508-L547).

### Bug 3 — `WP_Error` treated as truthy success

**File:** [core/instaread-core.php](../core/instaread-core.php) — `trigger_auto_update_now()`

```php
$result = $upgrader->install($download_url, ['overwrite_package' => true]);
if ($result) {  // ← BUG: WP_Error objects are truthy in PHP
    $this->send_telemetry('update', ...);  // ← fires even on real failure
}
```

When the install actually failed (file permissions, fs_unavailable, etc.), `Plugin_Upgrader::install()` returns a `WP_Error` object — which evaluates as truthy. So we'd send a "Plugin Updated" success telemetry email for an install that didn't happen. This made it impossible to distinguish "update worked" from "update silently failed" by looking at telemetry.

**Fix:** Changed to `if ($result === true)`. On failure, log the structured error and emit a new `update_failed` telemetry event so silent failures become visible. See [core/instaread-core.php:730-749](../core/instaread-core.php#L730-L749).

### Bug 4 — `the_content` filter bypassed entirely on granitegrok

**File:** [core/instaread-core.php](../core/instaread-core.php) — `inject_server_side_player()`

This was the actual reason no player ever rendered server-side, regardless of version. After fixing bugs 1, 2, 3 above and confirming the v4.4.5 install ran successfully, we still saw zero player divs in the HTML. The `<p>` wrapping suggested `the_content` ran, but our hook was demonstrably not modifying the output.

The hypothesis we converged on: GeneratePress (or one of granitegrok's plugins) outputs the main post body via a path that runs `wpautop` directly without invoking the `the_content` filter, OR a higher-priority filter rebuilds the content after we modify it. Either way, our `the_content` injection is a no-op on this site.

**Two fixes** for this layer, in priority order:

1. **`the_content_priority` config override** ([core/instaread-core.php:121-129](../core/instaread-core.php#L121-L129)). Default stays `PHP_INT_MAX - 1`. Granitegrok overrides to `99` (the v4.2.8 value) in case the issue was a content rebuilder running between `99` and `PHP_INT_MAX`. **This alone did not fix granitegrok**, but it's a useful escape hatch for future partners.

2. **Footer JS fallback** ([core/instaread-core.php:1279-1322](../core/instaread-core.php#L1279-L1322)). Opt-in via `"enable_footer_js_fallback": true`. Emits a tiny `wp_footer` inline script that does `document.querySelector(".entry-content").insertBefore(slot, firstChild)` if the slot isn't already present. Works regardless of whether `the_content` ran. **This is what actually fixed granitegrok.**

The slot div emitted by the JS fallback carries `data-instaread-source="footer-js-fallback"` so future debuggers can see at a glance that injection on this site is happening client-side, not server-side.

---

## What made this hard to diagnose

### No way to verify the actually-deployed version from outside

Telemetry was unreliable (Bug 2 + Bug 3). The only way to know what was on the partner's server was to ask the customer to look at the WP admin Plugins page. That's a slow loop, especially when the customer is also frustrated.

**Fix shipped in v4.4.6:** Every front-end page now emits `<meta name="instaread-version" content="X.Y.Z" data-partner="...">`. Every player slot div carries `data-instaread-version` and (when applicable) `data-instaread-source="footer-js-fallback"`. One curl tells you the truth:

```bash
curl -s https://<partner>/ | grep instaread-version
```

### The "telemetry says X" trap

Several rounds of debugging assumed the telemetry was ground truth. It wasn't. When telemetry contradicts directly-observable HTML, trust the HTML. The version meta tag now makes "directly observable" trivially cheap.

### Update webhook fan-out depends on telemetry history

The audio-processor only knows about partner sites that have previously sent telemetry. If a site goes silent (admin doesn't visit WP admin → no heartbeat → no DB row), it falls off the fan-out list and stops getting new release notifications. Granitegrok was effectively invisible to the webhook server until we curl-triggered it manually.

This isn't fixed yet — see "Follow-ups" below.

### Updates triggered on cached URLs are no-ops

Cloudflare caches `https://granitegrok.com/?instaread_force_update_check=granitegrok`. The first manual trigger reached origin PHP (4.2 second response time = real PHP work). Subsequent triggers within the cache window returned in 100ms — Cloudflare cached it, PHP never ran, no update happened.

**Workaround documented in [partner-config-reference.md](partner-config-reference.md#manually-triggering-an-update):** Always append a unique cache-buster like `&_=$(date +%s%N)` when manually triggering.

---

## How we proved the fix worked

Full chain of evidence, end-to-end:

1. Pushed v4.4.6 with `data-instaread-version` attributes and `<meta name="instaread-version">` head tag.
2. Triggered manual webhook: `curl ... "https://granitegrok.com/?instaread_force_update_check=granitegrok&_=$(date +%s%N)"`.
3. Telemetry email arrived: `Plugin Updated — granitegrok v4.4.6` with `4.4.5 → 4.4.6` from `https://granitegrok.com`. Confirmed our v4.4.3+ telemetry fix is also working — old → new versions are now reported correctly.
4. `curl -s https://granitegrok.com/ | grep instaread-version` returned `<meta name="instaread-version" content="4.4.6" ...>`. Confirmed v4.4.6 PHP is running.
5. Article page HTML contained `data-instaread-version="4.4.6" data-instaread-source="footer-js-fallback"` on the player slot. Confirmed the JS fallback is what's rendering the player on this site.
6. Customer can now see the player on articles.

---

## Timeline (compressed)

- **Customer reports issue.** Steve emails Rahul.
- **First diagnosis: bad selector.** Reverted `target_selector` to `""` → no visible improvement.
- **Multiple update triggers.** Got telemetry from `wp-test.instaread.co` only. Concluded granitegrok.com wasn't receiving updates.
- **Manual curl trigger to granitegrok.com directly.** Telemetry came back. Update was happening, but article still had no player.
- **Discovered `4.3.0 → 4.3.0` telemetry bug.** Fixed `on_plugin_updated()` to read version from disk post-upgrade.
- **Discovered `WP_Error treated as truthy` bug.** Added `update_failed` telemetry event.
- **Reverted filter priority to 99 for granitegrok.** No improvement.
- **Added opt-in footer JS fallback.** Player finally appeared.
- **Customer-reported v4.2.6 was previously working.** Compared old config to new — confirmed config was equivalent. The behavioral regression must have been in a core code change between Mar 24 and now.
- **Added `instaread-version` meta tag and slot data attributes.** Eliminated the "what version is actually deployed" question for all future debugging.

---

## Follow-ups (recommended, not yet done)

### High-priority

1. **Webhook fan-out should NOT depend on telemetry history.** The `triggerPartnerUpdateCheck` query (`SELECT DISTINCT site_url FROM plugin_telemetry`) drops sites that go quiet. Better source: the canonical `partners/<id>/config.json#domain` field plus a maintained registry of WP install URLs. As long as the webhook only knows about sites that have phoned home recently, we're one outage away from this same incident on a different partner.

2. **Audit the static `$already_injected` flag.** It's an early-return BEFORE the other gates. If anything triggers it on a non-target page (related-posts widget, social-sharing plugin), the actual main post render is silently skipped. We didn't conclusively prove it's safe in all theme combinations.

### Medium-priority

3. **Investigate why `the_content` is bypassed on granitegrok specifically.** The footer JS fallback works around the symptom. The real cause is probably a specific plugin or GeneratePress option. Identifying it would let us either fix it on the partner side or add a more targeted server-side workaround.

4. **Telemetry POST from granitegrok.com is being blocked.** Updates succeeded after v4.4.5 but the `Plugin Updated` email didn't always arrive — likely Wordfence or similar blocking outbound HTTP. Worth investigating the partner's security plugin config.

### Low-priority

5. **`injection_strategy` is documented in configs but unused by the code.** Either implement it or remove the dead key from configs and docs. It's harmless but confusing.

---

## Lessons

- **Telemetry is a hint, not a source of truth.** If it disagrees with the rendered HTML, the HTML wins. Bake a version marker into the rendered HTML so verification is one curl away.
- **Treat `WP_Error` like an exception.** Never use `if ($result)` on a WordPress API call that can return WP_Error. Always `if ($result === true)` or `if (!is_wp_error($result))`.
- **Don't let one fix mask the next layer.** When a fix produces no visible improvement, the immediate temptation is to assume the fix didn't apply. Equally likely: it applied and revealed the next problem. Verify each fix actually deployed before moving on.
- **Cache-bust manual diagnostic curls.** A 100ms response time on a URL that should require PHP work means you're seeing a CDN cache hit, not the actual server behavior. Always append `?_=$(date +%s%N)`.
- **Themes can bypass `the_content` even when wpautop runs.** Don't assume `<p>` wrapping in the output proves the filter chain executed. If injection isn't appearing despite a correct config, suspect filter bypass and have a JS-side fallback ready.
