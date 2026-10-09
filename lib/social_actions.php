<?php
/**
 * Social screen actions (index.php: if ($action==='social'||$action==='social_x') { require .../social_actions.php; pm_social_action($vb,$action); }).
 * Every change to posts goes through pm_social_update / pm_social_patch (locked read-modify-write): no load-all/save-all.
 * Needs pm_redirect() from index.php.
 * Roles (Settings > Marketing team, "Name | editor" or "Name | approver"): only approvers approve, publish and delete; editors press "Request approval".
 */

/** "upload_max_filesize 2M, post_max_size 8M": what this server allows (shown when an upload is refused). */
function pm_social_upload_limits(): string
{
    return 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size');
}

/** Plain-English reason for a PHP upload error code ('' = no error / nothing uploaded). */
function pm_social_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE => '',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is bigger than this server accepts (' . pm_social_upload_limits() . '). Use a smaller file, or raise those two values in php.ini.',
        UPLOAD_ERR_PARTIAL => 'The upload stopped part-way. Try again.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not save the upload (temporary folder problem). Tell whoever looks after the hosting.',
        default => 'The upload failed (code ' . $code . ').',
    };
}

/**
 * Stores an uploaded picture or video for a post. Returns [path relative to data/social, error]. The new file is moved in first; only then are
 * older uploads of the post deleted, so a failed upload never loses the old picture.
 */
function pm_social_store_upload(string $field, string $id, bool $videoOnly = false): array
{
    if (!isset($_FILES[$field])) {
        return ['', ''];
    }
    if (($e = pm_social_upload_error((int)($_FILES[$field]['error'] ?? 0))) !== '') {
        return ['', $e];
    }
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return ['', ''];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $ext = ($videoOnly ? ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'] : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'])[$mime] ?? '';
    if ($ext === '' || $_FILES[$field]['size'] > 200 * 1024 * 1024) {
        return ['', $videoOnly ? 'Use an MP4 or MOV video under 200 MB.' : 'Use a PNG or JPG picture, or an MP4 or MOV video under 200 MB.'];
    }
    $name = $id . '_own_' . date('YmdHis') . '.' . $ext;
    $dest = pm_social_dir() . '/' . $name;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        return ['', 'The server could not save the file. Check that the data/social folder is writable.'];
    }
    foreach (glob(pm_social_dir() . '/' . $id . '_own*') ?: [] as $old) {
        if (basename($old) !== $name) {
            @unlink($old);
        }
    }
    return [$name, ''];
}

/** Ids from the form (ids[] or id) that belong to this brand. */
function pm_social_post_ids(string $vb): array
{
    $ids = array_filter(array_map('strval', (array)($_POST['ids'] ?? [])), fn($x) => preg_match('/^[a-f0-9]{20}$/', $x));
    if (!$ids && preg_match('/^[a-f0-9]{20}$/', (string)($_POST['id'] ?? ''))) {
        $ids = [(string)$_POST['id']];
    }
    $mine = array_column(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $vb), 'id');
    return array_values(array_intersect($ids, $mine));
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
        if ($do === 'ig_url_test') {
            [$ok, $m] = pm_social_ig_url_test($vb);
            $back('Instagram picture address: ' . $m, $ok ? 'ok' : 'err');
        }
        $back('Unknown action.', 'err');
    }

    $id = (string)($_POST['id'] ?? '');
    $sback = fn($m = '', $kind = 'ok') => pm_redirect('social' . ($id !== '' ? '#s' . $id : ''), $m, $kind);

    if (in_array($do, ['approve', 'approve_all', 'approve_selected', 'publish', 'delete', 'request_changes'], true) && !pm_social_can_approve()) {
        pm_redirect('social', 'Only an approver can do that. Press "Request approval" and an approver will check the post.', 'err');
    }

    if ($do === 'splan') {
        try {
            $new = pm_agent_social_plan(max(1, min(10, (int)($_POST['n'] ?? 5))), (string)($_POST['focus'] ?? 'mix'), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['start'] ?? '')) ? $_POST['start'] : date('Y-m-d', strtotime('+1 day')));
        } catch (Throwable $e) {
            pm_redirect('social', 'Could not plan posts: ' . $e->getMessage(), 'err');
        }
        $n = pm_social_add_planned($new, pm_social_auto($vb));
        $held = count(array_filter($new, fn($p) => in_array($p['status'], ['needs_edit', 'needs_video', 'needs_asset'], true)));
        pm_redirect('social', "$n posts planned" . ($held ? " ($held need an edit, a video or a photo first)" : '') . '. Read them, adjust anything, then approve.');
    }
    if ($do === 'approve_all' || $do === 'approve_selected') {
        $ok = 0;
        $skipped = 0;
        $mine = array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $vb && in_array($p['status'], ['draft', 'review'], true));
        $ids = $do === 'approve_selected' ? array_values(array_intersect(array_column($mine, 'id'), pm_social_post_ids($vb))) : array_column($mine, 'id');
        if (!$ids) {
            pm_redirect('social', $do === 'approve_selected' ? 'Tick the posts to approve first.' : 'Nothing to approve.', 'err');
        }
        $cards = []; // draw the pictures BEFORE taking the posts lock: drawing is slow and would block everyone else
        foreach ($mine as $p) {
            if (in_array($p['id'], $ids, true)) {
                $cards[$p['id']] = pm_social_prepare_card($p);
            }
        }
        pm_social_update(function (array $posts) use ($ids, $cards, &$ok, &$skipped) {
            foreach ($posts as $i => $p) {
                if (in_array($p['id'], $ids, true) && in_array($p['status'], ['draft', 'review'], true)) {
                    [$np, , $good] = pm_social_approve($p, $cards[$p['id']] ?? null);
                    $posts[$i] = $np;
                    $good ? $ok++ : $skipped++;
                }
            }
            return $posts;
        });
        pm_redirect('social', "$ok post(s) approved" . ($skipped ? ", $skipped held for an edit, a video or a picture" : '') . '.', $ok ? 'ok' : 'err');
    }
    if ($do === 'hold') {
        $n = 0;
        $ids = pm_social_post_ids($vb);
        pm_social_update(function (array $posts) use ($ids, &$n) {
            foreach ($posts as $i => $p) {
                if (in_array($p['id'], $ids, true) && in_array($p['status'], ['approved', 'review'], true)) {
                    $posts[$i]['status'] = 'draft';
                    $posts[$i]['retry_at'] = '';
                    $posts[$i]['approved_hash'] = '';
                    $posts[$i] = pm_social_log_add($posts[$i], 'hold');
                    $n++;
                }
            }
            return $posts;
        });
        pm_redirect('social' . ($id !== '' && count($ids) === 1 ? '#s' . $id : ''), $n ? "$n post(s) held back." : 'Nothing to hold back.', $n ? 'ok' : 'err');
    }
    if ($do === 'delete' && !isset($_POST['caption'])) {
        $ids = pm_social_post_ids($vb);
        if (!$ids) {
            pm_redirect('social', 'Tick the posts to delete first.', 'err');
        }
        pm_social_update(fn(array $posts) => array_filter($posts, fn($p) => !in_array($p['id'], $ids, true)));
        foreach ($ids as $x) {
            foreach (glob(pm_social_dir() . '/' . $x . '*') ?: [] as $f) {
                @unlink($f);
            }
        }
        pm_redirect('social', count($ids) === 1 ? 'Deleted.' : count($ids) . ' posts deleted.');
    }
    if (in_array($do, ['draft_reply', 'reply'], true)) {
        $cid = (string)($_POST['cid'] ?? '');
        if (!preg_match('/^\d+(_\d+)?$/', $cid)) {
            pm_redirect('social', 'That comment id is not valid.', 'err');
        }
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
        if (function_exists('pm_reply_lint') && ($bad = (array)pm_reply_lint($txt, $vb))) {
            pm_redirect('social', 'Reply not posted: ' . implode(' ', array_map('strval', $bad)), 'err');
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
    if ($do === 'delete') { // delete from a card form that also carries edits
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
            return pm_social_log_add(pm_social_resolve($p, pm_social_lint_post($p)), 'video_attached');
        });
        $sback('Video attached. Read the caption, then approve.');
    }
    if ($do === 'retry_ig') {
        [$ok, $m] = pm_social_retry_ig($id);
        $sback($m, $ok ? 'ok' : 'err');
    }
    if ($do === 'ig_done') { // owner looked at Instagram: it is there
        pm_social_patch($id, function (array $p) {
            if (($p['ig_post'] ?? '') === '' && !str_starts_with((string)($p['ig'] ?? ''), 'published')) {
                $p['ig'] = 'published';
                $p['ig_container'] = '';
            }
            return pm_social_log_add($p, 'instagram_confirmed');
        });
        $sback('Marked as posted on Instagram.');
    }
    if ($do === 'mark_posted') { // owner checked the Page and it is there; a pasted post link saves its id
        $url = trim((string)($_POST['post_url'] ?? ''));
        $pa = ['url' => '', 'fb_id' => '', 'video_id' => ''];
        if ($url !== '') {
            $pa = pm_social_parse_post_url($url, pm_social_cfg($vb)['page_id']);
            if ($pa['url'] === '') {
                $sback('That does not look like a link to a Facebook post. Paste the address from the post, or leave it empty.', 'err');
            }
        }
        pm_social_patch($id, function (array $p) use ($pa) {
            if (in_array($p['status'], ['needs_check', 'failed'], true)) {
                $p['status'] = 'published';
                $p['published'] = date('Y-m-d H:i');
                $p['error'] = '';
                if ($pa['url'] !== '') {
                    $p['fb_url'] = $pa['url'];
                }
                if ($pa['fb_id'] !== '' && ($p['fb_id'] ?? '') === '') {
                    $p['fb_id'] = $pa['fb_id'];
                }
                if ($pa['video_id'] !== '') {
                    $p['fb_video_id'] = $pa['video_id'];
                    if (($p['fb_id'] ?? '') === '' && function_exists('pm_social_resolve_fb_id')) {
                        try {
                            $r = pm_social_resolve_fb_id($p['brand'], $p);
                            if (is_string($r) && $r !== '') {
                                $p['fb_id'] = $r;
                            }
                        } catch (Throwable) {
                        }
                    }
                }
                $p = pm_social_log_add($p, 'mark_posted', $pa['url']);
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
                $p = pm_social_log_add($p, 'retry');
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
            if (in_array($p['status'], ['publishing', 'published', 'expired'], true)) {
                return $p; // already out: the card is read-only
            }
            $p['caption'] = trim((string)$_POST['caption']);
            $p['hashtags'] = array_values(array_filter(array_map(fn($h) => '#' . ltrim($h, '#'), preg_split('/[\s,]+/', (string)($_POST['hashtags'] ?? '')))));
            $p['headline'] = mb_substr(trim((string)($_POST['headline'] ?? $p['headline'])), 0, 60);
            $p['sub'] = mb_substr(trim((string)($_POST['sub'] ?? $p['sub'])), 0, 90);
            $p['script'] = trim((string)($_POST['script'] ?? $p['script']));
            if (isset($_POST['alt'])) {
                $p['alt'] = mb_substr(trim((string)$_POST['alt']), 0, 400);
            }
            if (isset($_POST['first_comment'])) {
                $p['first_comment'] = mb_substr(trim((string)$_POST['first_comment']), 0, 800);
            }
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
            return pm_social_log_add(pm_social_resolve($p, pm_social_lint_post($p)), 'edited');
        });
    }
    $msg = 'Saved.';
    $kind = 'ok';
    if ($do === 'approve') {
        $card = pm_social_prepare_card($cur); // drawn before the lock is taken
        pm_social_patch($id, function (array $p) use (&$msg, &$kind, $card) {
            [$np, $msg, $good] = pm_social_approve($p, $card);
            $kind = $good ? 'ok' : 'err';
            return $np;
        });
    } elseif ($do === 'request_approval') { // an editor asks an approver to check it
        pm_social_patch($id, function (array $p) use (&$msg, &$kind) {
            if (in_array($p['status'], ['draft', 'needs_edit'], true)) {
                $p = pm_social_resolve($p, pm_social_lint_post($p));
                if ($p['status'] === 'draft') {
                    $p['status'] = 'review';
                    $p['review_note'] = '';
                    $msg = 'Sent for approval. An approver will check it.';
                    return pm_social_log_add($p, 'request_approval');
                }
            }
            $msg = 'It cannot be sent yet: ' . implode(' ', (array)($p['lint'] ?? [])) . ($p['status'] === 'needs_video' ? ' It needs its video first.' : '');
            $kind = 'err';
            return $p;
        });
    } elseif ($do === 'request_changes') {
        $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        if ($note === '') {
            $sback('Write what should change.', 'err');
        }
        pm_social_patch($id, function (array $p) use ($note) {
            if (in_array($p['status'], ['review', 'draft', 'approved'], true)) {
                $p['status'] = 'needs_edit';
                $p['review_note'] = $note;
                $p['approved_hash'] = '';
                $p = pm_social_log_add($p, 'request_changes', $note);
            }
            return $p;
        });
        $msg = 'Changes requested. The note shows on the post.';
    } elseif ($do === 'unapprove') {
        pm_social_patch($id, function (array $p) {
            if (in_array($p['status'], ['approved', 'review'], true)) {
                $p['status'] = 'draft';
                $p['retry_at'] = '';
                $p['approved_hash'] = '';
                $p = pm_social_log_add($p, 'hold');
            }
            return $p;
        });
        $msg = 'Held back.';
    } elseif ($do === 'redraw') {
        $f = pm_social_card($cur);
        if ($f === '') {
            $msg = 'Picture could not be drawn (check fonts). See Social > Accounts for the font and GD check.';
            $kind = 'err';
        } else {
            $msg = 'Picture redrawn.';
            pm_social_log($id, 'redraw');
        }
    } elseif ($do === 'publish') {
        [$ok, $msg] = pm_social_run_one($id, true);
        $kind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['caption'])) {
        $after = array_values(array_filter(pm_social_posts(), fn($p) => $p['id'] === $id))[0] ?? [];
        if (($after['status'] ?? '') === 'needs_edit') {
            $msg = 'Saved, but it cannot be posted yet: ' . implode(' ', (array)($after['lint'] ?? []));
            $kind = 'err';
        } elseif (($after['note'] ?? '') === 'edited after approval') {
            $msg = 'Saved. You changed an approved post, so it is back to a draft: approve it again.';
        }
    }
    $sback($msg, $kind);
}
