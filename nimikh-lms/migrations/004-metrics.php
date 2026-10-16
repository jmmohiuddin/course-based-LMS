<?php
/** Success-metric inputs (PRD 4.1): per-day certificate verification counts and anonymous client timings. */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_verify_views (
  certificate_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  views int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (certificate_id,day),
  KEY day (day)
) $charset;

CREATE TABLE {$p}nimikh_rum (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  metric varchar(20) NOT NULL,
  value_ms int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY metric_day (metric,day)
) $charset;
";
};
