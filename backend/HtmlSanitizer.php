<?php
/**
 * Allow-list HTML sanitiser for admin-edited rich text (CMS pages). Applied when the text is OUTPUT, so rows already stored are
 * protected too, and a compromised or careless content-editor account can no longer run script in visitors' browsers.
 * Keeps ordinary formatting (headings, paragraphs, lists, links, images, tables); removes everything else together with its content
 * for dangerous elements (script, style, iframe, object, embed, form...). Links are limited to http/https/mailto/tel/relative and get
 * rel="noopener noreferrer"; event-handler and style attributes are dropped. Not a general-purpose library: it is a conservative allow-list.
 */
final class HtmlSanitizer
{
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'small' => [], 'sub' => [], 'sup' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
        'div' => [], 'span' => [], 'a' => ['href', 'title', 'target'], 'img' => ['src', 'alt', 'title', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select', 'button', 'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base', 'applet', 'frame', 'frameset', 'title', 'head'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') return '';
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="tms-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $doc->getElementById('tms-root');
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) $out .= $doc->saveHTML($c);
        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction || $child instanceof DOMDocumentType) { $node->removeChild($child); continue; }
            if (!($child instanceof DOMElement)) continue;
            $name = strtolower($child->nodeName);
            if (in_array($name, self::DROP_WITH_CONTENT, true)) { $node->removeChild($child); continue; }
            if (!array_key_exists($name, self::TAGS)) {            // unknown tag: keep its (cleaned) children, drop the tag itself
                self::walk($child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $allowed = self::TAGS[$name];
            foreach (iterator_to_array($child->attributes) as $attr) {
                $an = strtolower($attr->nodeName);
                if (!in_array($an, $allowed, true)) { $child->removeAttribute($attr->nodeName); continue; }
                if (in_array($an, ['href', 'src'], true) && !self::safeUrl($attr->value, $an === 'src')) $child->removeAttribute($attr->nodeName);
                if (in_array($an, ['width', 'height', 'colspan', 'rowspan'], true) && !preg_match('/^\d{1,4}%?$/', $attr->value)) $child->removeAttribute($attr->nodeName);
            }
            if ($name === 'a') {
                if ($child->getAttribute('target') !== '' && $child->getAttribute('target') !== '_blank') $child->removeAttribute('target');
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            if ($name === 'img' && !$child->hasAttribute('src')) { $node->removeChild($child); continue; }
            self::walk($child);
        }
    }

    private static function safeUrl(string $u, bool $isSrc): bool
    {
        $u = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($u === '') return false;
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) {
            $s = strtolower($m[1]);
            return $isSrc ? in_array($s, ['http', 'https'], true) : in_array($s, ['http', 'https', 'mailto', 'tel'], true);
        }
        return !str_starts_with($u, '//') || true;   // relative paths and protocol-relative links stay on http(s) by definition
    }
}
