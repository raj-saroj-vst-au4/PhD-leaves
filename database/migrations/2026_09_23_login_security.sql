-- ============================================================================
-- Login security: attempt log for rate limiting
--
-- The login form had no rate limit (its maths captcha was plain text a script
-- could read), and forgot-password could be used to flood any inbox with reset
-- mail. Both now consult this table (includes/rate_limit.php):
--
--   login_fail     one row per failed sign-in. 5 per email or 20 per client IP
--                  within 15 minutes locks further attempts out. A successful
--                  sign-in clears that email's rows.
--   reset_request  one row per forgot-password submission (any address, real
--                  or not). More than 10 per client IP in 15 minutes and the
--                  rest are silently not acted on.
--   reset_sent     one row per reset email actually sent. At most one per
--                  account per 10 minutes and 5 per 24 hours.
--
-- `ip` is the resolved client address (X-Real-IP from the front proxy, see
-- getClientIp() in includes/auth.php), NULL when it could not be established;
-- NULL rows never count towards a per-IP limit. Rows older than a day are pruned
-- opportunistically by the application, so the table stays small.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_09_23_login_security.sql
-- Safe to re-run. The application fails OPEN (no limiting, error logged) until
-- this table exists, so deploying the code first does not lock anyone out.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `auth_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(20) NOT NULL COMMENT 'login_fail | reset_request | reset_sent',
  `email` VARCHAR(255) DEFAULT NULL COMMENT 'lower-cased address as submitted',
  `ip` VARCHAR(45) DEFAULT NULL COMMENT 'resolved client IP; NULL if unknown',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action_email_time` (`action`, `email`, `created_at`),
  KEY `idx_action_ip_time` (`action`, `ip`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
