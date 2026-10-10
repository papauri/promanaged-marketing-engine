<?php
/** The website link each brand can put in its emails and WhatsApp messages, with a generated thumbnail (page title, description and image). */

function pm_clean_url(string $u): string
{
    $u = trim($u);
    if ($u === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }
    return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $u) ? $u : '';
}

/** [url, on] for a brand ('' = the brand in use). Off or empty means nothing is added to messages. */
function pm_link_cfg(string $brand = ''): array
{
    $brand = $brand ?: pm_brand();
    $s = pm_settings();
    $c = pm_brand_block($s, $brand);
    $url = pm_clean_url((string)($c['link_url'] ?? ''));
    return ['url' => $url, 'on' => $url !== '' && !empty($c['link_on'])];
}

function pm_link_thumb_path(string $brand): string { return PM_DATA . '/thumbs/' . (pm_brand_norm($brand)) . '.img'; }
function pm_link_meta_file(string $brand): string { return PM_DATA . '/link_' . (pm_brand_norm($brand)) . '.json'; }

/** Fetched text (a web page) as clean UTF-8: converts from the declared or Windows-1252 charset and drops invalid bytes. */
function pm_to_utf8(string $s, string $contentType = ''): string
{
    $cs = '';
    if (preg_match('/charset=["\']?([\w\-]+)/i', $contentType, $m) || preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($s, 0, 4096), $m)) {
        $cs = strtoupper($m[1]);
    }
    if ($cs !== '' && $cs !== 'UTF-8' && $cs !== 'UTF8' && in_array($cs, array_map('strtoupper', mb_list_encodings()), true)) {
        $c = @mb_convert_encoding($s, 'UTF-8', $cs);
        if (is_string($c)) {
            return mb_scrub($c, 'UTF-8');
        }
    }
    if (!mb_check_encoding($s, 'UTF-8')) {
        $c = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        if (is_string($c)) {
            return mb_scrub($c, 'UTF-8');
        }
    }
    return mb_scrub($s, 'UTF-8');
}

function pm_link_get(string $url, int $max = 1500000): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LinkPreview)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!is_string($body) || $code < 200 || $code >= 300 || strlen($body) > $max) {
        return null;
    }
    return preg_match('#^(text/|application/(xhtml|xml|json))#i', $ct) ? pm_to_utf8($body, $ct) : $body; // images stay as they are
}

/** Fetches the page, reads its title, description and image, and saves a thumbnail. Returns the preview data. */
function pm_link_refresh(string $brand): array
{
    $url = pm_link_cfg($brand)['url'];
    $meta = ['url' => $url, 'title' => '', 'desc' => '', 'image' => false, 'at' => date('Y-m-d H:i'), 'ok' => false];
    if ($url !== '') {
        $html = pm_link_get($url, 3000000);
        if ($html !== null) {
            $tag = function (string $key) use ($html): string {
                foreach (['property', 'name'] as $attr) {
                    if (preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($key, '/') . '["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)
                        || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . preg_quote($key, '/') . '["\']/i', $html, $m)) {
                        return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                    }
                }
                return '';
            };
            $title = $tag('og:title') ?: (preg_match('/<title[^>]*>([^<]*)/i', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '');
            $meta['title'] = mb_substr($title, 0, 90);
            $meta['desc'] = mb_substr($tag('og:description') ?: $tag('description'), 0, 200);
            $img = $tag('og:image') ?: $tag('twitter:image');
            if ($img !== '') {
                $abs = preg_match('#^https?://#i', $img) ? $img : (str_starts_with($img, '//') ? 'https:' . $img : rtrim((string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $url), '/') . '/' . ltrim($img, '/'));
                $bin = pm_link_get($abs, 4000000);
                $info = $bin !== null ? @getimagesizefromstring($bin) : false;
                if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
                    @mkdir(PM_DATA . '/thumbs', 0775, true);
                    // Shrink to a 600px wide JPEG so emails stay light.
                    $src = @imagecreatefromstring($bin);
                    if ($src) {
                        $w = imagesx($src);
                        $h = imagesy($src);
                        $nw = min(600, $w);
                        $nh = (int)round($h * $nw / $w);
                        $dst = imagecreatetruecolor($nw, $nh);
                        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        imagejpeg($dst, pm_link_thumb_path($brand), 82);
                        $meta['image'] = true;
                    }
                }
            }
            $meta['ok'] = $meta['title'] !== '' || $meta['image'];
        }
    }
    if (!$meta['image']) {
        @unlink(pm_link_thumb_path($brand));
    }
    file_put_contents(pm_link_meta_file($brand), json_encode($meta, JSON_UNESCAPED_UNICODE));
    return $meta;
}

function pm_link_preview(string $brand): array
{
    $f = pm_link_meta_file($brand);
    $m = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($m) || ($m['url'] ?? '') !== pm_link_cfg($brand)['url']) {
        return pm_link_refresh($brand);
    }
    return $m;
}

/** For pm_mail: the link card for the brand in use, or null when the link is off. */
function pm_link_card(): ?array
{
    $c = pm_link_cfg();
    if (!$c['on']) {
        return null;
    }
    $m = pm_link_preview(pm_brand());
    $img = pm_link_thumb_path(pm_brand());
    return ['url' => $c['url'], 'title' => (string)($m['title'] ?? ''), 'desc' => (string)($m['desc'] ?? ''), 'img' => !empty($m['image']) && is_file($img) ? $img : ''];
}

/** The link line added to a WhatsApp message (WhatsApp draws its own thumbnail from the link). */
function pm_link_wa_line(): string
{
    $c = pm_link_cfg();
    return $c['on'] ? $c['url'] : '';
}
