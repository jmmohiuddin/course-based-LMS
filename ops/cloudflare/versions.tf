terraform {
  required_version = ">= 1.5"

  required_providers {
    cloudflare = {
      source = "cloudflare/cloudflare"
      # The ruleset syntax below (nested `rules { }` blocks) is the v4 provider. v5 turned rules into an attribute list
      # and needs a rewrite; do not loosen this pin without porting.
      version = "~> 4.52"
    }
  }
}

provider "cloudflare" {
  # Reads CLOUDFLARE_API_TOKEN from the environment. Token scopes: Zone > Zone Rulesets > Edit (and Zone > Zone > Read).
}
