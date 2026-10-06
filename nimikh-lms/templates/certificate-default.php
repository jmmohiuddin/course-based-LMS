<?php
/**
 * Default certificate layout (A4 landscape). {{placeholders}} are replaced and escaped by
 * CertificateRenderer. Kept to simple, table-free CSS that mPDF and Chromium both render.
 */
defined('ABSPATH') || exit;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate {{code}}</title>
<style>
	@page { size: A4 landscape; margin: 0; }
	body { margin: 0; font-family: 'Hind Siliguri', 'Inter', sans-serif; color: #0F172A; }
	.frame { margin: 24px; padding: 40px 56px; border: 6px double #1E4FD8; text-align: center; }
	.issuer { font-size: 16px; letter-spacing: 3px; color: #475569; text-transform: uppercase; }
	h1 { font-size: 40px; margin: 18px 0 6px; color: #1E4FD8; }
	.name { font-size: 34px; font-weight: 700; margin: 20px 0 6px; }
	.course { font-size: 24px; margin: 6px 0 18px; }
	.meta { font-size: 15px; color: #475569; }
	.verify { margin-top: 28px; font-size: 12px; color: #475569; }
	.code { color: #15803D; font-weight: 700; letter-spacing: 1px; }
</style>
</head>
<body>
<div class="frame">
	<div class="issuer">{{issuer}}</div>
	<h1>Certificate of Completion</h1>
	<div class="meta">This certifies that</div>
	<div class="name">{{name}}</div>
	<div class="meta">has successfully completed</div>
	<div class="course">{{course}}</div>
	<div class="meta">Score: {{score}} &middot; Issued: {{date}}</div>
	<div class="verify">
		{{qr}}<br>
		Verify at {{url}}<br>
		Certificate ID <span class="code">{{code}}</span>
	</div>
</div>
</body>
</html>
