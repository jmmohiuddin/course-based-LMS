<?php
/**
 * Verifies the assumptions nimikh-lms makes about Tutor LMS against an installed copy of its source.
 * Usage: php tools/check-tutor-contract.php /path/to/wp-content/plugins/tutor
 * Exit code 1 if any REQUIRED symbol is missing. Run it whenever you upgrade Tutor, before upgrading production.
 */
declare(strict_types=1);

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
	fwrite(STDERR, "usage: php tools/check-tutor-contract.php /path/to/tutor\n");
	exit(2);
}

// [label, needle, required, why]
$checks = [
	['hook',   'tutor_quiz/attempt_ended',        true,  'final exam finished -> certificate rule check (TutorHooks::onQuizFinished)'],
	['hook',   'tutor_lesson_completed_after',    true,  'lesson completed in Tutor -> certificate rule check; also fired by our fallback'],
	['hook',   'tutor_after_enroll',              false, 'enrolment SMS (TutorHooks::onEnrolled)'],
	['hook',   'tutor_complete_lesson',           false, 'manual "Mark as complete" action we block on interactive lessons (tutor_action_<name>)'],
	['meta',   '_tutor_course_id_for_lesson',     true,  'lesson -> course (TutorAdapter::courseIdForLesson)'],
	['meta',   '_tutor_course_id_for_quiz',       true,  'quiz -> course (TutorAdapter::examQuizId)'],
	['meta',   '_tutor_completed_lesson_id_',     true,  'per-user lesson completion marker (TutorAdapter::isLessonComplete/completeLesson)'],
	['meta',   '_tutor_instructor_course_id',     true,  'instructor -> course link (TutorAdapter::isCourseInstructor)'],
	['ptype',  "'courses'",                       true,  'course post type'],
	['ptype',  "'topics'",                        true,  'topic post type (lesson parent)'],
	['ptype',  "'lesson'",                        true,  'lesson post type'],
	['ptype',  "'tutor_quiz'",                    true,  'quiz post type'],
	['ptype',  "'tutor_enrolled'",                true,  'enrolment post type'],
	['status', "'completed'",                     true,  'enrolment status for an active enrolment'],
	['status', "'cancel'",                        true,  'enrolment status after cancelling (TutorAdapter::cancelEnrolment)'],
	['table',  'tutor_quiz_attempts',             true,  'quiz attempts table (bestQuizPercent, attempt)'],
	['column', 'earned_marks',                    true,  'attempts.earned_marks'],
	['column', 'total_marks',                     true,  'attempts.total_marks'],
	['column', 'attempt_status',                  true,  'attempts.attempt_status'],
	['value',  'attempt_ended',                   true,  "attempt_status value for a finished attempt ('attempt_ended')"],
	['method', 'function is_enrolled',            true,  'tutor_utils()->is_enrolled()'],
	['method', 'function do_enroll',              true,  'tutor_utils()->do_enroll() (batch enrolment, subscriptions)'],
	['method', 'function mark_lesson_complete',   false, 'optional: used if present, else we write the completion meta ourselves'],
];

$found = array_fill_keys(array_column($checks, 1), false);
$files = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
	static fn(SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), ['node_modules', 'vendor', 'assets', 'languages', 'tests'], true)
));
foreach ($it as $f) {
	if ($f->getExtension() !== 'php') {
		continue;
	}
	$files++;
	$src = (string) file_get_contents($f->getPathname());
	foreach ($found as $needle => $hit) {
		if (!$hit && str_contains($src, (string) $needle)) {
			$found[$needle] = true;
		}
	}
	if (!in_array(false, $found, true)) {
		break;
	}
}

$version = '?';
foreach (glob($dir . '/*.php') ?: [] as $main) {
	if (preg_match('/^\s*\*?\s*Version:\s*(\S+)/mi', (string) file_get_contents($main, false, null, 0, 4000), $m)) {
		$version = $m[1];
		break;
	}
}
printf("Tutor LMS %s at %s (%d PHP files scanned)\n\n", $version, $dir, $files);

$failed = 0;
foreach ($checks as [$kind, $needle, $required, $why]) {
	$ok = $found[$needle];
	printf("  %-5s %-8s %-34s %s\n", $ok ? 'ok' : ($required ? 'MISSING' : 'absent'), $kind, $needle, $why);
	if (!$ok && $required) {
		$failed++;
	}
}
echo $failed ? "\n$failed required symbol(s) missing: nimikh-lms's Tutor adapter needs updating before using this Tutor version.\n" : "\nAll required Tutor symbols found.\n";
exit($failed ? 1 : 0);
