variable "zone_id" {
  description = "Cloudflare zone ID of the site's apex domain."
  type        = string
}

variable "hostname" {
  description = "Fully qualified LMS hostname the rules apply to, e.g. lms.example.com."
  type        = string
}

variable "login_rate_limit_requests" {
  description = "Max requests per period per IP to /wp-login.php before blocking."
  type        = number
  default     = 10
}

variable "attempt_rate_limit_requests" {
  description = "Max requests per period per IP to /wp-json/nimikh/v1/*/attempt (quiz and exam attempts) before blocking."
  type        = number
  default     = 30
}

variable "rate_limit_period_seconds" {
  description = "Counting window. Free plans only allow 10; paid plans allow 60 and above. Scale the request limits above if you change it."
  type        = number
  default     = 10
}

variable "rate_limit_mitigation_seconds" {
  description = "How long an IP stays blocked after tripping a rate-limit rule. Free plans only allow 10; paid plans allow 60, 600, 3600, 86400."
  type        = number
  default     = 10
}
