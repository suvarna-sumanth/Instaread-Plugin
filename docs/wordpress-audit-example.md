# WordPress REST API Audit — Practical Example

This document shows a real-world example of auditing a WordPress site's REST API content to verify player injection placement.

---

## Example: IrishCentral.com

### Step 1: Find a Recent Post

Visit the site and get a post URL:
```
https://irishcentral.com/news/ireland-out-rugby-world-cup-new-zealand
```

Or fetch via REST API:
```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts?per_page=1&orderby=date&order=desc" | jq '.[0].id'
```

### Step 2: Fetch the Content via REST API

```bash
POST_ID=12345  # Replace with actual ID

curl -s "https://irishcentral.com/wp-json/wp/v2/posts/$POST_ID" | jq '.content.rendered' > content.html
```

**Expected output format:**

```html
<p>It is the fourth World Cup in a row that Ireland have been dumped out in the quarter-finals and the fifth time in the last six tournaments. They have suffered a total of eight World Cup quarter-final defeats, the most of any side in history.</p>

<figure class="wp-block-image"><img src="..." alt="..." /></figure>

<p>More article content continues here...</p>

<div class="wp-block-image">
  <figure class="wp-caption">
    <img src="..." alt="..." />
    <figcaption>Photo caption</figcaption>
  </figure>
</div>

<p>Final paragraph of article...</p>
```

### Step 3: What This Tells Us

✅ **Observations:**
- Starts with a `<p>` tag (first content element)
- Contains `<figure>`, `<img>`, `<div>` blocks (inner article structure)
- **No outer wrapper** like `<div class="article-body">` or `<div class="story">`

❌ **What we DON'T see:**
- No `.article-body` wrapper
- No `.story` container
- No `.entry-content` div
- No site chrome or navigation

### Step 4: Correct Configuration

Based on REST API audit, the config should be:

```json
{
  "partner_id": "irishcentral",
  "domain": "irishcentral.com",
  "publication": "irishcentral",
  "injection_context": "post",
  "injection_rules": [
    {
      "target_selector": "",
      "insert_position": "prepend",
      "exclude_slugs": ["/", "/category/", "/video/", "/about/", ...]
    }
  ]
}
```

**Why:**
- `target_selector: ""` → No selectors to match (REST API has no wrappers)
- `insert_position: "prepend"` → Player prepends to the article content
- Result: Player appears above the first `<p>` tag

### Step 5: How It Appears on Live Site

When the plugin injects with `target_selector: ""` and `prepend`:

**Before injection (REST API content):**
```html
<p>It is the fourth World Cup in a row...</p>
<figure>...</figure>
...
```

**After injection (rendered on page):**
```html
<div class="instaread-player-slot" style="min-height:144px;">
  <instaread-player publication="irishcentral" ...></instaread-player>
</div>

<p>It is the fourth World Cup in a row...</p>
<figure>...</figure>
...
```

✅ **Player is at the top of the article**, above the first paragraph.

---

## What NOT to Do

### ❌ Wrong Approach #1: Using Theme Wrapper as Selector

```json
{
  "target_selector": ".article-body",
  "insert_position": "prepend"
}
```

**Problem:**
1. REST API content has no `.article-body` wrapper
2. Plugin searches for `.article-body` in `$content` — finds nothing
3. Fallback JS mover injects this script:
   ```html
   <script>
     var t = document.querySelector(".article-body");
     var s = document.currentScript.previousElementSibling;
     if (t && s) { t.insertBefore(s, t.firstChild); }
   </script>
   ```
4. At runtime, script moves player to `.article-body` in the full page HTML
5. This works but is **fragile** — if theme structure changes, the JS fails

### ❌ Wrong Approach #2: Using Inner Content Selector

```json
{
  "target_selector": "figure",
  "insert_position": "prepend"
}
```

**Problem:**
1. REST API might contain multiple `<figure>` elements
2. Plugin finds the first one and tries to prepend to it
3. Player gets inserted in the wrong place (middle of article)
4. Confusing behavior across different posts

### ✅ Correct Approach: No Selector

```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

**Why it works:**
1. No selector matching needed
2. Directly prepends `<div class="instaread-player-slot">` to REST API content
3. Consistent across all posts
4. No JavaScript, no runtime DOM manipulation
5. Fast and reliable

---

## Verification Workflow

Use this checklist when setting up a WordPress partner:

1. **Fetch REST API content:**
   ```bash
   curl -s "https://DOMAIN/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered'
   ```

2. **Look for:**
   - ✅ First `<p>`, `<h2>`, `<figure>` or block element
   - ✅ Inner content blocks (`<div class="wp-block-image">`, etc.)
   - ✅ **No outer wrapper** classes

3. **Config must be:**
   ```json
   {
     "target_selector": "",
     "insert_position": "prepend"
   }
   ```

4. **Test on live site:**
   - Player above first paragraph?
   - No `<script>` tag after player?
   - Correct styling/height?

5. **If player is wrong location:**
   - Check if config has non-empty `target_selector`
   - Change to `"target_selector": ""`
   - Clear any caches
   - Reload page

---

## Key Rules

| Rule | Why |
|---|---|
| **Never use theme wrapper classes as selectors** | They don't exist in REST API content |
| **Always use empty `target_selector` for WordPress** | Triggers WordPress-native injection |
| **Always use `prepend` for WordPress** | Player appears above article content |
| **REST API content is canonical** | It's what the plugin actually injects into |
| **If selector not in REST API, it won't work** | Don't try to select elements outside REST API content |

---

## Tools & Commands

### Quick REST API check:
```bash
DOMAIN="irishcentral.com"
curl -s "https://$DOMAIN/wp-json/wp/v2/posts?per_page=1&orderby=date&order=desc" | jq '.[0].id'
```

### Fetch content:
```bash
DOMAIN="irishcentral.com"
POST_ID=12345
curl -s "https://$DOMAIN/wp-json/wp/v2/posts/$POST_ID" | jq '.content.rendered' | head -50
```

### Save to file for inspection:
```bash
DOMAIN="irishcentral.com"
POST_ID=12345
curl -s "https://$DOMAIN/wp-json/wp/v2/posts/$POST_ID" | jq -r '.content.rendered' > content.html
open content.html  # macOS
# or xdg-open content.html  # Linux
```

### With authentication (if needed):
```bash
curl -u "user:password" "https://$DOMAIN/wp-json/wp/v2/posts/$POST_ID" | jq '.content.rendered'
```
