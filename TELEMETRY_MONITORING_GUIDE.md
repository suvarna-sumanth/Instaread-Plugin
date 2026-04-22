# Telemetry Monitoring & Dashboard Guide

**Version:** 1.0  
**Last Updated:** April 23, 2026  
**Status:** Production Ready

---

## Overview

The WordPress plugin telemetry system provides real-time visibility into plugin installations, updates, and status across all partner sites. This guide explains how to monitor and use the dashboard.

---

## Table of Contents

1. [Dashboard URL & Access](#1-dashboard-url--access)
2. [Dashboard Features](#2-dashboard-features)
3. [Understanding the Data](#3-understanding-the-data)
4. [Monitoring Workflows](#4-monitoring-workflows)
5. [Email Notifications](#5-email-notifications)
6. [Database Queries](#6-database-queries)
7. [Alerts & Troubleshooting](#7-alerts--troubleshooting)

---

## 1. Dashboard URL & Access

**Production Dashboard:**
```
https://player-api.instaread.co/wordpress-plugin
```

**Features:**
- Real-time partner plugin status
- Installation and update history
- Last seen timestamps
- Plugin version tracking
- Website metadata enrichment

**Access:**
- Publicly accessible (no authentication required for now)
- Can be restricted later if needed
- Works on desktop, tablet, and mobile

---

## 2. Dashboard Features

### 2.1 Dashboard Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Instaread Audio Processor                                  │
├─────────────────────────────────────────────────────────────┤
│  🔌 WordPress Plugin Status         [Refresh] [↻ Auto-sync]  │
│  5 partners reporting · last checked 2 minutes ago           │
├─────────────────────────────────────────────────────────────┤
│ Partner      │ Website          │ Site URL  │ Ver  │ Event  │
├──────────────┼──────────────────┼───────────┼──────┼────────┤
│ irishcentral │ Irish Central    │ iriscentr…│ 4.4.7│ update │
│ hollywoodint…│ Hollywood in Toto│ hollywood…│ 4.4.9│ install│
│ abijita      │ Abijita          │ abijita.c…│ 4.4.5│ heart… │
│ …            │ …                │ …         │ …    │ …      │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Column Descriptions

| Column | Purpose | Example | Notes |
|--------|---------|---------|-------|
| **Partner** | Partner identifier | `irishcentral` | Used in config.json, GitHub tags |
| **Website** | Partner website name | `Irish Central` | From Loki metadata enrichment |
| **Site URL** | Partner's WordPress domain | `irishcentral.com` | Clickable link to site |
| **Version** | Current plugin version | `4.4.7` | Green badge, latest installed |
| **Event** | Last event type | `update` | install (blue), update (amber), heartbeat (gray) |
| **Last Seen** | Time since last activity | `2 minutes ago` | Relative time, updates every 30s |

### 2.3 Event Badges

**Install Event** (Blue)
- Plugin activated for first time
- New partner installation
- What to expect: Email notification, database record

**Update Event** (Amber/Orange)
- Plugin auto-updated or manually updated
- Shows version bump
- What to expect: Email with old/new version numbers

**Heartbeat Event** (Gray)
- Daily check-in from plugin
- Confirms plugin is still active
- What to expect: No email (prevents spam)

### 2.4 Refresh & Auto-Sync

**Manual Refresh Button:**
- Click to reload data immediately
- Useful for checking recent installations
- Shows "last checked X seconds ago"

**Auto-Sync:**
- Dashboard auto-refreshes every 30 seconds
- Shows real-time status updates
- Live timestamps

---

## 3. Understanding the Data

### 3.1 Event Types & Their Meaning

#### Install Event
```json
{
  "event": "install",
  "partner_id": "irishcentral",
  "version": "4.4.7",
  "old_version": null,
  "site_url": "https://irishcentral.com",
  "timestamp": 1713900000
}
```

**When it triggers:**
- Plugin activated for first time on a partner's WordPress

**What it means:**
- Partner site is now running your plugin
- Ready to display player on their content

**Action:**
- Verify player is injecting correctly on their site
- Check email notification received
- Monitor dashboard for "update" events

#### Update Event
```json
{
  "event": "update",
  "partner_id": "irishcentral",
  "version": "4.4.8",
  "old_version": "4.4.7",
  "site_url": "https://irishcentral.com",
  "timestamp": 1713901000
}
```

**When it triggers:**
- Manual update: User clicks "Update Now"
- Auto-update: Runs in background (every 12 hours)

**What it means:**
- Plugin successfully upgraded
- Version bump indicates bug fix or feature

**Action:**
- Verify update worked correctly
- Check changelog for what changed
- Monitor for any issues in player injection

#### Heartbeat Event
```json
{
  "event": "heartbeat",
  "partner_id": "irishcentral",
  "version": "4.4.7",
  "old_version": null,
  "site_url": "https://irishcentral.com",
  "timestamp": 1713907000
}
```

**When it triggers:**
- Daily (once per 24-hour period)
- On first WordPress admin page load each day

**What it means:**
- Plugin is still active on this partner's site
- Plugin is able to communicate with API

**Action:**
- Use for monitoring "last seen" times
- Detect if partner stops maintaining their WordPress
- No email sent (prevents spam)

### 3.2 Status Interpretation

**Recently Updated** (< 1 hour ago)
```
Event: update | Last Seen: 5 minutes ago
```
- Auto-update or manual update just completed
- Plugin is working
- Monitor next 24 hours for issues

**Active Installation** (< 24 hours)
```
Event: heartbeat | Last Seen: 3 hours ago
```
- Plugin was accessed today
- Partner is maintaining their WordPress

**Inactive Installation** (> 7 days)
```
Event: heartbeat | Last Seen: 8 days ago
```
- Partner hasn't accessed WordPress lately
- Plugin is installed but not being checked on
- May indicate site is abandoned or low-traffic

**Never Seen Heartbeat** (Only install events)
```
Event: install | Last Seen: 2 weeks ago
```
- Plugin installed but admin hasn't logged in since
- Possible setup issue or low-activity site

---

## 4. Monitoring Workflows

### 4.1 Daily Monitoring Checklist

**Morning Review (5 minutes):**

1. **Check Dashboard**
   - Go to https://player-api.instaread.co/wordpress-plugin
   - Look for new "install" events (blue badges)
   - Look for new "update" events (amber badges)

2. **Check Email Inbox**
   - Filter for "Plugin" in Gmail
   - Should see install/update notifications
   - Check for any error alerts

3. **Quick Stats**
   - How many total partners? (row count)
   - How many updated in last 24h? (recent events)
   - Any sites inactive for > 30 days?

### 4.2 Weekly Monitoring Checklist

**Weekly Review (15 minutes):**

1. **Dashboard Trends**
   ```sql
   -- Partners with no update in 30+ days
   SELECT partner_id, MAX(ts) as last_seen 
   FROM plugin_telemetry 
   WHERE ts < NOW() - INTERVAL '30 days'
   GROUP BY partner_id;
   ```

2. **Update Distribution**
   - Are all partners on same version?
   - Any partners stuck on old versions?
   - Should we force-push critical updates?

3. **Heartbeat Health**
   - Most partners should have heartbeat < 24h
   - No heartbeats for > 7 days = potential issue

4. **Plugin Version Matrix**
   ```sql
   SELECT version, COUNT(DISTINCT partner_id) as partner_count
   FROM (
     SELECT DISTINCT ON (partner_id) 
            partner_id, version 
     FROM plugin_telemetry 
     ORDER BY partner_id, ts DESC
   ) latest
   GROUP BY version
   ORDER BY version DESC;
   ```

### 4.3 New Partner Launch Workflow

**When launching new partner:**

1. **Before Launch**
   - Confirm config.json is correct
   - Confirm plugin.json points to GitHub release
   - Confirm GitHub release has ZIP file

2. **Launch Day**
   - Partner installs plugin
   - Partner activates plugin
   - Dashboard should show "install" event within 2 minutes
   - Email should arrive within 2 minutes

3. **Post-Launch (First 24h)**
   - Verify player injects correctly on their site
   - Check multiple article types for correct position
   - Monitor dashboard for heartbeat event (visit admin)
   - Verify update detection works (manually check or wait 12h)

4. **Ongoing Monitoring**
   - Daily: Check for heartbeats (confirms site is active)
   - Weekly: Check version (all updated?)
   - Monthly: Review trends

### 4.4 Update Release Workflow

**When releasing new plugin version (e.g., 4.4.8):**

1. **Pre-Release**
   ```bash
   # Update version everywhere
   # Commit and push to GitHub
   git push origin main
   
   # Create releases for each partner
   gh workflow run partner-builds.yml -f partner_id=irishcentral -f version=4.4.8
   gh workflow run partner-builds.yml -f partner_id=hollywoodintoto -f version=4.4.8
   ```

2. **Release Day**
   - Dashboard may not show updates immediately
   - Partners auto-update within 12 hours (or manual update)
   - Watch for "update" events in dashboard

3. **Post-Release Monitoring**
   - **Hour 1:** Check first partners updating
   - **Hour 6:** Check 50% have updated
   - **Hour 24:** Check most have updated
   - **Day 3:** Check all partners have updated (or investigate stragglers)

4. **Deployment Success Metrics**
   ```sql
   -- What percentage updated to new version?
   SELECT 
     version,
     COUNT(DISTINCT partner_id) as partners,
     ROUND(100.0 * COUNT(*) / (SELECT COUNT(DISTINCT partner_id) FROM plugin_telemetry), 1) as percent
   FROM (
     SELECT DISTINCT ON (partner_id) partner_id, version
     FROM plugin_telemetry
     ORDER BY partner_id, ts DESC
   ) latest
   GROUP BY version
   ORDER BY version DESC;
   ```

---

## 5. Email Notifications

### 5.1 Email Types

**Install Email**
```
Subject: 📦 Plugin Installed — Irish Central (irishcentral) v4.4.7

Content:
  Partner: irishcentral
  Website: Irish Central
  Domain: https://irishcentral.com
  Version: 4.4.7
  Event: Plugin Installation
  Time: 2026-04-23 15:30:00 UTC

Action: Verify player injecting correctly
```

**Update Email**
```
Subject: 🔄 Plugin Updated — Irish Central (irishcentral) v4.4.8

Content:
  Partner: irishcentral
  Website: Irish Central
  Domain: https://irishcentral.com
  Old Version: 4.4.7
  New Version: 4.4.8
  Event: Plugin Update
  Time: 2026-04-23 15:30:00 UTC

Action: Verify update successful, test player
```

### 5.2 Email Management

**Filter in Gmail:**
```
label:instaread from:audioarticles@instaread.co subject:Plugin
```

**Auto-Archive Rules:**
- Keep install/update emails (important)
- Archive heartbeat emails (if sent—currently disabled)

**Email Alerts Setup:**
```
Gmail Filter:
- From: audioarticles@instaread.co
- Subject: Plugin Updated
- Action: Star + Notify

This flags critical updates for immediate review
```

---

## 6. Database Queries

### 6.1 Common Queries

**Get latest status for all partners:**
```sql
SELECT DISTINCT ON (partner_id)
  partner_id,
  version,
  event,
  ts as last_seen,
  site_url,
  NOW() - ts as time_since_last_event
FROM plugin_telemetry
ORDER BY partner_id, ts DESC;
```

**Get all events for a specific partner:**
```sql
SELECT event, version, old_version, ts, site_url
FROM plugin_telemetry
WHERE partner_id = 'irishcentral'
ORDER BY ts DESC
LIMIT 20;
```

**Count total partners:**
```sql
SELECT COUNT(DISTINCT partner_id) as total_partners
FROM plugin_telemetry;
```

**Partners with no activity in X days:**
```sql
SELECT 
  partner_id,
  MAX(ts) as last_seen,
  NOW() - MAX(ts) as inactive_duration
FROM plugin_telemetry
WHERE ts < NOW() - INTERVAL '7 days'
GROUP BY partner_id
ORDER BY MAX(ts) DESC;
```

**Version distribution:**
```sql
SELECT 
  version,
  COUNT(DISTINCT partner_id) as partner_count,
  ROUND(100.0 * COUNT(*) / (SELECT COUNT(DISTINCT partner_id) FROM plugin_telemetry), 1) as percent
FROM (
  SELECT DISTINCT ON (partner_id) partner_id, version
  FROM plugin_telemetry
  ORDER BY partner_id, ts DESC
) latest
GROUP BY version
ORDER BY version DESC;
```

**Update timeline (how fast partners update):**
```sql
SELECT 
  event,
  EXTRACT(HOUR FROM (ts - LAG(ts) OVER (PARTITION BY partner_id ORDER BY ts))) as hours_since_last,
  COUNT(*) as count
FROM plugin_telemetry
WHERE event IN ('update', 'install')
GROUP BY event, hours_since_last
ORDER BY hours_since_last;
```

### 6.2 Performance Tips

**For large queries:**
- Always use `LIMIT` to prevent timeout
- Add index on `partner_id` for faster queries
- Use `DISTINCT ON` for latest records

**Current indexes:**
```sql
CREATE INDEX plugin_telemetry_partner_id ON plugin_telemetry(partner_id);
CREATE INDEX plugin_telemetry_ts ON plugin_telemetry(ts DESC);
```

---

## 7. Alerts & Troubleshooting

### 7.1 Alert Conditions

**Red Flags:**

1. **No install for new partner after 1 hour**
   - Check: Is plugin.json pointing to correct GitHub release?
   - Check: Does release ZIP exist?
   - Check: Did partner actually activate plugin?
   - Action: Verify partner's WordPress logs

2. **Partner version stuck on old version for > 30 days**
   - Check: Auto-updates enabled in wp-config.php?
   - Check: Is plugin update available showing?
   - Check: Any WordPress errors blocking updates?
   - Action: Contact partner, verify WordPress health

3. **No heartbeat for > 7 days**
   - Check: Is partner's WordPress still running?
   - Check: Is site accessible?
   - Check: Did they disable plugin?
   - Action: Contact partner for status

4. **Email not received for install/update**
   - Check: Is production environment? (`NODE_ENV=production`)
   - Check: Mailgun configured? (`MAILGUN_KEY` set)
   - Check: Check Mailgun dashboard for bounces
   - Action: Review server logs, verify Mailgun health

### 7.2 Troubleshooting Decision Tree

**"Partner installed but no database record"**
```
├─ Is admin_init being called?
│  ├─ Yes: Check if wp_remote_post() blocked
│  └─ No: Partner must visit WordPress admin
├─ Is endpoint reachable?
│  ├─ Yes: Check if request blocked by firewall
│  └─ No: Check network connectivity
└─ Check WordPress debug log
```

**"Some partners updated, others didn't"**
```
├─ Check partner wp-config.php
│  ├─ WP_AUTO_UPDATE_PLUGIN enabled?
│  ├─ wp-config.php writable?
│  └─ Cron jobs running?
├─ Check plugin.json on GitHub
│  ├─ Is version correct?
│  ├─ Is download_url valid?
│  └─ Does ZIP file exist?
└─ Manually test update on staging
```

**"Player not injecting but plugin is active"**
```
├─ Verify CSS selector correct
│  ├─ Does .article-content exist in HTML?
│  └─ Try fallback selectors
├─ Check injection_context
│  ├─ Is post type correct?
│  └─ Is page excluded?
├─ Enable debug logs
│  └─ tail -f wp-content/debug.log
└─ Check browser console for errors
```

### 7.3 Status Page (Future)

**Proposed Status Dashboard:**
```
System Health
├─ API Endpoint: ✅ Up (response time: 45ms)
├─ Database: ✅ Connected
├─ Mailgun: ✅ Sending
├─ GitHub: ✅ Releases accessible

Partner Summary
├─ Active Partners: 25
├─ Updated in 24h: 5
├─ Updated in 7d: 22
├─ Inactive > 30d: 3

Latest Updates (Last 24h)
├─ irishcentral: 4.4.8 ✅
├─ hollywoodintoto: 4.4.7 ✅
└─ abijita: 4.4.5 (pending)
```

---

## Quick Reference

**Dashboard URL:**
```
https://player-api.instaread.co/wordpress-plugin
```

**Key Metrics to Monitor:**
- ✅ New installations (install events)
- ✅ Update adoption (version distribution)
- ✅ Partner activity (heartbeat freshness)
- ✅ Email notifications (install/update only)

**Daily Action Items:**
1. Check dashboard for new installs
2. Check email inbox for notifications
3. Note any inactive partners (> 7 days)

**Weekly Action Items:**
1. Review version distribution
2. Check for stuck updates
3. Investigate inactive sites
4. Review trends in adoption

---

**Questions?** Check the relevant section above or review the main plugin documentation.

**Last Updated:** April 23, 2026 - v4.4.7 Release
