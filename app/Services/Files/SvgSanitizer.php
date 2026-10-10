<?php

namespace App\Services\Files;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Strips scripts, event handlers and external references from an SVG before it is stored or shown.
 */
class SvgSanitizer
{
    public function sanitize(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > 2_000_000 || ! str_contains($svg, '<svg')) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        $this->clean($document->documentElement);

        $output = $document->saveXML($document->documentElement);
        if (! is_string($output) || str_contains(strtolower($output), 'javascript:') || str_contains(strtolower($output), '<script')) {
            return null;
        }

        return $output;
    }

    private function clean(DOMNode $node): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }

        $remove = in_array(strtolower($node->tagName), ['script', 'foreignobject', 'iframe', 'handler', 'set'], true);
        if ($remove && $node->parentNode !== null) {
            $node->parentNode->removeChild($node);

            return;
        }

        if ($node->hasAttributes()) {
            $drop = [];
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                $local = str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name;
                if (str_starts_with($name, 'on') || $local === 'href' && ! str_starts_with($value, '#') || $name === 'style' && preg_match('/javascript|expression\s*\(/i', $value)) {
                    $drop[] = $attribute->name;
                }
            }
            foreach ($drop as $name) {
                $node->removeAttribute($name);
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $this->clean($child);
        }
    }
}
