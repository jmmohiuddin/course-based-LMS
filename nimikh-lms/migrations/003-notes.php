<?php
/** Learner notes pinned to a video timestamp (private to the learner). */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_notes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned NOT NULL,
  at_second int(10) unsigned NOT NULL,
  body varchar(500) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_lesson (user_id,lesson_id,at_second)
) $charset;
";
};
