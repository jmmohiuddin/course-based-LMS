# Cache rules and rate-limit rules for the Nimikh LMS zone. Matches docs/DEPLOYMENT.md "Cloudflare".
# STATUS: `terraform fmt` and `terraform validate` pass (Terraform 1.9.8, provider 4.52.1). `plan`/`apply` were never run
# against a real zone: read the plan before applying, and confirm rules in the dashboard (Caching > Cache Rules, Security > WAF).

locals {
  # Edge TTLs are literals, not variables, on purpose: provider v4.52's validation rejects a variable-derived
  # edge_ttl.default as "unset" during `terraform validate` (found while validating this file). Edit here.
  public_page_edge_ttl_seconds = 300 # keep short, prices change
  verify_edge_ttl_seconds      = 300 # a revoked certificate can look valid for up to this long; purge by URL on revocation

  # Things that must never be served from cache. The player heartbeat, quiz attempts and the health endpoint live under
  # /wp-json/. Bypassing the whole REST tree is a superset of the required /wp-json/nimikh/v1/*.
  bypass_expr = join(" or ", [
    "starts_with(http.request.uri.path, \"/wp-json/nimikh/v1/\")",
    "starts_with(http.request.uri.path, \"/wp-json/\")",
    "http.request.uri.query contains \"rest_route=\"",
    "starts_with(http.request.uri.path, \"/wp-admin\")",
    "http.request.uri.path eq \"/wp-login.php\"",
    "http.request.uri.path eq \"/wp-cron.php\"",
    "http.request.uri.path eq \"/xmlrpc.php\"",
    "http.cookie contains \"wordpress_logged_in_\"",
    "http.cookie contains \"wp-postpass_\"",
    "http.cookie contains \"comment_author_\"",
    "http.cookie contains \"woocommerce_items_in_cart\"",
    "http.cookie contains \"wp_woocommerce_session_\"",
    "http.request.method ne \"GET\"",
  ])
}

resource "cloudflare_ruleset" "nimikh_cache" {
  zone_id     = var.zone_id
  name        = "Nimikh LMS cache rules"
  description = "Bypass for dynamic/authenticated traffic, cache for public pages and /verify/*"
  kind        = "zone"
  phase       = "http_request_cache_settings"

  # Cache rules are evaluated top to bottom and later matches override earlier settings, so every cache rule below
  # also excludes the bypass expression itself instead of relying on order.

  rules {
    action      = "set_cache_settings"
    description = "Bypass: REST API, wp-admin, login, cron, XML-RPC, logged-in cookies, non-GET"
    enabled     = true
    expression  = "(http.host eq \"${var.hostname}\") and (${local.bypass_expr})"
    action_parameters {
      cache = false
    }
  }

  rules {
    action      = "set_cache_settings"
    description = "Cache certificate verification pages (/verify and /verify/*). Pages are noindex and carry no user data."
    enabled     = true
    expression  = "(http.host eq \"${var.hostname}\") and (http.request.uri.path eq \"/verify\" or starts_with(http.request.uri.path, \"/verify/\")) and not (${local.bypass_expr})"
    action_parameters {
      cache = true
      edge_ttl {
        mode    = "override_origin" # WordPress sends no-cache headers
        default = local.verify_edge_ttl_seconds
      }
      browser_ttl {
        mode = "respect_origin"
      }
    }
  }

  rules {
    action      = "set_cache_settings"
    description = "Cache anonymous public pages (catalogue, course pages, institute pages)"
    enabled     = true
    expression  = "(http.host eq \"${var.hostname}\") and not (${local.bypass_expr})"
    action_parameters {
      cache = true
      edge_ttl {
        mode    = "override_origin"
        default = local.public_page_edge_ttl_seconds
      }
      browser_ttl {
        mode = "respect_origin"
      }
    }
  }
}

resource "cloudflare_ruleset" "nimikh_rate_limit" {
  zone_id     = var.zone_id
  name        = "Nimikh LMS rate limits"
  description = "Login brute-force and quiz/exam attempt flooding"
  kind        = "zone"
  phase       = "http_ratelimit"

  rules {
    action      = "block"
    description = "Rate limit wp-login.php"
    enabled     = true
    expression  = "(http.host eq \"${var.hostname}\") and (http.request.uri.path eq \"/wp-login.php\")"
    ratelimit {
      characteristics     = ["ip.src"]
      period              = var.rate_limit_period_seconds
      requests_per_period = var.login_rate_limit_requests
      mitigation_timeout  = var.rate_limit_mitigation_seconds
    }
  }

  rules {
    action      = "block"
    description = "Rate limit POST /wp-json/nimikh/v1/*/attempt"
    enabled     = true
    expression  = "(http.host eq \"${var.hostname}\") and (http.request.method eq \"POST\") and starts_with(http.request.uri.path, \"/wp-json/nimikh/v1/\") and ends_with(http.request.uri.path, \"/attempt\")"
    ratelimit {
      characteristics     = ["ip.src"]
      period              = var.rate_limit_period_seconds
      requests_per_period = var.attempt_rate_limit_requests
      mitigation_timeout  = var.rate_limit_mitigation_seconds
    }
  }
}
