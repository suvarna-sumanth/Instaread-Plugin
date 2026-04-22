# Instaread Plugin Documentation

Complete guides for configuring, deploying, and auditing the Instaread audio player plugin.

---

## Core Documentation

### [WordPress Setup & Verification Guide](wordpress-setup-guide.md)
**Start here for WordPress partners.** Complete guide to:
- Creating WordPress partner configs
- Auditing REST API content
- Verifying player placement on live sites
- Troubleshooting common issues

### [WordPress Audit via REST API](wordpress-audit-via-rest-api.md)
**Detailed technical reference** on:
- How to use WordPress REST API for content auditing
- Why REST API content is canonical
- REST API endpoints and query parameters
- Bulk auditing multiple posts

### [WordPress Audit Example](wordpress-audit-example.md)
**Practical real-world example** showing:
- Step-by-step REST API audit workflow
- Common mistakes and how to avoid them
- What NOT to do (and why)
- Verification checklist

### [Config Scenarios Reference](config-scenarios.md)
**Script loading configuration guide** covering:
- `use_player_loader` option
- `enqueue_remote_player_script_sitewide` option
- 6 scenarios with expected behavior
- Decision table for quick reference

### [WordPress Injection Comparison](wordpress-injection-comparison.md)
**Visual before/after guide** showing:
- Why CSS selectors fail in REST API content
- Wrong approach vs correct WordPress-native approach
- How the plugin code works for each path
- Decision tree for choosing the right config

### [Audit Script Usage Guide](audit-script-usage.md)
**Complete guide to the automated audit tool** covering:
- Quick start and basic usage
- Output sections and interpretation
- Real-world examples and results
- Batch auditing multiple sites
- Troubleshooting and manual REST API inspection

---

## Quick Reference

### For WordPress Sites

**Always use this configuration:**
```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

**Why:**
- WordPress `the_content` filter returns only article body, no outer wrappers
- Empty selector triggers WordPress-native injection
- Player prepends cleanly above first paragraph
- No CSS selector matching needed, no JS mover fallback

### Verification

Before deploying a WordPress partner, verify using REST API:

```bash
# Get a recent post
curl -s "https://DOMAIN/wp-json/wp/v2/posts?per_page=1" | jq '.[0].id'

# Fetch its content (this is what the plugin injects into)
curl -s "https://DOMAIN/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered'

# Check:
# ✅ Starts with <p>, <h2>, <figure>, or block element
# ✅ No outer wrapper classes (.article-body, .story, etc.)
# ✅ Just raw article blocks
```

---

## File Structure

```
docs/
├── README.md (this file)
├── config-scenarios.md (script loading scenarios)
├── wordpress-setup-guide.md (setup & verification)
├── wordpress-audit-via-rest-api.md (technical reference)
└── wordpress-audit-example.md (practical example)

scripts/
└── audit-wordpress-site.sh (automated REST API audit)
```

---

## Common Tasks

### I'm setting up a new WordPress partner
1. Read [WordPress Setup & Verification Guide](wordpress-setup-guide.md)
2. Create config with `target_selector: ""` and `insert_position: "prepend"`
3. Run `./scripts/audit-wordpress-site.sh https://DOMAIN`
4. Verify player placement on live site

### I need to audit existing WordPress partners
Run the automated script:
```bash
./scripts/audit-wordpress-site.sh https://irishcentral.com
./scripts/audit-wordpress-site.sh https://winnipegfreepress.com
./scripts/audit-wordpress-site.sh https://bestofarkansassports.com
```

### Player is appearing in wrong location
1. Check if config has non-empty `target_selector`
2. Verify using REST API that selector exists in content
3. If not found in REST API, change `target_selector` to `""`
4. See [WordPress Setup Guide - Common Issues](wordpress-setup-guide.md#common-issues--fixes)

### I want to understand script loading behavior
Read [Config Scenarios Reference](config-scenarios.md) for 6 different configurations and their behavior.

---

## Key Principles

1. **REST API is canonical** — What you see in REST API is what the plugin injects into. Don't try to select elements that don't exist in REST API content.

2. **WordPress-native injection** — The plugin hooks into `the_content` filter. Content passed to the filter is only the article body, not the full page HTML.

3. **Empty selector for WordPress** — For all WordPress sites, use `target_selector: ""` to trigger the clean WordPress-native code path with no selector matching.

4. **Prepend for top placement** — `insert_position: "prepend"` places player above the first content element of the article.

5. **No JS mover needed** — If your config is correct, there should be no `<script>` tag after the player element. Presence of a script tag indicates the selector didn't match and fallback JS mover had to move it at runtime.

---

## Support

For issues or questions:
1. Check the relevant documentation file (listed above)
2. Look for your issue in [Common Issues & Fixes](wordpress-setup-guide.md#common-issues--fixes)
3. Run the automated audit script: `./scripts/audit-wordpress-site.sh`
4. Inspect browser DevTools to see actual player placement vs expected
