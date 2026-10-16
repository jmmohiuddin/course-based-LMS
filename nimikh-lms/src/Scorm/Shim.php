<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/**
 * The SCORM runtime injected into every HTML page of a package. The page runs in a sandboxed iframe with an opaque
 * origin, so it cannot see the parent's API object; instead it gets its own API whose reads are answered from a
 * local copy of the learner's data (SCORM calls are synchronous) and whose writes are batched to the parent window
 * with postMessage. Pure: returns JavaScript text.
 */
final class Shim {

	/**
	 * @param array<string, string> $init values the package can read
	 * @return string JavaScript (no <script> tags)
	 */
	public static function script(string $version, array $init, string $parentOrigin): string {
		$cfg = json_encode([
			'version'  => $version,
			'init'     => (object) $init,
			'readonly' => DataModel::readonlyKeys($version),
			'patterns' => DataModel::writablePatterns($version),
			'origin'   => $parentOrigin,
			'maxValue' => DataModel::MAX_VALUE,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

		return str_replace('__CFG__', $cfg, <<<'JS'
(function () {
  'use strict';
  var CFG = __CFG__;
  var is12 = CFG.version === '1.2';
  var data = CFG.init, dirty = {}, ro = {}, pats = [], state = 'idle', err = 0, timer = null;
  CFG.readonly.forEach(function (k) { ro[k] = 1; });
  CFG.patterns.forEach(function (p) { pats.push(new RegExp(p)); });
  var WRITE_ONLY = is12 ? { 'cmi.core.exit': 1, 'cmi.core.session_time': 1 } : { 'cmi.exit': 1, 'cmi.session_time': 1 };
  var ENUM = is12
    ? { 'cmi.core.lesson_status': ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'] }
    : { 'cmi.completion_status': ['completed', 'incomplete', 'not attempted', 'unknown'], 'cmi.success_status': ['passed', 'failed', 'unknown'] };
  var MSG = is12
    ? { 0: 'No error', 101: 'General exception', 201: 'Invalid argument error', 202: 'Element cannot have children', 203: 'Element not an array', 301: 'Not initialized', 401: 'Not implemented error', 402: 'Invalid set value, element is a keyword', 403: 'Element is read only', 404: 'Element is write only', 405: 'Incorrect data type' }
    : { 0: 'No Error', 101: 'General Exception', 103: 'Already Initialized', 104: 'Content Instance Terminated', 112: 'Termination Before Initialization', 113: 'Termination After Termination', 122: 'Retrieve Data Before Initialization', 123: 'Retrieve Data After Termination', 132: 'Store Data Before Initialization', 133: 'Store Data After Termination', 142: 'Commit Before Initialization', 143: 'Commit After Termination', 201: 'General Argument Error', 351: 'General Set Failure', 401: 'Undefined Data Model Element', 403: 'Data Model Element Value Not Initialized', 404: 'Data Model Element Is Read Only', 405: 'Data Model Element Is Write Only', 406: 'Data Model Element Type Mismatch' };

  function writable(k) { for (var i = 0; i < pats.length; i++) { if (pats[i].test(k)) { return true; } } return false; }
  function post(finish) {
    var keys = Object.keys(dirty);
    if (!keys.length && !finish) { return; }
    var msg = { nimikh: 'scorm', type: 'commit', finish: !!finish, data: dirty };
    dirty = {};
    try { window.top.postMessage(msg, CFG.origin); } catch (e) { /* no parent to tell */ }
  }
  function fail(code) { err = code; return 'false'; }
  function notRunning(codes) {
    if (state === 'running') { return 0; }
    return is12 ? 301 : (state === 'done' ? codes[1] : codes[0]);
  }

  function init(arg) {
    if (arg !== '') { return fail(201); }
    if (state === 'running') { return fail(is12 ? 101 : 103); }
    if (state === 'done' && !is12) { return fail(104); }
    state = 'running'; err = 0;
    if (!timer) { timer = setInterval(function () { if (state === 'running') { post(false); } }, 20000); }
    return 'true';
  }
  function finish(arg) {
    if (arg !== '') { return fail(201); }
    if (state !== 'running') { return fail(is12 ? 301 : (state === 'done' ? 113 : 112)); }
    post(true); state = 'done'; err = 0;
    return 'true';
  }
  function get(k) {
    var n = notRunning([122, 123]);
    if (n) { err = n; return ''; }
    k = String(k);
    var m = /^(.*)\._count$/.exec(k);
    if (m) {
      var max = -1, prefix = m[1] + '.';
      Object.keys(data).forEach(function (key) { if (key.indexOf(prefix) === 0) { var d = /^\d+/.exec(key.slice(prefix.length)); if (d) { max = Math.max(max, parseInt(d[0], 10)); } } });
      err = 0; return String(max + 1);
    }
    if (WRITE_ONLY[k]) { err = is12 ? 404 : 405; return ''; }
    if (Object.prototype.hasOwnProperty.call(data, k)) { err = 0; return String(data[k]); }
    if (writable(k) || /\._children$/.test(k)) { err = is12 ? 0 : 403; return ''; }
    err = 401; return '';
  }
  function set(k, v) {
    var n = notRunning([132, 133]);
    if (n) { return fail(n); }
    k = String(k); v = String(v);
    if (ro[k]) { return fail(is12 ? 403 : 404); }
    if (!writable(k)) { return fail(401); }
    if (v.length > CFG.maxValue) { return fail(is12 ? 405 : 351); }
    if (ENUM[k] && ENUM[k].indexOf(v) < 0) { return fail(is12 ? 405 : 406); }
    data[k] = v; dirty[k] = v; err = 0;
    return 'true';
  }
  function commit(arg) {
    if (arg !== '') { return fail(201); }
    var n = notRunning([142, 143]);
    if (n) { return fail(n); }
    post(false); err = 0;
    return 'true';
  }
  function lastError() { return String(err); }
  function errorString(n) { return MSG[parseInt(n, 10)] || ''; }
  function diagnostic() { return ''; }

  if (is12) {
    window.API = { LMSInitialize: init, LMSFinish: finish, LMSGetValue: get, LMSSetValue: set, LMSCommit: commit, LMSGetLastError: lastError, LMSGetErrorString: errorString, LMSGetDiagnostic: diagnostic };
  } else {
    window.API_1484_11 = { Initialize: init, Terminate: finish, GetValue: get, SetValue: set, Commit: commit, GetLastError: lastError, GetErrorString: errorString, GetDiagnostic: diagnostic };
  }
  window.addEventListener('pagehide', function () { if (state === 'running') { post(false); } });
})();
JS);
	}

	/** Put the shim at the top of an HTML document so it runs before the package's own scripts. */
	public static function inject(string $html, string $script): string {
		$tag = '<script>' . $script . '</script>';
		if (preg_match('/<head(\s[^>]*)?>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
			$at = $m[0][1] + strlen($m[0][0]);
			return substr($html, 0, $at) . $tag . substr($html, $at);
		}
		if (preg_match('/<html(\s[^>]*)?>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
			$at = $m[0][1] + strlen($m[0][0]);
			return substr($html, 0, $at) . $tag . substr($html, $at);
		}
		return $tag . $html;
	}
}
