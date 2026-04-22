#!/bin/bash
# audit-wordpress-site.sh — Audit WordPress site REST API content for Instaread injection
# Usage: ./scripts/audit-wordpress-site.sh https://irishcentral.com

set -e

DOMAIN="${1:?Usage: $0 https://domain.com}"

# Remove trailing slash and https:// prefix for cleaner display
DOMAIN_CLEAN=$(echo "$DOMAIN" | sed 's|https://||g' | sed 's|/$||g')
echo "=== WordPress Content Audit: $DOMAIN_CLEAN ==="
echo

# Step 1: Check if REST API is accessible
echo "Step 1: Checking REST API availability..."
if ! curl -s -o /dev/null -w "%{http_code}" "$DOMAIN/wp-json/"; then
  echo "❌ REST API not available at $DOMAIN/wp-json/"
  exit 1
fi
echo "✅ REST API is accessible"
echo

# Step 2: Fetch recent posts
echo "Step 2: Fetching recent posts..."
POSTS=$(curl -s "$DOMAIN/wp-json/wp/v2/posts?per_page=3&orderby=date&order=desc" 2>/dev/null | jq -r '.[].id' 2>/dev/null)

if [ -z "$POSTS" ]; then
  echo "❌ No posts found or JSON parsing failed"
  exit 1
fi

POST_COUNT=$(echo "$POSTS" | wc -l)
echo "✅ Found $POST_COUNT recent posts"
echo

# Step 3: Audit each post's content structure
echo "Step 3: Content structure audit..."
echo "=========================================="

for POST_ID in $POSTS; do
  echo
  echo "POST ID: $POST_ID"

  # Fetch post data
  POST_DATA=$(curl -s "$DOMAIN/wp-json/wp/v2/posts/$POST_ID" 2>/dev/null)

  if [ -z "$POST_DATA" ]; then
    echo "  ❌ Could not fetch post data"
    continue
  fi

  TITLE=$(echo "$POST_DATA" | jq -r '.title.rendered' 2>/dev/null || echo "Unknown")
  CONTENT=$(echo "$POST_DATA" | jq -r '.content.rendered' 2>/dev/null || echo "")

  echo "  Title: $TITLE"
  echo "  Content length: ${#CONTENT} characters"

  # Show first content element to verify REST API format
  if [ -n "$CONTENT" ]; then
    FIRST_ELEM=$(echo "$CONTENT" | sed 's/^[ \t]*//;s/[ \t]*$//' | head -1)

    # Truncate if too long
    if [ ${#FIRST_ELEM} -gt 100 ]; then
      FIRST_ELEM="${FIRST_ELEM:0:100}..."
    fi

    echo "  First element: $FIRST_ELEM"

    # Check for outer wrappers (which shouldn't be there in REST API content)
    if echo "$CONTENT" | grep -q "class=\"article-body\|class=\"story\|class=\"entry-content"; then
      echo "  ⚠️  WARNING: Found wrapper classes in REST API content (unexpected)"
    else
      echo "  ✅ No outer wrapper classes (expected for WordPress REST API)"
    fi
  fi
done

echo
echo "=========================================="
echo
echo "📋 Configuration Recommendation:"
echo "=========================================="
echo
echo "For '$DOMAIN_CLEAN', use this config:"
echo
cat << 'EOF'
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
EOF
echo
echo "Why:"
echo "  • target_selector: \"\" → WordPress-native injection (no selector matching)"
echo "  • insert_position: \"prepend\" → Player appears above first paragraph"
echo "  • REST API content has no outer wrapper classes — only inner article blocks"
echo
echo "✅ Audit complete!"
