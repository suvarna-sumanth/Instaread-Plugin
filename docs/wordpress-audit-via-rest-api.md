# WordPress Content Audit via REST API

## Overview

Every WordPress site exposes a REST API endpoint that returns the processed post content. Use this to:
1. **Verify the actual content structure** that the plugin receives in `the_content` filter
2. **Confirm player injection placement** — the player will prepend to this content
3. **Audit multiple posts** without inspecting HTML in a browser

The REST API returns the **exact same HTML** that `inject_server_side_player()` receives as the `$content` parameter.

---

## How to Audit a WordPress Site

### Step 1: Get a Post ID

Visit any article on the site and note the URL:
```
https://irishcentral.com/news/ireland-out-rugby-world-cup-new-zealand
```

Or find recent posts via the REST API posts endpoint:
```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts?per_page=1&orderby=date&order=desc" | jq '.[0] | {id, title, link}'
```

### Step 2: Fetch the Post Content via REST API

```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered'
```

Replace `POST_ID` with the actual post ID.

**Example:**
```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts/12345" | jq '.content.rendered'
```

This returns the **exact HTML** that `inject_server_side_player()` receives — no theme wrapper, no sidebars, no header/footer — just the article body.

### Step 3: Understand the Content Structure

The REST API content is **always** just the article body. Example output:
```html
<p>It is the fourth World Cup in a row that Ireland have been dumped out in the quarter-finals and the fifth time in the last six tournaments.</p>

<p>They have suffered a total of eight World Cup quarter-final defeats, the most of any side in history.</p>

<div class="wp-block-image"><figure class="wp-caption">
  <img src="..." alt="..." />
  <figcaption>Photo caption here</figcaption>
</figure></div>

<p>More article text follows...</p>
```

**Key insight:** There is NO outer `.article-body`, `.story`, `.entry-content`, or any other wrapper in this content. Those wrappers live in the WordPress theme template, outside `the_content` filter output.

---

## Correct WordPress Configuration

Given that REST API content has **no outer wrapper**, the config must use:

```json
{
  "partner_id": "irishcentral",
  "injection_context": "post",
  "injection_rules": [
    {
      "target_selector": "",
      "insert_position": "prepend",
      "exclude_slugs": [...]
    }
  ]
}
```

**Why:**
- `target_selector: ""` → Empty selector triggers the WordPress-native code path
- `insert_position: "prepend"` → Player prepends directly to the article content
- The plugin prepends `<div class="instaread-player-slot">` at the very start of the REST API content
- When the theme renders `the_content()`, the player appears above the first paragraph of the article

---

## Verification Checklist

For any WordPress site, verify:

1. **Fetch REST API content:**
   ```bash
   curl -s "https://DOMAIN.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered' > content.html
   ```

2. **Inspect the output:** Look for:
   - `<p>` tags (usually the first content element)
   - Images, headings, or other block-level elements
   - NO outer wrapper divs like `.article-body` or `.story`

3. **Confirm config:**
   - `target_selector` should be `""` (empty)
   - `insert_position` should be `"prepend"`

4. **Test on site:**
   - Visit the post page in browser
   - Open DevTools Inspector
   - Find `<div class="instaread-player-slot">`
   - It should appear **immediately inside** the article content area
   - There should be **no** inline `<script>` tag moving it (JS mover = indicator of wrong selector matching)

---

## Common Mistakes

### ❌ Mistake: Using a theme wrapper as target_selector

```json
{
  "target_selector": ".article-body",  // This won't work!
  "insert_position": "prepend"
}
```

**Why it fails:**
- REST API content has no `.article-body` wrapper
- Selector search fails → fallback JS mover injects an `<script>` tag
- At runtime, the script moves the player to `.article-body` (risky and fragile)

### ✅ Correct: Use empty selector for WordPress

```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

**Why it works:**
- Empty selector triggers WordPress-native path
- Directly prepends player to REST API content
- No selector searching, no JS mover, no runtime DOM manipulation

---

## Testing Multiple Posts

Audit several posts on a site to ensure consistent behavior:

```bash
#!/bin/bash
DOMAIN="irishcentral.com"

# Get last 5 posts
POSTS=$(curl -s "https://${DOMAIN}/wp-json/wp/v2/posts?per_page=5&orderby=date&order=desc" | jq -r '.[].id')

for POST_ID in $POSTS; do
  echo "=== Post ID: $POST_ID ==="
  curl -s "https://${DOMAIN}/wp-json/wp/v2/posts/${POST_ID}" | jq '.title.rendered, (.content.rendered | length), (.content.rendered | split("\n") | .[0])'
  echo
done
```

This shows:
- Post title
- Content length (in characters)
- First line of content (usually a `<p>` tag — confirms player will prepend above it)

---

## REST API Endpoints Reference

| Endpoint | Purpose |
|---|---|
| `/wp-json/wp/v2/posts` | List posts (supports `per_page`, `orderby`, `order`) |
| `/wp-json/wp/v2/posts/{id}` | Get single post with full content |
| `/wp-json/wp/v2/pages` | List pages |
| `/wp-json/wp/v2/pages/{id}` | Get single page |

**Note:** Most WordPress REST APIs require no authentication for public posts. If a site requires auth, use:
```bash
curl -u "user:password" "https://DOMAIN.com/wp-json/..."
```

---

## Why This Matters

The REST API approach is **canonical** because:
1. It returns the exact content the plugin filters
2. It's the same across all WordPress sites (no theme variations)
3. It proves what selectors will or won't match
4. It's faster than manual browser inspection
5. It can be automated for bulk audits

Never guess at selectors — **use the REST API to verify what you're injecting into**.
