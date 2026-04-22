# Plugin Lifecycle & Audit Guide

**Version:** 4.4.7  
**Last Updated:** April 23, 2026  
**Status:** Production Ready

---

## Table of Contents

1. [Plugin Installation Workflow](#1-plugin-installation-workflow)
2. [Auto-Update Workflow](#2-auto-update-workflow)
3. [Player Injection Audit](#3-player-injection-audit)
4. [Telemetry System Audit](#4-telemetry-system-audit)
5. [Developer Checklist](#5-developer-checklist)
6. [Troubleshooting & Common Issues](#6-troubleshooting--common-issues)

---

## 1. Plugin Installation Workflow

### 1.1 Installation Process Flow

```
Partner WordPress Admin
  ↓
1. Go to Plugins → Add Plugin
  ↓
2. Search "Instaread Audio Player - {partner_id}"
  ↓
3. Click "Install Now"
  ↓
WordPress downloads ZIP from GitHub release
  ↓
4. Click "Activate Plugin"
  ↓
register_activation_hook fires
  ↓
Set transient: instaread_send_activation_telemetry
  ↓
Partner visits WordPress admin (any page)
  ↓
admin_init hook fires
  ↓
maybe_send_activation_telemetry() checks transient
  ↓
Sends telemetry: event="install"
  ↓
POST /api/plugin-telemetry
  ↓
Database insert + Email notification
  ↓
Dashboard shows partner status
```

### 1.2 Installation Audit Checklist

- [ ] **config.json exists** in `/partners/{partner_id}/config.json`
  - [ ] Valid JSON (no syntax errors)
  - [ ] Has `partner_id`, `domain`, `publication`
  - [ ] Has `injection_context` (post/page/singular)
  - [ ] Has `injection_rules` with `target_selector` and `insert_position`
  - [ ] Has `version` matching plugin version

- [ ] **plugin.json exists** in `/partners/{partner_id}/plugin.json`
  - [ ] Valid JSON
  - [ ] `version` matches config.json
  - [ ] `download_url` points to correct GitHub release
  - [ ] `name` is descriptive (e.g., "Instaread Audio Player - irishcentral")

- [ ] **GitHub Release created** with tag `{partner_id}-v{version}`
  - [ ] ZIP file contains `instaread-core.php`
  - [ ] ZIP file contains `config.json`
  - [ ] ZIP file contains `styles.css`
  - [ ] ZIP file contains `plugin-update-checker/` directory

- [ ] **Plugin Update Checker configured** (in core.php)
  - [ ] Points to correct `plugin.json` on GitHub
  - [ ] Uses correct `partner_id` in slug

- [ ] **Activation telemetry works**
  - [ ] Partner installs plugin
  - [ ] Visits WordPress admin
  - [ ] Database has `event="install"` record
  - [ ] Email received (sumanth@instaread.com)

### 1.3 Installation Testing

**On partner WordPress:**

```bash
# 1. Check config.json is readable
curl https://partner-domain.com/wp-content/plugins/instaread-{partner_id}/config.json

# 2. Check plugin is active
wp plugin list | grep instaread

# 3. Enable debug logs
# In wp-config.php:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('INSTAREAD_DEBUG', true);

# 4. Check logs for telemetry
tail -f wp-content/debug.log | grep -i instaread
```

**Expected log output:**
```
[InstareadPlayer] Telemetry sent: event=install version=4.4.7
[InstareadPlayer] Plugin activated, telemetry sent via admin_init fallback.
```

---

## 2. Auto-Update Workflow

### 2.1 Auto-Update Process Flow

```
WordPress runs scheduled cron (every 12 hours)
  ↓
Plugin Update Checker checks GitHub for new plugin.json
  ↓
Compares version: installed (4.4.6) vs GitHub (4.4.7)
  ↓
If version mismatch detected → Update available
  ↓
WordPress admin shows "Update Available" notice
  ↓
OPTION A: User clicks "Update Now"
          ↓
          WordPress downloads ZIP
          ↓
          Extracts and replaces files
          ↓
          upgrader_process_complete hook fires
          ↓
          on_plugin_updated() runs
          ↓
          Sends telemetry: event="update", old_version="4.4.6", version="4.4.7"

OPTION B: Auto-update enabled in wp-config.php
          ↓
          WordPress automatically downloads and installs
          ↓
          upgrader_process_complete hook fires
          ↓
          Same telemetry sent
```

### 2.2 Auto-Update Audit Checklist

- [ ] **Plugin Update Checker v5 installed**
  - [ ] File exists: `core/plugin-update-checker/plugin-update-checker.php`
  - [ ] Properly required in core.php
  - [ ] No fatal errors on plugin load

- [ ] **Update checker configured correctly**
  ```php
  PucFactory::buildUpdateChecker(
      "https://raw.githubusercontent.com/suvarna-sumanth/Instaread-Plugin/main/partners/{partner_id}/plugin.json",
      __FILE__,
      'instaread-{partner_id}'
  );
  ```

- [ ] **Auto-update enabled in plugin**
  - [ ] `enable_auto_updates()` method returns `true`
  - [ ] Hooked to `auto_update_plugin` filter
  - [ ] Checks `plugin_basename` correctly

- [ ] **Auto-update enabled in WordPress** (partner's wp-config.php)
  ```php
  define('WP_AUTO_UPDATE_CORE', true);
  define('WP_AUTO_UPDATE_PLUGIN', true);
  define('WP_AUTO_UPDATE_THEME', true);
  ```

- [ ] **Update telemetry works**
  - [ ] Release new version on GitHub
  - [ ] Partner's WordPress detects update within 12 hours
  - [ ] Partner clicks "Update Now" or auto-update runs
  - [ ] Database has `event="update"` with old_version and new_version
  - [ ] Email received showing update details

### 2.3 Update Testing

**Test manual update on partner site:**

```bash
# 1. Push new version to GitHub
git tag irishcentral-v4.4.8
git push origin irishcentral-v4.4.8

# 2. Create GitHub release with ZIP file
gh release create irishcentral-v4.4.8 ./irishcentral-v4.4.8.zip \
  --title "Instaread Audio Player v4.4.8 for irishcentral"

# 3. Update plugin.json on GitHub
# (GitHub Actions does this automatically)

# 4. On partner WordPress, check for updates
# Go to Plugins page → "Check for updates" or wait 12 hours

# 5. Click "Update Now" and verify:
# - Plugin version changes
# - No errors displayed
# - Telemetry record in database
# - Email received
```

**Expected telemetry record:**
```json
{
  "event": "update",
  "partner_id": "irishcentral",
  "version": "4.4.8",
  "old_version": "4.4.7",
  "site_url": "https://irishcentral.com",
  "timestamp": 1713900000
}
```

---

## 3. Player Injection Audit

### 3.1 Injection Process Flow

```
Partner visits article page
  ↓
WordPress loads post content
  ↓
Runs the_content filter at priority PHP_INT_MAX - 1 (VERY LATE)
  ↓
inject_server_side_player() method called
  ↓
Check: Should inject?
  ├─ Is front page? No ✓
  ├─ Is home/archive? No ✓
  ├─ Is main query? Yes ✓
  ├─ Matches injection_context? Yes (post) ✓
  ├─ Not in exclude_slugs? Yes ✓
  └─ Player already in content? No ✓
  ↓
Try CSS selectors in order:
  1. .article-content (irishcentral specific)
  2. .entry-content (WordPress default)
  3. .post-content (Genesis theme)
  4. article (semantic HTML)
  ↓
Found match: .article-content
  ↓
Prepend player HTML to content
  ↓
Return modified content
  ↓
Browser renders article + player at top
```

### 3.2 Injection Audit Checklist

**Partner Configuration:**

- [ ] **target_selector configured correctly**
  - [ ] Primary selector matches partner's theme
  - [ ] Example: `.article-content` for irishcentral
  - [ ] Multiple selectors listed for fallback support
  - [ ] Format: `".selector1, .selector2, .selector3"`

- [ ] **insert_position correct**
  - [ ] Usually `"prepend"` (insert at start of container)
  - [ ] Results in player appearing right above content

- [ ] **injection_context correct**
  - [ ] `"post"` for blog posts only
  - [ ] `"singular"` for posts + pages + custom post types
  - [ ] `"page"` for pages only
  - [ ] Array form: `["post", "custom_type"]` for specific types

- [ ] **exclude_slugs configured**
  - [ ] Homepage (`"/"`) excluded
  - [ ] About/contact/privacy pages excluded
  - [ ] Category/archive pages excluded
  - [ ] Video/media pages excluded
  - [ ] Admin pages excluded

- [ ] **fallback_injection configured**
  - [ ] Set to `true` to inject even if selectors don't match
  - [ ] Uses JS positioning if CSS selector fails
  - [ ] Set to `false` if selectors must match exactly

### 3.3 Injection Testing

**On partner WordPress:**

```bash
# 1. Inspect element in browser (F12)
# Look for: <div class="instaread-player-slot">
# Should appear inside the content container

# 2. Verify selector matches
# In browser console:
document.querySelector('.article-content')  // Should return element

# 3. Check injection position
# Player should appear:
# ✓ AFTER article title/headline
# ✓ BEFORE first paragraph of content
# ✗ NOT above navigation
# ✗ NOT above sidebar

# 4. Test on different post types
# - Regular blog post ✓
# - Featured post ✓
# - Video post (excluded?) ✓
# - Category page (excluded?) ✓

# 5. Enable debug logs (wp-config.php)
define('INSTAREAD_DEBUG', true);

# Check logs:
tail -f wp-content/debug.log | grep -i "injection"
```

**Expected debug output:**
```
[InstareadPlayer] Injection successful with selector: .article-content.
[InstareadPlayer] Skipping excluded slug: /category/rumors/
[InstareadPlayer] Skipping injection: injection_context does not match current page.
```

### 3.4 Common Injection Issues & Fixes

**Issue: Player appears at top of page (above navigation)**

- [ ] **Cause:** CSS selector didn't match, fallback injection used
- [ ] **Solution:** 
  1. Inspect element to find content container class
  2. Add to `target_selector` in config.json
  3. Test on staging first
  4. Release new version

**Issue: Player doesn't appear on any page**

- [ ] **Checks:**
  1. Is `injection_context` correct? (post, singular, page, etc.)
  2. Is post URL in `exclude_slugs`?
  3. Do CSS selectors match actual HTML?
  4. Is `fallback_injection: true`?
- [ ] **Debug:**
  ```bash
  wp --allow-root shell <<'EOF'
  define('INSTAREAD_DEBUG', true);
  echo "Debug enabled\n";
  EOF
  tail -f wp-content/debug.log
  ```

**Issue: Player appears multiple times**

- [ ] **Solution:** Already handled by static flag in code
  ```php
  static $already_injected = false;
  if ($already_injected) return $content;
  $already_injected = true;
  ```

---

## 4. Telemetry System Audit

### 4.1 Telemetry Flow

```
WordPress Plugin (partner site)
  ↓
Sends POST /api/plugin-telemetry
  {
    "event": "install|update|heartbeat",
    "partner_id": "irishcentral",
    "version": "4.4.7",
    "old_version": "4.4.6",  // null for install/heartbeat
    "site_url": "https://irishcentral.com",
    "timestamp": 1713900000
  }
  ↓
Node.js API receives request
  ↓
PluginTelemetryService.record()
  ├─ Validate DTO (TypeScript type checking)
  ├─ Insert into plugin_telemetry table
  └─ If install/update: sendEmail()
      ├─ Enrich with PartnerLokiService metadata
      ├─ Create Mailgun client
      ├─ Send HTML + text email
      └─ Log success/failure
  ↓
Email sent to sumanth@instaread.com (production only)
  ↓
Dashboard API queries latest records per partner
  ↓
React dashboard displays plugin status

Heartbeat events: NO EMAIL (prevents spam)
```

### 4.2 Telemetry Audit Checklist

**WordPress Plugin Side:**

- [ ] **Activation telemetry**
  - [ ] Transient set on `register_activation_hook`
  - [ ] On first `admin_init`, telemetry sent
  - [ ] Event type: `"install"`
  - [ ] Record in database within 1 minute

- [ ] **Update telemetry**
  - [ ] `on_plugin_updated()` fires after upgrade
  - [ ] Event type: `"update"`
  - [ ] `old_version` populated correctly
  - [ ] Record in database within 1 minute

- [ ] **Heartbeat telemetry**
  - [ ] Sends daily via transient
  - [ ] Event type: `"heartbeat"`
  - [ ] Triggered on `admin_init`
  - [ ] Transient prevents duplicates (24h window)

- [ ] **Endpoint reachability**
  ```bash
  # From partner server:
  curl -X POST https://player-api.instaread.co/api/plugin-telemetry \
    -H "Content-Type: application/json" \
    -d '{"event":"install","partner_id":"test","version":"4.4.7","site_url":"https://test.com","timestamp":'$(date +%s)'}'
  
  # Should return: {"ok":true}
  ```

**Node.js API Side:**

- [ ] **Endpoint responds correctly**
  ```bash
  curl -X GET https://player-api.instaread.co/api/plugin-telemetry | jq .
  # Should return array of latest telemetry per partner
  ```

- [ ] **CORS allows external requests**
  - [ ] Header: `Access-Control-Allow-Origin: *`
  - [ ] Header: `Access-Control-Allow-Methods: GET, POST, OPTIONS`
  - [ ] Route whitelisted in `init-fast.ts`

- [ ] **Database stores records**
  ```sql
  SELECT COUNT(*) FROM plugin_telemetry;
  SELECT * FROM plugin_telemetry ORDER BY ts DESC LIMIT 10;
  ```

- [ ] **Email sends in production**
  - [ ] `NODE_ENV=production` set
  - [ ] `MAILGUN_KEY` configured
  - [ ] `MAILGUN_DOMAIN=mg.instaread.co`
  - [ ] Emails received (install/update only, not heartbeat)

### 4.3 Telemetry Testing

```bash
# 1. Send test event
curl -X POST https://player-api.instaread.co/api/plugin-telemetry \
  -H "Content-Type: application/json" \
  -d '{
    "event":"install",
    "partner_id":"test-partner",
    "version":"4.4.7",
    "site_url":"https://test.com",
    "timestamp":'$(date +%s)'
  }'

# 2. Verify database insert
psql -U postgres -d instaread -c "SELECT * FROM plugin_telemetry WHERE partner_id='test-partner' ORDER BY ts DESC;"

# 3. Check email received
# Gmail: sumanth@instaread.com → Search for "test-partner"

# 4. Verify dashboard shows data
# Visit: https://player-api.instaread.co/wordpress-plugin
# Should display partner status
```

---

## 5. Developer Checklist

### 5.1 Before Releasing New Partner

**Repository Setup:**

- [ ] Create partner directory: `/partners/{partner_id}/`
- [ ] Add `config.json` with correct settings
- [ ] Add `plugin.json` with GitHub release URL
- [ ] Add `styles.css` (can be empty or custom)
- [ ] Commit and push to GitHub

**Plugin Configuration:**

- [ ] Verify `config.json` values:
  - `partner_id` (lowercase, no spaces)
  - `domain` (partner's domain)
  - `publication` (for player metadata)
  - `injection_context` (post/page/singular)
  - `injection_rules` with correct selectors
  - `version` (matches core plugin version)

- [ ] Test CSS selectors on partner's live site
  - Open article in browser
  - Press F12 (DevTools)
  - Test each selector in console: `document.querySelector('.selector')`
  - Pick selector that matches content container

- [ ] Configure exclude_slugs
  - Homepage `/`
  - Navigation pages (about, contact, privacy, etc.)
  - Category/archive pages
  - Special pages (video, gallery, etc.)

**GitHub Release:**

- [ ] Create GitHub release with tag: `{partner_id}-v{version}`
- [ ] Upload ZIP file containing:
  - `instaread-core.php`
  - `config.json`
  - `styles.css`
  - `plugin-update-checker/` directory
- [ ] Release notes explain what's included

**Testing:**

- [ ] Test installation on staging WordPress
  - Install plugin
  - Activate plugin
  - Check telemetry in database
  - Check email notification
- [ ] Test player injection
  - Load article page
  - Verify player appears in correct position
  - Test on different post types
- [ ] Test auto-update
  - Create new version
  - Update plugin.json
  - Check for updates on WordPress
  - Click "Update Now"
  - Verify telemetry shows update event

### 5.2 Before Releasing New Core Version

**Code Changes:**

- [ ] Update `PLUGIN_VERSION` constant in core.php
- [ ] Update version header in core.php
- [ ] Update CHANGELOG or commit message
- [ ] All code reviewed and tested

**For Each Partner:**

- [ ] Update `/partners/{partner_id}/config.json` version
- [ ] Update `/partners/{partner_id}/plugin.json` version
- [ ] Update `/partners/{partner_id}/plugin.json` changelog
- [ ] Update `/partners/{partner_id}/plugin.json` download_url

**GitHub Release:**

- [ ] Create release for each partner
- [ ] Tag format: `{partner_id}-v{version}`
- [ ] Include ZIP file with all files
- [ ] Write release notes

**Testing:**

- [ ] Test on partner staging WordPress
- [ ] Verify telemetry works
- [ ] Verify auto-updates work
- [ ] Verify player injection works

---

## 6. Troubleshooting & Common Issues

### 6.1 Telemetry Not Sending

**Symptom:** Plugin installed but no database record

**Checklist:**

1. **Is endpoint reachable?**
   ```bash
   curl https://player-api.instaread.co/api/plugin-telemetry
   # Should return JSON array, not error
   ```

2. **Is admin_init being called?**
   - Partner must visit WordPress admin after activation
   - Telemetry sends on first admin page load
   - Check `/wp-admin/` is accessible

3. **Check WordPress debug log:**
   ```bash
   # On partner server:
   tail -100 /path/to/wp-content/debug.log | grep -i instaread
   ```

4. **Check partner_config loaded:**
   - Verify `config.json` exists at `wp-content/plugins/instaread-{id}/config.json`
   - Verify valid JSON (no syntax errors)

5. **Check endpoint from partner server:**
   ```bash
   # On partner server, test connectivity:
   curl -X POST https://player-api.instaread.co/api/plugin-telemetry \
     -H "Content-Type: application/json" \
     -d '{"event":"install","partner_id":"test","version":"4.4.7","site_url":"https://partner.com","timestamp":'$(date +%s)'}'
   ```

### 6.2 Player Not Injecting

**Symptom:** No player visible on article pages

**Checklist:**

1. **Is plugin active?**
   ```bash
   wp plugin list | grep instaread
   ```

2. **Is CSS selector correct?**
   - Open article in browser
   - Press F12
   - Run: `document.querySelector('.article-content')`
   - If null, selector doesn't match

3. **Is page excluded?**
   - Check current URL against `exclude_slugs`
   - Example: `/category/rumors/` → excluded

4. **Is injection_context correct?**
   - Post page should have `injection_context: "post"`
   - Page should have `injection_context: "singular"` or `"page"`

5. **Enable debug logs:**
   ```php
   // In wp-config.php:
   define('INSTAREAD_DEBUG', true);
   ```
   Then check logs for injection messages

### 6.3 Auto-Update Not Working

**Symptom:** Update shows as available but doesn't install

**Checklist:**

1. **Is Plugin Update Checker working?**
   - Check plugin Update Checker library exists
   - Verify `plugin.json` URL is correct on GitHub
   - Check GitHub file isn't returning 404

2. **Is auto-update enabled?**
   - In partner's `wp-config.php`:
   ```php
   define('WP_AUTO_UPDATE_PLUGIN', true);
   ```
   - Or manually click "Update Now"

3. **Check WordPress logs:**
   ```bash
   tail -100 /path/to/wp-content/debug.log | grep -i update
   ```

4. **Manual update test:**
   - Go to Plugins page
   - If "Update Available" shows, click "Update Now"
   - Check database for telemetry record

### 6.4 Emails Not Received

**Symptom:** Telemetry record in database but no email

**Checklist:**

1. **Is production environment?**
   - Email only sends when `NODE_ENV=production`
   - Check server environment variable

2. **Mailgun configured?**
   - `MAILGUN_KEY` set in environment
   - `MAILGUN_DOMAIN=mg.instaread.co`
   - Check Mailgun dashboard for bounces/errors

3. **Event type correct?**
   - Heartbeat events DON'T send email (by design)
   - Only install/update events send email
   - Check `event` field in database

4. **Check server logs:**
   - Look for Mailgun API errors
   - Check SMTP logs if applicable

---

## Summary

This audit guide covers the complete lifecycle:

✅ **Installation** — transient-based activation telemetry
✅ **Auto-Updates** — Plugin Update Checker v5 from GitHub
✅ **Player Injection** — CSS selector targeting with fallback
✅ **Telemetry** — Database storage with email notifications
✅ **Testing** — Step-by-step verification procedures
✅ **Troubleshooting** — Common issues and solutions

Use this guide to:
- 🚀 Launch new partners quickly and reliably
- 🔍 Audit existing installations
- 🐛 Debug issues systematically
- 📝 Document procedures for team

---

**Questions or issues?** Check the relevant section above, follow the checklist, and use the testing procedures.

**Last Updated:** April 23, 2026 - v4.4.7 Release
