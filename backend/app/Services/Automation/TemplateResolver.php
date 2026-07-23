<?php

namespace App\Services\Automation;

/**
 * Resolves `{{ order.<field> }}` / `{{ stock.<field> }}` placeholders against the same facts array
 * the conditions read (spec 02 §3.6 Templating).
 *
 * Deliberately NOT Blade and NOT eval — a whitelist substitution only. Unknown placeholders render
 * as empty string and are collected as warnings so a run stays explainable. Array facts render as a
 * comma-joined list.
 */
class TemplateResolver
{
    /**
     * @return array{text: string, warnings: array<int, string>}
     */
    public static function render(string $template, array $facts): array
    {
        $warnings = [];

        $text = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($facts, &$warnings) {
            $key = $m[1];
            if (! array_key_exists($key, $facts)) {
                $warnings[] = 'unknown_placeholder:'.$key;

                return '';
            }
            $value = $facts[$key];
            if (is_array($value)) {
                return implode(', ', $value);
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) ($value ?? '');
        }, $template);

        return ['text' => $text ?? '', 'warnings' => $warnings];
    }
}
