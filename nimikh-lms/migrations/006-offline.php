<?php
/** Offline download licences: which learner may keep which lesson on which device, until when. */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_offline_licenses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned NOT NULL,
  device_id char(32) NOT NULL,
  issued_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_lesson_device (user_id,lesson_id,device_id),
  KEY lesson_id (lesson_id)
) $charset;
";
};
