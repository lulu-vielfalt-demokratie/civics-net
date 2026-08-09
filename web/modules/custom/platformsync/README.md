# PlatformSync

Drupal 10/11 module that converts source text into platform-specific social media posts via the Anthropic Claude API.  
Supports multi-user operation, OAuth2-secured REST API for external CMS, subscription plans, credit-based billing, and usage logging.

---

## Features

| Feature | Details |
|---------|---------|
| **Platforms** | Bluesky, Mastodon, Threads, Instagram, Telegram, WhatsApp, Signal, X/Twitter, LinkedIn |
| **Multi-user** | Per-user subscription with plan + credit tracking |
| **Monetization** | Free / Pro / Enterprise plans + add-on credit packs |
| **External API** | OAuth2 client_credentials flow for any CMS or app |
| **Logging** | Immutable per-request log (tokens, credits, source, status) |
| **Monitoring** | Admin dashboard with daily sparkline, plan breakdown, revenue estimate |
| **Cron** | Automatic log purge after configurable retention period |
| **Drupal compat** | Core ^10 || ^11, no contrib dependencies beyond `rest`, `serialization`, `key` |

---

## Installation

```bash
# Copy module into your Drupal installation
cp -r platformsync /path/to/drupal/modules/custom/

# Enable via Drush
drush en platformsync -y
drush cr
```

Then navigate to:  
**Admin → Configuration → Services → PlatformSync**  
and enter your Anthropic API key.

---

## Permissions

| Permission | Role |
|-----------|------|
| `administer platformsync` | Admin |
| `use platformsync` | Authenticated editors |
| `use platformsync api` | External API clients |

---

## REST API for external CMS

### 1. Get a bearer token

```http
POST /api/platformsync/oauth/token
Content-Type: application/json

{
  "grant_type": "client_credentials",
  "client_id": "YOUR_CLIENT_ID",
  "client_secret": "YOUR_CLIENT_SECRET"
}
```

Response:
```json
{
  "access_token": "abc123...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "generate"
}
```

### 2. Generate posts

```http
POST /api/platformsync/generate
Authorization: Bearer abc123...
Content-Type: application/json

{
  "text": "Your source text here...",
  "platforms": ["bluesky", "mastodon", "telegram"],
  "tone": "informativ",
  "context": "Optional campaign context override"
}
```

Response:
```json
{
  "posts": {
    "bluesky":  "Generated Bluesky post...",
    "mastodon": "Generated Mastodon post...",
    "telegram": "Generated Telegram post..."
  },
  "tokens_used": 412,
  "credits_remaining": 87
}
```

### Available tones
`informativ` · `mobilisierend` · `persönlich` · `nüchtern-sachlich`

### Available platform IDs
`bluesky` · `mastodon` · `threads` · `instagram` · `telegram` · `whatsapp` · `signal` · `twitter` · `linkedin`

---

## Monetization

Three built-in plans, configurable in the admin UI:

| Plan | Default credits/month | Default price |
|------|----------------------|---------------|
| Free | 50 | €0 |
| Pro | 500 | €9 |
| Enterprise | 5000 | €49 |

Credits are **per generation request** (default: 1 credit = 1 request, regardless of platform count).  
Credits reset on the 1st of each month. The dashboard shows estimated monthly revenue based on active plan counts.

To integrate payment (Stripe, PayPal etc.), hook into `SubscriptionService::upgradePlan()` after successful payment confirmation.

---

## Database tables

| Table | Purpose |
|-------|---------|
| `platformsync_api_keys` | OAuth2 clients / API keys |
| `platformsync_oauth_tokens` | Short-lived bearer tokens |
| `platformsync_subscriptions` | Per-user plan + credit balance |
| `platformsync_usage_log` | Immutable request log |

---

## Development & extension hooks

- **Swap AI provider**: Replace `AnthropicService` and update the `platformsync.anthropic` service definition.
- **Add platforms**: Extend `AnthropicService::getPlatformDescriptions()` and the Twig template platform list.
- **Webhooks on events**: Subscribe to Drupal events in an `EventSubscriber` and dispatch on usage log writes.
- **Payment integration**: Call `SubscriptionService::upgradePlan($uid, 'pro')` from your payment confirmation controller.
- **WordPress plugin**: Use the REST API (`/api/platformsync/oauth/token` + `/api/platformsync/generate`). A companion WP plugin can be built around these two endpoints.

---

## Configuration reference (`platformsync.settings`)

```yaml
anthropic_api_key: 'sk-ant-...'
anthropic_model: 'claude-sonnet-4-20250514'
default_campaign_context: ''   # e.g. "Zusammen Bewegen, MV West gegen Rechts"
token_expiry_seconds: 3600
plans:
  free:       { label: Free,       credits_monthly: 50,   price_eur: 0  }
  pro:        { label: Pro,        credits_monthly: 500,  price_eur: 9  }
  enterprise: { label: Enterprise, credits_monthly: 5000, price_eur: 49 }
credit_cost_per_request: 1
rate_limit_per_hour: 30
log_retention_days: 365
monitoring_alert_email: ''
```
