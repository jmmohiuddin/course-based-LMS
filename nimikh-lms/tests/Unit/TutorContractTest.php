<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The contract checker must pass on a complete Tutor and fail loudly when a symbol disappears. */
final class TutorContractTest extends TestCase {

	private const FULL = <<<'PHP'
<?php
/* Plugin Name: Tutor Fixture
 * Version: 9.9.9 */
do_action('tutor_quiz/attempt_ended', $id); do_action('tutor_lesson_completed_after', $a, $b); do_action('tutor_after_enroll', $c, $d);
$x = 'tutor_complete_lesson'; get_post_meta($l, '_tutor_course_id_for_lesson'); get_post_meta($q, '_tutor_course_id_for_quiz');
update_user_meta($u, '_tutor_completed_lesson_id_' . $l, 1); add_user_meta($u, '_tutor_instructor_course_id', $c);
register_post_type('courses'); register_post_type('topics'); register_post_type('lesson'); register_post_type('tutor_quiz'); register_post_type('tutor_enrolled');
$s = ['completed', 'cancel']; $t1 = "'completed'"; $t2 = "'cancel'"; $tbl = $wpdb->prefix . 'tutor_quiz_attempts';
$sql = 'SELECT earned_marks, total_marks, attempt_status FROM x WHERE attempt_status = "attempt_ended"';
class U { function is_enrolled() {} function do_enroll() {} function mark_lesson_complete() {} }
PHP;

	private function check(string $source): array {
		$dir = sys_get_temp_dir() . '/nk-tutor-' . bin2hex(random_bytes(4));
		mkdir($dir . '/classes', 0777, true);
		file_put_contents($dir . '/tutor.php', $source);
		file_put_contents($dir . '/classes/x.php', '<?php // unrelated');
		exec('php ' . escapeshellarg(__DIR__ . '/../../tools/check-tutor-contract.php') . ' ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
		array_map('unlink', glob($dir . '/classes/*') ?: []); @rmdir($dir . '/classes'); @unlink($dir . '/tutor.php'); @rmdir($dir);
		return [$code, implode("\n", $out)];
	}

	public function test_a_complete_tutor_passes(): void {
		[$code, $out] = $this->check(self::FULL);
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('Tutor LMS 9.9.9', $out);
		$this->assertStringContainsString('All required Tutor symbols found.', $out);
	}

	public function test_a_missing_required_hook_fails(): void {
		[$code, $out] = $this->check(str_replace("do_action('tutor_quiz/attempt_ended', \$id);", '', self::FULL));
		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/MISSING\s+hook\s+tutor_quiz\/attempt_ended/', $out);
	}

	public function test_a_missing_optional_symbol_only_warns(): void {
		[$code, $out] = $this->check(str_replace('function mark_lesson_complete() {}', '', self::FULL));
		$this->assertSame(0, $code, $out);
		$this->assertMatchesRegularExpression('/absent\s+method\s+function mark_lesson_complete/', $out);
	}

	public function test_a_renamed_column_is_caught(): void {
		[$code, $out] = $this->check(str_replace('earned_marks', 'marks_earned', self::FULL));
		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/MISSING\s+column\s+earned_marks/', $out);
	}
}
