<?php
declare(strict_types=1);

namespace Nimikh\LMS\Demo;

/** Static content for the demo seeder: three bilingual courses with in-video questions. */
final class DemoContent {

	/** @return array<int, array<string, mixed>> */
	public static function courses(): array {
		return [
			[
				'key' => 'excel', 'title' => 'Excel for Work — এক্সেল ফর ওয়ার্ক',
				'excerpt' => 'Spreadsheets for everyday office work: formulas, references and charts you can use tomorrow.',
				'lessons' => [
					['title' => 'Cells, rows and columns', 'duration' => 300, 'questions' => [
						[60, 'What is the intersection of a row and a column called?', ['A cell', 'A sheet', 'A workbook'], 0, 'Every cell is named by its column and row, like B3.'],
						[170, 'Which cell does the reference C4 point to?', ['Column C, row 4', 'Row C, column 4', 'The fourth sheet'], 0, 'Columns are letters, rows are numbers.'],
					]],
					['title' => 'Formulas: SUM and AVERAGE', 'duration' => 360, 'questions' => [
						[75, 'Every formula in Excel starts with which character?', ['=', '#', '@'], 0, 'Type = first so Excel knows you are writing a formula.'],
						[170, 'Which formula adds the values in A1 to A5?', ['=SUM(A1:A5)', '=ADD(A1,A5)', '=A1+A5'], 0, 'SUM with a range adds every cell between the two ends.'],
						[290, 'AVERAGE(2, 4, 6) returns…', ['4', '6', '12'], 0, '(2 + 4 + 6) / 3 = 4.'],
					]],
					['title' => 'Lock references with $', 'duration' => 330, 'questions' => [
						[90, 'What does the $ in $A$1 do?', ['Fixes the reference when you copy the formula', 'Formats the cell as currency', 'Hides the cell'], 0, 'Dollar signs lock the column and/or row.'],
						[210, 'আপনি একটি সূত্র নিচের দিকে কপি করছেন, কিন্তু একটি ঘর যেন না নড়ে। কী করবেন?', ['$ দিয়ে ঘরটি লক করুন', 'সূত্রটি মুছে ফেলুন', 'শিট বদলান'], 0, '$ চিহ্ন দিলে ঘরের রেফারেন্স স্থির থাকে।', 'bn'],
					]],
					['title' => 'Charts in five minutes', 'duration' => 270, 'questions' => [
						[80, 'Which chart is best for showing a trend over time?', ['Line chart', 'Pie chart', 'Table'], 0, 'Lines make change over time easy to see.'],
						[180, 'A pie chart is best when…', ['Parts add up to a whole', 'You have 20 categories', 'You need exact numbers'], 0, 'Pies show shares of a total, and work best with few slices.'],
					]],
				],
				'exam' => 'Excel for Work: final exam',
			],
			[
				'key' => 'marketing', 'title' => 'Digital Marketing Basics — ডিজিটাল মার্কেটিং পরিচিতি',
				'excerpt' => 'How small businesses in Bangladesh find customers online: Facebook, search and messaging.',
				'lessons' => [
					['title' => 'What is digital marketing?', 'duration' => 240, 'questions' => [
						[60, 'Which of these is a digital marketing channel?', ['Facebook page', 'Newspaper ad', 'Street banner'], 0, 'Digital channels run on the internet.'],
						[150, 'ডিজিটাল মার্কেটিংয়ের একটি বড় সুবিধা কোনটি?', ['কম খরচে নির্দিষ্ট গ্রাহকের কাছে পৌঁছানো', 'কোনো পরিকল্পনা লাগে না', 'ফলাফল মাপা যায় না'], 0, 'টার্গেটিং ও ফলাফল মাপা সহজ।', 'bn'],
					]],
					['title' => 'Facebook Ads basics', 'duration' => 360, 'questions' => [
						[90, 'Before spending on an ad you should first decide…', ['Your goal and audience', 'The font', 'The weekday'], 0, 'A clear goal tells you who to target and how to measure success.'],
						[200, 'CTR stands for…', ['Click-through rate', 'Cost to reach', 'Customer trust rating'], 0, 'Clicks divided by impressions.'],
						[310, 'Which is the better test?', ['Change one thing at a time', 'Change everything at once', 'Never test'], 0, 'One change at a time shows what worked.'],
					]],
					['title' => 'SEO in plain words', 'duration' => 300, 'questions' => [
						[70, 'SEO helps your page…', ['Appear in search results', 'Load offline', 'Print faster'], 0, 'Search engine optimisation is about being found.'],
						[190, 'Which is most useful for a page title?', ['The words people actually search for', 'Only your company name', 'A long paragraph'], 0, 'Match the page title to the searcher’s words.'],
					]],
				],
				'exam' => 'Digital Marketing Basics: final exam',
			],
			[
				'key' => 'english', 'title' => 'Spoken English for Jobs — চাকরির জন্য ইংরেজি',
				'excerpt' => 'Short, practical English for interviews, phone calls and workplace emails.',
				'lessons' => [
					['title' => 'Introducing yourself', 'duration' => 240, 'questions' => [
						[55, 'Which is the most natural way to start an introduction?', ['"Hello, I\'m Rafi. Nice to meet you."', '"I am Rafi son of…"', '"Hey you."'], 0, 'Short, polite and clear.'],
						[150, 'When asked "Tell me about yourself" in an interview, you should…', ['Give a short summary of your skills and goals', 'Read your whole CV aloud', 'Say nothing'], 0, 'Keep it to about a minute and relevant to the job.'],
					]],
					['title' => 'Phone calls and polite requests', 'duration' => 270, 'questions' => [
						[65, 'Which request is the most polite?', ['"Could you please send me the file?"', '"Send the file."', '"File now."'], 0, '"Could you please…" is polite and professional.'],
						[170, 'If you did not hear someone on the phone, you can say…', ['"Sorry, could you repeat that?"', '"What?"', '"Louder!"'], 0, 'Politely ask for repetition.'],
					]],
					['title' => 'Answering common interview questions', 'duration' => 330, 'questions' => [
						[80, 'A good answer to "What is your biggest weakness?" is…', ['A real one plus how you are improving it', '"I have none."', '"I am always late."'], 0, 'Honest, specific and improving.'],
						[200, 'Interviewers ask "Why this company?" to see…', ['That you researched them', 'How fast you speak', 'Your handwriting'], 0, 'Show you did your homework.'],
						[280, 'আপনি কোনো প্রশ্নের উত্তর না জানলে কী বলা ভালো?', ['"I\'m not sure, but I would find out by…"', 'চুপ থাকা', 'অন্য প্রশ্ন করা'], 0, 'সততার সাথে সমাধানের উপায় বলুন।', 'bn'],
					]],
				],
				'exam' => 'Spoken English for Jobs: final exam',
			],
		];
	}

	/** @return array<int, array{name:string, profile:string, courses:int[]}> learner personas; courses are indexes into courses() */
	public static function learners(): array {
		return [
			['name' => 'Rafi Ahmed', 'profile' => 'finisher', 'courses' => [0, 1]],
			['name' => 'Nusrat Jahan', 'profile' => 'finisher', 'courses' => [0]],
			['name' => 'Tanvir Hasan', 'profile' => 'midway', 'courses' => [0, 2]],
			['name' => 'Sadia Islam', 'profile' => 'finisher', 'courses' => [1, 2]],
			['name' => 'Imran Khan', 'profile' => 'struggler', 'courses' => [0]],
			['name' => 'Farhana Akter', 'profile' => 'midway', 'courses' => [1]],
			['name' => 'Mahmud Hasan', 'profile' => 'dropoff', 'courses' => [0]],
			['name' => 'Rina Begum', 'profile' => 'starter', 'courses' => [2]],
			['name' => 'Shakil Ahmed', 'profile' => 'dropoff', 'courses' => [1]],
			['name' => 'Tasnim Rahman', 'profile' => 'midway', 'courses' => [0]],
			['name' => 'Arif Chowdhury', 'profile' => 'starter', 'courses' => [0, 1]],
			['name' => 'Mim Sultana', 'profile' => 'finisher', 'courses' => [2]],
		];
	}
}
