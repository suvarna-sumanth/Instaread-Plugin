# WordPress Partner Setup & Verification Guide

This guide walks through setting up a WordPress partner site and verifying correct player injection using the WordPress REST API.

---

## Quick Start

1. **Create partner config** with WordPress-native injection settings
2. **Audit the REST API content** to confirm what the plugin receives
3. **Verify player placement** on live site

---

## Step 1: Create Partner Config

For any WordPress site, the config should always be:

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
      "exclude_slugs": [...]
    }
  ]
}
```

### Why This Configuration

| Field | Value | Reason |
|---|---|---|
| `target_selector` | `""` (empty) | WordPress `the_content` filter returns only article body, no outer wrapper divs |
| `insert_position` | `"prepend"` | Player appears above the first paragraph of the article |
| `injection_context` | `"post"` | Only inject on individual post/article pages |

**Key insight:** WordPress REST API and `the_content` filter both return the article body **without theme wrapper elements**. CSS selectors like `.article-body` or `.story` don't exist in that content — they're in the page template. Using empty selector triggers the WordPress-native code path that directly prepends to the content.

---

## Step 2: Audit via WordPress REST API

Every WordPress site exposes a REST API that returns the exact content the plugin receives.

### 2a. Find a Post ID

**Option A: Browser URL**
```
https://irishcentral.com/news/ireland-out-rugby-world-cup-new-zealand
```
The post ID is in the URL or visible in DevTools Network tab when fetching the REST API.

**Option B: REST API posts endpoint**
```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts?per_page=1&orderby=date&order=desc" \
  | jq '.[0] | {id, title: .title.rendered}'
```

### 2b. Fetch the Post Content

Once you have a POST_ID, fetch the content:

```bash
curl -s "https://irishcentral.com/wp-json/wp/v2/posts/POST_ID" \
  | jq '.content.rendered' > content.html
```

This returns the **exact same HTML** that `inject_server_side_player()` receives as the `$content` parameter.

### 2c. Inspect the Content

Open `content.html` in a text editor or browser. Example output:

```html
<p>It is the fourth World Cup in a row that Ireland have been dumped out in the quarter-finals...</p>

<figure class="wp-block-image">
  <img src="..." alt="..." />
</figure>

<p>They have suffered a total of eight World Cup quarter-final defeats...</p>
```

**Key observations:**
- ✅ Starts with `<p>`, `<h2>`, `<figure>`, or other block-level content elements
- ✅ No outer wrapper divs (no `.article-body`, `.story`, `.entry-content`)
- ✅ Just raw article blocks

If you see wrapper divs in REST API content, something is misconfigured on that WordPress installation.

---

## Step 3: Verify Player Placement

### 3a. Browser Inspection

Visit the article page in a browser:
```
https://irishcentral.com/news/ireland-out-rugby-world-cup-new-zealand
```

Open DevTools Inspector and search for `instaread-player-slot`:

**✅ Correct placement:**
```html
<article>
  <div class="instaread-player-slot" style="min-height:144px;">
    <instaread-player ...></instaread-player>
  </div>
  <p>It is the fourth World Cup in a row...</p>
  ...
</article>
```

**❌ Wrong placement (indicates fallback JS mover):**
```html
<article>
  <p>It is the fourth World Cup in a row...</p>
  <div class="instaread-player-slot" style="min-height:144px;">
    <instaread-player ...></instaread-player>
  </div>
  <script>
    // JS mover script — shouldn't be present!
    (function() { ... })();
  </script>
</article>
```

If there's a `<script>` tag after the player, the config used a non-empty `target_selector` that didn't match, and the fallback JS mover had to move it at runtime. **Fix the config to use `target_selector: ""` instead.**

### 3b. Automated Check

Run the audit script:

```bash
./scripts/audit-wordpress-site.sh https://irishcentral.com
```

This will:
1. Verify REST API is available
2. Fetch recent posts
3. Analyze content structure
4. Confirm no outer wrapper classes exist
5. Recommend the correct config

---

## Common Issues & Fixes

### Issue: Player appears in wrong location

**Symptom:** Player is in the middle of the article or at the bottom, not at the top.

**Cause:** Config uses `target_selector: ".article-body"` or similar. Selector doesn't match REST API content, so JS mover moves it at runtime but finds the wrong element.

**Fix:** Change config to:
```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

### Issue: Player doesn't appear at all

**Symptom:** No player on the article page.

**Causes:**
1. Post URL is excluded in `exclude_slugs`
2. `injection_context: "post"` but post is a page, review, or custom post type
3. REST API returns empty content (rare)

**Debug steps:**
1. Check `exclude_slugs` array — does it include this post's slug?
2. Check `injection_context` — does it match the post type?
3. Try a different post to isolate the issue

### Issue: Selector matches but player still in wrong place

**Symptom:** Config has `target_selector` and it matches, but player position is still wrong.

**Why:** Each site has different HTML structure. A selector like `.article-body` might exist, but there could be multiple instances or nested wrappers.

**Solution:** Don't use selectors for WordPress sites. Use the WordPress-native config with `target_selector: ""` and let the plugin directly prepend to REST API content.

---

## REST API Endpoints Reference

| Endpoint | Method | Returns |
|---|---|---|
| `/wp-json/wp/v2/posts` | GET | List of posts |
| `/wp-json/wp/v2/posts/{id}` | GET | Single post with full content |
| `/wp-json/wp/v2/pages` | GET | List of pages |
| `/wp-json/wp/v2/pages/{id}` | GET | Single page |

**Query parameters for posts endpoint:**
- `per_page=10` — number of posts (default 10, max 100)
- `orderby=date` — sort by date
- `order=desc` — newest first
- `page=2` — pagination

**Authentication (if needed):**
```bash
curl -u "username:password" "https://site.com/wp-json/..."
```

---

## Checklist for New WordPress Partner

- [ ] Config file created at `partners/{partner_id}/config.json`
- [ ] `target_selector` is `""` (empty string, not null)
- [ ] `insert_position` is `"prepend"`
- [ ] `injection_context` is `"post"` (or appropriate type)
- [ ] `exclude_slugs` array includes irrelevant pages (homepage, about, contact, etc.)
- [ ] Verified REST API content via `curl` command
- [ ] Inspected page in browser — player appears above first paragraph
- [ ] No `<script>` tag after player element (no JS mover)
- [ ] Tested on at least 3 different article pages

---

## Why REST API Audit Matters

The REST API approach is **canonical** because:

1. **It's what the plugin actually receives** — `the_content` filter returns the same HTML
2. **It's identical across all WordPress sites** — no theme variations, no inconsistencies
3. **It proves selectors will/won't match** — if a selector isn't in REST API content, it won't work
4. **It's faster than browser inspection** — no waiting for page load or scrolling
5. **It can be automated** — batch audit hundreds of sites
6. **It prevents mistakes** — you see exactly what you're injecting into

**Rule:** If you can't see it in the REST API content, don't try to select it.
