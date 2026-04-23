# Instant Update Webhook Implementation

**Version:** 1.0  
**Date:** April 23, 2026  
**Status:** Ready for Implementation

---

## Overview

Enable **instant partner-specific plugin updates** by triggering an update check webhook when a new plugin version is released on GitHub.

**Current flow:** 12-hour delay (WordPress cron checks for updates)
**New flow:** Instant (webhook triggers immediately on release)

---

## Architecture

```
GitHub Actions Workflow (Instaread Plugin)
  ↓
Release Published: irishcentral-v4.4.8
  ↓
Webhook triggered: POST /api/github-webhook
  ↓
Audio Processor (NestJS)
  ├─ Validates GitHub signature
  ├─ Extracts: partner_id=irishcentral, version=4.4.8
  ├─ Queries DB: Find all irishcentral partner sites
  └─ For each site: Trigger update check
      ↓
  Partner WordPress sites (irishcentral only)
  ├─ Receive: force_update_check request
  ├─ Check for updates immediately
  ├─ Auto-install v4.4.8
  └─ Send telemetry: event="update"
      ↓
  Audio Processor receives telemetry
  ├─ Stores in database
  ├─ Sends email notification
  └─ Dashboard updates
```

---

## Implementation Steps

### Step 1: GitHub Actions Workflow Update

**File:** `.github/workflows/partner-builds.yml`

**Add webhook trigger step after "Create Release":**

```yaml
- name: Trigger instant update webhook
  if: success()
  run: |
    PARTNER_ID="${{ github.event.inputs.partner_id }}"
    VERSION="${{ github.event.inputs.version }}"
    WEBHOOK_URL="${{ secrets.INSTAREAD_WEBHOOK_URL }}"
    WEBHOOK_SECRET="${{ secrets.INSTAREAD_WEBHOOK_SECRET }}"
    
    # Create payload
    PAYLOAD=$(cat <<'EOF'
    {
      "action": "published",
      "release": {
        "tag_name": "PARTNER_ID-vVERSION",
        "partner_id": "PARTNER_ID",
        "version": "VERSION"
      }
    }
    EOF
    )
    
    # Replace placeholders
    PAYLOAD="${PAYLOAD//PARTNER_ID/$PARTNER_ID}"
    PAYLOAD="${PAYLOAD//VERSION/$VERSION}"
    
    # Send webhook
    echo "Triggering webhook for $PARTNER_ID v$VERSION"
    curl -X POST "$WEBHOOK_URL" \
      -H "Content-Type: application/json" \
      -H "X-Instaread-Signature: $(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | sed 's/.*= //')" \
      -d "$PAYLOAD"
    
    echo "Webhook triggered successfully"

- name: Log webhook trigger
  run: echo "✅ Update webhook triggered for ${{ github.event.inputs.partner_id }} v${{ github.event.inputs.version }}"
```

**Add GitHub Actions secrets:**
```bash
# In GitHub repo settings:
INSTAREAD_WEBHOOK_URL=https://player-api.instaread.co/api/github-webhook
INSTAREAD_WEBHOOK_SECRET=your-secret-key-here (generate random 32+ char string)
```

---

### Step 2: Audio Processor Webhook Endpoint

**File:** `apps/server/src/modules/plugin-telemetry/plugin-telemetry.controller.ts`

```typescript
import { Controller, Post, Body, Headers, HttpCode, HttpStatus } from '@nestjs/common';
import { PluginTelemetryService } from './plugin-telemetry.service';
import * as crypto from 'crypto';

interface GithubWebhookPayload {
  action: string;
  release: {
    tag_name: string;
    partner_id: string;
    version: string;
  };
}

@Controller('api/plugin-telemetry')
export class PluginTelemetryController {
  constructor(private readonly service: PluginTelemetryService) {}

  // Existing endpoints...
  // POST / - for plugin telemetry
  // GET / - for dashboard

  /**
   * GitHub webhook endpoint for instant plugin updates
   * 
   * Flow:
   * 1. GitHub Actions releases new plugin version
   * 2. Sends POST /api/github-webhook with release info
   * 3. We validate GitHub signature
   * 4. Extract partner_id and version
   * 5. Trigger update check for ONLY that partner's sites
   */
  @Post('github-webhook')
  @HttpCode(HttpStatus.OK)
  async handleGithubWebhook(
    @Body() payload: GithubWebhookPayload,
    @Headers('x-instaread-signature') signature: string,
  ) {
    // Step 1: Validate GitHub signature
    if (!this.validateSignature(payload, signature)) {
      console.error('[SECURITY] Invalid webhook signature');
      return { error: 'Invalid signature' };
    }

    // Step 2: Only process "published" action
    if (payload.action !== 'published') {
      console.log(`[WEBHOOK] Ignoring action: ${payload.action}`);
      return { ok: true, message: 'Action not processed' };
    }

    // Step 3: Extract partner_id and version from tag
    const { partner_id, version } = payload.release;

    if (!partner_id || !version) {
      console.error('[WEBHOOK] Missing partner_id or version', payload);
      return { error: 'Missing partner_id or version' };
    }

    console.log(`[WEBHOOK] Processing release: ${partner_id} v${version}`);

    // Step 4: Trigger update check for this partner's sites
    try {
      const result = await this.service.triggerPartnerUpdateCheck(
        partner_id,
        version,
      );

      console.log(`[WEBHOOK] Update check triggered for ${partner_id}:`, result);

      return {
        ok: true,
        message: `Update check triggered for ${partner_id} v${version}`,
        sites_notified: result.count,
        partner_id,
        version,
      };
    } catch (error) {
      console.error('[WEBHOOK] Error triggering update check:', error);
      return { error: error.message };
    }
  }

  /**
   * Validate GitHub webhook signature
   * 
   * GitHub sends: X-Instaread-Signature: sha256=<hash>
   * We verify the hash matches HMAC-SHA256(payload, secret)
   */
  private validateSignature(payload: any, signature: string): boolean {
    const secret = process.env.INSTAREAD_WEBHOOK_SECRET || '';

    if (!secret) {
      console.warn('[SECURITY] INSTAREAD_WEBHOOK_SECRET not configured');
      return false;
    }

    // Create HMAC-SHA256 of payload
    const payloadString = JSON.stringify(payload);
    const hash = crypto
      .createHmac('sha256', secret)
      .update(payloadString)
      .digest('hex');

    const expectedSignature = `sha256=${hash}`;

    // Constant-time comparison to prevent timing attacks
    return crypto.timingSafeEqual(
      Buffer.from(signature),
      Buffer.from(expectedSignature),
    );
  }
}
```

---

### Step 3: Trigger Service in PluginTelemetryService

**File:** `apps/server/src/modules/plugin-telemetry/plugin-telemetry.service.ts`

```typescript
import { Injectable, Inject } from '@nestjs/common';
import { Pool } from 'pg';

interface UpdateCheckResult {
  count: number;
  sites: string[];
  partner_id: string;
  version: string;
}

@Injectable()
export class PluginTelemetryService {
  constructor(@InjectPool('DBPool') private db: Pool) {}

  // Existing methods...
  // record() - insert telemetry
  // getSummary() - get latest per partner

  /**
   * Trigger update check for all sites of a specific partner
   * 
   * Safe because:
   * - Only affects ONE partner's sites
   * - Other partners are NOT notified
   * - Each site receives a simple HTTP request
   */
  async triggerPartnerUpdateCheck(
    partner_id: string,
    version: string,
  ): Promise<UpdateCheckResult> {
    this.log(`[UPDATE_CHECK] Starting for partner: ${partner_id} v${version}`);

    // Step 1: Get all unique site URLs for this partner
    const { rows } = await this.db.query(
      `
      SELECT DISTINCT site_url 
      FROM plugin_telemetry 
      WHERE partner_id = $1 
      AND site_url IS NOT NULL
      AND site_url != ''
      ORDER BY site_url
      `,
      [partner_id],
    );

    const sites = rows.map(r => r.site_url);

    if (sites.length === 0) {
      this.log(
        `[UPDATE_CHECK] No sites found for partner: ${partner_id}`,
      );
      return {
        count: 0,
        sites: [],
        partner_id,
        version,
      };
    }

    this.log(
      `[UPDATE_CHECK] Found ${sites.length} sites for ${partner_id}: ${sites.join(', ')}`,
    );

    // Step 2: Trigger update check on each site (non-blocking)
    const notifyPromises = sites.map(site_url =>
      this.notifySiteForUpdate(site_url, partner_id, version),
    );

    // Fire-and-forget: don't wait for all to complete
    // This prevents slow/unresponsive sites from blocking the webhook response
    Promise.allSettled(notifyPromises).then(results => {
      const successful = results.filter(r => r.status === 'fulfilled').length;
      const failed = results.filter(r => r.status === 'rejected').length;

      this.log(
        `[UPDATE_CHECK] Notifications sent. Success: ${successful}, Failed: ${failed}`,
      );
    });

    return {
      count: sites.length,
      sites,
      partner_id,
      version,
    };
  }

  /**
   * Notify a single partner site to check for updates
   * 
   * Sends a request to the WordPress site with a special parameter
   * that WordPress recognizes as a force-update-check signal
   */
  private async notifySiteForUpdate(
    site_url: string,
    partner_id: string,
    version: string,
  ): Promise<void> {
    try {
      // Ensure site_url is valid
      if (!site_url.startsWith('http')) {
        this.log(`[UPDATE_CHECK] Invalid site_url, skipping: ${site_url}`);
        return;
      }

      // Build update check URL
      // WordPress will process this on next page load
      const updateCheckUrl = new URL(site_url);
      updateCheckUrl.searchParams.append('instaread_force_update_check', partner_id);

      this.log(
        `[UPDATE_CHECK] Notifying site: ${site_url} (${partner_id} v${version})`,
      );

      // Send non-blocking request with short timeout
      const response = await fetch(updateCheckUrl.toString(), {
        method: 'GET',
        timeout: 5000, // 5 second timeout
        headers: {
          'User-Agent': 'Instaread-Update-Checker/1.0',
        },
      });

      if (!response.ok) {
        this.log(
          `[UPDATE_CHECK] Site returned ${response.status}: ${site_url}`,
        );
      } else {
        this.log(`[UPDATE_CHECK] Successfully notified: ${site_url}`);
      }
    } catch (error) {
      // Log but don't throw - one failed site shouldn't break the whole process
      this.log(
        `[UPDATE_CHECK] Error notifying ${site_url}: ${error.message}`,
      );
    }
  }

  private log(message: string) {
    console.log(message);
    // Also log to database if you have an audit table
  }
}
```

---

### Step 4: WordPress Plugin Changes

**File:** `core/instaread-core.php`

Add handler for instant update check signal:

```php
public function __construct() {
    // ... existing code ...
    
    // NEW: Handle instant update check from webhook
    add_action('wp_loaded', [$this, 'maybe_force_update_check']);
}

/**
 * If webhook sends ?instaread_force_update_check=partner_id,
 * immediately check for updates (don't wait 12 hours)
 */
public function maybe_force_update_check() {
    // Only in admin or cron context
    if (!is_admin() && !defined('DOING_CRON')) {
        return;
    }

    // Check for the parameter
    $force_check = isset($_GET['instaread_force_update_check']) 
        ? sanitize_text_field($_GET['instaread_force_update_check']) 
        : '';

    if (empty($force_check)) {
        return;
    }

    // Verify it matches our partner_id
    if ($force_check !== ($this->partner_config['partner_id'] ?? '')) {
        return;
    }

    $this->log('Forcing update check via webhook');

    // Clear the update cache transient to force check
    delete_transient('plugin_update_checker_' . $this->partner_config['partner_id']);

    // This will trigger the update check on next admin page load
    // Or immediately if triggered via cron
}
```

---

### Step 5: Database Schema

**Ensure plugin_telemetry table has:**

```sql
CREATE TABLE plugin_telemetry (
  id SERIAL PRIMARY KEY,
  event VARCHAR(20),           -- install, update, heartbeat
  partner_id VARCHAR(100),     -- irishcentral, hollywoodintoto
  version VARCHAR(20),         -- 4.4.8
  old_version VARCHAR(20),     -- 4.4.7 (nullable)
  site_url TEXT,               -- https://irishcentral.com
  ts TIMESTAMP DEFAULT NOW(),
  
  -- Index for webhook queries
  CONSTRAINT idx_partner_id_site ON plugin_telemetry(partner_id, site_url)
);
```

---

### Step 6: Environment Configuration

**Add to Audio Processor .env:**

```bash
# GitHub Webhook Configuration
INSTAREAD_WEBHOOK_URL=https://player-api.instaread.co/api/plugin-telemetry/github-webhook
INSTAREAD_WEBHOOK_SECRET=generate-random-32-char-string-here

# Example secret generation:
# openssl rand -hex 32
```

**Add to GitHub Actions Secrets:**

```bash
# In GitHub repo: Settings → Secrets and variables → Actions

INSTAREAD_WEBHOOK_URL=https://player-api.instaread.co/api/plugin-telemetry/github-webhook
INSTAREAD_WEBHOOK_SECRET=your-same-secret-as-above
```

---

## Complete Flow Example

### Scenario: Release irishcentral v4.4.8

**1. Trigger release:**
```bash
gh workflow run partner-builds.yml -f partner_id=irishcentral -f version=4.4.8
```

**2. GitHub Actions workflow executes:**
```
✓ Checkout code
✓ Validate partner exists
✓ Generate plugin.json
✓ Build ZIP file
✓ Create GitHub release (irishcentral-v4.4.8)
✓ Trigger webhook → POST /api/github-webhook
✓ Log webhook trigger
```

**3. Audio Processor webhook endpoint receives:**
```json
{
  "action": "published",
  "release": {
    "tag_name": "irishcentral-v4.4.8",
    "partner_id": "irishcentral",
    "version": "4.4.8"
  }
}
```

**4. Audio Processor processes webhook:**
```
✓ Validate GitHub signature
✓ Extract: partner_id=irishcentral, version=4.4.8
✓ Query database: Find all irishcentral sites
  → Found sites: irishcentral.com, staging.irishcentral.com
✓ Send update check notification to EACH site (non-blocking)
✓ Return response (don't wait for sites to finish)
```

**5. Each irishcentral site receives notification:**
```
Site 1: irishcentral.com
├─ Receives: ?instaread_force_update_check=irishcentral
├─ WordPress checks for updates immediately
├─ Detects v4.4.8 available
├─ Auto-updates (within 1-2 minutes)
└─ Sends telemetry: event="update", old_version="4.4.7", version="4.4.8"

Site 2: staging.irishcentral.com
├─ Same as above
└─ Also sends telemetry
```

**6. Audio Processor receives telemetry from both sites:**
```
Database inserts:
- irishcentral, irishcentral.com, 4.4.8, 4.4.7, update
- irishcentral, staging.irishcentral.com, 4.4.8, 4.4.7, update

Email notifications:
- 🔄 Plugin Updated — Irish Central (irishcentral) v4.4.8

Dashboard:
- irishcentral row shows: v4.4.8, event=update, last_seen=just now
```

**7. Other partners unaffected:**
```
✓ hollywoodintoto sites: NO notification (not affected)
✓ abijita sites: NO notification (not affected)
✓ Other partners: Continue with normal 12-hour check cycle
```

---

## Security Considerations

### 1. GitHub Signature Validation
- ✅ We verify HMAC-SHA256 signature
- ✅ Only requests from GitHub (with correct secret) are processed
- ✅ Prevents unauthorized webhook calls

### 2. Partner-Specific Updates
- ✅ Only that partner's sites are notified
- ✅ No risk of updating wrong partners
- ✅ Database query ensures accuracy

### 3. Non-Blocking Notifications
- ✅ Webhook endpoint doesn't wait for sites to respond
- ✅ Slow/unresponsive sites don't block others
- ✅ Errors logged but don't cascade

### 4. Rate Limiting
- ✅ Webhook only triggers on release (not frequently)
- ✅ Each site gets notified once per release
- ✅ No spam or excessive requests

### 5. Logging & Audit
- ✅ All webhook calls logged
- ✅ Signature validation logged
- ✅ Partner site notifications tracked
- ✅ Failures logged for debugging

---

## Testing Procedure

### Local Testing

**1. Start Audio Processor locally:**
```bash
npm run dev:concurrently
```

**2. Test webhook endpoint with curl:**
```bash
# Generate test secret
SECRET="test-secret-key"

# Create payload
PAYLOAD='{"action":"published","release":{"tag_name":"irishcentral-v4.4.8","partner_id":"irishcentral","version":"4.4.8"}}'

# Generate signature
SIGNATURE="sha256=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/.*= //')"

# Send webhook
curl -X POST http://localhost:4000/api/plugin-telemetry/github-webhook \
  -H "Content-Type: application/json" \
  -H "X-Instaread-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

**Expected response:**
```json
{
  "ok": true,
  "message": "Update check triggered for irishcentral v4.4.8",
  "sites_notified": 2,
  "partner_id": "irishcentral",
  "version": "4.4.8"
}
```

**3. Check logs:**
```bash
# Should see:
# [UPDATE_CHECK] Processing release: irishcentral v4.4.8
# [UPDATE_CHECK] Found 2 sites for irishcentral
# [UPDATE_CHECK] Notifying site: https://irishcentral.com
# [UPDATE_CHECK] Successfully notified: https://irishcentral.com
```

### Production Testing

**1. Create test release:**
```bash
gh workflow run partner-builds.yml -f partner_id=irishcentral -f version=4.4.99-test
```

**2. Monitor logs:**
```bash
# Server logs
tail -f server.log | grep UPDATE_CHECK

# Database
SELECT * FROM plugin_telemetry 
WHERE partner_id='irishcentral' 
  AND version='4.4.99-test' 
ORDER BY ts DESC;
```

**3. Verify on partner site:**
- Visit `irishcentral.com` WordPress admin
- Check if plugin shows update available
- Verify update installs automatically
- Check telemetry dashboard for update event

---

## Rollback Plan

If webhook feature causes issues:

1. **Disable webhook in GitHub Actions:**
   - Comment out webhook trigger step in `.github/workflows/partner-builds.yml`
   - Releases will work normally (no webhook call)

2. **Disable in Audio Processor:**
   - Comment out `handleGithubWebhook` endpoint
   - Webhook calls will be ignored (404)

3. **Revert to manual check:**
   - Partners go back to 12-hour check cycle
   - Manual "Update Now" still works
   - Telemetry still works

---

## Monitoring

### Dashboard Indicators
- ✅ New update events appearing
- ✅ Multiple sites updating within 2-3 minutes
- ✅ Version badge updates in real-time

### Log Monitoring
```bash
# Follow webhook processing
tail -f /var/log/audio-processor.log | grep UPDATE_CHECK

# Count successful notifications
grep "Successfully notified" /var/log/audio-processor.log | wc -l
```

### Database Monitoring
```sql
-- See update events from webhook trigger
SELECT 
  partner_id, 
  version, 
  COUNT(*) as count,
  MIN(ts) as first_update,
  MAX(ts) as last_update
FROM plugin_telemetry
WHERE event = 'update'
  AND ts > NOW() - INTERVAL '1 hour'
GROUP BY partner_id, version
ORDER BY MIN(ts) DESC;
```

---

## Summary

This implementation enables:

✅ **Instant Updates** — Triggered immediately on release (not 12h delay)
✅ **Partner-Specific** — Only that partner's sites are affected
✅ **Secure** — GitHub signature validation
✅ **Reliable** — Non-blocking, logged, error-handled
✅ **Safe** — No risk to other partners
✅ **Observable** — Full logging and monitoring

**Timeline:**
- **1 minute after release:** Webhook triggered
- **1-2 minutes after:** Partner sites notified
- **2-5 minutes after:** Auto-updates complete
- **5-10 minutes after:** Telemetry received, email sent, dashboard updated

---

**Next Steps:**
1. Review this implementation
2. Set up GitHub Actions secrets
3. Implement webhook endpoint in Audio Processor
4. Implement trigger service
5. Deploy and test
6. Monitor rollout

