<?php
/**
 * Generates src/Profile.php from Garmin's FIT SDK profile.
 *
 * Garmin's FIT SDK is the source of truth for messages, fields, scales, units and enumerated
 * types; this library's hand-written tables only add names and labels its callers already use
 * (see phpFITFileAnalysis::$data_mesg_info and $enum_data). The Python SDK's profile.py is read
 * because it already uses the library's snake_case names.
 *
 * Usage:
 *   php bin/generate-profile.php                     version from bin/fit-sdk-version.txt
 *   php bin/generate-profile.php --version=21.219.0  that release (a garmin/fit-python-sdk tag)
 *   php bin/generate-profile.php --source=profile.py a local copy instead of downloading
 *   php bin/generate-profile.php --summary=FILE      also write a Markdown summary of what changed
 *   php bin/generate-profile.php --check             exit 1 if src/Profile.php would change
 */

const PROFILE_URL = 'https://raw.githubusercontent.com/garmin/fit-python-sdk/%s/garmin_fit_sdk/profile.py';
const BASE_TYPES = [
    'enum' => 0x00, 'sint8' => 0x01, 'uint8' => 0x02, 'sint16' => 0x83, 'uint16' => 0x84, 'sint32' => 0x85,
    'uint32' => 0x86, 'string' => 0x07, 'float32' => 0x88, 'float64' => 0x89, 'uint8z' => 0x0A, 'uint16z' => 0x8B,
    'uint32z' => 0x8C, 'byte' => 0x0D, 'sint64' => 0x8E, 'uint64' => 0x8F, 'uint64z' => 0x90, 'bool' => 0x00,
];

$root = dirname(__DIR__);
$options = getopt('', ['version:', 'source:', 'summary:', 'check']);
$version = $options['version'] ?? trim(file_get_contents("$root/bin/fit-sdk-version.txt"));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Not a version: $version\n");
    exit(2);
}

$source = $options['source'] ?? sprintf(PROFILE_URL, $version);
$python = file_get_contents($source);
if ($python === false || $python === '') {
    fwrite(STDERR, "Could not read $source\n");
    exit(2);
}

$profile = (new PythonLiteralParser($python))->parseAssignment('Profile');
$found = sprintf('%d.%d.%d', $profile['version']['major'], $profile['version']['minor'], $profile['version']['patch']);
if ($found !== $version) {
    fwrite(STDERR, "$source is profile $found, not $version\n");
    exit(2);
}

$messages = [];
foreach ($profile['messages'] as $num => $message) {
    $fields = [];
    foreach ($message['fields'] as $field_num => $field) {
        $fields[(int) $field_num] = field($field);
    }
    ksort($fields);
    $messages[(int) $num] = ['name' => $message['name'], 'fields' => $fields];
}
ksort($messages);

$types = [];
foreach ($profile['types'] as $name => $values) {
    ksort($values);
    $types[$name] = $values;
}
ksort($types);

$php = render($version, $messages, $types);
$target = "$root/src/Profile.php";
$current = is_file($target) ? file_get_contents($target) : '';

if (isset($options['summary'])) {
    $previous = $current !== '' ? previousProfile($target) : ['version' => 'none', 'messages' => [], 'types' => []];
    file_put_contents($options['summary'], summary($previous, $version, $messages, $types));
}

if (isset($options['check'])) {
    if ($php !== $current) {
        fwrite(STDERR, "src/Profile.php is not what profile $version generates. Run php bin/generate-profile.php\n");
        exit(1);
    }
    exit(0);
}

file_put_contents($target, $php);
file_put_contents("$root/bin/fit-sdk-version.txt", "$version\n");
fwrite(STDERR, sprintf("Wrote src/Profile.php: profile %s, %d messages, %d types\n", $version, count($messages), count($types)));

/** One field (or subfield) in the shape src/Profile.php documents. */
function field(array $field): array
{
    $list = fn($v) => is_array($v) ? $v : [$v];
    $scales = $list($field['scale']);
    $offsets = $list($field['offset']);
    $units = $list($field['units']);
    // Several scales are one per component; the field itself is then left raw, as the SDK's
    // decoders leave it (e.g. record compressed_speed_distance, scales [100, 16]).
    $own = count($scales) <= 1;

    $out = [
        'name' => $field['name'],
        'type' => $field['type'],
        'array' => ($field['array'] ?? '') === 'true',
        'scale' => $own ? number($scales[0] ?? 1) : 1,
        'offset' => $own ? number($offsets[0] ?? 0) : 0,
        'units' => $own ? (string) ($units[0] ?? '') : '',
    ];
    if (isset($field['base_type'])) {
        $out['base_type'] = BASE_TYPES[$field['base_type']] ?? null;
    }
    $components = [];
    foreach ($field['components'] as $i => $target) {
        $components[] = [(int) $target, (int) $field['bits'][$i], number($scales[$i] ?? 1), number($offsets[$i] ?? 0)];
    }
    if ($components) {
        $out['components'] = $components;
    }
    if (!empty($field['is_accumulated'])) {
        $out['accumulated'] = true;
    }
    $subfields = [];
    foreach ($field['sub_fields'] ?? [] as $subfield) {
        $sub = field($subfield + ['sub_fields' => []]);
        $sub['map'] = array_map(fn($m) => [(int) $m['num'], (int) $m['raw_value']], $subfield['map']);
        $subfields[] = $sub;
    }
    if ($subfields) {
        $out['subfields'] = $subfields;
    }

    return $out;
}

/** Integral floats as ints, so the file reads 1000 rather than 1000.0. */
function number(int|float $n): int|float
{
    return is_float($n) && floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : $n;
}

function render(string $version, array $messages, array $types): string
{
    $out = "<?php\nnamespace adriangibbons;\n\n";
    $out .= "/**\n";
    $out .= " * Garmin FIT SDK profile $version, generated by bin/generate-profile.php from\n";
    $out .= " * https://github.com/garmin/fit-python-sdk/blob/$version/garmin_fit_sdk/profile.py\n";
    $out .= " * Do not edit: regenerate. The FIT SDK is licensed under the Flexible and Interoperable Data\n";
    $out .= " * Transfer (FIT) Protocol License.\n";
    $out .= " *\n";
    $out .= " * MESSAGES: global message number => name, and fields by field number: name, type (a TYPES name\n";
    $out .= " * or a base type name), array, scale, offset, units, base_type, and when present components\n";
    $out .= " * ([target field number, bits, scale, offset] each), accumulated, and subfields (a field read\n";
    $out .= " * under another name and type when a reference field holds a value: map [[field number, raw\n";
    $out .= " * value], ...]). TYPES: type name => value => name.\n";
    $out .= " */\nfinal class Profile\n{\n";
    $out .= "    public const VERSION = " . var_export($version, true) . ";\n\n";
    $out .= "    public const MESSAGES = [\n";
    foreach ($messages as $num => $message) {
        $out .= "        $num => ['name' => " . var_export($message['name'], true) . ", 'fields' => [\n";
        foreach ($message['fields'] as $field_num => $field) {
            $out .= "            $field_num => " . inline($field) . ",\n";
        }
        $out .= "        ]],\n";
    }
    $out .= "    ];\n\n";
    $out .= "    public const TYPES = [\n";
    foreach ($types as $name => $values) {
        $out .= "        " . var_export($name, true) . " => " . inline($values) . ",\n";
    }
    $out .= "    ];\n}\n";

    return $out;
}

/** A value as one line of short-array PHP; lists without keys. */
function inline(mixed $value): string
{
    if (!is_array($value)) {
        return var_export($value, true);
    }
    $is_list = array_is_list($value);
    $parts = [];
    foreach ($value as $k => $v) {
        $parts[] = ($is_list ? '' : var_export($k, true) . ' => ') . inline($v);
    }

    return '[' . implode(', ', $parts) . ']';
}

/** The profile in src/Profile.php now, read without loading the class (the new one may share its name). */
function previousProfile(string $path): array
{
    $code = file_get_contents($path);
    $code = preg_replace('/^<\?php\s*namespace [^;]+;/', '', $code);
    $class = 'PreviousProfile' . md5($code);
    eval(preg_replace('/final class Profile\b/', "final class $class", $code, 1));

    return ['version' => $class::VERSION, 'messages' => $class::MESSAGES, 'types' => $class::TYPES];
}

/**
 * What changed between the profile in src/Profile.php and the new one: messages and fields added
 * or removed, changes to a field's name, type, scale, offset or units (which change values the
 * library already returns), and enum values added or renamed.
 */
function summary(array $previous, string $version, array $messages, array $types): string
{
    $lines = ["## FIT SDK profile {$previous['version']} → $version", ''];
    $changed = [];
    $added_fields = [];
    foreach ($messages as $num => $message) {
        $old = $previous['messages'][$num] ?? null;
        if ($old === null) {
            $lines[] = "- New message **{$message['name']}** ($num), " . count($message['fields']) . ' fields';
            continue;
        }
        foreach ($message['fields'] as $field_num => $field) {
            $old_field = $old['fields'][$field_num] ?? null;
            if ($old_field === null) {
                $added_fields[] = "{$message['name']}.{$field['name']} ($field_num)";
                continue;
            }
            foreach (['name', 'type', 'scale', 'offset', 'units'] as $key) {
                if ($old_field[$key] !== $field[$key]) {
                    $changed[] = "- **{$message['name']}.{$old_field['name']}** ($num/$field_num) $key: `" . var_export($old_field[$key], true) . '` → `' . var_export($field[$key], true) . '`';
                }
            }
        }
        foreach (array_diff_key($old['fields'], $message['fields']) as $field_num => $field) {
            $changed[] = "- Removed field **{$message['name']}.{$field['name']}** ($num/$field_num)";
        }
    }
    foreach (array_diff_key($previous['messages'], $messages) as $num => $message) {
        $changed[] = "- Removed message **{$message['name']}** ($num)";
    }
    if ($added_fields) {
        $lines[] = '- New fields: ' . implode(', ', $added_fields);
    }
    if ($changed) {
        $lines[] = '';
        $lines[] = '### Changed or removed (review: these change values already returned)';
        array_push($lines, ...$changed);
    }

    $enum_lines = [];
    foreach ($types as $name => $values) {
        $old = $previous['types'][$name] ?? [];
        $added = array_diff_key($values, $old);
        $renamed = [];
        foreach (array_intersect_key($values, $old) as $value => $label) {
            if ($old[$value] !== $label) {
                $renamed[] = "$value `{$old[$value]}` → `$label`";
            }
        }
        if ($added) {
            $enum_lines[] = "- **$name**: " . count($added) . ' new (' . implode(', ', array_slice(array_map(fn($v, $l) => "$v $l", array_keys($added), $added), 0, 12)) . (count($added) > 12 ? ', …' : '') . ')';
        }
        if ($renamed) {
            $enum_lines[] = "- **$name** renamed: " . implode(', ', $renamed);
        }
    }
    if ($enum_lines) {
        $lines[] = '';
        $lines[] = '### Enumerated types';
        array_push($lines, ...$enum_lines);
    }
    if (count($lines) === 2) {
        $lines[] = 'No differences.';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Just enough of Python's literal syntax for profile.py: dicts, lists, tuples, strings, ints,
 * floats, True/False/None, and # comments.
 */
final class PythonLiteralParser
{
    private string $src;
    private int $pos = 0;
    private int $len;

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->len = strlen($src);
    }

    public function parseAssignment(string $name): mixed
    {
        if (!preg_match('/^' . preg_quote($name, '/') . '\s*=\s*/m', $this->src, $m, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("No assignment to $name");
        }
        $this->pos = $m[0][1] + strlen($m[0][0]);

        return $this->value();
    }

    private function value(): mixed
    {
        $this->skip();
        $c = $this->src[$this->pos] ?? '';
        switch ($c) {
            case '{':
                return $this->collection('}', true);
            case '[':
                return $this->collection(']', false);
            case '(':
                return $this->collection(')', false);
            case '"':
            case "'":
                return $this->string();
        }
        if (preg_match('/\G(True|False|None)\b/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[1]);

            return ['True' => true, 'False' => false, 'None' => null][$m[1]];
        }
        if (preg_match('/\G-?(0x[0-9A-Fa-f]+|\d+\.\d*(?:[eE][-+]?\d+)?|\d+(?:[eE][-+]?\d+)?)/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $n = $m[0];
            if (stripos($n, '0x') !== false) {
                return ($n[0] === '-' ? -1 : 1) * hexdec(ltrim($n, '-'));
            }

            return preg_match('/[.eE]/', $n) ? (float) $n : (int) $n;
        }
        throw new RuntimeException('Unexpected ' . substr($this->src, $this->pos, 30) . " at offset {$this->pos}");
    }

    private function collection(string $close, bool $is_dict): array
    {
        $this->pos++;
        $out = [];
        while (true) {
            $this->skip();
            if (($this->src[$this->pos] ?? '') === $close) {
                $this->pos++;

                return $out;
            }
            $item = $this->value();
            if ($is_dict) {
                $this->skip();
                $this->expect(':');
                $out[$item] = $this->value();
            } else {
                $out[] = $item;
            }
            $this->skip();
            if (($this->src[$this->pos] ?? '') === ',') {
                $this->pos++;
            }
        }
    }

    private function string(): string
    {
        $quote = $this->src[$this->pos++];
        $out = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== $quote) {
            $c = $this->src[$this->pos++];
            if ($c === '\\') {
                $e = $this->src[$this->pos++];
                $out .= ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0"][$e] ?? $e;
            } else {
                $out .= $c;
            }
        }
        $this->pos++;

        return $out;
    }

    private function skip(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === '#') {
                $end = strpos($this->src, "\n", $this->pos);
                $this->pos = $end === false ? $this->len : $end;
            } elseif (ctype_space($c)) {
                $this->pos++;
            } else {
                return;
            }
        }
    }

    private function expect(string $c): void
    {
        if (($this->src[$this->pos] ?? '') !== $c) {
            throw new RuntimeException("Expected '$c' at offset {$this->pos}");
        }
        $this->pos++;
    }
}
