# Player Injection & Auto-Updates Strategy

**Version:** 4.4.5  
**Last Updated:** April 23, 2026  
**Status:** Production Ready

---

## 1. Player Injection Strategy

### Overview
The Instaread audio player is injected **right above the article content** on partner WordPress sites. This positioning:
- ✅ Appears immediately after the article title/headline
- ✅ Before the main article body text
- ✅ Above all article content
- ✅ Below navigation/header elements

### Injection Process

#### Step 1: Timing
The player is injected via WordPress `the_content` filter hook:
```php
add_filter('the_content', [$this, 'inject_server_side_player'], PHP_INT_MAX - 1, 1);
```

**Why priority `PHP_INT_MAX - 1`?**
- Runs VERY LATE in the filter chain
- Ensures all other content plugins have finished modifying content
- Guarantees the player sees the final, complete HTML

#### Step 2: Context Detection
Before injecting, the plugin checks:
```php
if (!$this->should_inject()) return $content;
```

**Checks performed:**
- ✅ Is this a post/page/article? (checks `injection_context`)
- ✅ Is this NOT the home page? (checks `is_front_page()`)
- ✅ Is this NOT an archive? (checks `!is_main_query()`)
- ✅ Matches partner's injection context? (post, page, singular, etc.)
- ✅ Not in an excluded slug? (category, about, contact, etc.)
- ✅ Player not already in content? (prevents duplicates)

#### Step 3: Selector Targeting
The plugin uses CSS selectors to find WHERE to inject:
```php
"target_selector": ".entry-content, .post-content, .article-body, main article"
```

**How it works:**
1. Tries `.entry-content` (most common WordPress theme)
2. If not found, tries `.post-content` (alternative theme)
3. If not found, tries `.article-body` (custom themes)
4. If not found, tries `main article` (semantic HTML)
5. If none found, fallback: prepend to entire content

**Why multiple selectors?**
- Different WordPress themes use different class names
- Ensures player appears in correct location across all themes
- Fallback catches edge cases

#### Step 4: Insertion Position
```php
"insert_position": "prepend"
```

**Prepend = Insert at the BEGINNING of the target**
- Places player right after the title/headline
- Before the first paragraph of content
- Perfect position for audio player

### HTML Structure (After Injection)
```html
<article>
  <h1>Article Title</h1>
  
  <!-- PLAYER INJECTED HERE (prepend) -->
  <div class="instaread-player-slot">
    <instaread-player publication="irishcentral"></instaread-player>
  </div>
  
  <div class="entry-content">
    <p>First paragraph of article content...</p>
    <p>Second paragraph...</p>
  </div>
</article>
```

---

## 2. irishcentral Configuration

### Current Config
```json
{
  "partner_id": "irishcentral",
  "domain": "irishcentral.com",
  "publication": "irishcentral",
  "injection_context": "post",
  "injection_strategy": "first",
  "fallback_injection": true,
  "player_position": "above-content",
  "injection_rules": [
    {
      "target_selector": ".entry-content, .post-content, .article-body, main article",
      "insert_position": "prepend",
      "exclude_slugs": [...]
    }
  ],
  "version": "4.4.5"
}
```

### Configuration Breakdown

| Setting | Value | Meaning |
|---------|-------|---------|
| `injection_context` | `post` | Only inject on blog posts, not pages |
| `injection_strategy` | `first` | Use first matching rule (ordered priority) |
| `fallback_injection` | `true` | If no selector matches, still inject (at top) |
| `player_position` | `above-content` | Semantic marker for desired position |
| `target_selector` | `.entry-content, ...` | CSS selectors to find content container |
| `insert_position` | `prepend` | Insert at beginning of target |
| `exclude_slugs` | `["/", "/about/", ...]` | Don't inject on these pages |

### Selectors Explained

```javascript
".entry-content, .post-content, .article-body, main article"
```

**Each selector targets different theme patterns:**
1. `.entry-content` - Default WordPress theme pattern
2. `.post-content` - Genesis child theme pattern
3. `.article-body` - Custom theme pattern
4. `main article` - Semantic HTML5 theme pattern

**Priority Order:**
- Plugin tries selectors left-to-right
- Uses first selector that EXISTS in the DOM
- Falls back to prepending entire content if none found

---

## 3. Auto-Updates Strategy

### Update Detection
The plugin detects updates via WordPress `upgrader_process_complete` hook:
```php
add_action('upgrader_process_complete', [$this, 'on_plugin_updated'], 10, 2);
```

**Detects:**
- ✅ Fresh installation (event: `install`)
- ✅ Manual update (event: `update`)
- ✅ Auto-update via background task (event: `update`)

### Update Checker
Uses **Plugin Update Checker v5** to fetch updates from GitHub:
```php
PucFactory::buildUpdateChecker(
    "https://raw.githubusercontent.com/suvarna-sumanth/Instaread-Plugin/main/partners/{$this->partner_config['partner_id']}/plugin.json",
    __FILE__,
    $this->partner_config ? 'instaread-' . $this->partner_config['partner_id'] : 'instaread-audio-player'
);
```

**Flow:**
1. WordPress checks GitHub for new `plugin.json`
2. If version is newer, update is available
3. Admin sees "Update Available" notice
4. Admin clicks "Update Now" or auto-update runs
5. WordPress downloads ZIP from GitHub release
6. Plugin files extracted and old files deleted
7. `upgrader_process_complete` hook fires
8. Telemetry sent: `event: "update", old_version: "4.4.5", version: "4.4.6"`
9. Email notification: `🔄 Plugin Updated — irishcentral (4.4.5 → 4.4.6)`

### GitHub Release Process
```bash
# 1. Update version in config.json
{
  "version": "4.4.6"
}

# 2. Trigger GitHub Actions
gh workflow run partner-builds.yml -f partner_id=irishcentral -f version=4.4.6

# 3. GitHub Actions:
#    - Creates ZIP file
#    - Publishes GitHub release
#    - Updates plugin.json
#    - Generates download URL

# 4. Plugin Update Checker detects new version
# 5. WordPress offers update to site admin
# 6. Site updates automatically or on-demand
```

### Update Verification
Telemetry confirms updates:
```json
{
  "event": "update",
  "partner_id": "irishcentral",
  "old_version": "4.4.5",
  "version": "4.4.6",
  "site_url": "https://irishcentral.com",
  "timestamp": 1713876000
}
```

Email notifies:
```
🔄 Plugin Updated — Irish Central (irishcentral) v4.4.6
Partner: irishcentral
Old Version: 4.4.5
New Version: v4.4.6
Event Time: 2026-04-23 10:00:00 UTC
```

---

## 4. Potential Issues & Solutions

### Issue 1: Player Appears at Top of Page
**Problem:** Player appears above all navigation/header  
**Cause:** Selector didn't match, fallback injection used  
**Solution:**
1. Inspect element in browser (F12)
2. Find content container class/id
3. Add to `target_selector` in config.json
4. Example: `".entry-content, .site-content, #main-content"`
5. Rebuild and redeploy

### Issue 2: Player Appears Multiple Times
**Problem:** Player shows more than once on page  
**Cause:** `the_content` filter called multiple times  
**Solution:** ✅ Already handled - plugin sets static flag:
```php
static $already_injected = false;
if ($already_injected) return $content;
$already_injected = true;
```

### Issue 3: Player Doesn't Appear
**Problem:** No player visible on post  
**Cause:** Could be multiple reasons:
1. Post not matching `injection_context`
2. Slug in `exclude_slugs`
3. Selector not matching content container
4. Player already in content (custom placement)

**Debug:**
```php
// Enable debug in wp-config.php
define('INSTAREAD_DEBUG', true);

// Check WordPress debug.log
tail -f wp-content/debug.log | grep InstareadPlayer
```

**Debug Output Example:**
```
[InstareadPlayer] Skipping injection: injection_context does not match current page.
[InstareadPlayer] Skipping excluded slug: /about/
[InstareadPlayer] Injection successful with selector: .entry-content.
```

### Issue 4: Updates Not Installing
**Problem:** WordPress shows "Update Available" but doesn't auto-update  
**Cause:**
1. Auto-updates disabled in wp-config.php
2. Plugin Update Checker can't reach GitHub
3. GitHub release not published

**Solution:**
```php
// Check wp-config.php for auto-update settings
define('WP_AUTO_UPDATE_CORE', true);
define('WP_AUTO_UPDATE_THEME', true);
define('WP_AUTO_UPDATE_PLUGIN', true);  // Enable plugin auto-updates

// Verify GitHub release exists
curl https://api.github.com/repos/suvarna-sumanth/Instaread-Plugin/releases/latest

// Check plugin Update Checker
wp plugin update-checks --all
```

---

## 5. Best Practices

### For Players
1. **Test on staging first** - Always test injection before pushing to production
2. **Inspect selectors** - Verify content container class/id matches your theme
3. **Check excluded pages** - Make sure high-value pages aren't in `exclude_slugs`
4. **Monitor position** - Watch first few days to ensure player appears correctly

### For Updates
1. **Version incrementally** - Follow semantic versioning (4.4.5 → 4.4.6 → 4.5.0)
2. **Test updates** - Install on staging WordPress, update manually, verify
3. **Monitor telemetry** - Check email notifications confirm update delivery
4. **Keep changelog** - Update plugin.json changelog for transparency
5. **GitHub releases** - Always create corresponding GitHub release with notes

---

## 6. Configuration Reference

### Injection Rules Schema
```json
{
  "target_selector": "CSS selector(s) for content container",
  "insert_position": "prepend|append|inside_first_child|before_element|after_element",
  "exclude_slugs": ["list of URL paths to skip injection"]
}
```

### Insert Position Options
| Position | Result | Use Case |
|----------|--------|----------|
| `prepend` | Add to start of container | Default - best for players |
| `append` | Add to end of container | After all content |
| `inside_first_child` | Insert into first child | Specific element |
| `before_element` | Before target element | Outside container |
| `after_element` | After target element | Outside container |

### Partner Config Options
```json
{
  "injection_context": "post|page|singular|[array of post types]",
  "injection_strategy": "first",
  "fallback_injection": "true|false",
  "player_position": "above-content|custom-description",
  "suppress_body_classes": ["single-post-type-name"],
  "enqueue_remote_player_script_sitewide": "true|false",
  "use_player_loader": "true|false"
}
```

---

## 7. Monitoring & Verification

### Verify Injection (Browser)
1. Open partner website post
2. Press F12 (Developer Tools)
3. Search for `instaread-player` element
4. Confirm it appears before article content
5. Inspect styles for correct rendering

### Verify Auto-Update (WordPress Admin)
1. Log in to WordPress admin
2. Go to Plugins page
3. Look for "Update Available" notice
4. Click "Update Now" to test manual update
5. Verify plugin version changes in database:
   ```bash
   wp plugin list --field=name,version
   ```

### Verify Telemetry
1. Check database:
   ```sql
   SELECT * FROM plugin_telemetry WHERE partner_id = 'irishcentral' ORDER BY ts DESC LIMIT 5;
   ```
2. Check email inbox for notifications
3. Check dashboard at `/wordpress-plugin`

---

## 8. Summary

✅ **Player Injection:**
- Detects article content using CSS selectors
- Prepends player to start of content container
- Falls back gracefully if selector doesn't match
- Prevents duplicates via static flag
- Runs at highest priority to catch final HTML

✅ **Auto-Updates:**
- Monitors GitHub for new plugin.json versions
- Offers update in WordPress admin
- Auto-updates via background task (if enabled)
- Sends telemetry notification on update
- Tracks old → new version in database

✅ **Configuration:**
- Multiple selector support for theme compatibility
- Exclude pages via slug matching
- Customizable injection position and context
- Fallback behavior configurable
- Comments explain each setting

**Result:** Reliable player injection + transparent auto-update tracking ✅
