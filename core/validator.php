<?php
/**
 * AH5 Office - Request input + validation
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Rules: required|string|int|number|bool|email|date|in:a,b|min:n|max:n|currency|phone
 */

declare(strict_types=1);

final class Validator
{
    private array $data;
    private array $errors = [];
    private array $clean  = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** Read the JSON (or form) body of the current request. */
    public static function input(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($ctype, 'application/json')) {
            $raw  = file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            $cached = is_array($json) ? $json : [];
        } elseif (str_contains($ctype, 'multipart/form-data')) {
            $cached = $_POST;
        } else {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '' && $_POST === []) {
                parse_str($raw, $parsed);
                $cached = is_array($parsed) ? $parsed : [];
            } else {
                $cached = $_POST;
            }
        }
        return $cached;
    }

    public static function make(?array $data = null): self
    {
        return new self($data ?? self::input());
    }

    public function check(string $field, string $rules, ?string $label = null): self
    {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;
        if (is_string($value)) {
            $value = trim($value);
        }
        $ruleList = explode('|', $rules);
        $required = in_array('required', $ruleList, true);
        $nullable = in_array('nullable', $ruleList, true);

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = $label . ' is required';
            } elseif ($nullable || !$required) {
                $this->clean[$field] = null;
            }
            return $this;
        }

        foreach ($ruleList as $rule) {
            if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                continue;
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'string':
                    $value = (string) $value;
                    break;
                case 'int':
                    if (!is_numeric($value) || (int) $value != $value) {
                        $this->errors[$field][] = $label . ' must be a whole number';
                    }
                    $value = (int) $value;
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        $this->errors[$field][] = $label . ' must be a number';
                    }
                    $value = (float) $value;
                    break;
                case 'bool':
                    $value = in_array($value, [1, '1', true, 'true', 'yes', 'on'], true) ? 1 : 0;
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[$field][] = $label . ' is not a valid email';
                    }
                    break;
                case 'date':
                    $ts = strtotime((string) $value);
                    if ($ts === false) {
                        $this->errors[$field][] = $label . ' is not a valid date';
                    } else {
                        $value = date('Y-m-d', $ts);
                    }
                    break;
                case 'datetime':
                    $ts = strtotime((string) $value);
                    if ($ts === false) {
                        $this->errors[$field][] = $label . ' is not a valid datetime';
                    } else {
                        $value = date('Y-m-d H:i:s', $ts);
                    }
                    break;
                case 'in':
                    $opts = explode(',', (string) $arg);
                    if (!in_array((string) $value, $opts, true)) {
                        $this->errors[$field][] = $label . ' must be one of: ' . implode(', ', $opts);
                    }
                    break;
                case 'min':
                    // numeric only when an int/number rule already cast it; strings use length
                    if (is_int($value) || is_float($value)) {
                        if ($value < (float) $arg) {
                            $this->errors[$field][] = $label . ' must be at least ' . $arg;
                        }
                    } elseif (mb_strlen((string) $value) < (int) $arg) {
                        $this->errors[$field][] = $label . ' must be at least ' . $arg . ' characters';
                    }
                    break;
                case 'max':
                    if (is_int($value) || is_float($value)) {
                        if ($value > (float) $arg) {
                            $this->errors[$field][] = $label . ' must not be more than ' . $arg;
                        }
                    } elseif (mb_strlen((string) $value) > (int) $arg) {
                        $this->errors[$field][] = $label . ' is too long (max ' . $arg . ' characters)';
                    }
                    break;
                case 'currency':
                    $value = strtoupper((string) $value);
                    if (!Helper::isValidCurrency($value)) {
                        $this->errors[$field][] = $label . ' must be one of: ' . implode(', ', SUPPORTED_CURRENCIES);
                    }
                    break;
                case 'phone':
                    $value = Helper::normalizePhone((string) $value);
                    if ($value === null) {
                        $this->errors[$field][] = $label . ' is not a valid phone number';
                    }
                    break;
                case 'exists':
                    // exists:table,column
                    [$tbl, $col] = array_pad(explode(',', (string) $arg), 2, 'id');
                    if (!preg_match('/^[a-z_]+$/', $tbl) || !preg_match('/^[a-z_]+$/', (string) $col)) {
                        break;
                    }
                    $found = DB::value("SELECT id FROM `{$tbl}` WHERE `{$col}` = ? LIMIT 1", [$value]);
                    if ($found === null) {
                        $this->errors[$field][] = $label . ' does not exist';
                    }
                    break;
            }
        }

        $this->clean[$field] = $value;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** Stops the request with 422 if anything failed. */
    public function validate(): array
    {
        if ($this->fails()) {
            Response::validation($this->errors);
        }
        return $this->clean;
    }

    /** Only the keys that were actually sent by the client (for PATCH). */
    public function present(array $clean): array
    {
        $out = [];
        foreach ($clean as $k => $v) {
            if (array_key_exists($k, $this->data)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
