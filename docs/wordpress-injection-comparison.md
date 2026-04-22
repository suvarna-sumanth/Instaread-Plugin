# WordPress Injection: Before vs After

Visual comparison of the wrong approach vs the correct WordPress-native approach.

---

## The Problem: CSS Selector Mismatch

### What the Plugin Receives (via `the_content` filter)

This is the **exact HTML** that `inject_server_side_player()` receives as the `$content` parameter:

```html
<p>It is the fourth World Cup in a row that Ireland have been dumped out in the quarter-finals...</p>

<figure class="wp-block-image">
  <img src="..." alt="..." />
</figure>

<p>They have suffered a total of eight World Cup quarter-final defeats...</p>
```

**Key:** No outer wrapper. Just raw article blocks.

---

## ❌ WRONG APPROACH: Using Theme Wrapper Selector

**Config:**
```json
{
  "target_selector": ".article-body",
  "insert_position": "prepend"
}
```

**What happens:**

1. Plugin receives REST API content (shown above)
2. Plugin searches for `.article-body` in `$content`
3. `.article-body` doesn't exist in the REST API content ← **SELECTOR MISMATCH**
4. Fallback JS mover kicks in
5. Plugin injects player + script tag:

```html
<p>It is the fourth World Cup in a row...</p>

<div class="instaread-player-slot">
  <instaread-player ...></instaread-player>
</div>

<!-- This script shouldn't be here! -->
<script data-cfasync="false" data-no-optimize="1">
(function(){
  var t = document.querySelector(".article-body");
  var s = document.currentScript.previousElementSibling;
  if (t && s) {
    if("prepend" === "prepend") t.insertBefore(s, t.firstChild);
    else t.appendChild(s);
  }
})();
</script>

<figure class="wp-block-image">
  <img src="..." alt="..." />
</figure>
```

**Problems:**
- ❌ Unnecessary `<script>` tag in content
- ❌ Runtime DOM manipulation
- ❌ Fragile if theme structure changes
- ❌ Slower (selector search + script execution)
- ❌ Hard to debug

---

## ✅ CORRECT APPROACH: WordPress-Native (Empty Selector)

**Config:**
```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

**What happens:**

1. Plugin receives REST API content
2. Plugin sees empty `target_selector`
3. Skips selector matching entirely ← **CLEAN CODE PATH**
4. Directly prepends player to `$content`:

```html
<div class="instaread-player-slot" style="min-height:144px;">
  <instaread-player publication="irishcentral" playertype="" color="#59476b"></instaread-player>
</div>

<p>It is the fourth World Cup in a row that Ireland have been dumped out in the quarter-finals...</p>

<figure class="wp-block-image">
  <img src="..." alt="..." />
</figure>

<p>They have suffered a total of eight World Cup quarter-final defeats...</p>
```

**Benefits:**
- ✅ No selector searching
- ✅ No JS mover script
- ✅ No runtime DOM manipulation
- ✅ Faster (simple string concatenation)
- ✅ Reliable across all WordPress versions
- ✅ Easy to debug (pure string output)

---

## How the Plugin Code Works

### Code Path for Empty Selector (WordPress-native)

**File:** `core/instaread-core.php:853-859`

```php
if (empty($target_selector)) {
    // WordPress-native injection: $content is already just the article body
    // (from the_content filter), so CSS selectors won't match. Empty selector
    // means: directly prepend/append player to article body without selector
    // searching or JS mover fallback.
    if (in_array($insert_position, ['prepend', 'inside_first_child', 'before_element'], true)) {
        return $player_html . $content;  // ← Direct string concatenation
    }
    return $content . $player_html;
}
```

**This is the fast, clean path.** Direct string concatenation, no DOM parsing, no fallback logic.

---

## Code Path for Non-Empty Selector (Fallback)

**File:** `core/instaread-core.php:860-887`

```php
// Non-empty selector — search for element
$target_info = $this->find_target_element($content, $target_selector);

if (!$target_info) {
    // Selector not found — use JS mover fallback
    $mover = sprintf(
        '<script data-cfasync="false">
        (function(){
            var t=document.querySelector("%s");
            // ... move player to target at runtime
        })();
        </script>',
        esc_js($target_selector)
    );
    
    // Inject player + mover script
    return $content . $player_html . $mover;
}
```

This is slower and less reliable — it only works if the selector exists **at runtime** in the full page DOM.

---

## Why This Matters

### REST API Content vs Full Page HTML

**REST API Content (what the plugin receives):**
```html
<p>Article text...</p>
<figure>...</figure>
```
← No theme wrappers, just article blocks

**Full Page HTML (what the browser sees):**
```html
<!DOCTYPE html>
<html>
  <head>...</head>
  <body>
    <header>...</header>
    <div class="main-wrapper">
      <div class="article-body">          ← HERE!
        <p>Article text...</p>
        <figure>...</figure>
      </div>
      <aside>...</aside>
    </div>
    <footer>...</footer>
  </body>
</html>
```
← Has theme wrappers AROUND the article

**Consequence:**
- `.article-body` selector exists in **full page HTML**
- `.article-body` selector does **NOT exist** in REST API content
- Using `.article-body` as selector causes fallback JS mover to run
- Fallback JS mover works at runtime, but it's fragile and slow

**Solution:**
- Use empty selector
- Let WordPress-native path handle it
- Direct prepend to REST API content
- No selector matching needed

---

## Decision Tree

```
Is the site WordPress?
    ↓
    YES
    ↓
Does the content exist in REST API?
    ↓
    YES (always true for WordPress)
    ↓
    Use: target_selector = ""
         insert_position = "prepend"
    ↓
    No selector matching, no JS mover
    Direct string prepend
    ✅ DONE
```

---

## Quick Comparison Table

| Aspect | ❌ CSS Selector | ✅ Empty Selector |
|--------|---|---|
| **Selector matching** | Yes (will fail) | No (skipped) |
| **JS mover** | Yes (fallback) | No |
| **Runtime DOM manipulation** | Yes | No |
| **Reliability** | Medium (depends on theme) | High (always works) |
| **Speed** | Slow (regex + fallback) | Fast (string concat) |
| **Maintenance** | Fragile (theme changes break it) | Robust (theme-independent) |
| **Debugging** | Hard (JS execution at runtime) | Easy (static HTML output) |

---

## Takeaway

**For all WordPress sites: Always use `target_selector: ""` and `insert_position: "prepend"`**

This triggers the clean WordPress-native code path that:
1. Skips selector matching
2. Avoids fallback JS mover
3. Directly prepends player to article content
4. Works reliably across all WordPress versions and themes
5. Is fast and easy to debug

If you're using any other selector for a WordPress site, you're working around a limitation that doesn't actually exist.
