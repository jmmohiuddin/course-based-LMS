<?php
/** Phase 2-3 schema: badges, discussion, subscriptions, institutes, live classes, SMS log. */

return static function (string $p, string $charset): string {
	return "
CREATE TABLE {$p}nimikh_user_badges (
  user_id bigint(20) unsigned NOT NULL,
  badge varchar(40) NOT NULL,
  awarded_at datetime NOT NULL,
  PRIMARY KEY  (user_id,badge)
) $charset;

CREATE TABLE {$p}nimikh_activity_days (
  user_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  PRIMARY KEY  (user_id,day)
) $charset;

CREATE TABLE {$p}nimikh_discussions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lesson_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  body text NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'visible',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lesson_parent (lesson_id,parent_id,id),
  KEY user_id (user_id)
) $charset;

CREATE TABLE {$p}nimikh_plans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  course_ids longtext NOT NULL,
  duration_days int(10) unsigned NOT NULL,
  price_label varchar(60) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id)
) $charset;

CREATE TABLE {$p}nimikh_subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  plan_id bigint(20) unsigned NOT NULL,
  starts_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'active',
  source varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY user_status (user_id,status),
  KEY expires_at (expires_at)
) $charset;

CREATE TABLE {$p}nimikh_orgs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(60) NOT NULL,
  name varchar(120) NOT NULL,
  brand_color char(7) NOT NULL DEFAULT '#1E4FD8',
  logo_url varchar(255) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset;

CREATE TABLE {$p}nimikh_org_members (
  org_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(10) NOT NULL DEFAULT 'member',
  PRIMARY KEY  (org_id,user_id),
  KEY user_id (user_id)
) $charset;

CREATE TABLE {$p}nimikh_org_courses (
  org_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (org_id,course_id),
  KEY course_id (course_id)
) $charset;

CREATE TABLE {$p}nimikh_live_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  title varchar(160) NOT NULL,
  starts_at datetime NOT NULL,
  duration_min smallint(5) unsigned NOT NULL DEFAULT 60,
  provider varchar(10) NOT NULL DEFAULT 'jitsi',
  join_url varchar(500) NOT NULL DEFAULT '',
  room varchar(40) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'scheduled',
  reminded_at datetime NULL,
  created_by bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY course_start (course_id,starts_at),
  KEY starts_at (starts_at)
) $charset;

CREATE TABLE {$p}nimikh_live_attendance (
  session_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  joined_at datetime NOT NULL,
  PRIMARY KEY  (session_id,user_id)
) $charset;

CREATE TABLE {$p}nimikh_sms_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  phone varchar(20) NOT NULL,
  event varchar(40) NOT NULL,
  status varchar(10) NOT NULL,
  response varchar(255) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset;
";
};
