<?php
/**
 * Pure tests (no DB, no model, no network) for backend/ai/StructuredOutput.php.
 *   php tests/ai/structured_output_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/ai/StructuredOutput.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($d !== '' && !$ok ? "   $d" : '') . "\n"; }

$intent = [
    'type' => 'object', 'required' => ['intent', 'confidence'],
    'properties' => [
        'intent'     => ['type' => 'string', 'enum' => ['search', 'book', 'cancel', 'other']],
        'confidence' => ['type' => 'number', 'min' => 0, 'max' => 1],
        'budget'     => ['type' => 'int', 'min' => 0, 'nullable' => true],
        'tags'       => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 20], 'maxItems' => 3],
    ],
    'additionalProperties' => false,
];

// extraction
$r = StructuredOutput::extract("Sure! Here you go:\n```json\n{\"a\": 1}\n```\nHope that helps");
t('extracts JSON from a code fence', $r['ok'] && $r['data'] === ['a' => 1]);
$r = StructuredOutput::extract('The answer is {"a": {"b": [1,2,{"c":"}"}]}} and that is all');
t('extracts a balanced object from prose; "}" inside a string does not end it', $r['ok'] && $r['data']['a']['b'][2]['c'] === '}');
$r = StructuredOutput::extract('no json here at all');
t('no JSON -> error, not a crash', !$r['ok'] && $r['error'] !== '');
$r = StructuredOutput::extract('');
t('empty output -> error', !$r['ok']);
$r = StructuredOutput::extract('[1,2,3]');
t('bare array is accepted', $r['ok'] && $r['data'] === [1, 2, 3]);
$r = StructuredOutput::extract("```\n```\n{\"x\":true}");
t('empty fence is skipped, later JSON found', $r['ok'] && $r['data'] === ['x' => true]);

// validation
$ok = ['intent' => 'search', 'confidence' => 0.9, 'budget' => null, 'tags' => ['beach']];
t('valid object passes', StructuredOutput::validate($ok, $intent) === []);
t('missing required key is reported with its path', in_array('$.confidence: required but missing', StructuredOutput::validate(['intent' => 'search'], $intent), true));
t('enum violation rejected', StructuredOutput::validate(['intent' => 'hack', 'confidence' => 0.5], $intent) !== []);
t('number above max rejected', StructuredOutput::validate(['intent' => 'book', 'confidence' => 1.5], $intent) !== []);
t('string "5" is not an int (strict types)', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'budget' => '5'], $intent) !== []);
t('integral float 5.0 is accepted as int', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'budget' => 5.0], $intent) === []);
t('fractional float is not an int', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'budget' => 5.5], $intent) !== []);
t('nullable accepts null', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'budget' => null], $intent) === []);
t('additionalProperties=false rejects unknown keys', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'evil' => 1], $intent) !== []);
t('array item maxLength checked with path', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'tags' => [str_repeat('x', 21)]], $intent) !== []);
t('maxItems enforced', StructuredOutput::validate(['intent' => 'book', 'confidence' => 0.5, 'tags' => ['a', 'b', 'c', 'd']], $intent) !== []);
t('a list is not an object', StructuredOutput::validate([1, 2], $intent) !== []);
t('bool is not a number', StructuredOutput::validate(['intent' => 'book', 'confidence' => true], $intent) !== []);
t('multi-byte string length is counted in characters', StructuredOutput::validate('বাংলা', ['type' => 'string', 'maxLength' => 5]) === []);
t('pattern enforced', StructuredOutput::validate('abc1', ['type' => 'string', 'pattern' => '/^[a-z]+$/']) !== []);
t('type list: string|null accepts null', StructuredOutput::validate(null, ['type' => ['string', 'null']]) === []);

// parse + repair
$good = '{"intent":"search","confidence":0.8}';
$p = StructuredOutput::parse($good, $intent);
t('parse: valid output, one attempt, not repaired', $p['ok'] && $p['attempts'] === 1 && !$p['repaired']);

$p = StructuredOutput::parse("```json\n{\"intent\":\"search\",\"confidence\":0.8,}\n```", $intent);
t('parse: trailing comma fixed deterministically, no model call', $p['ok'] && $p['repaired'] && $p['attempts'] === 2);

$calls = 0;
$p = StructuredOutput::parse('{"intent":"buy","confidence":0.8}', $intent, function ($err, $orig) use (&$calls) { $calls++; return '{"intent":"book","confidence":0.8}'; });
t('parse: invalid enum -> model repair called exactly once and accepted', $p['ok'] && $calls === 1 && $p['repaired'] && $p['data']['intent'] === 'book');

$calls = 0;
$p = StructuredOutput::parse('not json', $intent, function () use (&$calls) { $calls++; return 'still not json'; });
t('parse: repair that also fails -> not ok, still only one repair call', !$p['ok'] && $calls === 1 && $p['errors'] !== []);

$calls = 0;
$p = StructuredOutput::parse('{"intent":"buy","confidence":0.8}', $intent, function () use (&$calls) { $calls++; return '{"intent":"hack","confidence":2}'; });
t('parse: repair returning invalid data is rejected, no second repair', !$p['ok'] && $calls === 1);

$p = StructuredOutput::parse('{"intent":"buy","confidence":0.8}', $intent, function () { throw new RuntimeException('model down'); });
t('parse: a throwing repair callable is contained', !$p['ok']);

$p = StructuredOutput::parse('{"intent":"buy","confidence":0.8}', $intent, function () { return null; });
t('parse: repair returning null is contained', !$p['ok']);

$calls = 0;
StructuredOutput::parse($good, $intent, function () use (&$calls) { $calls++; return null; });
t('parse: valid output never calls the repair callable', $calls === 0);

// error summary handed to the repair callable is bounded and carries paths
$seen = '';
StructuredOutput::parse('{"intent":"buy","confidence":9}', $intent, function ($err) use (&$seen) { $seen = $err; return null; });
t('repair callable receives validation errors with paths', str_contains($seen, '$.intent') && str_contains($seen, '$.confidence'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
