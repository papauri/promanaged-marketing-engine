<?php
/**
 * Social screen actions (index.php: if ($action==='social'||$action==='social_x') { require .../social_actions.php; pm_social_action($vb,$action); }).
 * Every change to posts goes through pm_social_update / pm_social_patch (locked read-modify-write): no load-all/save-all.
 * Needs pm_redirect() from index.php.
 */

/** Stores an uploaded picture or video for a post. Returns [path, error]. */
function pm_social_store_upload(string $field, string $id, bool $videoOnly = false): array
{
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return ['', ''];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $ext = ($videoOnly ? ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'] : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'])[$mime] ?? '';
    if ($ext === '' || $_FILES[$field]['size'] > 200 * 1024 * 1024) {
        return ['', $videoOnly ? 'Use an MP4 or MOV video under 200 MB.' : 'Use a PNG or JPG picture, or an MP4 or MOV video under 200 MB.'];
    }
    $dest = pm_social_dir() . '/' . $id . '_own.' . $ext;
    foreach (glob(pm_social_dir() . '/' . $id . '_own.*') ?: [] as $old) {
        @unlink($old);
    }
    move_uploaded_file($_FILES[$field]['tmp_name'], $dest);
    return [$dest, ''];
}

function pm_social_action(string $vb, string $action): never
{
    @set_time_limit(300);
    pm_brand_set($vb);
    $do = (string)($_POST['do'] ?? '');

    if ($action === 'social_x') { // pillars and proof bank, saved from Social > Accounts
        $back = fn($m, $k = 'ok') => pm_redirect('social&view=accounts', $m, $k);
        if ($do === 'pillars_save') {
            $rows = [];
            foreach ((array)($_POST['pillar_name'] ?? []) as $i => $n) {
                $rows[] = ['name' => (string)$n, 'weight' => (int)($_POST['pillar_weight'][$i] ?? 0)];
            }
            pm_social_pillars_save($vb, $rows);
            $back($rows ? 'Content pillars saved.' : 'Content pillars reset to the defaults.');
        }
        if ($do === 'pillars_reset') {
            pm_social_pillars_save($vb, []);
            $back('Content pillars reset to the defaults.');
        }
        if ($do === 'proof_save') {
            $rows = [];
            foreach ((array)($_POST['proof'] ?? []) as $r) {
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
            pm_social_proof_save($vb, $rows);
            $back('Proof bank saved. Only items with consent ticked are ever used in posts.');
        }
        $back('Unknown action.', 'err');
    }

    $id = (string)($_POST['id'] ?? '');
    $sback = fn($m = '', $kind = 'ok') => pm_redirect('social' . ($id !== '' ? '#s' . $id : ''), $m, $kind);

    if ($do === 'splan') {
        try {
            $new = pm_agent_social_plan(max(1, min(10, (int)($_POST['n'] ?? 5))), (string)($_POST['focus'] ?? 'mix'), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['start'] ?? '')) ? $_POST['start'] : date('Y-m-d', strtotime('+1 day')));
        } catch (Throwable $e) {
            pm_redirect('social', 'Could not plan posts: ' . $e->getMessage(), 'err');
        }
        $n = pm_social_add_planned($new, pm_social_auto($vb));
        $held = count(array_filter($new, fn($p) => in_array($p['status'], ['needs_edit', 'needs_video'], true)));
        pm_redirect('social', "$n posts planned" . ($held ? " ($held need an edit or a video first)" : '') . '. Read them, adjust anything, then approve.');
    }
    if ($do === 'approve_all') {
        $ok = 0;
        $skipped = 0;
        $ids = array_column(array_filter(pm_social_posts(), fn($p) => $p['brand'] === $vb && $p['status'] === 'draft'), 'id');
        pm_social_update(function (array $posts) use ($ids, &$ok, &$skipped) {
            foreach ($posts as $i => $p) {
                if (in_array($p['id'], $ids, true) && $p['status'] === 'draft') {
                    [$np, , $good] = pm_social_approve($p);
                    $posts[$i] = $np;
                    $good ? $ok++ : $skipped++;
                }
            }
            return $posts;
        });
        pm_redirect('social', "$ok post(s) approved" . ($skipped ? ", $skipped held for an edit or a video" : '') . '.', $ok ? 'ok' : 'err');
    }
    if (in_array($do, ['draft_reply', 'reply'], true)) {
        $cid = (string)($_POST['cid'] ?? '');
        if ($do === 'draft_reply') {
            try {
                $_SESSION['sreply'][$cid] = pm_agent_social_reply(['post' => (string)$_POST['post'], 'from' => (string)$_POST['from'], 'message' => (string)$_POST['message']]);
            } catch (Throwable $e) {
                pm_redirect('social', 'Could not draft a reply: ' . $e->getMessage(), 'err');
            }
            pm_redirect('social', 'Reply drafted. Read it, then post it.');
        }
        $txt = trim((string)($_POST['reply'] ?? ''));
        $c = pm_social_cfg($vb);
        if ($txt === '' || !$c['ready']) {
            pm_redirect('social', $txt === '' ? 'Write a reply first.' : 'The Facebook Page is not connected.', 'err');
        }
        [$ok, $d] = pm_graph('POST', $cid . '/comments', ['message' => $txt], $c['token']);
        unset($_SESSION['sreply'][$cid]);
        pm_redirect('social', $ok ? 'Reply posted.' : 'Facebook refused the reply: ' . $d, $ok ? 'ok' : 'err');
    }

    $cur = null;
    foreach (pm_social_posts() as $p) {
        if ($p['id'] === $id && $p['brand'] === $vb) {
            $cur = $p;
        }
    }
    if ($cur === null) {
        pm_redirect('social', 'That post was not found.', 'err');
    }
    if ($do === 'delete') {
        pm_social_update(fn(array $posts) => array_filter($posts, fn($p) => $p['id'] !== $id));
        foreach (glob(pm_social_dir() . '/' . $id . '*') ?: [] as $f) {
            @unlink($f);
        }
        pm_redirect('social', 'Deleted.');
    }
    if ($do === 'attach_video') {
        [$dest, $err] = pm_social_store_upload('video', $id, true);
        if ($dest === '') {
            $sback($err ?: 'Choose a video file first.', 'err');
        }
        pm_social_patch($id, function (array $p) use ($dest) {
            $p['media'] = $dest;
            $p['format'] = 'reel';
            return pm_social_resolve($p, pm_social_lint_post($p));
        });
        $sback('Video attached. Read the caption, then approve.');
    }
    if ($do === 'retry_ig') {
        [$ok, $m] = pm_social_retry_ig($id);
        $sback($m, $ok ? 'ok' : 'err');
    }
    if ($do === 'mark_posted') { // owner checked the Page and it is there
        pm_social_patch($id, function (array $p) {
            if (in_array($p['status'], ['needs_check', 'failed'], true)) {
                $p['status'] = 'published';
                $p['published'] = date('Y-m-d H:i');
                $p['error'] = '';
            }
            return $p;
        });
        $sback('Marked as posted.');
    }
    if ($do === 'retry') { // owner checked the Page and it is not there
        pm_social_patch($id, function (array $p) {
            if (in_array($p['status'], ['needs_check', 'failed'], true)) {
                $p['status'] = 'approved';
                $p['when'] = date('Y-m-d H:i');
                $p['retry_at'] = '';
                $p['tries'] = 0;
                $p['error'] = '';
            }
            return $p;
        });
        $sback('Will be posted at the next run.');
    }

    // edits from the card are saved first, whatever the button
    if (isset($_POST['caption'])) {
        [$dest, $err] = pm_social_store_upload('media', $id);
        if ($err !== '') {
            $sback($err, 'err');
        }
        pm_social_patch($id, function (array $p) use ($dest) {
            if (in_array($p['status'], ['publishing', 'published'], true)) {
                return $p; // already out: the card is read-only
            }
            $p['caption'] = trim((string)$_POST['caption']);
            $p['hashtags'] = array_values(array_filter(array_map(fn($h) => '#' . ltrim($h, '#'), preg_split('/[\s,]+/', (string)($_POST['hashtags'] ?? '')))));
            $p['headline'] = mb_substr(trim((string)($_POST['headline'] ?? $p['headline'])), 0, 60);
            $p['sub'] = mb_substr(trim((string)($_POST['sub'] ?? $p['sub'])), 0, 90);
            $p['script'] = trim((string)($_POST['script'] ?? $p['script']));
            $w = strtotime((string)($_POST['when'] ?? ''));
            if ($w) {
                $p['when'] = date('Y-m-d H:i', $w);
            }
            if ($dest !== '') {
                $p['media'] = $dest;
                if (pm_social_is_video($dest) && $p['format'] === 'text') {
                    $p['format'] = 'reel';
                }
            }
            return pm_social_resolve($p, pm_social_lint_post($p));
        });
    }
    $msg = 'Saved.';
    $kind = 'ok';
    if ($do === 'approve') {
        pm_social_patch($id, function (array $p) use (&$msg, &$kind) {
            [$np, $msg, $good] = pm_social_approve($p);
            $kind = $good ? 'ok' : 'err';
            return $np;
        });
    } elseif ($do === 'unapprove') {
        pm_social_patch($id, function (array $p) {
            if ($p['status'] === 'approved') {
                $p['status'] = 'draft';
                $p['retry_at'] = '';
            }
            return $p;
        });
        $msg = 'Held back.';
    } elseif ($do === 'redraw') {
        pm_social_card($cur);
        $msg = 'Picture redrawn.';
    } elseif ($do === 'publish') {
        [$ok, $msg] = pm_social_run_one($id, true);
        $kind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['caption'])) {
        $after = array_values(array_filter(pm_social_posts(), fn($p) => $p['id'] === $id))[0] ?? [];
        if (($after['status'] ?? '') === 'needs_edit') {
            $msg = 'Saved, but it cannot be posted yet: ' . implode(' ', (array)($after['lint'] ?? []));
            $kind = 'err';
        }
    }
    $sback($msg, $kind);
}
