<?php
/** SCORM learner tracking: the data a package reports, plus its completion verdict (one row per learner per lesson). */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_scorm_tracking (
  user_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned NOT NULL,
  version varchar(6) NOT NULL DEFAULT '1.2',
  data longtext NULL,
  status varchar(20) NOT NULL DEFAULT '',
  score decimal(5,2) NULL,
  total_seconds int(10) unsigned NOT NULL DEFAULT 0,
  session_seconds int(10) unsigned NOT NULL DEFAULT 0,
  started_at datetime NULL,
  completed_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id,lesson_id),
  KEY lesson_id (lesson_id)
) $charset;
";
};
