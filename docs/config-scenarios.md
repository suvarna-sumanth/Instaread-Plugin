# Instaread Plugin — Config Scenario Reference

Two config keys control how and where scripts are injected:

| Key | Type | Default |
|---|---|---|
| `use_player_loader` | boolean | `false` (absent = false) |
| `enqueue_remote_player_script_sitewide` | boolean | `false` (absent = false) |

**Post page** = any page matching `injection_context` (singular, post, page, etc.)
**Non-post page** = homepage, category, archive, tag, search, etc.

---

## Scenario 1 — Neither key present

```json
{}
```

| Page type | What loads |
|---|---|
| Post page | Slot div + `instaread.{publication}.js` injected inline next to the slot |
| Non-post page | Nothing |

**How:** `render_single()` falls through all guards → appends `publication.js` inline.
`maybe_enqueue_remote_instaread_player_script()` exits early because `enqueue_remote_player_script_sitewide` is false.

---

## Scenario 2 — Only `use_player_loader: true`

```json
{
  "use_player_loader": true
}
```

| Page type | What loads |
|---|---|
| Post page | Slot div + `instaread.playerv3.js` injected inline next to the slot. `playerv3.js` then dynamically loads `instaread.{publication}.js` at runtime (no integrity hash). |
| Non-post page | Nothing |

**How:** `render_single()` hits the `should_use_player_loader()` guard → appends `playerv3.js` inline.
`maybe_enqueue_remote_instaread_player_script()` exits early because `enqueue_remote_player_script_sitewide` is false.

**Why this matters:** `playerv3.js` is a stable file partners can safely pin with an `integrity` hash. The publication bundle it loads dynamically has no integrity check, so Instaread can update it freely without breaking anything on the partner side.

---

## Scenario 3 — Only `enqueue_remote_player_script_sitewide: true`

```json
{
  "enqueue_remote_player_script_sitewide": true
}
```

| Page type | What loads |
|---|---|
| Post page | Slot div only (no inline script). `instaread.{publication}.js` is already loaded in `<head>` sitewide. |
| Non-post page | `instaread.{publication}.js` loaded in `<head>` |

**How:** `render_single()` detects `should_enqueue_remote_player_script_sitewide()` is true → returns slot only, no inline script.
`maybe_enqueue_remote_instaread_player_script()` proceeds → enqueues `publication.js` in `<head>` on every front-end page.

**Why this is used:** Floating player — the player needs to persist across navigation, so the script must be present on every page, not just post pages.

---

## Scenario 4 — `enqueue_remote_player_script_sitewide: true` + `use_player_loader: false`

```json
{
  "enqueue_remote_player_script_sitewide": true,
  "use_player_loader": false
}
```

Identical to Scenario 3. `use_player_loader: false` is the same as absent.

| Page type | What loads |
|---|---|
| Post page | Slot div only. `instaread.{publication}.js` in `<head>` sitewide. |
| Non-post page | `instaread.{publication}.js` in `<head>` |

---

## Scenario 5 — `enqueue_remote_player_script_sitewide: false` + `use_player_loader: true`

```json
{
  "enqueue_remote_player_script_sitewide": false,
  "use_player_loader": true
}
```

Identical to Scenario 2. `enqueue_remote_player_script_sitewide: false` is the same as absent.

| Page type | What loads |
|---|---|
| Post page | Slot div + `playerv3.js` inline. `playerv3.js` loads `publication.js` dynamically. |
| Non-post page | Nothing |

---

## Scenario 6 — `enqueue_remote_player_script_sitewide: true` + `use_player_loader: true`

```json
{
  "enqueue_remote_player_script_sitewide": true,
  "use_player_loader": true
}
```

| Page type | What loads |
|---|---|
| Post page | Slot div only. `playerv3.js` loaded in `<head>` sitewide (which then loads `publication.js` dynamically). |
| Non-post page | `playerv3.js` in `<head>` (which then loads `publication.js` dynamically). |

**How:** `render_single()` detects `should_enqueue_remote_player_script_sitewide()` is true → returns slot only, no inline script.
`maybe_enqueue_remote_instaread_player_script()` detects `should_use_player_loader()` is true → enqueues `playerv3.js` in `<head>` sitewide instead of `publication.js`.

**Net effect:** Same floating-player behavior as Scenario 3, but with `playerv3.js` as the sitewide script instead of `publication.js` directly — keeps the integrity-safety guarantee.

---

## Quick Decision Table

| `use_player_loader` | `enqueue_remote_player_script_sitewide` | Post page script | Non-post page script |
|---|---|---|---|
| false / absent | false / absent | `publication.js` inline | nothing |
| **true** | false / absent | `playerv3.js` inline → loads publication.js | nothing |
| false / absent | **true** | `publication.js` in `<head>` | `publication.js` in `<head>` |
| false | **true** | `publication.js` in `<head>` | `publication.js` in `<head>` |
| **true** | false | `playerv3.js` inline → loads publication.js | nothing |
| **true** | **true** | `playerv3.js` in `<head>` → loads publication.js | `playerv3.js` in `<head>` → loads publication.js |
