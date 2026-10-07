<?php
declare(strict_types=1);

use Nimikh\LMS\Auth\GoogleToken;
use Nimikh\LMS\Auth\OtpCode;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase {

	public function test_generated_codes_are_six_digits(): void {
		for ($i = 0; $i < 200; $i++) {
			$this->assertMatchesRegularExpression('/^\d{6}$/', OtpCode::generate());
		}
	}

	public function test_code_verifies_only_for_the_same_phone_and_secret(): void {
		$hash = OtpCode::hash('042917', '8801712345678', 'secret');
		$this->assertTrue(OtpCode::verify('042917', '8801712345678', 'secret', $hash));
		$this->assertTrue(OtpCode::verify(' ০৪২৯১৭ ', '8801712345678', 'secret', $hash), 'Bangla digits and spaces are accepted');
		$this->assertFalse(OtpCode::verify('042918', '8801712345678', 'secret', $hash));
		$this->assertFalse(OtpCode::verify('042917', '8801799999999', 'secret', $hash), 'bound to the phone');
		$this->assertFalse(OtpCode::verify('042917', '8801712345678', 'other', $hash), 'bound to the secret');
		$this->assertFalse(OtpCode::verify('42917', '8801712345678', 'secret', $hash), 'wrong length');
		$this->assertFalse(OtpCode::verify('', '8801712345678', 'secret', $hash));
	}

	private function claims(array $over = []): array {
		return array_merge([
			'aud' => 'client-123.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com', 'exp' => '2000',
			'email' => 'Rafi@Example.com', 'email_verified' => 'true', 'sub' => '1122', 'name' => 'Rafi',
		], $over);
	}

	public function test_valid_google_claims(): void {
		$r = GoogleToken::validate($this->claims(), 'client-123.apps.googleusercontent.com', 1000);
		$this->assertSame(['email' => 'rafi@example.com', 'name' => 'Rafi', 'sub' => '1122'], $r);
	}

	public function test_every_bad_claim_is_rejected(): void {
		$id = 'client-123.apps.googleusercontent.com';
		$this->assertNull(GoogleToken::validate($this->claims(['aud' => 'someone-else']), $id, 1000), 'token for another app');
		$this->assertNull(GoogleToken::validate($this->claims(), '', 1000), 'no client id configured');
		$this->assertNull(GoogleToken::validate($this->claims(['iss' => 'https://evil.example']), $id, 1000));
		$this->assertNull(GoogleToken::validate($this->claims(['exp' => '999']), $id, 1000), 'expired');
		$this->assertNull(GoogleToken::validate($this->claims(['email_verified' => 'false']), $id, 1000), 'unverified email');
		$this->assertNull(GoogleToken::validate($this->claims(['email_verified' => null]), $id, 1000));
		$this->assertNull(GoogleToken::validate($this->claims(['email' => 'not-an-email']), $id, 1000));
		$this->assertNull(GoogleToken::validate($this->claims(['sub' => '']), $id, 1000));
		$this->assertSame('accounts.google.com' !== '', GoogleToken::validate($this->claims(['iss' => 'accounts.google.com', 'email_verified' => true]), $id, 1000) !== null);
	}
}
