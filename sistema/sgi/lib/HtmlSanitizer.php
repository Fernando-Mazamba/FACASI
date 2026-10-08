<?php
declare(strict_types=1);

/**
 * Remove conteúdo ativo de HTML (scripts, manipuladores on*, javascript:, iframes, objetos, formulários externos).
 * Usado para versões finais de registos e para modelos carregados por utilizadores.
 */
class HtmlSanitizer
{
    private const DROP_TAGS = ['script', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset', 'base', 'portal'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($doc);
        foreach (self::DROP_TAGS as $t) {
            foreach (iterator_to_array($xp->query('//' . $t)) as $n) {
                $n->parentNode->removeChild($n);
            }
        }
        // <meta http-equiv="refresh">
        foreach (iterator_to_array($xp->query('//meta[@http-equiv]')) as $n) {
            if (strtolower($n->getAttribute('http-equiv')) !== 'content-type') {
                $n->parentNode->removeChild($n);
            }
        }
        foreach (iterator_to_array($xp->query('//@*')) as $attr) {
            /** @var DOMAttr $attr */
            $name = strtolower($attr->nodeName);
            $val = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', (string)$attr->nodeValue) ?? '');
            if (strpos($name, 'on') === 0
                || (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href', 'srcdoc', 'data'], true)
                    && (strpos($val, 'javascript:') === 0 || strpos($val, 'vbscript:') === 0 || ($name === 'srcdoc')
                        || (strpos($val, 'data:') === 0 && strpos($val, 'data:image/') !== 0)))) {
                $attr->ownerElement->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($xp->query('//form[@action]')) as $f) {
            $f->removeAttribute('action');
        }
        // Remove expressões perigosas em CSS inline antigo
        foreach (iterator_to_array($xp->query('//@style')) as $s) {
            if (preg_match('/expression\s*\(|javascript:|behavior\s*:/i', (string)$s->nodeValue)) {
                $s->ownerElement->removeAttribute('style');
            }
        }
        $out = $doc->saveHTML();
        $out = preg_replace('/^<\?xml encoding="UTF-8">/', '', (string)$out);
        return "<!DOCTYPE html>\n" . ltrim((string)$out);
    }

    /** Verdadeiro se o HTML contém conteúdo ativo (usado para avisos). */
    public static function hasActiveContent(string $html): bool
    {
        return (bool)preg_match('/<script\b|\son[a-z]+\s*=|javascript:/i', $html);
    }
}
