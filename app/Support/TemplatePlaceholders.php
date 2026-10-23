<?php

namespace App\Support;

class TemplatePlaceholders
{
    public static function fill(string $content, array $variables, bool $escape = false, array $html = []): string
    {
        return (string) preg_replace_callback('/\{\{\s*([^{}\s]+)\s*\}\}/', function (array $match) use ($variables, $escape, $html): string {
            if (!array_key_exists($match[1], $variables)) {
                return $match[0];
            }

            $value = $variables[$match[1]];
            $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

            return $escape && !in_array($match[1], $html, true) ? e($text) : $text;
        }, $content);
    }
}
