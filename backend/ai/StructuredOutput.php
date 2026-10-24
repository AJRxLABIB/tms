<?php
/**
 * StructuredOutput — turns model text into validated, typed data.
 *
 * Model output is untrusted text. This class never executes or evals anything: it finds one JSON value in the text,
 * checks it against a small schema, and returns either typed data or a list of precise errors.
 *
 * Schema (plain arrays):
 *   ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => [...]], 'additionalProperties' => false]
 *   ['type' => 'array', 'items' => [...], 'minItems' => 1, 'maxItems' => 10]
 *   ['type' => 'string', 'enum' => [...], 'minLength' => 1, 'maxLength' => 200, 'pattern' => '/^[a-z]+$/']
 *   ['type' => 'int'|'number', 'min' => 0, 'max' => 100]      'bool'      'null'
 *   Any schema may add 'nullable' => true. 'type' may also be a list, e.g. ['string','null'].
 *
 * Types are strict: the string "5" is not an int. An integral float (5.0) is accepted as int because JSON has one
 * number type. One bounded repair attempt is made: first deterministic fixes (code fences, trailing commas, smart
 * quotes, comments), then — at most once — an optional caller-supplied callable that may ask the model to fix its own
 * output. No further attempts, so a bad model cannot cause a loop.
 */
final class StructuredOutput
{
    /**
     * @param string        $text    raw model output
     * @param array         $schema  see class docblock
     * @param callable|null $repair  fn(string $errorSummary, string $originalText): ?string — returns new model text or null
     * @return array{ok:bool,data:mixed,errors:string[],repaired:bool,attempts:int}
     */
    public static function parse(string $text, array $schema, ?callable $repair = null): array
    {
        $attempts = 1;
        $repaired = false;
        $extracted = self::extract($text);
        $errors = [];

        if ($extracted['ok']) {
            $errors = self::validate($extracted['data'], $schema);
            if (!$errors) return ['ok' => true, 'data' => $extracted['data'], 'errors' => [], 'repaired' => false, 'attempts' => $attempts];
        } else {
            $errors = [$extracted['error']];
        }

        // Deterministic repair of the same text.
        $fixed = self::deterministicFix($text);
        if ($fixed !== $text) {
            $attempts++;
            $ex2 = self::extract($fixed);
            if ($ex2['ok']) {
                $e2 = self::validate($ex2['data'], $schema);
                if (!$e2) return ['ok' => true, 'data' => $ex2['data'], 'errors' => [], 'repaired' => true, 'attempts' => $attempts];
                $errors = $e2;
            }
        }

        // One model-assisted repair, at most.
        if ($repair !== null) {
            $attempts++;
            $summary = implode('; ', array_slice($errors, 0, 6));
            $new = null;
            try { $new = $repair($summary, mb_substr($text, 0, 4000)); } catch (\Throwable $e) { $new = null; }
            if (is_string($new) && $new !== '') {
                $ex3 = self::extract($new);
                if (!$ex3['ok']) $ex3 = self::extract(self::deterministicFix($new));
                if ($ex3['ok']) {
                    $e3 = self::validate($ex3['data'], $schema);
                    if (!$e3) return ['ok' => true, 'data' => $ex3['data'], 'errors' => [], 'repaired' => true, 'attempts' => $attempts];
                    $errors = $e3;
                } else {
                    $errors = [$ex3['error']];
                }
            }
        }
        return ['ok' => false, 'data' => null, 'errors' => $errors, 'repaired' => $repaired, 'attempts' => $attempts];
    }

    /** Finds the first complete JSON object/array in the text (fenced or bare), string-aware. */
    public static function extract(string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'data' => null, 'error' => 'empty output'];

        $candidates = [];
        if (preg_match_all('/```(?:json|JSON)?\s*(.*?)```/s', $text, $m)) {
            foreach ($m[1] as $block) $candidates[] = trim($block);
        }
        $candidates[] = $text;

        foreach ($candidates as $c) {
            if ($c === '') continue;
            $d = json_decode($c, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($d) || is_scalar($d) || $d === null) && ($c[0] === '{' || $c[0] === '[')) {
                return ['ok' => true, 'data' => $d, 'error' => ''];
            }
            $slice = self::firstBalanced($c);
            if ($slice !== null) {
                $d = json_decode($slice, true);
                if (json_last_error() === JSON_ERROR_NONE) return ['ok' => true, 'data' => $d, 'error' => ''];
            }
        }
        return ['ok' => false, 'data' => null, 'error' => 'no valid JSON found in the output'];
    }

    /** Returns the first balanced {...} or [...] substring, ignoring brackets inside strings. */
    private static function firstBalanced(string $s): ?string
    {
        $len = strlen($s);
        for ($start = 0; $start < $len; $start++) {
            $ch = $s[$start];
            if ($ch !== '{' && $ch !== '[') continue;
            $stack = []; $inStr = false; $esc = false;
            for ($i = $start; $i < $len; $i++) {
                $c = $s[$i];
                if ($inStr) {
                    if ($esc) $esc = false;
                    elseif ($c === '\\') $esc = true;
                    elseif ($c === '"') $inStr = false;
                    continue;
                }
                if ($c === '"') { $inStr = true; continue; }
                if ($c === '{' || $c === '[') { $stack[] = $c === '{' ? '}' : ']'; continue; }
                if ($c === '}' || $c === ']') {
                    if (!$stack || array_pop($stack) !== $c) break;
                    if (!$stack) {
                        $cand = substr($s, $start, $i - $start + 1);
                        json_decode($cand);
                        if (json_last_error() === JSON_ERROR_NONE) return $cand;
                        break; // this opening bracket did not yield valid JSON, try the next one
                    }
                }
            }
        }
        return null;
    }

    /** Fixes the commonest harmless defects: smart quotes, comments, trailing commas. Never invents data. */
    public static function deterministicFix(string $t): string
    {
        $t = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $t);
        $t = preg_replace('/^\s*\/\/.*$/m', '', $t) ?? $t;               // whole-line // comments
        $t = preg_replace('/,\s*([}\]])/', '$1', $t) ?? $t;               // trailing commas
        return $t;
    }

    /** @return string[] error messages with paths; empty array means valid */
    public static function validate($value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = (array)($schema['type'] ?? []);
        if (!empty($schema['nullable'])) $types[] = 'null';
        if (array_key_exists('enum', $schema) && !in_array($value, $schema['enum'], true)) {
            return ["$path: value is not one of the allowed options"];
        }
        if ($types) {
            $matched = null;
            foreach ($types as $t) { if (self::isType($value, (string)$t)) { $matched = (string)$t; break; } }
            if ($matched === null) return ["$path: expected " . implode('|', array_unique($types)) . ', got ' . self::typeName($value)];
        } else {
            $matched = self::typeName($value);
        }

        switch ($matched) {
            case 'string':
                $len = mb_strlen((string)$value);
                if (isset($schema['minLength']) && $len < (int)$schema['minLength']) $errors[] = "$path: shorter than {$schema['minLength']} characters";
                if (isset($schema['maxLength']) && $len > (int)$schema['maxLength']) $errors[] = "$path: longer than {$schema['maxLength']} characters";
                if (isset($schema['pattern']) && @preg_match($schema['pattern'], (string)$value) !== 1) $errors[] = "$path: does not match the required pattern";
                break;
            case 'int':
            case 'number':
                if (isset($schema['min']) && $value < $schema['min']) $errors[] = "$path: below the minimum {$schema['min']}";
                if (isset($schema['max']) && $value > $schema['max']) $errors[] = "$path: above the maximum {$schema['max']}";
                break;
            case 'array':
                $n = count($value);
                if (isset($schema['minItems']) && $n < (int)$schema['minItems']) $errors[] = "$path: fewer than {$schema['minItems']} items";
                if (isset($schema['maxItems']) && $n > (int)$schema['maxItems']) $errors[] = "$path: more than {$schema['maxItems']} items";
                if (isset($schema['items'])) {
                    foreach ($value as $i => $item) {
                        array_push($errors, ...self::validate($item, $schema['items'], $path . '[' . $i . ']'));
                        if (count($errors) >= 20) break;
                    }
                }
                break;
            case 'object':
                foreach ((array)($schema['required'] ?? []) as $req) {
                    if (!array_key_exists($req, $value)) $errors[] = "$path.$req: required but missing";
                }
                $props = (array)($schema['properties'] ?? []);
                foreach ($props as $k => $sub) {
                    if (array_key_exists($k, $value)) array_push($errors, ...self::validate($value[$k], $sub, "$path.$k"));
                }
                if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
                    foreach (array_keys($value) as $k) if (!array_key_exists($k, $props)) $errors[] = "$path.$k: unexpected property";
                }
                break;
        }
        return array_slice($errors, 0, 20);
    }

    private static function isType($v, string $t): bool
    {
        return match ($t) {
            'string' => is_string($v),
            'int'    => is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 9.0e15),
            'number' => is_int($v) || (is_float($v) && is_finite($v)),
            'bool'   => is_bool($v),
            'null'   => $v === null,
            'array'  => is_array($v) && ($v === [] || array_is_list($v)),
            'object' => is_array($v) && ($v === [] || !array_is_list($v)),
            default  => false,
        };
    }

    private static function typeName($v): string
    {
        if (is_string($v)) return 'string';
        if (is_int($v)) return 'int';
        if (is_float($v)) return 'number';
        if (is_bool($v)) return 'bool';
        if ($v === null) return 'null';
        if (is_array($v)) return ($v === [] || array_is_list($v)) ? 'array' : 'object';
        return 'unknown';
    }
}
