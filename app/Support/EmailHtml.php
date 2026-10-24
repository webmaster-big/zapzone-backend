<?php

namespace App\Support;

use DOMDocument;
use DOMElement;

class EmailHtml
{
    public const MESSAGE = 'This email contains code that cannot be used in emails (scripts, event handlers or javascript: links). Remove it and save again.';

    private const BLOCKED_TAGS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'svg', 'math', 'portal'];

    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'background', 'poster', 'cite', 'data', 'longdesc', 'lowsrc', 'dynsrc', 'xlink:href', 'srcset'];

    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && ($problem = self::problem($value)) !== null) {
                $fail($problem);
            }
        };
    }

    public static function problem(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        if (preg_match('/<\s*\/?\s*(script|iframe|object|embed|svg|math|frame|frameset|applet|base|portal)\b/i', $html)
            || preg_match('/<[a-z][^>]*?[\s\/"\'](on[a-z]+)\s*=/i', $html)) {
            return self::MESSAGE;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('*') as $element) {
            if (self::unsafe($element)) {
                return self::MESSAGE;
            }
        }

        return null;
    }

    private static function unsafe(DOMElement $element): bool
    {
        $tag = strtolower($element->nodeName);

        if (in_array($tag, self::BLOCKED_TAGS, true)) {
            return true;
        }

        if ($tag === 'meta' && strtolower(trim((string) $element->getAttribute('http-equiv'))) === 'refresh') {
            return true;
        }

        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode((string) $attribute->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if (str_starts_with($name, 'on') || $name === 'srcdoc') {
                return true;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && self::unsafeUrl($value, $name)) {
                return true;
            }

            if ($name === 'style' && preg_match('/expression\(|javascript:|vbscript:|behavior:|-moz-binding/', $value)) {
                return true;
            }
        }

        return false;
    }

    private static function unsafeUrl(string $value, string $attribute): bool
    {
        if (preg_match('/^(javascript|vbscript):/', $value)) {
            return true;
        }

        if (str_starts_with($value, 'data:')) {
            return !($attribute === 'src' && preg_match('#^data:image/(png|jpe?g|gif|webp);#', $value));
        }

        return false;
    }
}
