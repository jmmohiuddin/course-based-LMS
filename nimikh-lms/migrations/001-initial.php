<?php
/**
 * Initial schema (blueprint 5.3). `last_second` on watch_progress is an addition used for resume.
 */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_questions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  author_id bigint(20) unsigned NOT NULL,
  stem text NOT NULL,
  options longtext NOT NULL,
  correct_index tinyint(3) unsigned NOT NULL,
  explanation text NULL,
  tags varchar(255) NOT NULL DEFAULT '',
  lang varchar(10) NOT NULL DEFAULT 'en',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY author_id (author_id)
) $charset;

CREATE TABLE {$p}nimikh_video_interactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lesson_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  at_second int(10) unsigned NOT NULL,
  required tinyint(1) NOT NULL DEFAULT 1,
  allow_retry tinyint(1) NOT NULL DEFAULT 1,
  points smallint(5) unsigned NOT NULL DEFAULT 1,
  rewind_to_second int(10) unsigned NULL,
  PRIMARY KEY  (id),
  KEY lesson_at (lesson_id,at_second),
  KEY question_id (question_id)
) $charset;

CREATE TABLE {$p}nimikh_watch_progress (
  user_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned NOT NULL,
  furthest_second int(10) unsigned NOT NULL DEFAULT 0,
  last_second int(10) unsigned NOT NULL DEFAULT 0,
  watched_ranges longtext NULL,
  percent decimal(5,2) NOT NULL DEFAULT 0,
  completed_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id,lesson_id),
  KEY lesson_id (lesson_id)
) $charset;

CREATE TABLE {$p}nimikh_interaction_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  interaction_id bigint(20) unsigned NOT NULL,
  selected_index tinyint(3) unsigned NOT NULL,
  is_correct tinyint(1) NOT NULL,
  attempt_no smallint(5) unsigned NOT NULL,
  answered_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_interaction (user_id,interaction_id),
  KEY interaction_id (interaction_id)
) $charset;

CREATE TABLE {$p}nimikh_certificates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code char(10) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  template_id bigint(20) unsigned NOT NULL DEFAULT 0,
  score decimal(5,2) NULL,
  issued_at datetime NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'valid',
  revoked_reason varchar(255) NULL,
  pdf_key varchar(255) NULL,
  sha256 char(64) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY user_course (user_id,course_id)
) $charset;
";
};
