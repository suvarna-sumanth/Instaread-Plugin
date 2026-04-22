# WordPress Audit Script Usage Guide

Fast, automated REST API audits for WordPress partner sites.

---

## Quick Start

```bash
./scripts/audit-wordpress-site.sh https://abijita.com
```

Output shows:
- ✅ REST API availability
- 📊 Recent posts and content analysis
- 📋 Recommended config
- ✅ Configuration status

---

## Full Usage

### Basic Audit

```bash
./scripts/audit-wordpress-site.sh https://domain.com
```

**What it does:**
1. Checks if REST API is accessible at `/wp-json/`
2. Fetches 3 most recent posts
3. Analyzes content structure
4. Checks for outer wrapper classes
5. Displays config recommendation

### Output Sections

**Step 1: REST API Check**
```
Step 1: Checking REST API availability...
✅ REST API is accessible
```

**Step 2: Post Fetching**
```
Step 2: Fetching recent posts...
✅ Found 3 recent posts
```

**Step 3: Content Analysis**
```
Step 3: Content structure audit...
==========================================

POST ID: 53134
  Title: Meta To Track Employee Keystrokes For AI Training
  Content length: 1099 characters
  First element: <p>Meta is turning inward in its search...</p>
  ✅ No outer wrapper classes (expected for WordPress REST API)
```

**Configuration Recommendation**
```
📋 Configuration Recommendation:
==========================================

For 'abijita.com', use this config:

{
  "partner_id": "PARTNER_ID",
  "domain": "DOMAIN.com",
  "publication": "PUBLICATION",
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

---

## Real-World Examples

### Example 1: Audit abijita.com

```bash
$ ./scripts/audit-wordpress-site.sh https://abijita.com

=== WordPress Content Audit: abijita.com ===

Step 1: Checking REST API availability...
✅ REST API is accessible

Step 2: Fetching recent posts...
✅ Found 3 recent posts

Step 3: Content structure audit...
==========================================

POST ID: 53134
  Title: Meta To Track Employee Keystrokes For AI Training
  Content length: 1099 characters
  First element: <p>Meta is turning inward...
  ✅ No outer wrapper classes (expected for WordPress REST API)

POST ID: 53131
  Title: OpenAI Launches Images 2.0 With Smarter Visual Generation
  Content length: 2924 characters
  First element: <p>OpenAI announced on Tuesday...
  ✅ No outer wrapper classes (expected for WordPress REST API)

POST ID: 53126
  Title: France Government Agency ANTS Discloses Data Breach
  Content length: 2240 characters
  First element: <p>France's data protection...
  ✅ No outer wrapper classes (expected for WordPress REST API)

==========================================

📋 Configuration Recommendation:
==========================================

For 'abijita.com', use this config:

{
  "partner_id": "abijita",
  "domain": "abijita.com",
  "publication": "abijita",
  "injection_context": "post",
  "injection_rules": [
    {
      "target_selector": "",
      "insert_position": "prepend",
      "exclude_slugs": [...]
    }
  ]
}

Why:
  • target_selector: "" → WordPress-native injection (no selector matching)
  • insert_position: "prepend" → Player appears above first paragraph
  • REST API content has no outer wrapper classes — only inner article blocks

✅ Audit complete!
```

---

## Interpreting Results

### ✅ All Posts Show No Wrapper Classes

**Meaning:** Site is properly configured for WordPress-native injection.

**Config to use:**
```json
{
  "target_selector": "",
  "insert_position": "prepend"
}
```

**Result:** Player will prepend cleanly to article content.

### ⚠️ Some Posts Show Wrapper Classes

**Meaning:** REST API might be returning theme-modified content (rare but possible).

**Action:**
1. Manually inspect a post via REST API:
   ```bash
   curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered'
   ```
2. Check if wrapper classes are actually present in raw REST API output
3. If yes, investigate theme customization
4. If no, it's a display artifact of the audit script

### ❌ REST API Not Accessible

**Message:**
```
❌ REST API not available at https://domain.com/wp-json/
```

**Possible causes:**
1. Site doesn't have WordPress REST API enabled
2. REST API is blocked or password-protected
3. Domain is incorrect or unreachable

**Action:**
1. Verify domain and protocol (https vs http)
2. Check if `/wp-json/` is accessible manually:
   ```bash
   curl -I https://domain.com/wp-json/
   ```
3. If 401/403, the site requires authentication
4. If 404, REST API might be disabled

---

## Batch Auditing

Audit multiple sites in a loop:

```bash
#!/bin/bash
# Audit multiple WordPress partners

SITES=(
  "https://abijita.com"
  "https://irishcentral.com"
  "https://bestofarkansassports.com"
  "https://winnipegfreepress.com"
)

for SITE in "${SITES[@]}"; do
  echo
  echo "════════════════════════════════════════════════════════════"
  ./scripts/audit-wordpress-site.sh "$SITE"
  echo "════════════════════════════════════════════════════════════"
done
```

This will generate a comprehensive audit report for all sites.

---

## Manual REST API Inspection

If you need more detailed information than the script provides, use curl directly:

### Get Recent Posts

```bash
curl -s "https://domain.com/wp-json/wp/v2/posts?per_page=5&orderby=date&order=desc" | jq '.'
```

### Get Single Post with Full Content

```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered'
```

### Save Content to File for Inspection

```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq -r '.content.rendered' > content.html
open content.html  # macOS
# or xdg-open content.html  # Linux
```

### Check for Specific Classes

```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered' | grep -i "article-body\|entry-content\|story"
```

If no matches, then CSS selectors don't exist in REST API content.

---

## Troubleshooting

### Script returns "parse error: Invalid numeric literal"

**Cause:** REST API is returning HTML instead of JSON (site might have redirects).

**Fix:**
1. Check the domain manually:
   ```bash
   curl -I https://domain.com/wp-json/
   ```
2. Look for redirect status codes (301, 302, 307)
3. Try without trailing slash or www prefix

### Script shows content length but no "First element"

**Cause:** Content is present but empty or whitespace-only.

**Investigation:**
```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq '.content.rendered | length'
```

If length > 0, content exists. Display it:
```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq -r '.content.rendered' | head -20
```

### Script takes a long time

**Cause:** Slow network or site performance.

**Mitigation:**
- Try a different post ID (might be causing timeouts)
- Increase curl timeout:
  ```bash
  curl -m 30 "https://domain.com/wp-json/wp/v2/posts"
  ```

---

## Script Source

**File:** `scripts/audit-wordpress-site.sh`

Features:
- ✅ REST API availability check
- ✅ Recent posts fetching
- ✅ Content structure analysis
- ✅ Wrapper class detection
- ✅ Config recommendation
- ✅ Simple error handling

---

## Integration with Partner Setup

### Workflow

1. **Create partner directory:**
   ```bash
   mkdir -p partners/PARTNER_ID
   ```

2. **Run audit:**
   ```bash
   ./scripts/audit-wordpress-site.sh https://domain.com
   ```

3. **Copy recommended config:**
   Create `partners/PARTNER_ID/config.json` with output from script

4. **Verify on live site:**
   Visit an article page and check player placement

---

## Recommended Commands

### Quick audit
```bash
./scripts/audit-wordpress-site.sh https://domain.com
```

### Detailed REST API inspection
```bash
curl -s "https://domain.com/wp-json/wp/v2/posts?per_page=1" | jq '.[0]'
```

### Check specific post content
```bash
curl -s "https://domain.com/wp-json/wp/v2/posts/POST_ID" | jq -r '.content.rendered'
```

### Batch audit
```bash
for domain in domain1.com domain2.com domain3.com; do
  ./scripts/audit-wordpress-site.sh "https://$domain"
done
```
