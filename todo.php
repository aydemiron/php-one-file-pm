<?php
session_start();

// ── DB Setup ─────────────────────────────────────────────────
$db = new PDO('sqlite:' . __DIR__ . '/todo.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");

$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT NULL,
    title TEXT NOT NULL,
    done INTEGER DEFAULT 0,
    priority INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT DEFAULT '',
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
)");

foreach (['priority','sort_order'] as $col)
    try { $db->exec("ALTER TABLE tasks ADD COLUMN $col INTEGER DEFAULT " . ($col==='priority'?1:0)); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE notes ADD COLUMN sort_order INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE tasks ADD COLUMN type TEXT DEFAULT 'task'"); } catch(Exception $e) {}

// Default şifreyi yükle (ilk açılış)
$pwRow = $db->query("SELECT value FROM settings WHERE key='password_hash'")->fetch();
if (!$pwRow) {
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO settings(key,value)VALUES('password_hash',?)")->execute([$hash]);
    $pwRow = ['value' => $hash];
}
$passwordHash = $pwRow['value'];

// ── Helpers ──────────────────────────────────────────────────
function pid($v) { return ($v===''||$v===null||$v==='null') ? null : (int)$v; }

function cascadeToggle($db,$id,$done) {
    $db->prepare("UPDATE tasks SET done=? WHERE id=?")->execute([$done,$id]);
    $ch=$db->prepare("SELECT id FROM tasks WHERE parent_id=?"); $ch->execute([$id]);
    foreach($ch->fetchAll(PDO::FETCH_COLUMN) as $cid) cascadeToggle($db,$cid,$done);
}
function cascadeDelete($db,$id) {
    $ch=$db->prepare("SELECT id FROM tasks WHERE parent_id=?"); $ch->execute([$id]);
    foreach($ch->fetchAll(PDO::FETCH_COLUMN) as $cid) cascadeDelete($db,$cid);
    $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
}

// ── Auth Actions (session gerektirmez) ───────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tab    = $_GET['tab'] ?? 'tasks';
$loginError = null;

if ($action === 'login') {
    $pw = $_POST['password'] ?? '';
    if (password_verify($pw, $passwordHash)) {
        $_SESSION['todo_auth'] = true;
        header("Location: todo.php?tab=$tab"); exit;
    }
    $loginError = 'Şifre yanlış.';
}
if ($action === 'logout') {
    session_destroy();
    header("Location: todo.php"); exit;
}
if ($action === 'change_password') {
    $old  = $_POST['old_pw']  ?? '';
    $new1 = $_POST['new_pw1'] ?? '';
    $new2 = $_POST['new_pw2'] ?? '';
    if (!password_verify($old, $passwordHash)) {
        $changePwError = 'Mevcut şifre yanlış.';
    } elseif (strlen($new1) < 4) {
        $changePwError = 'Yeni şifre en az 4 karakter olmalı.';
    } elseif ($new1 !== $new2) {
        $changePwError = 'Yeni şifreler eşleşmiyor.';
    } else {
        $newHash = password_hash($new1, PASSWORD_BCRYPT);
        $db->prepare("UPDATE settings SET value=? WHERE key='password_hash'")->execute([$newHash]);
        $changePwSuccess = true;
    }
    // AJAX yanıtı
    header('Content-Type: application/json');
    echo json_encode(isset($changePwSuccess)
        ? ['ok' => true]
        : ['ok' => false, 'msg' => $changePwError]);
    exit;
}

// ── Auth Guard ───────────────────────────────────────────────
$isAuth = !empty($_SESSION['todo_auth']);

// Auth gerektiren AJAX/POST işlemleri için guard
if (!$isAuth && in_array($action, ['add_task','toggle_task','update_task','save_tree',
    'delete_task','add_note','save_note','delete_note','search'])) {
    http_response_code(403); echo 'Unauthorized'; exit;
}

if ($action === 'search') {
    $raw = trim($_GET['q'] ?? '');
    $q   = '%' . $raw . '%';
    $results = ['tasks' => [], 'notes' => []];

    if ($raw !== '') {
        // Tasks
        $st = $db->prepare("SELECT id, title, done, type, priority FROM tasks WHERE title LIKE ? ORDER BY sort_order ASC, id DESC LIMIT 30");
        $st->execute([$q]);
        $results['tasks'] = $st->fetchAll(PDO::FETCH_ASSOC);

        // Notes — başlık + eşleşen satır snippet'leri
        $st2 = $db->prepare("SELECT id, title, content FROM notes WHERE title LIKE ? OR content LIKE ? ORDER BY id DESC LIMIT 30");
        $st2->execute([$q, $q]);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $snippets = [];
            $lines = explode("\n", $note['content']);
            foreach ($lines as $lineNo => $line) {
                if (mb_stripos($line, $raw) !== false) {
                    $snippets[] = ['line' => $lineNo + 1, 'text' => trim($line)];
                    if (count($snippets) >= 3) break; // max 3 snippet per note
                }
            }
            $results['notes'][] = [
                'id'       => $note['id'],
                'title'    => $note['title'],
                'snippets' => $snippets,
                'titleMatch' => mb_stripos($note['title'], $raw) !== false,
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}

if ($action === 'add_task') {
    $title = trim($_POST['title'] ?? '');
    $parentId = pid($_POST['parent_id'] ?? '');
    $prio = max(1,min(3,(int)($_POST['priority']??1)));
    $type = ($_POST['type']??'task') === 'heading' ? 'heading' : 'task';
    if ($title)
        $db->prepare("INSERT INTO tasks(parent_id,title,priority,sort_order,type)VALUES(?,?,?,0,?)")
           ->execute([$parentId,$title,$prio,$type]);
    header("Location: todo.php?tab=tasks"); exit;
}
if ($action === 'toggle_task') {
    cascadeToggle($db,(int)$_POST['id'],(int)$_POST['done']);
    echo 'ok'; exit;
}
if ($action === 'update_task') {
    $db->prepare("UPDATE tasks SET title=?,priority=? WHERE id=?")
       ->execute([trim($_POST['title']),max(1,min(3,(int)$_POST['priority'])),(int)$_POST['id']]);
    echo 'ok'; exit;
}
if ($action === 'save_tree') {
    $items = json_decode($_POST['items']??'[]',true);
    if (is_array($items))
        foreach($items as $it)
            $db->prepare("UPDATE tasks SET parent_id=?,sort_order=? WHERE id=?")
               ->execute([pid($it['parent_id']??''),(int)($it['order']??0),(int)$it['id']]);
    echo 'ok'; exit;
}
if ($action === 'delete_task') {
    cascadeDelete($db,(int)$_POST['id']);
    header("Location: todo.php?tab=tasks"); exit;
}
if ($action === 'add_note') {
    $title = trim($_POST['title'] ?? '');
    $newId = null;
    if ($title) {
        $db->exec("UPDATE notes SET sort_order = sort_order + 1");
        $db->prepare("INSERT INTO notes(title,content,sort_order)VALUES(?,?,0)")->execute([$title,'']);
        $newId = $db->lastInsertId();
    }
    header("Location: todo.php?tab=notes" . ($newId ? "&note=$newId" : '')); exit;
}
if ($action === 'save_note') {
    $db->prepare("UPDATE notes SET title=?,content=? WHERE id=?")
       ->execute([trim($_POST['title']??''),$_POST['content']??'',(int)$_POST['id']]);
    echo 'ok'; exit;
}
if ($action === 'delete_note') {
    $db->prepare("DELETE FROM notes WHERE id=?")->execute([(int)$_POST['id']]);
    header("Location: todo.php?tab=notes"); exit;
}
if ($action === 'reorder_notes') {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (is_array($ids)) {
        foreach ($ids as $order => $id) {
            $db->prepare("UPDATE notes SET sort_order=? WHERE id=?")->execute([(int)$order, (int)$id]);
        }
    }
    echo 'ok'; exit;
}

// ── Data ─────────────────────────────────────────────────────
$tasks = $db->query("SELECT * FROM tasks ORDER BY sort_order ASC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
$notes = $db->query("SELECT * FROM notes ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$onlyTasks   = array_filter($tasks, fn($t) => ($t['type']??'task') === 'task');
$taskTotal   = count($onlyTasks);
$taskDone    = count(array_filter($onlyTasks, fn($t) => $t['done']));
$taskPending = $taskTotal - $taskDone;

$selectedNote = null;
if (isset($_GET['note'])) {
    $s=$db->prepare("SELECT * FROM notes WHERE id=?"); $s->execute([(int)$_GET['note']]);
    $selectedNote=$s->fetch(PDO::FETCH_ASSOC);
}

// Build recursive tree JSON for sortable-tree
function toTree($items,$parent=null) {
    $out=[];
    foreach($items as $it) {
        $ip=$it['parent_id']===null?null:(int)$it['parent_id'];
        if($ip===$parent)
            $out[]=['data'=>$it,'nodes'=>toTree($items,(int)$it['id'])];
    }
    return $out;
}
$taskJson = json_encode(toTree($tasks),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Todo</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortable-tree@0.5.1/dist/sortable-tree.css">
<script src="https://cdn.jsdelivr.net/npm/sortable-tree@0.5.1/dist/sortable-tree.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<style>
/* ── Linear-style Dark Theme ──
   Kaynak: https://linear.app renk paleti
   bg:       #08090A  (en koyu, body)
   surface:  #111113  (panel bg)
   elevated: #1A1C1F  (card/input bg)
   hover:    #1F2125  (hover state)
   border:   #282A2F  (subtle border)
   border2:  #3A3D45  (active border)
   text:     #E2E4E9  (primary text)
   dim:      #8A8F98  (secondary text)
   muted:    #4B5060  (placeholder/disabled)
   accent:   #5E6AD2  (primary accent – Linear purple)
   blue:     #4EA7FC  (secondary accent)
   green:    #4CB782  (success)
   yellow:   #E8A338  (warning)
   red:      #EB5757  (danger)
*/

/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif;
    background:#08090A;color:#E2E4E9;
    height:100vh;display:flex;flex-direction:column;overflow:hidden;
    font-size:16px;line-height:1.5;-webkit-font-smoothing:antialiased;
}

/* ── Tabs ── */
.tabs{
    display:flex;padding:0 12px;
    background:#111113;
    border-bottom:1px solid #282A2F;
    flex-shrink:0;gap:0;
    height:44px;align-items:stretch;
}
.tab-btn{
    padding:0 22px;border:none;
    border-bottom:2px solid transparent;
    cursor:pointer;font-size:16px;font-weight:500;
    background:transparent;color:#4B5060;
    transition:color .15s,border-color .15s;
    letter-spacing:.01em;
}
.tab-btn.active{color:#E2E4E9;border-bottom-color:#5E6AD2}
.tab-btn:hover:not(.active){color:#8A8F98}
.tab-actions{margin-left:auto;display:flex;align-items:center;gap:4px;padding:0 4px;height:100%}
.tab-action-btn{
    background:transparent;border:1px solid #282A2F;color:#4B5060;
    padding:6px 14px;border-radius:5px;font-size:14px;cursor:pointer;
    transition:all .15s;white-space:nowrap;
}
.tab-action-btn:hover{background:#1A1C1F;color:#8A8F98;border-color:#3A3D45}
.tab-action-danger:hover{color:#EB5757 !important;border-color:#EB575740 !important;background:#1F1010 !important}
.main{flex:1;overflow:hidden;display:flex;flex-direction:column}

/* ── Tasks Tab ── */
#tab-tasks{display:flex;flex-direction:column;height:100%;overflow:hidden}

.task-toolbar{
    display:flex;gap:6px;padding:8px 12px;
    background:#111113;border-bottom:1px solid #282A2F;
    flex-shrink:0;align-items:center;
}
.task-toolbar input[type=text]{
    flex:1;background:#1A1C1F;
    border:1px solid #282A2F;color:#E2E4E9;
    padding:7px 11px;border-radius:6px;font-size:16px;min-width:0;
    transition:border-color .15s,box-shadow .15s;
}
.task-toolbar input:focus{outline:none;border-color:#5E6AD2;box-shadow:0 0 0 2px rgba(94,106,210,.18)}
.task-toolbar input::placeholder{color:#4B5060}
.prio-pick{display:flex;gap:3px;flex-shrink:0}
.prio-pick button{
    width:22px;height:22px;border-radius:50%;
    border:2px solid transparent;cursor:pointer;
    padding:0;background:transparent;transition:all .15s;
    display:flex;align-items:center;justify-content:center;
}
.prio-pick button.active{border-color:currentColor;box-shadow:0 0 6px currentColor}
.prio-pick button span{width:9px;height:9px;border-radius:50%;display:block}
.btn-primary{
    background:#1A1C1F;border:1px solid #3A3D45;
    color:#8A8F98;padding:8px 16px;border-radius:6px;
    cursor:pointer;font-size:14px;font-weight:500;
    white-space:nowrap;transition:all .15s;flex-shrink:0;
}
.btn-primary:hover{background:#1F2125;border-color:#5E6AD2;color:#E2E4E9}

.filter-bar{
    padding:7px 12px;background:#111113;
    border-bottom:1px solid #1A1C1F;
    flex-shrink:0;display:flex;align-items:center;gap:8px;
}
.filter-bar input{
    flex:1;background:transparent;border:none;
    color:#8A8F98;padding:4px 0;font-size:14px;
}
.filter-bar input:focus{outline:none;color:#E2E4E9}
.filter-bar input::placeholder{color:#4B5060}
.filter-bar label{font-size:13px;color:#4B5060;flex-shrink:0}

.tasks-body{flex:1;overflow-y:auto;padding:8px 12px}

/* ── sortable-tree CSS vars ── */
#task-tree{
    --st-label-height:35px;
    --st-subnodes-padding-left:0px;
    --st-collapse-icon-height:35px;
    --st-collapse-icon-width:20px;
    --st-collapse-icon-size:10px;
}

/* ── Node label ── */
#task-tree .tn-label{
    background:transparent;
    border:1px solid transparent;
    border-radius:6px;
    margin:1px 0;
    padding:0 8px 0 4px;
    display:flex;align-items:center;gap:7px;
    min-height:35px;cursor:grab;
    transition:background .1s,border-color .1s;
    position:relative;
}
#task-tree .tn-label:hover{background:#1A1C1F;border-color:#282A2F}

/* Collapse toggle */
#task-tree .tn-collapse{
    color:#4B5060;font-size:10px;width:16px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;cursor:pointer;
}
#task-tree .tn-collapse:hover{color:#8A8F98}

/* Tree guide lines */
#task-tree .tn-subnodes{
    border-left:1px solid #282A2F;
    margin-left:12px;
    padding-left:12px;
}

/* ── Drag states ── */
#task-tree .tn-dragging > .tn-label{opacity:0.25;border-style:dashed}

#task-tree .tn-drop-before > .tn-label::before{
    content:'';position:absolute;
    top:-2px;left:0;right:0;height:2px;
    background:#5E6AD2;border-radius:2px;
}
#task-tree .tn-drop-after > .tn-label::after{
    content:'';position:absolute;
    bottom:-2px;left:0;right:0;height:2px;
    background:#5E6AD2;border-radius:2px;
}
#task-tree .tn-drop-inside > .tn-label{
    border:1px solid #5E6AD2 !important;
    background:#13152A !important;
}

/* ── Task label internals ── */
.t-cb{
    width:14px;height:14px;
    accent-color:#5E6AD2;
    cursor:pointer;flex-shrink:0;
}
.t-dot{
    width:8px;height:8px;border-radius:50%;
    flex-shrink:0;cursor:pointer;
    transition:transform .15s;opacity:.85;
}
.t-dot:hover{transform:scale(1.7);opacity:1}
.t-title{
    flex:1;font-size:14px;color:#C8CAD0;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    min-width:0;cursor:pointer;user-select:none;
}
.t-title.done{text-decoration:line-through;color:#4B5060}
.t-edit{
    display:none;flex:1;min-width:0;
    background:#1A1C1F;border:1px solid #5E6AD2;
    color:#E2E4E9;padding:3px 8px;border-radius:4px;font-size:13px;
}
.t-edit:focus{outline:none;box-shadow:0 0 0 2px rgba(94,106,210,.2)}

/* Action buttons */
.t-actions{display:flex;gap:2px;flex-shrink:0;opacity:0;transition:opacity .12s}
#task-tree .tn-label:hover .t-actions{opacity:1}
.t-btn{
    background:transparent;border:1px solid #282A2F;
    cursor:pointer;color:#4B5060;
    font-size:13px;padding:3px 8px;
    border-radius:4px;transition:all .1s;
    line-height:1.4;white-space:nowrap;
}
.t-btn:hover{background:#1F2125;color:#8A8F98;border-color:#3A3D45}
.t-btn.del:hover{color:#EB5757;background:#1F1010;border-color:#EB575740}

/* ── Filter ── */
.tn-filtered-hide{display:none !important}

/* ── Notes Tab ── */
#tab-notes{display:flex;height:100%;overflow:hidden}

.notes-sidebar{width:300px;background:#111113;border-right:1px solid #1A1C1F;display:flex;flex-direction:column;flex-shrink:0}
.notes-topbar{padding:8px;border-bottom:1px solid #1A1C1F}
.notes-topbar input{
    width:100%;background:#1A1C1F;border:1px solid #282A2F;
    color:#E2E4E9;padding:6px 10px;border-radius:5px;font-size:12px;
}
.notes-topbar input:focus{outline:none;border-color:#5E6AD2}
.notes-topbar input::placeholder{color:#4B5060}

/* ── Search Tab ── */
#tab-search{display:flex;flex-direction:column;height:100%;overflow:hidden}
.search-hero{
    display:flex;flex-direction:column;align-items:center;
    padding:40px 20px 20px;flex-shrink:0;
}
.search-hero h2{font-size:18px;color:#E2E4E9;font-weight:600;margin-bottom:20px;letter-spacing:-.01em}
.search-input-wrap{position:relative;width:100%;max-width:560px}
.search-input-wrap input{
    width:100%;background:#1A1C1F;border:1px solid #282A2F;color:#E2E4E9;
    padding:14px 48px 14px 18px;border-radius:8px;font-size:16px;outline:none;
    transition:border-color .15s,box-shadow .15s;
}
.search-input-wrap input:focus{border-color:#5E6AD2;box-shadow:0 0 0 3px rgba(94,106,210,.15)}
.search-input-wrap input::placeholder{color:#4B5060}
.search-inp-clear{
    position:absolute;right:13px;top:50%;transform:translateY(-50%);
    background:none;border:none;color:#4B5060;cursor:pointer;font-size:16px;
    display:none;line-height:1;padding:2px;
}
.search-inp-clear.visible{display:block}
.search-inp-clear:hover{color:#8A8F98}
.search-hint{font-size:11px;color:#4B5060;margin-top:8px}
.search-body{flex:1;overflow-y:auto;padding:0 20px 20px}
.search-results{max-width:560px;margin:0 auto}
.search-loader{
    display:none;width:18px;height:18px;border:2px solid #1A1C1F;
    border-top-color:#5E6AD2;border-radius:50%;
    animation:spin .6s linear infinite;margin:30px auto;
}
.search-loader.visible{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
.search-group{margin-bottom:20px}
.search-group-title{
    font-size:13px;color:#4B5060;text-transform:uppercase;
    letter-spacing:.06em;font-weight:600;
    padding:0 0 8px;border-bottom:1px solid #1A1C1F;margin-bottom:8px;
}
.search-item{
    display:flex;flex-direction:column;gap:3px;
    padding:9px 12px;border-radius:6px;cursor:pointer;
    border:1px solid transparent;margin-bottom:2px;
    transition:background .1s,border-color .1s;
}
.search-item:hover{background:#1A1C1F;border-color:#282A2F}
.search-item-title{font-size:16px;color:#E2E4E9;font-weight:500;display:flex;align-items:center;gap:7px}
.search-item-title .si-done{text-decoration:line-through;color:#4B5060}
.search-item-badge{
    font-size:10px;padding:1px 6px;border-radius:3px;font-weight:500;
    background:#13152A;color:#5E6AD2;border:1px solid #282A2F;flex-shrink:0;
}
.search-item-badge.heading{color:#E8A338;border-color:#E8A33830}
.search-snippet{
    font-size:16px;color:#4B5060;
    padding:2px 8px;border-left:2px solid #282A2F;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.search-snippet mark{background:transparent;color:#E8A338;font-weight:600}
.search-snippet-link{cursor:pointer;border-radius:3px;transition:background .1s,border-color .1s,color .1s}
.search-snippet-link:hover{background:rgba(94,106,210,.08);border-left-color:#5E6AD2;color:#8A8F98}
.search-snippet-line{color:#4B5060;font-weight:600;margin-right:6px;flex-shrink:0;font-size:14px}
.search-empty{text-align:center;padding:40px 0;color:#4B5060;font-size:13px}
.search-item.highlighted .tn-label{animation:highlight-pulse 1.5s ease}
@keyframes highlight-pulse{0%,100%{box-shadow:none}50%{box-shadow:0 0 0 3px rgba(94,106,210,.4)}}

.notes-topbar .btn-primary{width:100%;margin-top:5px;text-align:center;display:block;font-size:14px;padding:7px}
.notes-list{flex:1;overflow-y:auto;padding:4px}
.note-item{
    display:flex;align-items:center;gap:4px;
    padding:6px 8px;border-radius:5px;cursor:pointer;
    transition:background .12s;margin:1px 0;border:1px solid transparent;
}
.note-item:hover{background:#1A1C1F}
.note-item.active{background:#1F2125;border-color:#282A2F}
.note-item-title{flex:1;font-size:13px;color:#8A8F98;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.note-item.active .note-item-title{color:#E2E4E9;font-weight:500}
.note-del{background:none;border:none;cursor:pointer;color:#4B5060;font-size:11px;padding:2px 5px;border-radius:3px;transition:all .1s;flex-shrink:0}
.note-del:hover{color:#EB5757;background:#1F1010}

.note-editor{flex:1;display:flex;flex-direction:column;overflow:hidden;background:#08090A}
.note-empty{flex:1;display:flex;align-items:center;justify-content:center;color:#282A2F;font-size:13px}
.note-header{display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid #1A1C1F;flex-shrink:0;background:#111113}
.note-title-inp{flex:1;background:transparent;border:1px solid transparent;color:#E2E4E9;padding:6px 10px;border-radius:5px;font-size:14px;font-weight:500}
.note-title-inp:focus{outline:none;background:#1A1C1F;border-color:#282A2F}
.note-toolbar{display:flex;gap:4px;flex-shrink:0}
.n-btn{background:transparent;border:1px solid #282A2F;color:#8A8F98;padding:5px 11px;border-radius:5px;cursor:pointer;font-size:12px;transition:all .15s}
.n-btn:hover{background:#1A1C1F;color:#E2E4E9;border-color:#3A3D45}
.note-body{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.cm-wrap{flex:1;overflow:hidden;display:flex;flex-direction:column;min-height:0}

/* ── EasyMDE dark theme ── */
.EasyMDEContainer{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.EasyMDEContainer .CodeMirror{
    flex:1;height:auto !important;
    background:#08090A !important;color:#C8CAD0;
    font-size:15px;font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif;
    line-height:1.75;border:none !important;padding:12px 16px;
}
.EasyMDEContainer .CodeMirror-scroll{padding:0;min-height:200px}
.EasyMDEContainer .CodeMirror-wrap{flex:1;overflow-y:auto}
.EasyMDEContainer .CodeMirror-cursor{border-left-color:#5E6AD2}
.EasyMDEContainer .CodeMirror-selected{background:#1F2A4A !important}

/* Editörde başlık font boyutlarını düzelt — sabit kalacak */
.EasyMDEContainer .CodeMirror .cm-header-1,
.EasyMDEContainer .CodeMirror .cm-header-2,
.EasyMDEContainer .CodeMirror .cm-header-3,
.EasyMDEContainer .CodeMirror .cm-header-4,
.EasyMDEContainer .CodeMirror .cm-header-5,
.EasyMDEContainer .CodeMirror .cm-header-6 { font-size:15px !important; line-height:1.75 !important; }

/* Syntax renkleri */
.EasyMDEContainer .CodeMirror .cm-header-1{ color:#7AA2F7 !important; font-weight:700 }
.EasyMDEContainer .CodeMirror .cm-header-2{ color:#7DCFFF !important; font-weight:700 }
.EasyMDEContainer .CodeMirror .cm-header-3{ color:#9ECE6A !important; font-weight:600 }
.EasyMDEContainer .CodeMirror .cm-strong  { color:#E2E4E9 !important; font-weight:700 }
.EasyMDEContainer .CodeMirror .cm-em      { color:#BB9AF7 !important; font-style:italic }
.EasyMDEContainer .CodeMirror .cm-strikethrough{ color:#4B5060 !important }
.EasyMDEContainer .CodeMirror .cm-link    { color:#5E6AD2 !important }
.EasyMDEContainer .CodeMirror .cm-url     { color:#4EA7FC !important }
.EasyMDEContainer .CodeMirror .cm-quote   { color:#4B5060 !important; font-style:italic }
.EasyMDEContainer .CodeMirror .cm-comment { color:#4EA7FC !important; background:#1A1C1F; border-radius:3px; padding:0 2px }
.EasyMDEContainer .CodeMirror .cm-formatting { color:#3A3D45 !important }
.EasyMDEContainer .CodeMirror .cm-variable-2{ color:#E8A338 !important }

.editor-toolbar{
    background:#111113 !important;border:none !important;
    border-bottom:1px solid #1A1C1F !important;
    padding:4px 8px !important;opacity:1 !important;
}
.editor-toolbar button{
    color:#8A8F98 !important;border:none !important;
    border-radius:5px !important;
    width:30px;height:28px;
    transition:background .12s,color .12s;
}
.editor-toolbar button:hover,.editor-toolbar button.active{
    background:#1A1C1F !important;color:#E2E4E9 !important;
}
.editor-toolbar i.separator{border-color:#282A2F !important}
.editor-statusbar{
    background:#111113 !important;color:#4B5060 !important;
    border-top:1px solid #1A1C1F !important;
    font-size:11px !important;padding:4px 10px !important;
}

/* Preview ortak stiller */
.editor-preview,
.editor-preview-side{
    background:#0D0E10 !important;color:#A0A4B0 !important;
    font-size:15px !important;line-height:1.85 !important;
    padding:20px 28px !important;overflow-y:auto !important;
    font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif !important;
}
.editor-preview h1,.editor-preview-side h1{color:#E2E4E9 !important;font-size:22px !important;border-bottom:1px solid #1A1C1F;padding-bottom:6px;margin:20px 0 10px}
.editor-preview h2,.editor-preview-side h2{color:#E2E4E9 !important;font-size:18px !important;margin:18px 0 8px}
.editor-preview h3,.editor-preview-side h3{color:#C8CAD0 !important;font-size:16px !important;margin:14px 0 6px}
.editor-preview p,.editor-preview-side p{margin:8px 0}
.editor-preview ul,.editor-preview ol,.editor-preview-side ul,.editor-preview-side ol{padding-left:22px;margin:8px 0}
.editor-preview li,.editor-preview-side li{margin:3px 0}
.editor-preview code,.editor-preview-side code{background:#1A1C1F !important;padding:2px 6px;border-radius:4px;font-size:13px;color:#4EA7FC;font-family:Consolas,monospace}
.editor-preview pre,.editor-preview-side pre{background:#282C34 !important;border:1px solid #3A3D45;border-radius:6px;padding:16px;margin:12px 0;overflow-x:auto}
.editor-preview pre code,.editor-preview-side pre code{background:none !important;color:#C8CAD0;font-size:15px;font-family:Consolas,'Courier New',monospace !important}
/* hljs override — kendi bg'sini kullanmasın */
.editor-preview pre .hljs,.editor-preview-side pre .hljs{background:transparent !important;padding:0 !important;font-size:15px;line-height:1.8;font-family:Consolas,'Courier New',monospace !important}
.editor-preview blockquote,.editor-preview-side blockquote{border-left:3px solid #5E6AD2;padding-left:14px;color:#4B5060;margin:10px 0;font-style:italic}
.editor-preview a,.editor-preview-side a{color:#5E6AD2}
.editor-preview strong,.editor-preview-side strong{color:#E2E4E9;font-weight:600}
.editor-preview em,.editor-preview-side em{color:#BB9AF7}
.editor-preview table,.editor-preview-side table{border-collapse:collapse;width:100%;margin:12px 0}
.editor-preview td,.editor-preview th,.editor-preview-side td,.editor-preview-side th{border:1px solid #282A2F;padding:7px 12px;font-size:14px}
.editor-preview th,.editor-preview-side th{background:#1A1C1F;color:#8A8F98;font-weight:600}
.editor-preview-side{border-left:1px solid #1A1C1F !important}

/* Side-by-side oranlar */
.CodeMirror-sided{width:50% !important}
.editor-preview-active-side{width:50% !important;display:block !important}
.cm-line-highlight{background:rgba(232,163,56,.12) !important}

/* ── Toolbar select ── */
.task-toolbar select{
    background:#1A1C1F;border:1px solid #282A2F;color:#8A8F98;
    padding:6px 10px;border-radius:6px;font-size:12px;cursor:pointer;
    flex-shrink:0;outline:none;
}
.task-toolbar select:focus{border-color:#5E6AD2}
.task-toolbar select option{background:#1A1C1F}

/* ── Heading row ── */
#task-tree .tn-label.is-heading{
    background:transparent;
    border-left:2px solid #5E6AD2 !important;
    border-radius:0 6px 6px 0;
    padding-left:6px;
}
#task-tree .tn-label.is-heading:hover{background:#0F1120}
#task-tree .tn-label.is-heading .t-title{
    color:#8A98D8;font-weight:600;font-size:14px;
    letter-spacing:.02em;text-transform:uppercase;
}
#task-tree .tn-label.is-heading .t-title.done{text-decoration:line-through;color:#4B5060}

/* ── Task Footer ── */
.task-footer{
    flex-shrink:0;display:flex;align-items:center;gap:16px;
    padding:10px 16px;background:#111113;border-top:1px solid #1A1C1F;
    font-size:13px;color:#4B5060;
}
.task-footer .tf-stat{display:flex;align-items:center;gap:5px}
.task-footer .tf-icon{font-size:14px}
.task-footer .tf-val{font-size:14px;font-weight:600}
.task-footer .tf-val.total{color:#8A8F98}
.task-footer .tf-val.pending{color:#E8A338}
.task-footer .tf-val.done{color:#4CB782}
.task-footer .tf-sep{color:#282A2F;margin:0 2px}

/* ── Toast ── */
#toast{
    position:fixed;bottom:16px;right:16px;
    background:#1A1C1F;border:1px solid #282A2F;
    color:#4CB782;padding:8px 16px;border-radius:6px;
    font-size:12px;opacity:0;transition:opacity .25s;pointer-events:none;z-index:999;
}
#toast.show{opacity:1}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#1F2125;border-radius:2px}
::-webkit-scrollbar-thumb:hover{background:#282A2F}

/* ── Login Page ── */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#08090A}
.login-card{
    background:#111113;border:1px solid #282A2F;border-radius:10px;
    padding:36px 40px;width:100%;max-width:340px;
    box-shadow:0 8px 40px rgba(0,0,0,.6);
}
.login-card h2{font-size:17px;font-weight:600;color:#E2E4E9;margin-bottom:6px;text-align:center}
.login-card p.sub{font-size:12px;color:#4B5060;text-align:center;margin-bottom:24px}
.login-field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.login-field label{font-size:10px;color:#8A8F98;text-transform:uppercase;letter-spacing:.06em;font-weight:500}
.login-field input{
    background:#1A1C1F;border:1px solid #282A2F;color:#E2E4E9;
    padding:9px 13px;border-radius:6px;font-size:13px;outline:none;
}
.login-field input:focus{border-color:#5E6AD2;box-shadow:0 0 0 2px rgba(94,106,210,.15)}
.login-btn{
    width:100%;padding:10px;border:none;border-radius:6px;
    background:#5E6AD2;color:#fff;font-size:13px;font-weight:600;
    cursor:pointer;transition:background .15s;margin-top:4px;
}
.login-btn:hover{background:#6B77DA}
.login-err{
    background:#1F1010;border:1px solid #EB575740;color:#EB5757;
    padding:8px 12px;border-radius:5px;font-size:12px;margin-bottom:12px;text-align:center;
}

/* ── Şifre Modal ── */
.pw-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center}
.pw-modal-bg.open{display:flex}
.pw-modal{
    background:#111113;border:1px solid #282A2F;border-radius:8px;
    padding:24px 28px;width:100%;max-width:320px;
    box-shadow:0 8px 40px rgba(0,0,0,.6);position:relative;
}
.pw-modal h3{font-size:14px;color:#E2E4E9;margin-bottom:16px;font-weight:600}
.pw-modal .pw-field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.pw-modal .pw-field label{font-size:10px;color:#8A8F98;text-transform:uppercase;letter-spacing:.05em;font-weight:500}
.pw-modal .pw-field input{
    background:#1A1C1F;border:1px solid #282A2F;color:#E2E4E9;
    padding:7px 11px;border-radius:5px;font-size:12px;outline:none;
}
.pw-modal .pw-field input:focus{border-color:#5E6AD2}
.pw-modal-actions{display:flex;gap:6px;margin-top:14px}
.pw-modal-actions button{flex:1;padding:8px;border-radius:5px;font-size:12px;cursor:pointer;border:none;font-weight:500;transition:all .12s}
.pw-save{background:#5E6AD2;color:#fff}
.pw-save:hover{background:#6B77DA}
.pw-cancel{background:#1A1C1F;color:#8A8F98;border:1px solid #282A2F !important}
.pw-cancel:hover{background:#1F2125;color:#E2E4E9}
.pw-msg{font-size:12px;padding:7px 10px;border-radius:5px;margin-bottom:10px;text-align:center}
.pw-msg.err{background:#1F1010;border:1px solid #EB575740;color:#EB5757}
.pw-msg.ok{background:#0C1F14;border:1px solid #4CB78240;color:#4CB782}
.pw-close{position:absolute;top:10px;right:12px;background:none;border:none;color:#4B5060;font-size:17px;cursor:pointer;line-height:1}
.pw-close:hover{color:#8A8F98}

/* ── Notes DnD ghost ── */
.note-dnd-ghost{opacity:0.35;background:#1A1C1F !important;border-color:#5E6AD2 !important}
.note-item-title{cursor:grab}
.note-item-title:active{cursor:grabbing}

/* ── Hide done filter ── */
</style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- ══ LOGIN ══════════════════════════════════════════════════ -->
<div class="login-wrap">
    <div class="login-card">
        <h2>📋 Todo</h2>
        <p class="sub">Devam etmek için şifrenizi girin</p>
        <?php if ($loginError): ?>
        <div class="login-err"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST" action="todo.php">
            <input type="hidden" name="action" value="login">
            <div class="login-field">
                <label>Şifre</label>
                <input type="password" name="password" autofocus placeholder="••••••••" required>
            </div>
            <button type="submit" class="login-btn">Giriş Yap</button>
        </form>
    </div>
</div>
<?php else: ?>

<!-- ══ APP ════════════════════════════════════════════════════ -->
<div class="tabs">
    <button class="tab-btn <?= $tab==='tasks'?'active':'' ?>" onclick="switchTab('tasks')">📋 Görevler</button>
    <button class="tab-btn <?= $tab==='notes'?'active':'' ?>" onclick="switchTab('notes')">📝 Notlar</button>
    <button class="tab-btn <?= $tab==='search'?'active':'' ?>" onclick="switchTab('search')">🔍 Ara</button>
    <div class="tab-actions">
        <button onclick="openPwModal()" class="tab-action-btn">🔒 Şifre</button>
        <form method="POST" action="todo.php" style="display:inline">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="tab-action-btn tab-action-danger">Çıkış</button>
        </form>
    </div>
</div>

<div class="main">

<!-- ══ TASKS ══════════════════════════════════════════════ -->
<div id="tab-tasks" style="display:<?= $tab==='tasks'?'flex':'none' ?>;flex-direction:column;height:100%;overflow:hidden">

    <form class="task-toolbar" method="POST" action="todo.php?tab=tasks" id="add-task-form">
        <input type="hidden" name="action" value="add_task">
        <input type="hidden" name="parent_id" value="">
        <input type="hidden" name="priority" id="add-prio" value="1">
        <select name="type" id="add-type" onchange="onTypeChange(this.value)">
            <option value="task">Görev</option>
            <option value="heading">Başlık</option>
        </select>
        <input type="text" name="title" id="new-task-title" placeholder="Yeni görev ekle..." autocomplete="off">
        <div class="prio-pick" id="prio-pick-wrap">
            <button type="button" class="active" style="color:#9ece6a" data-prio="1" onclick="setPrio(1)"><span style="background:#9ece6a"></span></button>
            <button type="button" style="color:#e0af68" data-prio="2" onclick="setPrio(2)"><span style="background:#e0af68"></span></button>
            <button type="button" style="color:#f7768e" data-prio="3" onclick="setPrio(3)"><span style="background:#f7768e"></span></button>
        </div>
        <button type="submit" class="btn-primary">+ Ekle</button>
    </form>

    <div class="filter-bar">
        <label>Ara:</label>
        <input type="text" id="task-filter" placeholder="Görev filtrele..." oninput="filterTasks(this.value)">
        <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:#a9b1d6;cursor:pointer;flex-shrink:0;margin-left:8px">
            <input type="checkbox" id="hide-done-cb" onchange="filterTasks(document.getElementById('task-filter').value)"
                style="accent-color:#7aa2f7;width:13px;height:13px;cursor:pointer">
            Tamamlananları gizle
        </label>
    </div>

    <div class="tasks-body">
        <div id="task-tree"></div>
    </div>

    <div class="task-footer" id="task-footer">
        <div class="tf-stat">
            <span class="tf-icon">📋</span>
            <span class="tf-val total" id="ft-total"><?= $taskTotal ?></span>
            <span style="color:#292e42;font-size:12px;color:#fff;">toplam</span>
        </div>
        <span class="tf-sep">·</span>
        <div class="tf-stat">
            <span class="tf-icon">⏳</span>
            <span class="tf-val pending" id="ft-pending"><?= $taskPending ?></span>
            <span style="color:#292e42;font-size:12px;color:#fff;">bekliyor</span>
        </div>
        <span class="tf-sep">·</span>
        <div class="tf-stat">
            <span class="tf-icon">✅</span>
            <span class="tf-val done" id="ft-done"><?= $taskDone ?></span>
            <span style="color:#292e42;font-size:12px;color:#fff;">tamamlandı</span>
        </div>
        <div style="flex:1"></div>
        <button onclick="exportExcel()" style="background:none;border:none;color:#4CB782;font-size:14px;cursor:pointer;padding:2px 0;transition:color .12s" onmouseover="this.style.color='#6DDBA0'" onmouseout="this.style.color='#4CB782'">📊 Excel İndir</button>
    </div>
</div>

<!-- ══ NOTES ══════════════════════════════════════════════ -->
<div id="tab-notes" style="display:<?= $tab==='notes'?'flex':'none' ?>;height:100%;overflow:hidden">

    <div class="notes-sidebar">
        <div class="notes-topbar">
            <form method="POST" action="todo.php?tab=notes" style="display:flex;flex-direction:column;gap:6px">
                <input type="hidden" name="action" value="add_note">
                <input type="text" name="title" placeholder="Not başlığı..." autocomplete="off" required>
                <button type="submit" class="btn-primary" style="font-size:12px;padding:6px">+ Not Ekle</button>
            </form>
        </div>
        <div class="notes-list" id="notes-list">
            <?php foreach($notes as $n):
                $active = ($selectedNote && (int)$selectedNote['id']===(int)$n['id']) ? 'active' : '';
            ?>
            <div class="note-item <?=$active?>" data-id="<?=$n['id']?>" onclick="window.location='todo.php?tab=notes&note=<?=$n['id']?>'">
                <span class="note-item-title"><?=htmlspecialchars($n['title'])?></span>
                <button class="note-del" onclick="event.stopPropagation();delNote(<?=$n['id']?>)" title="Sil">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="note-editor">
        <?php if($selectedNote): ?>
        <div class="note-header">
            <input type="text" class="note-title-inp" id="note-title" value="<?=htmlspecialchars($selectedNote['title'])?>">
            <div class="note-toolbar">
                <button class="n-btn" onclick="exportNote()">↓ .md</button>
                <button class="n-btn" onclick="saveNote(<?=$selectedNote['id']?>)" style="color:#4CB782;border-color:#4CB78244">Kaydet</button>
            </div>
        </div>
        <div class="note-body">
            <div class="cm-wrap"><textarea id="note-content"><?=htmlspecialchars($selectedNote['content'])?></textarea></div>
        </div>
        <?php else: ?>
        <div class="note-empty">← Bir not seç veya yeni not oluştur</div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ SEARCH ═════════════════════════════════════════════ -->
<div id="tab-search" style="display:<?= $tab==='search'?'flex':'none' ?>;flex-direction:column;height:100%;overflow:hidden">
    <div class="search-hero">
        <h2>🔍 Ara</h2>
        <div class="search-input-wrap">
            <input type="text" id="search-inp" placeholder="Görev veya not ara..."
                autocomplete="off"
                onkeydown="if(event.key==='Enter')doSearch()"
                oninput="document.getElementById('search-inp-clear').classList.toggle('visible',this.value.length>0)">
            <button class="search-inp-clear" id="search-inp-clear" onclick="clearSearch()" title="Temizle">✕</button>
        </div>
        <span class="search-hint">Enter'a basarak ara</span>
    </div>
    <div class="search-body">
        <div class="search-results" id="search-results"></div>
        <div class="search-loader" id="search-loader"></div>
    </div>
</div>

</div><!-- .main -->
<div id="toast"></div>

<!-- Hidden forms -->
<form id="f-del-task" method="POST" action="todo.php?tab=tasks" style="display:none">
    <input type="hidden" name="action" value="delete_task">
    <input type="hidden" name="id" id="f-del-task-id">
</form>
<form id="f-del-note" method="POST" action="todo.php?tab=notes" style="display:none">
    <input type="hidden" name="action" value="delete_note">
    <input type="hidden" name="id" id="f-del-note-id">
</form>

<!-- ══ Şifre Modal ══════════════════════════════════════════ -->
<div class="pw-modal-bg" id="pw-modal-bg" onclick="if(event.target===this)closePwModal()">
    <div class="pw-modal">
        <button class="pw-close" onclick="closePwModal()">✕</button>
        <h3>🔒 Şifre Değiştir</h3>
        <div id="pw-modal-msg" style="display:none"></div>
        <div class="pw-field">
            <label>Mevcut Şifre</label>
            <input type="password" id="pw-old" placeholder="••••••••">
        </div>
        <div class="pw-field">
            <label>Yeni Şifre</label>
            <input type="password" id="pw-new1" placeholder="••••••••">
        </div>
        <div class="pw-field">
            <label>Yeni Şifre (Tekrar)</label>
            <input type="password" id="pw-new2" placeholder="••••••••">
        </div>
        <div class="pw-modal-actions">
            <button class="pw-cancel" onclick="closePwModal()">İptal</button>
            <button class="pw-save" onclick="submitPwChange()">Kaydet</button>
        </div>
    </div>
</div>

<script>
// ── Data ─────────────────────────────────────────────────────
const TASK_DATA = <?= $taskJson ?>;
const PRIO_COLORS = {1:'#9ece6a', 2:'#e0af68', 3:'#f7768e'};

// ── Utilities ────────────────────────────────────────────────
function switchTab(t) {
    ['tasks','notes','search'].forEach(id => {
        const el = document.getElementById('tab-' + id);
        if (el) el.style.display = (t === id) ? 'flex' : 'none';
    });
    document.querySelectorAll('.tab-btn').forEach((b, i) =>
        b.classList.toggle('active', ['tasks','notes','search'][i] === t));
    history.replaceState(null, '', 'todo.php?tab=' + t);
    if (t === 'search') setTimeout(() => document.getElementById('search-inp')?.focus(), 50);
}

function toast(msg) {
    const el = document.getElementById('toast');
    el.textContent = msg; el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2000);
}

function post(data) {
    return fetch('todo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: Object.entries(data).map(([k,v]) => k + '=' + encodeURIComponent(v ?? '')).join('&')
    });
}

function formPost(action, data, tab = 'tasks') {
    const f = document.createElement('form');
    f.method = 'POST'; f.action = 'todo.php?tab=' + tab; f.style.display = 'none';
    data.action = action;
    for (const [k,v] of Object.entries(data)) {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v ?? '';
        f.appendChild(i);
    }
    document.body.appendChild(f); f.submit();
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Type picker ──────────────────────────────────────────────
function onTypeChange(val) {
    // Başlık seçilince öncelik picker'ı gizle
    const wrap = document.getElementById('prio-pick-wrap');
    const inp  = document.getElementById('new-task-title');
    if (wrap) wrap.style.opacity = val === 'heading' ? '0.3' : '1';
    if (inp)  inp.placeholder = val === 'heading' ? 'Başlık metni...' : 'Yeni görev ekle...';
}

// ── Priority picker ──────────────────────────────────────────
function setPrio(p) {
    document.getElementById('add-prio').value = p;
    document.querySelectorAll('.prio-pick button').forEach(b =>
        b.classList.toggle('active', parseInt(b.dataset.prio) === p));
}

// ── Task Tree ────────────────────────────────────────────────
let taskTree = null;

// onChange'den gelen nodes: [{ element: SortableTreeNode, guid, subnodes: [...] }]
// element.data => { id, title, ... } — renderLabel'a geçilen orijinal data
function flattenNodes(nodesArr, parentId, items) {
    if (!Array.isArray(nodesArr)) return;
    nodesArr.forEach((node, idx) => {
        const dbId = node.element?.data?.id;
        if (!dbId) return;
        items.push({ id: String(dbId), parent_id: String(parentId), order: idx });
        if (node.subnodes && node.subnodes.length) {
            flattenNodes(node.subnodes, dbId, items);
        }
    });
}

function saveTreeFromNodes(nodes) {
    const items = [];
    flattenNodes(nodes, '', items);
    if (items.length === 0) return;
    post({ action: 'save_tree', items: JSON.stringify(items) });
}

// DOM fallback (kullanılmıyor ama korunuyor)
function collectNodes(nodeEl, parentId, order, items) {
    const label = nodeEl.querySelector(':scope > .tn-label') ||
                  nodeEl.querySelector(':scope > .tree__label');
    if (!label) return;
    const dbId = label.querySelector('[data-db-id]')?.dataset?.dbId;
    if (!dbId) return;
    items.push({ id: dbId, parent_id: parentId, order });
    const sub = nodeEl.querySelector(':scope > .tn-subnodes') ||
                nodeEl.querySelector(':scope > .tree__subnodes');
    if (sub) {
        let co = 0;
        sub.querySelectorAll(':scope > sortable-tree-node').forEach(child => {
            collectNodes(child, dbId, co, items); co++;
        });
    }
}

function initTaskTree() {
    const el = document.getElementById('task-tree');
    if (!el) return;

    taskTree = new SortableTree({
        nodes: TASK_DATA,
        element: el,
        lockRootLevel: false,
        stateId: 'todo-task-tree',
        initCollapseLevel: 99,
        icons: { collapsed: '▸', open: '▾' },
        // Override CSS class names — tam kontrol
        styles: {
            tree:          'st-tree',
            node:          'st-node',
            nodeHover:     'tn-hover',
            nodeDragging:  'tn-dragging',
            nodeDropBefore:'tn-drop-before',
            nodeDropInside:'tn-drop-inside',
            nodeDropAfter: 'tn-drop-after',
            label:         'tn-label',
            subnodes:      'tn-subnodes',
            collapse:      'tn-collapse',
        },
        renderLabel: (data) => {
            const d = data;
            const isHeading = d.type === 'heading';
            const color = PRIO_COLORS[d.priority] || PRIO_COLORS[1];
            const doneClass = d.done ? 'done' : '';
            const checked = d.done ? 'checked' : '';
            return `<span data-db-id="${d.id}" data-type="${d.type||'task'}" style="display:none"></span>
                <input type="checkbox" class="t-cb" ${checked}
                    onmousedown="event.stopPropagation()"
                    onclick="event.stopPropagation();toggleTask(${d.id},this.checked)">
                <span class="t-dot"
                    style="background:${color};box-shadow:0 0 5px ${color}88"
                    data-id="${d.id}" data-prio="${d.priority}"
                    onmousedown="event.stopPropagation()"
                    onclick="event.stopPropagation();cyclePrio(this)"
                    title="Tıkla: öncelik değiştir"></span>
                <span class="t-title ${doneClass}" data-id="${d.id}"
                    ondblclick="event.stopPropagation();editTask(${d.id})"
                    title="Çift tıkla: düzenle">${esc(d.title)}</span>
                <input class="t-edit" data-id="${d.id}" value="${esc(d.title)}"
                    onkeydown="editKey(event,${d.id})" onblur="saveEdit(${d.id})"
                    onmousedown="event.stopPropagation()"
                    onclick="event.stopPropagation()">
                <span class="t-actions">
                    <button class="t-btn"
                        onmousedown="event.stopPropagation()"
                        onclick="event.stopPropagation();addSub(${d.id},'task')"
                        title="Alt görev ekle">+ görev</button>
                    <button class="t-btn"
                        onmousedown="event.stopPropagation()"
                        onclick="event.stopPropagation();addSub(${d.id},'heading')"
                        title="Alt başlık ekle">+ başlık</button>
                    <button class="t-btn del"
                        onmousedown="event.stopPropagation()"
                        onclick="event.stopPropagation();delTask(${d.id})"
                        title="Sil">sil</button>
                </span>`;
        },
        onChange: ({ nodes }) => {
            if (nodes && nodes.length) {
                saveTreeFromNodes(nodes);
            }
        }
    });
}

function toggleTask(id, checked) {
    post({ action: 'toggle_task', id, done: checked ? 1 : 0 });
    const node = document.querySelector(`[data-db-id="${id}"]`)?.closest('sortable-tree-node');
    if (!node) return;
    node.querySelectorAll('.t-title').forEach(t => t.classList.toggle('done', checked));
    node.querySelectorAll('.t-cb').forEach(cb => cb.checked = checked);
    updateFooter();
}

function editTask(id) {
    const title = document.querySelector(`.t-title[data-id="${id}"]`);
    const inp = document.querySelector(`.t-edit[data-id="${id}"]`);
    if (!title || !inp) return;
    title.style.display = 'none'; inp.style.display = 'block'; inp.focus(); inp.select();
}
function saveEdit(id) {
    const title = document.querySelector(`.t-title[data-id="${id}"]`);
    const inp = document.querySelector(`.t-edit[data-id="${id}"]`);
    if (!title || !inp) return;
    const newVal = inp.value.trim();
    if (newVal && newVal !== title.textContent) {
        const dot = document.querySelector(`.t-dot[data-id="${id}"]`);
        post({ action: 'update_task', id, title: newVal, priority: dot?.dataset.prio || 1 })
            .then(() => toast('Güncellendi'));
        title.textContent = newVal;
    }
    title.style.display = ''; inp.style.display = 'none';
}
function editKey(e, id) {
    if (e.key === 'Enter') { e.preventDefault(); saveEdit(id); }
    if (e.key === 'Escape') {
        const title = document.querySelector(`.t-title[data-id="${id}"]`);
        const inp = document.querySelector(`.t-edit[data-id="${id}"]`);
        title.style.display = ''; inp.style.display = 'none';
    }
}

// (dblclick is now inline in renderLabel)

function cyclePrio(el) {
    const id = el.dataset.id;
    const cur = parseInt(el.dataset.prio);
    const next = cur >= 3 ? 1 : cur + 1;
    const color = PRIO_COLORS[next];
    el.style.background = color;
    el.style.boxShadow = `0 0 5px ${color}40`;
    el.dataset.prio = next;
    const title = document.querySelector(`.t-title[data-id="${id}"]`);
    post({ action: 'update_task', id, title: title?.textContent || '', priority: next });
}

function addSub(parentId, type) {
    const label = type === 'heading' ? 'Alt başlık adı:' : 'Alt görev adı:';
    const name = prompt(label);
    if (name && name.trim()) {
        formPost('add_task', { parent_id: parentId, title: name.trim(), priority: 1, type: type || 'task' }, 'tasks');
    }
}

function delTask(id) {
    if (!confirm('Görevi sil? (Alt görevler de silinir)')) return;
    document.getElementById('f-del-task-id').value = id;
    document.getElementById('f-del-task').submit();
}

// ── Filter ───────────────────────────────────────────────────
function filterTasks(q) {
    q = (q || '').toLowerCase().trim();
    const hideDone = document.getElementById('hide-done-cb')?.checked;
    const HIDE = 'tn-filtered-hide';
    const TAG  = 'sortable-tree-node';
    const allNodes = Array.from(document.querySelectorAll(`#task-tree ${TAG}`));

    // Önce tümünü göster
    allNodes.forEach(n => n.classList.remove(HIDE));

    // Tamamlananları gizle
    if (hideDone) {
        allNodes.forEach(n => {
            const titleEl = n.querySelector(':scope > .tn-label .t-title');
            if (titleEl && titleEl.classList.contains('done')) {
                n.classList.add(HIDE);
            }
        });
    }

    // Metin filtresi
    if (q) {
        // Tümünü gizle, sonra eşleşenleri aç
        allNodes.forEach(n => n.classList.add(HIDE));
        allNodes.forEach(n => {
            const titleEl = n.querySelector(':scope > .tn-label .t-title');
            if (!titleEl) return;
            if (!titleEl.textContent.toLowerCase().includes(q)) return;
            // hideDone aktifse ve bu node done ise gösterme
            if (hideDone && titleEl.classList.contains('done')) return;
            // eşleşen + torunlar
            n.classList.remove(HIDE);
            n.querySelectorAll(TAG).forEach(c => {
                if (hideDone) {
                    const t2 = c.querySelector(':scope > .tn-label .t-title');
                    if (t2 && t2.classList.contains('done')) return;
                }
                c.classList.remove(HIDE);
            });
            // atalar
            let p = n.parentElement?.closest(TAG);
            while (p) { p.classList.remove(HIDE); p = p.parentElement?.closest(TAG); }
        });
    }
}

// ── Notes ────────────────────────────────────────────────────
function delNote(id) {
    if (!confirm('Notu sil?')) return;
    document.getElementById('f-del-note-id').value = id;
    document.getElementById('f-del-note').submit();
}

// ── Merkezi Arama ────────────────────────────────────────────
function doSearch() {
    const inp = document.getElementById('search-inp');
    const q   = inp?.value?.trim();
    const loader  = document.getElementById('search-loader');
    const results = document.getElementById('search-results');
    if (!q) return;

    loader.classList.add('visible');
    results.innerHTML = '';

    fetch(`todo.php?action=search&q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            loader.classList.remove('visible');
            renderSearchResults(data, q);
            // Sonuçları sessionStorage'a kaydet
            sessionStorage.setItem('search_q', q);
            sessionStorage.setItem('search_html', document.getElementById('search-results').innerHTML);
        })
        .catch(() => loader.classList.remove('visible'));
}

function highlightText(text, q) {
    if (!q) return esc(text);
    const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
    return esc(text).replace(re, '<mark>$1</mark>');
}

function renderSearchResults(data, q) {
    const el = document.getElementById('search-results');
    const PRIO = {1:'#9ece6a',2:'#e0af68',3:'#f7768e'};
    let html = '';

    if (data.tasks?.length) {
        html += `<div class="search-group">
            <div class="search-group-title">📋 Görevler (${data.tasks.length})</div>`;
        data.tasks.forEach(t => {
            const badge = t.type === 'heading'
                ? '<span class="search-item-badge heading">Başlık</span>'
                : '<span class="search-item-badge">Görev</span>';
            const dot = `<span style="width:8px;height:8px;border-radius:50%;background:${PRIO[t.priority]||PRIO[1]};display:inline-block;flex-shrink:0"></span>`;
            const titleCls = t.done == 1 ? 'si-done' : '';
            html += `<div class="search-item" onclick="goToTask(${t.id})">
                <div class="search-item-title">
                    ${dot}
                    <span class="${titleCls}">${highlightText(t.title, q)}</span>
                    ${badge}
                </div>
            </div>`;
        });
        html += '</div>';
    }

    if (data.notes?.length) {
        html += `<div class="search-group">
            <div class="search-group-title">📝 Notlar (${data.notes.length})</div>`;
        data.notes.forEach(n => {
            const snippetHtml = n.snippets?.length
                ? n.snippets.map(s => `
                    <div class="search-snippet search-snippet-link"
                        onclick="event.stopPropagation();goToNote(${n.id}, ${s.line})"
                        title="Satır ${s.line}'e git">
                        <span class="search-snippet-line">Satır ${s.line}</span>
                        ${highlightText(s.text, q)}
                    </div>`).join('')
                : '';
            html += `<div class="search-item">
                <div class="search-item-title" onclick="goToNote(${n.id}, 0)" style="cursor:pointer">
                    <span>${highlightText(n.title, q)}</span>
                    <span class="search-item-badge">Not</span>
                </div>
                ${snippetHtml}
            </div>`;
        });
        html += '</div>';
    }

    if (!data.tasks?.length && !data.notes?.length) {
        html = '<div class="search-empty">Sonuç bulunamadı.</div>';
    }

    el.innerHTML = html;
}

function goToTask(id) {
    switchTab('tasks');
    // tree render sonrası node'u bul ve highlight et
    setTimeout(() => {
        const span = document.querySelector(`[data-db-id="${id}"]`);
        if (!span) return;
        const label = span.closest('.tn-label');
        if (!label) return;
        label.scrollIntoView({ behavior: 'smooth', block: 'center' });
        label.style.transition = 'box-shadow .3s';
        label.style.boxShadow = '0 0 0 3px rgba(122,162,247,.7)';
        setTimeout(() => label.style.boxShadow = '', 1800);
    }, 100);
}

function goToNote(id, line) {
    window.location = `todo.php?tab=notes&note=${id}${line ? '&line=' + line : ''}`;
}

function clearSearch() {
    const inp = document.getElementById('search-inp');
    if (inp) inp.value = '';
    document.getElementById('search-inp-clear')?.classList.remove('visible');
    // Sonuçları temizle + sessionStorage'ı da sıfırla
    document.getElementById('search-results').innerHTML = '';
    sessionStorage.removeItem('search_q');
    sessionStorage.removeItem('search_html');
    inp?.focus();
}

// ── Note Editor ──────────────────────────────────────────────
let cm = null, autoTimer = null;
const NOTE_ID = <?= $selectedNote ? (int)$selectedNote['id'] : 'null' ?>;

function initEditor() {
    const ta = document.getElementById('note-content');
    if (!ta || typeof EasyMDE === 'undefined') return;
    cm = new EasyMDE({
        element: ta,
        autofocus: false,
        spellChecker: false,
        lineWrapping: true,
        tabSize: 4,
        indentWithTabs: false,
        autosave: { enabled: false },
        toolbar: [
            'bold','italic','strikethrough','|',
            'heading-1','heading-2','heading-3','|',
            'quote','unordered-list','ordered-list','|',
            'code','|',
            'link',
            {
                name: 'table',
                action(editor) {
                    const cm = editor.codemirror;
                    const t = '\n| Başlık 1 | Başlık 2 | Başlık 3 |\n| --- | --- | --- |\n| Hücre | Hücre | Hücre |\n';
                    cm.replaceSelection(t);
                },
                className: 'fa fa-table',
                title: 'Tablo ekle'
            },
            {
                name: 'hr',
                action(editor) { editor.codemirror.replaceSelection('\n---\n'); },
                className: 'fa fa-minus',
                title: 'Yatay çizgi'
            },
            {
                name: 'checklist',
                action(editor) { editor.codemirror.replaceSelection('- [ ] Görev\n'); },
                className: 'fa fa-check-square',
                title: 'Checkbox listesi'
            },
            '|',
            'preview','side-by-side','fullscreen',
            '|',
            'guide'
        ],
        shortcuts: {
            'toggleBold': 'Ctrl-B',
            'toggleItalic': 'Ctrl-I',
            'drawLink': 'Ctrl-K',
        },
        previewRender(plainText, preview) {
            // EasyMDE'nin built-in markdown parser'ı
            const html = this.parent.markdown(plainText);
            preview.innerHTML = html;
            // Kod bloklarını highlight et
            if (typeof hljs !== 'undefined') {
                preview.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
            }
            return preview.innerHTML;
        }
    });

    // Ctrl-S kaydet
    cm.codemirror.addKeyMap({
        'Ctrl-S': () => saveNote(NOTE_ID),
        'Cmd-S':  () => saveNote(NOTE_ID)
    });

    // Otomatik kayıt (2sn debounce)
    cm.codemirror.on('change', () => {
        clearTimeout(autoTimer);
        autoTimer = setTimeout(() => saveNote(NOTE_ID), 2000);
    });

    // Arama'dan gelindiyse belirtilen satıra git
    const urlLine = new URLSearchParams(location.search).get('line');
    if (urlLine) {
        const lineNo = Math.max(0, parseInt(urlLine) - 1);
        setTimeout(() => {
            const c = cm.codemirror;
            c.setCursor(lineNo, 0);
            c.scrollIntoView({ line: lineNo, ch: 0 }, 100);
            const marker = c.addLineClass(lineNo, 'background', 'cm-line-highlight');
            setTimeout(() => c.removeLineClass(lineNo, 'background', 'cm-line-highlight'), 2000);
        }, 300);
    }
}

function saveNote(id) {
    if (!id || !cm) return;
    const title = document.getElementById('note-title')?.value || '';
    post({ action: 'save_note', id, title, content: cm.value() })
        .then(() => {
            toast('Kaydedildi');
            document.querySelectorAll('.note-item.active .note-item-title')
                .forEach(el => el.textContent = title);
        });
}

function exportNote() {
    if (!cm) return;
    const title = document.getElementById('note-title')?.value || 'note';
    const blob = new Blob([cm.value()], { type: 'text/markdown' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = title.replace(/[^a-z0-9_\-]/gi, '_') + '.md';
    a.click(); URL.revokeObjectURL(a.href);
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (NOTE_ID) saveNote(NOTE_ID); }
});

// ── Heading stillerini uygula (tree render sonrası) ──────────
function applyHeadingStyles() {
    document.querySelectorAll('#task-tree [data-type="heading"]').forEach(span => {
        const label = span.closest('.tn-label');
        if (label) label.classList.add('is-heading');
    });
}

// ── Footer sayaç güncelle ────────────────────────────────────
function updateFooter() {
    // Sadece 'task' tipindeki checkbox'ları say, 'heading' hariç
    const all  = Array.from(document.querySelectorAll('#task-tree [data-type]'));
    const taskSpans = all.filter(s => (s.dataset.type || 'task') === 'task');
    const total = taskSpans.length;
    const doneCount = taskSpans.filter(s => {
        const cb = s.closest('.tn-label')?.querySelector('.t-cb');
        return cb && cb.checked;
    }).length;
    const pending = total - doneCount;
    const elT = document.getElementById('ft-total');
    const elP = document.getElementById('ft-pending');
    const elD = document.getElementById('ft-done');
    if (elT) elT.textContent = total;
    if (elP) elP.textContent = pending;
    if (elD) elD.textContent = doneCount;
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initTaskTree();
    initEditor();
    initNotesDnd();

    // Enter → submit + focus geri
    const titleInp = document.getElementById('new-task-title');
    const addForm  = document.getElementById('add-task-form');
    if (titleInp && addForm) {
        titleInp.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (!titleInp.value.trim()) return;
                // sessionStorage'a "refocus" flag koy
                sessionStorage.setItem('refocus-task-input', '1');
                addForm.submit();
            }
        });
        // Sayfa yüklenince flag varsa focus ver
        if (sessionStorage.getItem('refocus-task-input')) {
            sessionStorage.removeItem('refocus-task-input');
            titleInp.focus();
        }
    }

    // Tree render sonrası heading stilleri ve footer
    setTimeout(() => { applyHeadingStyles(); updateFooter(); }, 400);

    // Arama sonuçlarını sessionStorage'dan restore et
    const savedQ    = sessionStorage.getItem('search_q');
    const savedHtml = sessionStorage.getItem('search_html');
    if (savedQ && savedHtml) {
        const inp = document.getElementById('search-inp');
        const res = document.getElementById('search-results');
        const clr = document.getElementById('search-inp-clear');
        if (inp) { inp.value = savedQ; }
        if (clr) clr.classList.add('visible');
        if (res) res.innerHTML = savedHtml;
    }

    // Escape ile modal kapat
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closePwModal();
    });
});

// ── Şifre Modal ──────────────────────────────────────────────
function openPwModal() {
    document.getElementById('pw-modal-bg').classList.add('open');
    document.getElementById('pw-old').value = '';
    document.getElementById('pw-new1').value = '';
    document.getElementById('pw-new2').value = '';
    const msg = document.getElementById('pw-modal-msg');
    msg.style.display = 'none'; msg.className = 'pw-msg';
    setTimeout(() => document.getElementById('pw-old').focus(), 50);
}
function closePwModal() {
    document.getElementById('pw-modal-bg').classList.remove('open');
}
function submitPwChange() {
    const old_pw  = document.getElementById('pw-old').value;
    const new_pw1 = document.getElementById('pw-new1').value;
    const new_pw2 = document.getElementById('pw-new2').value;
    const msg = document.getElementById('pw-modal-msg');
    msg.style.display = 'none';

    fetch('todo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=change_password&old_pw=${encodeURIComponent(old_pw)}&new_pw1=${encodeURIComponent(new_pw1)}&new_pw2=${encodeURIComponent(new_pw2)}`
    })
    .then(r => r.json())
    .then(d => {
        msg.style.display = 'block';
        if (d.ok) {
            msg.className = 'pw-msg ok';
            msg.textContent = '✓ Şifre güncellendi.';
            setTimeout(closePwModal, 1500);
        } else {
            msg.className = 'pw-msg err';
            msg.textContent = d.msg || 'Hata oluştu.';
        }
    });
}

// ── Excel Export ─────────────────────────────────────────────
function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('SheetJS yüklenemedi.'); return; }

    const PRIO_LABELS = { '1': 'Düşük', '2': 'Orta', '3': 'Yüksek' };
    const INDENT_CHAR = '    '; // 4 boşluk per seviye

    // Node derinliğini hesapla
    function getDepth(node) {
        let depth = 0;
        let cur = node.parentElement?.closest('sortable-tree-node');
        while (cur) { depth++; cur = cur.parentElement?.closest('sortable-tree-node'); }
        return depth;
    }

    // Satır verilerini topla
    const dataRows = []; // { title, type, prio, status, depth, isHeading }
    const allNodes = Array.from(document.querySelectorAll('#task-tree sortable-tree-node'));
    allNodes.forEach(node => {
        const titleEl = node.querySelector(':scope > .tn-label .t-title');
        const dotEl   = node.querySelector(':scope > .tn-label .t-dot');
        const cbEl    = node.querySelector(':scope > .tn-label .t-cb');
        const spanEl  = node.querySelector(':scope > .tn-label [data-type]');
        if (!titleEl) return;
        const depth     = getDepth(node);
        const isHeading = spanEl?.dataset.type === 'heading';
        dataRows.push({
            title:     INDENT_CHAR.repeat(depth) + titleEl.textContent.trim(),
            type:      isHeading ? 'Başlık' : 'Görev',
            prio:      PRIO_LABELS[dotEl?.dataset.prio] || 'Düşük',
            status:    cbEl?.checked ? 'Tamamlandı' : 'Bekliyor',
            depth,
            isHeading,
            rowIndex:  dataRows.length + 1  // +1 = header row offset
        });
    });

    // Stiller
    const S = {
        header:  { font: { bold: true, sz: 11 }, fill: { fgColor: { rgb: 'F2F2F2' } }, border: { bottom: { style: 'thin', color: { rgb: 'CCCCCC' } } } },
        heading: { font: { bold: true, sz: 11 } },
        done:    { font: { color: { rgb: 'AAAAAA' }, italic: true, sz: 11 } },
        normal:  { font: { sz: 11 } },
    };

    // Sheet oluştur
    const aoa = [['Başlık','Tür','Öncelik','Durum']];
    dataRows.forEach(r => aoa.push([r.title, r.type, r.prio, r.status]));
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{wch:50},{wch:10},{wch:10},{wch:14}];
    ws['!rows'] = [{hpt:18}]; // header row yüksekliği

    // Header satırı stil
    for (let c = 0; c < 4; c++) {
        const cell = ws[XLSX.utils.encode_cell({r:0, c})];
        if (cell) cell.s = S.header;
    }

    // Data satırları stil
    dataRows.forEach((r, i) => {
        const excelRow = i + 1;
        const style = r.isHeading ? S.heading : (r.status === 'Tamamlandı' ? S.done : S.normal);
        for (let c = 0; c < 4; c++) {
            const cell = ws[XLSX.utils.encode_cell({r: excelRow, c})];
            if (cell) cell.s = style;
        }
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Görevler');
    const date = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `gorevler-${date}.xlsx`);
}

// ── Notes Drag-and-Drop Sıralama ─────────────────────────────
function initNotesDnd() {
    const list = document.getElementById('notes-list');
    if (!list || typeof Sortable === 'undefined') return;
    Sortable.create(list, {
        animation: 150,
        handle: '.note-item-title',
        ghostClass: 'note-dnd-ghost',
        onEnd() {
            const ids = [...list.querySelectorAll('.note-item[data-id]')]
                .map(el => el.dataset.id);
            fetch('todo.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=reorder_notes&ids=' + encodeURIComponent(JSON.stringify(ids))
            });
        }
    });
}
</script>
<?php endif; ?>
</body>
</html>
