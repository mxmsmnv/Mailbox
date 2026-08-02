<?php namespace ProcessWire;

/** Builds an inert HTML document with optional, explicitly acknowledged external content. */
final class MailboxMessageView {

    public static function sanitizedDocument(string $html, bool $allowRemoteImages = false, bool $allowExternalLinks = false): string {
        $csp = "default-src 'none'; img-src data:" . ($allowRemoteImages ? ' https:' : '') . "; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
        $fallback = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp, ENT_QUOTES, 'UTF-8') . '"><style>' . self::baseCss() . '</style></head><body>' . htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</body></html>';
        if($html === '' || !class_exists('DOMDocument')) return $fallback;

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if(!$loaded) return $fallback;

        $xpath = new \DOMXPath($document);
        foreach(['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'meta', 'link', 'base', 'video', 'audio', 'source', 'svg', 'math'] as $tag) {
            $nodes = [];
            foreach($xpath->query('//' . $tag) ?: [] as $node) $nodes[] = $node;
            foreach($nodes as $node) if($node->parentNode) $node->parentNode->removeChild($node);
        }
        foreach($xpath->query('//*') ?: [] as $element) {
            if(!($element instanceof \DOMElement)) continue;
            $remove = [];
            foreach($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if(strpos($name, 'on') === 0 || in_array($name, ['action', 'formaction', 'srcset', 'poster', 'background'], true)) $remove[] = $attribute->name;
                if($name === 'href' && (strtolower($element->tagName) !== 'a' || !$allowExternalLinks || !self::safePublicHttpsUrl($value))) $remove[] = $attribute->name;
                if($name === 'src' && !preg_match('#^data:image/(?:png|gif|jpe?g|webp);base64,#i', $value) && (!$allowRemoteImages || !self::safeRemoteImageUrl($value))) $remove[] = $attribute->name;
            }
            foreach(array_unique($remove) as $name) $element->removeAttribute($name);
            foreach(['ping', 'download'] as $name) $element->removeAttribute($name);
            if(strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
                $element->setAttribute('referrerpolicy', 'no-referrer');
            } else foreach(['target', 'rel', 'referrerpolicy'] as $name) $element->removeAttribute($name);
            if($element->hasAttribute('style')) {
                $style = self::sanitizedCss($element->getAttribute('style'), $allowRemoteImages);
                if($style === '') $element->removeAttribute('style');
                else $element->setAttribute('style', $style);
            }
            if(strtolower($element->tagName) === 'img' && $element->hasAttribute('src')) {
                $element->setAttribute('referrerpolicy', 'no-referrer');
                $element->setAttribute('loading', 'lazy');
                $element->setAttribute('decoding', 'async');
            }
        }
        foreach($xpath->query('//style') ?: [] as $styleNode) {
            $styleNode->nodeValue = self::sanitizedCss((string) $styleNode->nodeValue, $allowRemoteImages);
        }

        $body = '';
        $bodyNode = $xpath->query('//body')->item(0);
        $nodes = $bodyNode ? $bodyNode->childNodes : $document->childNodes;
        foreach($nodes as $node) {
            if($node->nodeType === XML_PI_NODE) continue;
            $body .= (string) $document->saveHTML($node);
        }
        return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp, ENT_QUOTES, 'UTF-8') . '"><meta name="referrer" content="no-referrer"><style>' . self::baseCss() . '</style></head><body>' . $body . '</body></html>';
    }

    private static function baseCss(): string {
        return 'html{color-scheme:light}body{margin:0;padding:20px;background:#fff;color:#222;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}pre{white-space:pre-wrap}a{color:inherit;text-decoration:underline;cursor:not-allowed}a[href]{color:#d8175f;cursor:pointer}';
    }

    private static function safeRemoteImageUrl(string $url): bool {
        $parts = self::safePublicHttpsParts($url);
        return is_array($parts) && !isset($parts['fragment']);
    }

    private static function safePublicHttpsUrl(string $url): bool {
        return is_array(self::safePublicHttpsParts(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function safePublicHttpsParts(string $url): ?array {
        if(strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
        $parts = parse_url($url);
        if(!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) return null;
        $host = strtolower((string) ($parts['host'] ?? ''));
        if($host === '' || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) return null;
        $tld = substr((string) strrchr($host, '.'), 1);
        if(in_array($tld, ['local', 'localhost', 'internal', 'home', 'lan', 'test', 'invalid', 'example', 'onion'], true)) return null;
        if(isset($parts['port']) && (int) $parts['port'] !== 443) return null;
        return $parts;
    }

    private static function sanitizedCss(string $css, bool $allowRemoteImages): string {
        if(strlen($css) > 262144 || preg_match('/(?:expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:|@import)/i', $css)) return '';
        $urls = [];
        $css = preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/i', static function(array $match) use($allowRemoteImages, &$urls): string {
            $url = html_entity_decode(trim((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if(preg_match('#^data:image/(?:png|gif|jpe?g|webp);base64,#i', $url) || ($allowRemoteImages && self::safeRemoteImageUrl($url))) {
                $token = '__MAILBOX_SAFE_CSS_URL_' . count($urls) . '__';
                $urls[$token] = 'url("' . str_replace(['"', '\\'], '', $url) . '")';
                return $token;
            }
            return 'none';
        }, $css);
        if(!is_string($css) || preg_match('/url\s*\(/i', $css)) return '';
        return trim(strtr($css, $urls));
    }
}
