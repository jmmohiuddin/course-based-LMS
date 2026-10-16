<?php
declare(strict_types=1);

use Nimikh\LMS\Leaderboard\Ranking;
use PHPUnit\Framework\TestCase;

final class RankingTest extends TestCase {

	public function test_score(): void {
		$this->assertSame(0, Ranking::score(0, 0, false));
		$this->assertSame(30 + 12, Ranking::score(3, 12, false));
		$this->assertSame(50 + 20 + 100, Ranking::score(5, 20, true));
	}

	public function test_competition_ranking_with_ties(): void {
		$r = Ranking::rank([['user_id' => 3, 'score' => 50], ['user_id' => 1, 'score' => 80], ['user_id' => 2, 'score' => 50], ['user_id' => 4, 'score' => 10]]);
		$this->assertSame([1, 2, 3, 4], array_column($r, 'user_id'));
		$this->assertSame([1, 2, 2, 4], array_column($r, 'rank'), 'tied learners share a rank, the next rank skips');
	}

	public function test_empty_and_single(): void {
		$this->assertSame([], Ranking::rank([]));
		$this->assertSame(1, Ranking::rank([['user_id' => 9, 'score' => 0]])[0]['rank']);
	}

	public function test_public_names_hide_the_full_name(): void {
		$this->assertSame('Rafi A.', Ranking::publicName('Rafi Ahmed'));
		$this->assertSame('Nusrat J.', Ranking::publicName('  Nusrat   Jahan  '));
		$this->assertSame('Karim', Ranking::publicName('Karim'));
		$this->assertSame('রফিক আ.', Ranking::publicName('রফিক আহমেদ'));
		$this->assertSame('Md R.', Ranking::publicName('Md Rafiqul Islam Rahman'));
		$this->assertSame('—', Ranking::publicName('   '));
	}
}
