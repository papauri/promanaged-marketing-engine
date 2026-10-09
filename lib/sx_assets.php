<?php
/**
 * Photo library: the owner's own pictures, each with credit, consent and an expiry. data/social_assets.json rows:
 * {id, brand, file (inside data/social/assets/), orig_name, credit, consent, consent_note, owner, note, tags:{pillars:[],place}, expires, created, uses:[postIds], w, h}.
 * Only a ticked consent makes a picture usable. Uploads are JPG/PNG only, turned upright, shrunk to 1600px and re-saved as JPEG (this also drops GPS/EXIF data).
 */

const PM_SX_ASSET_MAX_PX = 1600;
const PM_SX_ASSET_MAX_BYTES = 15 * 1024 * 1024;
/** Post statuses in which a post still needs its picture. */
const PM_SX_LIVE_STATUSES = ['draft', 'approved', 'publishing', 'needs_edit', 'needs_video', 'needs_check', 'review', 'needs_asset'];

function pm_sx_assets_dir(): string
{
    $d = PM_DATA . '/social/assets';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

function pm_sx_assets_all(): array { return array_values(pm_load('social_assets', fn() => [])); }

function pm_social_asset_find(string $id): ?array
{
    foreach (pm_sx_assets_all() as $a) {
        if (($a['id'] ?? '') === $id && $id !== '') {
            return $a;
        }
    }
    return null;
}

/** Absolute path of an asset's file, or '' if it is missing or the stored name is not one of ours. */
function pm_social_asset_path(array $row): string
{
    $f = basename((string)($row['file'] ?? ''));
    $p = pm_sx_assets_dir() . '/' . $f;
    return $f !== '' && $f === ($row['file'] ?? '') && is_file($p) ? $p : '';
}

/** Does this picture suit a request? filter: pillar, place, owner (host). A place-specific picture is never used for a generic post. */
function pm_sx_asset_fits(array $a, array $f): bool
{
    $pillars = array_map('strtolower', (array)($a['tags']['pillars'] ?? []));
    if ($pillars && !empty($f['pillar'])) {
        $cls = function_exists('pm_sx_pillar_class') ? pm_sx_pillar_class((string)$f['pillar']) : '';
        $match = in_array(strtolower((string)$f['pillar']), $pillars, true);
        foreach ($pillars as $pn) {
            $match = $match || ($cls !== '' && pm_sx_pillar_class($pn) === $cls);
        }
        if (!$match) {
            return false;
        }
    }
    $ap = strtolower(trim((string)($a['tags']['place'] ?? '')));
    $fp = strtolower(trim((string)($f['place'] ?? '')));
    if ($ap !== $fp) {
        return false;
    }
    $fo = strtolower(trim((string)($f['owner'] ?? '')));
    return $fo === '' || strtolower(trim((string)($a['owner'] ?? ''))) === $fo;
}

/** Pictures that may be used today: consent ticked, not expired, file present. Least used first; deterministic. */
function pm_social_assets_usable(string $brand, array $filter = []): array
{
    $today = date('Y-m-d');
    $out = array_values(array_filter(pm_sx_assets_all(), fn($a) => ($a['brand'] ?? 'promanaged') === $brand && !empty($a['consent'])
        && (($a['expires'] ?? '') === '' || $a['expires'] >= $today) && pm_social_asset_path($a) !== ''
        && (!$filter || pm_sx_asset_fits($a, $filter))));
    usort($out, fn($a, $b) => [count((array)($a['uses'] ?? [])), (string)($a['created'] ?? ''), $a['id']] <=> [count((array)($b['uses'] ?? [])), (string)($b['created'] ?? ''), $b['id']]);
    return $out;
}

function pm_sx_asset_clean(array $in, array $base = []): array
{
    $pillars = $in['tags']['pillars'] ?? ($base['tags']['pillars'] ?? []);
    if (is_string($pillars)) {
        $pillars = preg_split('/\s*,\s*/', $pillars) ?: [];
    }
    $exp = (string)($in['expires'] ?? ($base['expires'] ?? ''));
    $row = $base + ['uses' => [], 'created' => date('Y-m-d H:i')];
    return [
        'id' => (string)$row['id'], 'brand' => ($in['brand'] ?? $base['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged', 'file' => (string)$row['file'], 'orig_name' => (string)($row['orig_name'] ?? ''),
        'credit' => mb_substr(trim((string)($in['credit'] ?? ($base['credit'] ?? ''))), 0, 80), 'consent' => !empty($in['consent'] ?? ($base['consent'] ?? false)),
        'consent_note' => mb_substr(trim((string)($in['consent_note'] ?? ($base['consent_note'] ?? ''))), 0, 200), 'owner' => mb_substr(trim((string)($in['owner'] ?? ($base['owner'] ?? ''))), 0, 80),
        'note' => mb_substr(trim((string)($in['note'] ?? ($base['note'] ?? ''))), 0, 300),
        'tags' => ['pillars' => array_values(array_filter(array_map(fn($p) => mb_substr(trim((string)$p), 0, 40), array_slice((array)$pillars, 0, 6)))),
            'place' => mb_substr(trim((string)($in['tags']['place'] ?? ($base['tags']['place'] ?? ''))), 0, 80)],
        'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : '', 'created' => (string)$row['created'], 'uses' => array_values((array)$row['uses']),
        'w' => (int)($row['w'] ?? 0), 'h' => (int)($row['h'] ?? 0),
    ];
}

/** Inserts or updates a row (an unknown id needs a 'file' that exists in data/social/assets/). Returns the stored row, or [] when refused. */
function pm_social_asset_save(array $row): array
{
    $saved = [];
    pm_update('social_assets', function (array $rows) use ($row, &$saved) {
        $rows = array_values($rows);
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === ($row['id'] ?? null)) {
                $rows[$i] = $saved = pm_sx_asset_clean($row, $r); // file, name, uses, size and date stay as they were
                return $rows;
            }
        }
        $id = (string)($row['id'] ?? '');
        $file = (string)($row['file'] ?? '');
        if (!preg_match('/^[a-f0-9]{12}$/', $id) || $file !== $id . '.jpg' || !is_file(pm_sx_assets_dir() . '/' . $file)) {
            return $rows;
        }
        $rows[] = $saved = pm_sx_asset_clean($row, ['id' => $id, 'file' => $file, 'orig_name' => mb_substr(basename((string)($row['orig_name'] ?? '')), 0, 120),
            'w' => (int)($row['w'] ?? 0), 'h' => (int)($row['h'] ?? 0), 'created' => (string)($row['created'] ?? date('Y-m-d H:i'))]);
        return $rows;
    }, fn() => []);
    return $saved;
}

/** Deletes a row and the picture file this app created. Refuses (false) while a waiting post still uses it. */
function pm_social_asset_delete(string $id): bool
{
    $a = pm_social_asset_find($id);
    if (!$a) {
        return false;
    }
    if (function_exists('pm_social_posts')) {
        foreach (pm_social_posts() as $p) {
            if (($p['asset_id'] ?? '') === $id && in_array($p['status'] ?? '', PM_SX_LIVE_STATUSES, true)) {
                return false;
            }
        }
    }
    pm_update('social_assets', fn(array $rows) => array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id)), fn() => []);
    $f = pm_social_asset_path($a);
    if ($f !== '' && preg_match('/^[a-f0-9]{12}\.jpg$/', basename($f))) {
        @unlink($f);
    }
    return true;
}

/** Notes that a post used a picture (so the least used one is chosen next time). */
function pm_social_asset_use(string $id, string $postId): void
{
    if ($id === '' || $postId === '') {
        return;
    }
    pm_update('social_assets', function (array $rows) use ($id, $postId) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id && !in_array($postId, (array)($r['uses'] ?? []), true)) {
                $rows[$i]['uses'] = array_slice(array_merge((array)($r['uses'] ?? []), [$postId]), -50);
            }
        }
        return $rows;
    }, fn() => []);
}

/**
 * Turns an uploaded picture into a library row. $tmp is the uploaded file (the caller has checked it really was an upload).
 * Returns [row, ''] or [null, why].
 */
function pm_social_asset_ingest(string $tmp, string $origName, string $brand, array $meta = []): array
{
    if (!is_file($tmp) || !function_exists('imagecreatetruecolor')) {
        return [null, 'The picture could not be read.'];
    }
    if (filesize($tmp) > PM_SX_ASSET_MAX_BYTES) {
        return [null, 'The picture is over 15 MB. Use a smaller one.'];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return [null, 'Use a JPG or PNG picture.'];
    }
    $info = @getimagesize($tmp);
    if (!$info || $info[0] < 200 || $info[1] < 200 || $info[0] * $info[1] > 60000000) {
        return [null, 'The picture is too small (under 200 pixels) or too large to process.'];
    }
    @ini_set('memory_limit', '512M');
    $im = $mime === 'image/png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
    if (!$im) {
        return [null, 'The picture could not be opened. Is the file damaged?'];
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) { // phones store the turn in EXIF
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg !== 0 && ($r = imagerotate($im, $deg, 0))) {
            $im = $r;
        }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $k = min(1, PM_SX_ASSET_MAX_PX / max($w, $h));
    $nw = max(1, (int)round($w * $k));
    $nh = max(1, (int)round($h * $k));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNGs get a white background
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $id = bin2hex(random_bytes(6));
    $file = $id . '.jpg';
    if (!imagejpeg($out, pm_sx_assets_dir() . '/' . $file, 85)) {
        return [null, 'The picture could not be saved.'];
    }
    $row = pm_social_asset_save($meta + ['id' => $id, 'brand' => $brand, 'file' => $file, 'orig_name' => $origName, 'w' => $nw, 'h' => $nh, 'created' => date('Y-m-d H:i')]);
    return $row ? [$row, ''] : [null, 'The picture could not be recorded.'];
}

/* ---------------- screens and handlers ---------------- */

/** Plan screen: posts waiting for a photo and photos about to expire. */
function pm_panel_plan_top_assets(string $vb, array $ctx = []): string
{
    $wait = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $vb && ($p['status'] ?? '') === 'needs_asset'));
    $soon = date('Y-m-d', strtotime('+14 days'));
    $exp = array_values(array_filter(pm_sx_assets_all(), fn($a) => ($a['brand'] ?? '') === $vb && ($a['expires'] ?? '') !== '' && $a['expires'] <= $soon));
    if (!$wait && !$exp) {
        return '';
    }
    $h = '<div class="card"><h2>Photos</h2>';
    foreach ($wait as $p) {
        $h .= '<p>Waiting for a photo: <b>' . pm_h($p['headline'] ?: mb_substr((string)$p['caption'], 0, 60)) . '</b> <span class="hint">' . pm_h((string)($p['pillar'] ?? '')) . ' · ' . pm_h(substr((string)$p['when'], 0, 10)) . '</span></p>';
    }
    foreach ($exp as $a) {
        $gone = $a['expires'] < date('Y-m-d');
        $h .= '<p>' . ($gone ? 'Photo no longer usable (expired ' : 'Photo permission ends ') . pm_h($a['expires']) . '): ' . pm_h(($a['credit'] ?? '') ?: ($a['orig_name'] ?? 'photo')) . '</p>';
    }
    return $h . '<a class="btn small primary" href="?tab=social&amp;view=content#photos">Open the photo library</a></div>';
}

function pm_today_assets(string $brand): array
{
    $out = [];
    $n = count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && ($p['status'] ?? '') === 'needs_asset'));
    if ($n > 0) {
        $out[] = ['text' => $n . ($n === 1 ? ' post is' : ' posts are') . ' waiting for a photo', 'href' => '?tab=social&view=content#photos', 'urgency' => 1];
    }
    $soon = date('Y-m-d', strtotime('+7 days'));
    $e = count(array_filter(pm_sx_assets_all(), fn($a) => ($a['brand'] ?? '') === $brand && ($a['expires'] ?? '') !== '' && $a['expires'] >= date('Y-m-d') && $a['expires'] <= $soon));
    if ($e > 0) {
        $out[] = ['text' => $e . ' photo permission' . ($e === 1 ? '' : 's') . ' end within a week', 'href' => '?tab=social&view=content#photos', 'urgency' => 0];
    }
    return $out;
}

function pm_sx_upload_ok(string $tmp): bool { return getenv('PM_TEST') ? is_file($tmp) : is_uploaded_file($tmp); }

function pm_sx_asset_meta_from_post(): array
{
    return ['credit' => (string)($_POST['credit'] ?? ''), 'consent' => !empty($_POST['consent']), 'consent_note' => (string)($_POST['consent_note'] ?? ''), 'owner' => (string)($_POST['owner'] ?? ''),
        'note' => (string)($_POST['note'] ?? ''), 'expires' => (string)($_POST['expires'] ?? ''), 'tags' => ['pillars' => (string)($_POST['pillars'] ?? ''), 'place' => (string)($_POST['place'] ?? '')]];
}

/** After a new picture: posts that were waiting for a photo and fit it get it and go back to draft. */
function pm_sx_attach_waiting(array $asset): int
{
    $n = 0;
    if (empty($asset['consent'])) {
        return 0;
    }
    $brand = $asset['brand'];
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? '') !== $brand || ($p['status'] ?? '') !== 'needs_asset') {
            continue;
        }
        if (!pm_sx_asset_fits($asset, ['pillar' => (string)($p['pillar'] ?? ''), 'place' => (string)($p['place'] ?? ''), 'owner' => (string)($p['host'] ?? '')])) {
            continue;
        }
        pm_social_patch((string)$p['id'], function (array $q) use ($asset) {
            if (($q['status'] ?? '') === 'needs_asset') {
                $q['asset_id'] = $asset['id'];
                $q['layout'] = 'photo';
                $q['status'] = 'draft';
            }
            return $q;
        });
        pm_social_asset_use($asset['id'], (string)$p['id']);
        $n++;
        $asset = pm_social_asset_find($asset['id']) ?? $asset;
        break; // one picture serves one waiting post
    }
    return $n;
}

function pm_do_asset_upload(string $vb): array
{
    $to = 'social&view=content#photos';
    $f = $_FILES['photo'] ?? [];
    if (empty($f['tmp_name']) || (int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $err = (int)($f['error'] ?? 4);
        $why = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The picture is bigger than the server allows (upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . ').' : 'Choose a picture first.';
        return ['msg' => $why, 'kind' => 'err', 'to' => $to];
    }
    if (!pm_sx_upload_ok((string)$f['tmp_name'])) {
        return ['msg' => 'That was not an uploaded file.', 'kind' => 'err', 'to' => $to];
    }
    [$row, $why] = pm_social_asset_ingest((string)$f['tmp_name'], (string)($f['name'] ?? ''), $vb, pm_sx_asset_meta_from_post());
    if (!$row) {
        return ['msg' => $why, 'kind' => 'err', 'to' => $to];
    }
    $att = pm_sx_attach_waiting($row);
    return ['msg' => 'Photo saved' . (empty($row['consent']) ? ' but it will not be used until you tick that you have permission.' : '.') . ($att ? " It went onto a post that was waiting for a photo." : ''), 'kind' => 'ok', 'to' => $to];
}

function pm_do_asset_update(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    $a = pm_social_asset_find($id);
    if (!$a || $a['brand'] !== $vb) {
        return ['msg' => 'Photo not found.', 'kind' => 'err', 'to' => 'social&view=content#photos'];
    }
    $row = pm_social_asset_save(['id' => $id] + pm_sx_asset_meta_from_post());
    $att = $row ? pm_sx_attach_waiting($row) : 0;
    return ['msg' => 'Photo details saved.' . ($att ? ' It went onto a post that was waiting for a photo.' : ''), 'kind' => 'ok', 'to' => 'social&view=content#photos'];
}

function pm_do_asset_delete(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    $a = pm_social_asset_find($id);
    if (!$a || $a['brand'] !== $vb) {
        return ['msg' => 'Photo not found.', 'kind' => 'err', 'to' => 'social&view=content#photos'];
    }
    return pm_social_asset_delete($id)
        ? ['msg' => 'Photo deleted.', 'kind' => 'ok', 'to' => 'social&view=content#photos']
        : ['msg' => 'A post that has not gone out yet still uses this photo. Delete or change that post first.', 'kind' => 'err', 'to' => 'social&view=content#photos'];
}
