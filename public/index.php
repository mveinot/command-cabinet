<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/layout.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

function placeholder_keys(string $template): array {
    preg_match_all('/{{\s*([a-zA-Z][a-zA-Z0-9_.-]*)\s*}}/', $template, $matches);
    return array_values(array_unique($matches[1]));
}
function tags_for(int $commandId): array {
    $s = db()->prepare('SELECT t.name FROM tags t JOIN command_tags ct ON ct.tag_id=t.id WHERE ct.command_id=? ORDER BY t.name');
    $s->execute([$commandId]); return $s->fetchAll(PDO::FETCH_COLUMN);
}
function placeholder_select_options(mixed $options): array {
    if (is_string($options)) { try { $options=json_decode($options,true,512,JSON_THROW_ON_ERROR); } catch (Throwable) { $options=[]; } }
    if (!is_array($options)) return [];
    return array_values(array_unique(array_filter(array_map(fn($option)=>trim((string)$option),$options),fn($option)=>$option!=='')));
}
function render_placeholder_input(array $placeholder): string {
    $key=e($placeholder['placeholder_key']); $required=$placeholder['is_required']?' required':'';
    if (($placeholder['input_type'] ?? '') === 'select') {
        $options=placeholder_select_options($placeholder['select_options'] ?? []); $html='<select data-placeholder="'.$key.'"'.$required.'><option value="">Select a value</option>';
        foreach($options as $option)$html.='<option value="'.e($option).'">'.e($option).'</option>';
        return $html.'</select>';
    }
    return '<input data-placeholder="'.$key.'" type="'.(($placeholder['input_type'] ?? '')==='password'?'password':'text').'"'.$required.' placeholder="'.e($placeholder['example_value'] ?? '').'">';
}
function site_export_data(): array {
    $queries = [
        'users' => 'SELECT id,name,email,password_hash,role,email_verified_at,created_at,updated_at FROM users ORDER BY id',
        'invitations' => 'SELECT id,email,role,token_hash,invited_by,expires_at,accepted_at,created_at FROM invitations ORDER BY id',
        'commands' => 'SELECT id,title,template,description,environment,safety_level,created_by,updated_by,last_verified_at,created_at,updated_at,archived_at,archived_by,last_copied_at,last_copied_by FROM commands ORDER BY id',
        'command_placeholders' => 'SELECT id,command_id,placeholder_key,label,input_type,help_text,example_value,select_options,is_required,position FROM command_placeholders ORDER BY id',
        'tags' => 'SELECT id,name,created_at FROM tags ORDER BY id',
        'command_tags' => 'SELECT command_id,tag_id FROM command_tags ORDER BY command_id,tag_id',
        'command_versions' => 'SELECT id,command_id,title,template,description,environment,safety_level,last_verified_at,placeholders,tag_names,changed_by,change_note,created_at FROM command_versions ORDER BY id',
        'favorites' => 'SELECT user_id,command_id,created_at FROM favorites ORDER BY user_id,command_id',
    ];
    db()->beginTransaction(); db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $tables=[]; foreach ($queries as $name => $query) $tables[$name]=db()->query($query)->fetchAll();
        foreach ($tables['command_versions'] as &$version) foreach (['placeholders','tag_names'] as $field) if (is_string($version[$field])) $version[$field]=json_decode($version[$field],true,512,JSON_THROW_ON_ERROR);
        unset($version);
        db()->commit();
    } catch (Throwable $e) { if(db()->inTransaction())db()->rollBack(); throw $e; }
    return ['format'=>'command-cabinet-export','format_version'=>1,'exported_at'=>gmdate('c'),'password_storage'=>'password_hash (one-way; no plaintext passwords)','tables'=>$tables];
}
function restore_site_export(array $export): void {
    $tableColumns = [
        'users'=>['id','name','email','password_hash','role','email_verified_at','created_at','updated_at'],
        'invitations'=>['id','email','role','token_hash','invited_by','expires_at','accepted_at','created_at'],
        'commands'=>['id','title','template','description','environment','safety_level','created_by','updated_by','last_verified_at','created_at','updated_at','archived_at','archived_by','last_copied_at','last_copied_by'],
        'command_placeholders'=>['id','command_id','placeholder_key','label','input_type','help_text','example_value','select_options','is_required','position'],
        'tags'=>['id','name','created_at'],
        'command_tags'=>['command_id','tag_id'],
        'command_versions'=>['id','command_id','title','template','description','environment','safety_level','last_verified_at','placeholders','tag_names','changed_by','change_note','created_at'],
        'favorites'=>['user_id','command_id','created_at'],
    ];
    if (($export['format'] ?? '') !== 'command-cabinet-export' || ($export['format_version'] ?? null) !== 1 || !isset($export['tables']) || !is_array($export['tables'])) throw new RuntimeException('This is not a compatible Command Cabinet export.');
    foreach ($tableColumns as $table => $columns) if (!isset($export['tables'][$table]) || !is_array($export['tables'][$table])) throw new RuntimeException("Export is missing the {$table} table.");
    $hasAdmin=false; foreach($export['tables']['users'] as $user) if(is_array($user) && ($user['role']??'')==='admin'){$hasAdmin=true;break;} if(!$hasAdmin) throw new RuntimeException('The export must contain at least one administrator.');
    db()->beginTransaction();
    try {
        db()->exec('TRUNCATE TABLE users, tags RESTART IDENTITY CASCADE');
        foreach ($tableColumns as $table => $columns) {
            $insert=db()->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            foreach ($export['tables'][$table] as $row) {
                if(!is_array($row)) throw new RuntimeException("Invalid row in {$table}.");
                $values=[]; foreach($columns as $column){if(!array_key_exists($column,$row))throw new RuntimeException("Export row in {$table} is missing {$column}.");$value=$row[$column];if(($table==='command_versions' && in_array($column,['placeholders','tag_names'],true) || $table==='command_placeholders' && $column==='select_options') && is_array($value))$value=json_encode($value,JSON_THROW_ON_ERROR);if($table==='command_placeholders' && $column==='is_required')$value=$value?'true':'false';$values[]=$value;}
                $insert->execute($values);
            }
        }
        foreach (['users','invitations','commands','command_placeholders','tags','command_versions'] as $table) {
            $count=(int)db()->query('SELECT count(*) FROM '.$table)->fetchColumn();
            if($count>0)db()->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT max(id) FROM {$table}), true)");
        }
        db()->commit();
    } catch (Throwable $e) { if(db()->inTransaction())db()->rollBack(); throw $e; }
}
function catalog_commands(int $userId, string $query = '', string $environment = ''): array {
    $where=['c.archived_at IS NULL']; $params=[];
    if ($query !== '') { $where[] = "(c.search_document @@ websearch_to_tsquery('english', ?) OR c.template ILIKE ? OR c.title ILIKE ?)"; array_push($params,$query,'%'.$query.'%','%'.$query.'%'); }
    if ($environment !== '') { $where[]='c.environment=?'; $params[]=$environment; }
    $sql='SELECT c.*, u.name AS author, copier.name AS last_copied_by_name, EXISTS(SELECT 1 FROM favorites f WHERE f.command_id=c.id AND f.user_id=?) AS is_favorite FROM commands c JOIN users u ON u.id=c.updated_by LEFT JOIN users copier ON copier.id=c.last_copied_by';
    array_unshift($params,$userId); if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY c.updated_at DESC LIMIT 60';
    $s=db()->prepare($sql); $s->execute($params); return $s->fetchAll();
}
function render_command_cards(array $commands): string {
    ob_start();
    if (!$commands): ?><p class="empty">No commands found yet. Add the team’s first reliable snippet.</p><?php endif;
    foreach ($commands as $command): ?><article class="command-card"><div class="card-top"><div><span class="badge <?= e($command['safety_level']) ?>"><?= e($command['safety_level']) ?></span><span class="environment"><?= e($command['environment']) ?></span></div><button class="favorite" data-command-id="<?= $command['id'] ?>" aria-label="Favorite command"><?= $command['is_favorite'] ? '★' : '☆' ?></button></div><h2><a href="/commands/<?= $command['id'] ?>"><?= e($command['title']) ?></a></h2><pre><code><?= e($command['template']) ?></code></pre><div class="tag-row"><?php foreach(tags_for((int)$command['id']) as $tag): ?><span>#<?= e($tag) ?></span><?php endforeach ?></div><p class="card-meta">Updated <?= e(date('M j, Y', strtotime($command['updated_at']))) ?> by <?= e($command['author']) ?></p><?php if($command['last_copied_at']): ?><p class="copy-status">Last copied by <?= e($command['last_copied_by_name'] ?? 'a deleted user') ?> · <?= e(date('M j, Y g:i a',strtotime($command['last_copied_at']))) ?></p><?php endif; ?></article><?php endforeach;
    return (string) ob_get_clean();
}
function command_revision_snapshot(int $commandId): array {
    $command = db()->prepare('SELECT title,template,description,environment,safety_level,last_verified_at FROM commands WHERE id=?');
    $command->execute([$commandId]); $command = $command->fetch();
    if (!$command) throw new RuntimeException('Command not found.');
    $placeholders = db()->prepare('SELECT placeholder_key,label,input_type,help_text,example_value,select_options,is_required,position FROM command_placeholders WHERE command_id=? ORDER BY position');
    $placeholders->execute([$commandId]);
    return ['command'=>$command, 'placeholders'=>$placeholders->fetchAll(), 'tag_names'=>tags_for($commandId)];
}
function store_command_version(int $commandId, int $changedBy, string $note): void {
    $snapshot = command_revision_snapshot($commandId); $command = $snapshot['command'];
    db()->prepare('INSERT INTO command_versions (command_id,title,template,description,environment,safety_level,last_verified_at,placeholders,tag_names,changed_by,change_note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $commandId,$command['title'],$command['template'],$command['description'],$command['environment'],$command['safety_level'],$command['last_verified_at'],json_encode($snapshot['placeholders']),json_encode($snapshot['tag_names']),$changedBy,$note,
    ]);
}
function replace_command_metadata(int $commandId, array $placeholders, array $tagNames): void {
    db()->prepare('DELETE FROM command_placeholders WHERE command_id=?')->execute([$commandId]);
    $insert = db()->prepare('INSERT INTO command_placeholders (command_id,placeholder_key,label,input_type,help_text,example_value,select_options,is_required,position) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($placeholders as $position => $placeholder) {
        $key = $placeholder['placeholder_key'];
        $type = $placeholder['input_type'] ?? 'text';
        if (!in_array($type,['text','password','hostname','path','port','multiline','select'],true)) $type='text';
        $options=$type==='select'?placeholder_select_options($placeholder['select_options'] ?? []):[];
        $insert->execute([$commandId,$key,$placeholder['label'] ?? ucwords(str_replace('_',' ',$key)),$type,$placeholder['help_text'] ?? null,$placeholder['example_value'] ?? null,$options?json_encode($options):null,(bool)($placeholder['is_required'] ?? true),$placeholder['position'] ?? $position]);
    }
    db()->prepare('DELETE FROM command_tags WHERE command_id=?')->execute([$commandId]);
    $tagInsert = db()->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT (name) DO UPDATE SET name=EXCLUDED.name RETURNING id');
    $link = db()->prepare('INSERT INTO command_tags (command_id,tag_id) VALUES (?,?)');
    foreach (array_unique(array_filter($tagNames)) as $tag) { $tagInsert->execute([$tag]); $link->execute([$commandId,$tagInsert->fetchColumn()]); }
}
function markdown_inline(string $text): string {
    $parts = preg_split('/(`[^`]*`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $index => $part) {
        if ($index % 2 === 1 && str_starts_with($part, '`')) { $parts[$index] = '<code>'.e(substr($part,1,-1)).'</code>'; continue; }
        $part = e($part);
        $part = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)\)/', function(array $match): string { $url=html_entity_decode($match[2],ENT_QUOTES,'UTF-8'); return preg_match('#^(https?://|mailto:)#i',$url) ? '<a href="'.e($url).'" rel="noopener noreferrer" target="_blank">'.$match[1].'</a>' : $match[0]; }, $part);
        $part = preg_replace('/\*\*(.+?)\*\*/s','<strong>$1</strong>',$part);
        $part = preg_replace('/__(.+?)__/s','<strong>$1</strong>',$part);
        $part = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s','<em>$1</em>',$part);
        $part = preg_replace('/(?<!_)_(?!\s)(.+?)(?<!\s)_(?!_)/s','<em>$1</em>',$part);
        $part = preg_replace('/~~(.+?)~~/s','<del>$1</del>',$part);
        $parts[$index] = $part;
    }
    return implode('', $parts);
}
function render_markdown(string $markdown): string {
    $lines=preg_split('/\R/',trim($markdown)); if (!$lines || ($lines===[''])) return '';
    $html=[]; $paragraph=[]; $listType=null; $listItems=[]; $quote=[]; $code=[]; $inCode=false;
    $flushParagraph=function() use (&$html,&$paragraph): void { if($paragraph){$html[]='<p>'.implode('<br>',array_map('markdown_inline',$paragraph)).'</p>';$paragraph=[];} };
    $flushList=function() use (&$html,&$listType,&$listItems): void { if($listType){$html[]='<'.$listType.'><li>'.implode('</li><li>',array_map('markdown_inline',$listItems)).'</li></'.$listType.'>';$listType=null;$listItems=[];} };
    $flushQuote=function() use (&$html,&$quote): void { if($quote){$html[]='<blockquote><p>'.implode('<br>',array_map('markdown_inline',$quote)).'</p></blockquote>';$quote=[];} };
    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) { if($inCode){$html[]='<pre><code>'.e(implode("\n",$code)).'</code></pre>';$code=[];$inCode=false;} else {$flushParagraph();$flushList();$flushQuote();$inCode=true;} continue; }
        if($inCode){$code[]=$line;continue;}
        if(trim($line)===''){$flushParagraph();$flushList();$flushQuote();continue;}
        if(preg_match('/^(#{1,6})\s+(.+)$/',$line,$match)){$flushParagraph();$flushList();$flushQuote();$level=strlen($match[1]);$html[]="<h{$level}>".markdown_inline($match[2])."</h{$level}>";continue;}
        if(preg_match('/^[-*_]{3,}\s*$/',$line)){$flushParagraph();$flushList();$flushQuote();$html[]='<hr>';continue;}
        if(preg_match('/^>\s?(.*)$/',$line,$match)){$flushParagraph();$flushList();$quote[]=$match[1];continue;}
        if(preg_match('/^[-*+]\s+(.+)$/',$line,$match)){$flushParagraph();$flushQuote();if($listType && $listType!=='ul')$flushList();$listType='ul';$listItems[]=$match[1];continue;}
        if(preg_match('/^\d+\.\s+(.+)$/',$line,$match)){$flushParagraph();$flushQuote();if($listType && $listType!=='ol')$flushList();$listType='ol';$listItems[]=$match[1];continue;}
        $flushList();$flushQuote();$paragraph[]=$line;
    }
    if($inCode)$html[]='<pre><code>'.e(implode("\n",$code)).'</code></pre>'; $flushParagraph();$flushList();$flushQuote(); return implode("\n",$html);
}
function render_revision_history(int $commandId, array $viewer): string {
    $versions = db()->prepare('SELECT v.*,u.name AS changed_by_name FROM command_versions v JOIN users u ON u.id=v.changed_by WHERE v.command_id=? ORDER BY v.created_at DESC');
    $versions->execute([$commandId]); $versions = $versions->fetchAll();
    ob_start(); ?>
    <section class="revision-history"><h2>Revision history</h2><?php if (!$versions): ?><p>No earlier revisions have been recorded.</p><?php endif; ?><?php foreach ($versions as $version): ?><details class="revision"><summary><strong><?= e($version['title']) ?></strong> · <?= e(date('M j, Y g:i a', strtotime($version['created_at']))) ?> · <?= e($version['changed_by_name']) ?></summary><p><?= e($version['change_note'] ?: 'Saved revision') ?></p><p><span class="environment"><?= e($version['environment']) ?></span> <span class="badge <?= e($version['safety_level']) ?>"><?= e($version['safety_level']) ?></span></p><pre><code><?= e($version['template']) ?></code></pre><?php if ($version['description'] !== ''): ?><div class="markdown-content"><?= render_markdown($version['description']) ?></div><?php endif; ?><?php if ($viewer['role'] === 'admin'): ?><form method="post" action="/commands/<?= $commandId ?>/revisions/<?= $version['id'] ?>/restore"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="link-button" type="submit">Restore this revision</button></form><?php endif; ?></details><?php endforeach; ?></section><?php
    return (string) ob_get_clean();
}
function render_archive_controls(int $commandId, array $viewer): string {
    $command = db()->prepare('SELECT c.archived_at,u.name AS archived_by_name FROM commands c LEFT JOIN users u ON u.id=c.archived_by WHERE c.id=?');
    $command->execute([$commandId]); $command = $command->fetch();
    if (!$command) return '';
    ob_start(); ?>
    <?php if ($command['archived_at']): ?>
      <aside class="archive-notice"><strong>Archived</strong> <?= e(date('M j, Y g:i a', strtotime($command['archived_at']))) ?><?= $command['archived_by_name'] ? ' by '.e($command['archived_by_name']) : '' ?>. This command is hidden from the catalog.</aside>
      <?php if ($viewer['role'] === 'admin'): ?><form class="archive-controls" method="post" action="/commands/<?= $commandId ?>/unarchive"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="link-button" type="submit">Restore to catalog</button></form><?php endif; ?>
    <?php elseif ($viewer['role'] === 'admin'): ?>
      <form class="archive-controls" method="post" action="/commands/<?= $commandId ?>/archive" onsubmit="return confirm('Archive this command? It can be restored later.')"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="link-button" type="submit">Archive command</button></form>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
}
function render_copy_status(int $commandId): string {
    $copy = db()->prepare('SELECT c.last_copied_at,u.name AS last_copied_by_name FROM commands c LEFT JOIN users u ON u.id=c.last_copied_by WHERE c.id=?');
    $copy->execute([$commandId]); $copy=$copy->fetch();
    if (!$copy || !$copy['last_copied_at']) return '';
    return '<p class="copy-status">Last copied by '.e($copy['last_copied_by_name'] ?? 'a deleted user').' · '.e(date('M j, Y g:i a',strtotime($copy['last_copied_at']))).'</p>';
}
function admin_user_management_page(array $admin): never {
    $users = db()->query('SELECT u.*,COUNT(DISTINCT c.id) AS command_count FROM users u LEFT JOIN commands c ON c.created_by=u.id GROUP BY u.id ORDER BY u.role DESC,u.name')->fetchAll();
    page_start('User management'); ?>
    <section class="user-management"><div class="page-heading"><p class="eyebrow">Administration</p><h1>User management</h1><p>Manage team access directly. Passwords are never displayed.</p><form method="post" action="/admin/export"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="link-button" type="submit">Download full JSON site export</button></form><p class="user-self">Includes account password hashes and other sensitive team data. Store it securely.</p></div>
      <section class="site-import"><h2>Restore site export</h2><p>This replaces every current user, command, revision, tag, favorite, and invitation with the uploaded export. You will be signed out when it completes.</p><form method="post" action="/admin/import" enctype="multipart/form-data" onsubmit="return confirm('Replace the entire site with this export? This cannot be undone.')"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>JSON export file<input type="file" name="export_file" accept="application/json,.json" required></label><label>Type <code>REPLACE</code> to confirm<input name="confirmation" required autocomplete="off"></label><button class="link-button danger-button" type="submit">Replace site from export</button></form></section>
      <h2>Add user directly</h2><form class="user-create" method="post" action="/admin/users"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" required maxlength="120"></label><label>Email<input name="email" type="email" required maxlength="255"></label><label>Role<select name="role"><option value="member">Member</option><option value="viewer">View only</option><option value="admin">Administrator</option></select></label><label>Temporary password<input name="password" type="password" required minlength="12" autocomplete="new-password"></label><button class="button">Create user</button></form>
      <h2>Existing users</h2><?php foreach ($users as $account): $self=(int)$account['id']===(int)$admin['id']; ?><article class="user-card"><div class="user-summary"><strong><?= e($account['name']) ?> <?= $self ? '<span class="user-self">(you)</span>' : '' ?></strong><p><?= e($account['email']) ?></p><p><?= e(ucfirst($account['role'])) ?> · <?= (int)$account['command_count'] ?> command<?= (int)$account['command_count']===1?'':'s' ?> · Joined <?= e(date('M j, Y',strtotime($account['created_at']))) ?></p></div><div class="user-actions">
        <form class="user-action" method="post" action="/admin/users"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile"><input type="hidden" name="user_id" value="<?= $account['id'] ?>"><label>Name<input name="name" required maxlength="120" value="<?= e($account['name']) ?>"></label><label>Email<input name="email" type="email" required maxlength="255" value="<?= e($account['email']) ?>"></label><button class="link-button">Save profile</button></form>
        <form class="user-action" method="post" action="/admin/users"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= $account['id'] ?>"><label>Role<select name="role" <?= $self?'disabled':'' ?>><option value="member" <?= $account['role']==='member'?'selected':'' ?>>Member</option><option value="viewer" <?= $account['role']==='viewer'?'selected':'' ?>>View only</option><option value="admin" <?= $account['role']==='admin'?'selected':'' ?>>Administrator</option></select></label><?php if($self): ?><span class="user-self">Your own role cannot be changed here.</span><?php else: ?><button class="link-button">Change role</button><?php endif; ?></form>
        <form class="user-action" method="post" action="/admin/users"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= $account['id'] ?>"><label>New password<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><button class="link-button">Reset password</button></form>
        <?php if(!$self): ?><form class="user-action" method="post" action="/admin/users" onsubmit="return confirm('Delete this user? Their command and revision attribution will be reassigned to you.')"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= $account['id'] ?>"><button class="link-button danger-button">Delete user</button></form><?php endif; ?>
      </div></article><?php endforeach; ?></section><?php page_end(); exit;
}
function command_form(?array $command = null): void {
    $command ??= ['title'=>'','template'=>'','description'=>'','environment'=>'Linux / Bash','safety_level'=>'normal','last_verified_at'=>''];
    $existing = $command['id'] ? tags_for((int)$command['id']) : ($command['tags'] ?? []);
    page_start($command['id'] ? 'Edit command' : 'Add command'); ?>
    <div class="page-heading"><div><p class="eyebrow">Team catalog</p><h1><?= $command['id'] ? 'Edit command' : 'Add a command' ?></h1></div></div>
    <form class="command-form" method="post" action="<?= $command['id'] ? '/commands/'.$command['id'] : '/commands' ?>">
      <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
      <label>Title<input name="title" required maxlength="180" value="<?= e($command['title']) ?>" placeholder="Restart a Docker Compose stack"></label>
      <label>Command template<textarea name="template" required rows="6" spellcheck="false" placeholder="ssh {{user}}@{{host}} \"cd {{project_path}} && git pull\""><?= e($command['template']) ?></textarea><small>Use <code>{{placeholder_key}}</code> for values people can fill in before copying.</small></label>
      <div class="form-grid"><label>Environment<input name="environment" required maxlength="80" value="<?= e($command['environment']) ?>" placeholder="Linux / Bash"></label><label>Safety level<select name="safety_level"><option value="normal" <?= $command['safety_level']==='normal'?'selected':'' ?>>Normal</option><option value="caution" <?= $command['safety_level']==='caution'?'selected':'' ?>>Caution</option><option value="destructive" <?= $command['safety_level']==='destructive'?'selected':'' ?>>Destructive</option></select></label><label>Last verified<input type="date" name="last_verified_at" value="<?= e((string)$command['last_verified_at']) ?>"></label></div>
      <label>Tags<input name="tags" maxlength="300" value="<?= e(implode(', ', $existing)) ?>" placeholder="docker, deployment, maintenance"><small>Separate tags with commas.</small></label>
      <label>Description / operating notes<textarea id="notes-markdown" name="description" rows="6" placeholder="Explain prerequisites, expected result, and important warnings."><?= e($command['description']) ?></textarea><small>Markdown is supported: headings, lists, links, emphasis, and code. Plain text continues to display normally.</small></label>
      <section class="markdown-preview-panel"><h2>Live preview</h2><div id="notes-markdown-preview" class="markdown-content" aria-live="polite"><?= render_markdown($command['description']) ?></div></section>
      <section class="placeholder-help"><h2>Placeholder details</h2><p>Metadata makes the temporary fill-in form understandable. Placeholder values are never submitted or stored.</p><div id="placeholder-editor" data-existing='<?= e(json_encode($command['placeholders'] ?? [])) ?>'></div></section>
      <button class="button" type="submit">Save command</button> <a class="subtle-link" href="/">Cancel</a>
    </form><?php page_end();
}
function save_command(?int $id = null): never {
    $user = require_editor(); verify_csrf();
    $title = trim($_POST['title'] ?? ''); $template = trim($_POST['template'] ?? '');
    $environment = trim($_POST['environment'] ?? ''); $description = trim($_POST['description'] ?? '');
    $safety = $_POST['safety_level'] ?? 'normal'; $verified = $_POST['last_verified_at'] ?: null;
    if (!$title || !$template || !$environment || !in_array($safety, ['normal','caution','destructive'], true)) { flash('error', 'Title, template, and environment are required.'); redirect($id ? "/commands/$id/edit" : '/commands/new'); }
    $keys = placeholder_keys($template); $metadata = $_POST['placeholders'] ?? [];
    db()->beginTransaction();
    try {
        if ($id) {
            store_command_version((int)$id, (int)$user['id'], 'Edited command');
            db()->prepare('UPDATE commands SET title=?,template=?,description=?,environment=?,safety_level=?,last_verified_at=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$title,$template,$description,$environment,$safety,$verified,$user['id'],$id]);
        } else {
            db()->prepare('INSERT INTO commands (title,template,description,environment,safety_level,last_verified_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$title,$template,$description,$environment,$safety,$verified,$user['id'],$user['id']]); $id = (int)db()->lastInsertId();
        }
        db()->prepare('DELETE FROM command_placeholders WHERE command_id=?')->execute([$id]);
        $insert = db()->prepare('INSERT INTO command_placeholders (command_id,placeholder_key,label,input_type,help_text,example_value,select_options,is_required,position) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($keys as $position => $key) { $m = $metadata[$key] ?? []; $type = $m['type'] ?? 'text'; if (!in_array($type,['text','password','hostname','path','port','multiline','select'],true)) $type='text'; $options=$type==='select'?placeholder_select_options(preg_split('/\R/',(string)($m['options'] ?? ''))):[]; $insert->execute([$id,$key,trim($m['label'] ?? '') ?: ucwords(str_replace('_',' ',$key)),$type,trim($m['help'] ?? '') ?: null,trim($m['example'] ?? '') ?: null,$options?json_encode($options):null,isset($m['required']) ? 1 : 0,$position]); }
        db()->prepare('DELETE FROM command_tags WHERE command_id=?')->execute([$id]);
        $tagInsert = db()->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT (name) DO UPDATE SET name=EXCLUDED.name RETURNING id');
        $link = db()->prepare('INSERT INTO command_tags (command_id,tag_id) VALUES (?,?)');
        foreach (array_unique(array_filter(array_map(fn($t)=>strtolower(trim($t)), explode(',', $_POST['tags'] ?? '')))) as $tag) { $tagInsert->execute([$tag]); $link->execute([$id,$tagInsert->fetchColumn()]); }
        db()->commit(); flash('success', 'Command saved.'); redirect('/commands/'.$id);
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
}

if ($method === 'GET' && $path === '/') {
    $user = require_auth(); $query = trim($_GET['q'] ?? ''); $environment = trim($_GET['environment'] ?? '');
    $commands=catalog_commands((int)$user['id'], $query, $environment); $envs=db()->query('SELECT DISTINCT environment FROM commands ORDER BY environment')->fetchAll(PDO::FETCH_COLUMN);
    page_start('Catalog'); ?>
    <section class="hero"><p class="eyebrow">Shared operational memory</p><h1>Find the command<br>you need.</h1><p>Reusable team commands, with safe temporary values when you need them.</p></section>
    <form class="catalog-controls" method="get"><div class="search-input-wrap"><input id="command-search" name="q" value="<?= e($query) ?>" placeholder="Search commands, tags, notes, or flags…" autofocus><button class="search-clear" type="button" aria-label="Clear search" title="Clear search" hidden>×</button></div><select id="environment-filter" name="environment"><option value="">All environments</option><?php foreach($envs as $env): ?><option <?= $environment===$env?'selected':'' ?>><?= e($env) ?></option><?php endforeach ?></select><button>Search</button></form>
    <section class="command-list" id="command-list" aria-live="polite"><?= render_command_cards($commands) ?></section><?php page_end(); exit;
}
if ($method === 'GET' && $path === '/api/commands') { $user=require_auth();$query=trim($_GET['q']??'');$environment=trim($_GET['environment']??'');$commands=catalog_commands((int)$user['id'],$query,$environment);header('Content-Type: application/json');echo json_encode(['html'=>render_command_cards($commands),'count'=>count($commands)]);exit; }
if ($method === 'POST' && $path === '/api/markdown-preview') { require_auth();verify_csrf();header('Content-Type: application/json');echo json_encode(['html'=>render_markdown((string)($_POST['markdown']??''))]);exit; }
if ($method === 'GET' && $path === '/admin/users') { admin_user_management_page(require_admin()); }
if ($method === 'POST' && $path === '/admin/export') { require_admin();verify_csrf();$json=json_encode(site_export_data(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="command-cabinet-export-'.gmdate('Y-m-d-His').'Z.json"');header('Cache-Control: private, no-store');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');echo $json;exit; }
if ($method === 'POST' && $path === '/admin/import') { require_admin();verify_csrf();if(($_POST['confirmation']??'')!=='REPLACE'){flash('error','Type REPLACE to confirm the full site restore.');redirect('/admin/users');}$upload=$_FILES['export_file']??null;if(!$upload || $upload['error']!==UPLOAD_ERR_OK){flash('error','Choose a valid JSON export file to restore.');redirect('/admin/users');}$size=(int)filesize($upload['tmp_name']);if($size<1 || $size>25*1024*1024){flash('error','The export file must be between 1 byte and 25 MB.');redirect('/admin/users');}try{$contents=file_get_contents($upload['tmp_name']);if($contents===false)throw new RuntimeException('Unable to read the uploaded export.');restore_site_export(json_decode($contents,true,512,JSON_THROW_ON_ERROR));$_SESSION=[];session_destroy();redirect('/login');}catch(Throwable $e){flash('error','Import failed: '.$e->getMessage());redirect('/admin/users');} }
if ($method === 'POST' && $path === '/admin/users') {
    $admin=require_admin(); verify_csrf(); $action=$_POST['action']??''; $id=(int)($_POST['user_id']??0);
    $role=$_POST['role']??'member'; if (!in_array($role,['admin','member','viewer'],true)) $role='member';
    $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $password=$_POST['password']??'';
    if ($action==='create') {
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) { flash('error','Name, a valid email address, and a password of at least 12 characters are required.'); redirect('/admin/users'); }
        $exists=db()->prepare('SELECT 1 FROM users WHERE email=?');$exists->execute([$email]); if($exists->fetch()){flash('error','That email address already belongs to a user.');redirect('/admin/users');}
        db()->prepare('INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES(?,?,?,?,NOW())')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]); flash('success','User created. Share the temporary password securely.'); redirect('/admin/users');
    }
    $target=db()->prepare('SELECT id,role FROM users WHERE id=?');$target->execute([$id]);$target=$target->fetch(); if(!$target){flash('error','User not found.');redirect('/admin/users');}
    if ($action==='profile') {
        if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Enter a name and valid email address.');redirect('/admin/users');}
        $exists=db()->prepare('SELECT 1 FROM users WHERE email=? AND id<>?');$exists->execute([$email,$id]);if($exists->fetch()){flash('error','That email address already belongs to another user.');redirect('/admin/users');}
        db()->prepare('UPDATE users SET name=?,email=?,updated_at=NOW() WHERE id=?')->execute([$name,$email,$id]);flash('success','User profile updated.');redirect('/admin/users');
    }
    if ($action==='password') {
        if(strlen($password)<12){flash('error','The new password must be at least 12 characters.');redirect('/admin/users');}
        db()->beginTransaction();try{db()->prepare('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);db()->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$id]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}flash('success','Password reset. Share it securely with the user.');redirect('/admin/users');
    }
    if ($action==='role') {
        if($id===(int)$admin['id']){flash('error','Change your own role through another administrator.');redirect('/admin/users');}
        if($target['role']==='admin' && $role==='member' && (int)db()->query("SELECT count(*) FROM users WHERE role='admin'")->fetchColumn()<=1){flash('error','At least one administrator must remain.');redirect('/admin/users');}
        db()->prepare('UPDATE users SET role=?,updated_at=NOW() WHERE id=?')->execute([$role,$id]);flash('success','User role updated.');redirect('/admin/users');
    }
    if ($action==='delete') {
        if($id===(int)$admin['id']){flash('error','You cannot delete your own account.');redirect('/admin/users');}
        if($target['role']==='admin' && (int)db()->query("SELECT count(*) FROM users WHERE role='admin'")->fetchColumn()<=1){flash('error','At least one administrator must remain.');redirect('/admin/users');}
        db()->beginTransaction(); try { db()->prepare('UPDATE commands SET created_by=? WHERE created_by=?')->execute([$admin['id'],$id]);db()->prepare('UPDATE commands SET updated_by=? WHERE updated_by=?')->execute([$admin['id'],$id]);db()->prepare('UPDATE command_versions SET changed_by=? WHERE changed_by=?')->execute([$admin['id'],$id]);db()->prepare('UPDATE invitations SET invited_by=? WHERE invited_by=?')->execute([$admin['id'],$id]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);db()->commit();flash('success','User deleted. Their command and revision attribution was reassigned to you.'); } catch(Throwable $e){db()->rollBack();throw $e;} redirect('/admin/users');
    }
    flash('error','Unknown user-management action.'); redirect('/admin/users');
}
if ($method === 'GET' && $path === '/register') { if (db()->query('SELECT count(*) FROM users')->fetchColumn() > 0) redirect('/login'); page_start('Create administrator'); ?> <section class="auth"><p class="eyebrow">First setup</p><h1>Create the administrator account</h1><form method="post" action="/register"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label><label>Password<input name="password" type="password" minlength="12" required></label><button class="button">Create account</button></form></section><?php page_end(); exit; }
if ($method === 'POST' && $path === '/register') { verify_csrf(); if (db()->query('SELECT count(*) FROM users')->fetchColumn()>0) redirect('/login'); $s=db()->prepare('INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES(?,?,?,\'admin\',NOW()) RETURNING id'); $s->execute([trim($_POST['name']),strtolower(trim($_POST['email'])),password_hash($_POST['password'],PASSWORD_DEFAULT)]); $_SESSION['user_id']=$s->fetchColumn(); redirect('/'); }
if ($method === 'GET' && $path === '/login') { page_start('Sign in'); ?> <section class="auth"><p class="eyebrow">Private team catalog</p><h1>Welcome back</h1><form method="post" action="/login"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Email<input name="email" type="email" required autofocus></label><label>Password<input name="password" type="password" required></label><button class="button">Sign in</button> <a class="subtle-link" href="/forgot-password">Forgot password?</a></form></section><?php page_end(); exit; }
if ($method === 'GET' && $path === '/forgot-password') { page_start('Reset password'); ?> <section class="auth"><p class="eyebrow">Account recovery</p><h1>Reset password</h1><p>Enter your email address and we’ll send a reset link if an account exists.</p><form method="post" action="/forgot-password"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Email<input name="email" type="email" required autofocus></label><button class="button">Send reset link</button> <a class="subtle-link" href="/login">Back to sign in</a></form></section><?php page_end();exit; }
if ($method === 'POST' && $path === '/forgot-password') { verify_csrf();$email=strtolower(trim($_POST['email']??''));if(filter_var($email,FILTER_VALIDATE_EMAIL)){$user=db()->prepare('SELECT id,email FROM users WHERE email=?');$user->execute([$email]);$user=$user->fetch();if($user){$recent=db()->prepare("SELECT 1 FROM password_resets WHERE user_id=? AND created_at>NOW()-INTERVAL '5 minutes' AND used_at IS NULL");$recent->execute([$user['id']]);if(!$recent->fetch()){db()->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$user['id']]);$token=bin2hex(random_bytes(32));db()->prepare("INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,NOW()+INTERVAL '1 hour')")->execute([$user['id'],hash('sha256',$token)]);$url=rtrim((string)env('APP_URL','http://localhost:8000'),'/').'/reset-password?token='.rawurlencode($token);if(!send_password_reset_email($user['email'],$url))error_log('Command Cabinet password-reset delivery failed for '.$user['email']);}}}flash('success','If an account exists for that address, a password-reset link has been sent.');redirect('/forgot-password'); }
if ($method === 'GET' && $path === '/reset-password') { $token=$_GET['token']??'';$s=db()->prepare('SELECT 1 FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()');$s->execute([hash('sha256',$token)]);if(!$s->fetch()){http_response_code(404);exit('This password-reset link is invalid or expired.');}page_start('Choose a new password'); ?> <section class="auth"><p class="eyebrow">Account recovery</p><h1>Choose a new password</h1><form method="post" action="/reset-password"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>New password<input name="password" type="password" minlength="12" required autofocus></label><label>Confirm new password<input name="password_confirmation" type="password" minlength="12" required></label><button class="button">Update password</button></form></section><?php page_end();exit; }
if ($method === 'POST' && $path === '/reset-password') { verify_csrf();$token=$_POST['token']??'';$password=$_POST['password']??'';if(strlen($password)<12 || !hash_equals($password,$_POST['password_confirmation']??'')){flash('error','Use matching passwords of at least 12 characters.');redirect('/reset-password?token='.rawurlencode($token));}db()->beginTransaction();try{$reset=db()->prepare('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$reset->execute([hash('sha256',$token)]);$reset=$reset->fetch();if(!$reset){db()->rollBack();http_response_code(404);exit('This password-reset link is invalid or expired.');}db()->prepare('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$reset['user_id']]);db()->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$reset['user_id']]);db()->commit();session_regenerate_id(true);$_SESSION['user_id']=$reset['user_id'];flash('success','Your password has been updated.');redirect('/');}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;} }
if ($method === 'GET' && $path === '/account/password') { require_auth();page_start('Change password'); ?> <section class="auth"><p class="eyebrow">Account settings</p><h1>Change password</h1><form method="post" action="/account/password"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Current password<input name="current_password" type="password" required autofocus></label><label>New password<input name="password" type="password" minlength="12" required></label><label>Confirm new password<input name="password_confirmation" type="password" minlength="12" required></label><button class="button">Update password</button> <a class="subtle-link" href="/">Cancel</a></form></section><?php page_end();exit; }
if ($method === 'POST' && $path === '/account/password') { $user=require_auth();verify_csrf();$password=$_POST['password']??'';if(strlen($password)<12 || !hash_equals($password,$_POST['password_confirmation']??'')){flash('error','Use matching passwords of at least 12 characters.');redirect('/account/password');}$current=db()->prepare('SELECT password_hash FROM users WHERE id=?');$current->execute([$user['id']]);if(!password_verify($_POST['current_password']??'',(string)$current->fetchColumn())){flash('error','Your current password was not recognized.');redirect('/account/password');}db()->beginTransaction();try{db()->prepare('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);db()->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$user['id']]);db()->commit();flash('success','Password updated.');redirect('/');}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;} }
if ($method === 'POST' && $path === '/login') { verify_csrf(); $s=db()->prepare('SELECT * FROM users WHERE email=?'); $s->execute([strtolower(trim($_POST['email']))]); $user=$s->fetch(); if(!$user || !password_verify($_POST['password']??'', $user['password_hash'])) { flash('error','Those sign-in details were not recognized.'); redirect('/login'); } session_regenerate_id(true); $_SESSION['user_id']=$user['id']; redirect('/'); }
if ($method === 'POST' && $path === '/logout') { verify_csrf(); $_SESSION=[]; session_destroy(); redirect('/login'); }
if ($method === 'GET' && $path === '/commands/new') { require_editor(); command_form(); exit; }
if (preg_match('#^/commands/(\d+)/clone$#',$path,$m) && $method==='GET') { require_editor();$s=db()->prepare('SELECT * FROM commands WHERE id=?');$s->execute([$m[1]]);$command=$s->fetch();if(!$command){http_response_code(404);exit('Not found');}$p=db()->prepare('SELECT * FROM command_placeholders WHERE command_id=? ORDER BY position');$p->execute([$m[1]]);$command['placeholders']=[];foreach($p as $row){$command['placeholders'][$row['placeholder_key']]=['label'=>$row['label'],'type'=>$row['input_type'],'help'=>$row['help_text'],'example'=>$row['example_value'],'options'=>placeholder_select_options($row['select_options'] ?? []),'required'=>(bool)$row['is_required']];}$command['tags']=tags_for((int)$m[1]);$command['id']=null;$command['title']='Copy of '.$command['title'];command_form($command);exit; }
if ($method === 'POST' && $path === '/commands') save_command();
if (preg_match('#^/commands/(\d+)/edit$#',$path,$m) && $method==='GET') { require_editor(); $s=db()->prepare('SELECT * FROM commands WHERE id=?');$s->execute([$m[1]]);$command=$s->fetch();if(!$command){http_response_code(404);exit('Not found');}$p=db()->prepare('SELECT * FROM command_placeholders WHERE command_id=? ORDER BY position');$p->execute([$m[1]]);$command['placeholders']=[];foreach($p as $row){$command['placeholders'][$row['placeholder_key']]=['label'=>$row['label'],'type'=>$row['input_type'],'help'=>$row['help_text'],'example'=>$row['example_value'],'options'=>placeholder_select_options($row['select_options'] ?? []),'required'=>(bool)$row['is_required']];}command_form($command);exit; }
if (preg_match('#^/commands/(\d+)/revisions/(\d+)/restore$#',$path,$m) && $method==='POST') { $user=require_admin();verify_csrf();$commandId=(int)$m[1];$versionId=(int)$m[2];db()->beginTransaction();try{$lock=db()->prepare('SELECT id FROM commands WHERE id=? FOR UPDATE');$lock->execute([$commandId]);if(!$lock->fetch())throw new RuntimeException('Command not found.');$version=db()->prepare('SELECT * FROM command_versions WHERE id=? AND command_id=?');$version->execute([$versionId,$commandId]);$version=$version->fetch();if(!$version)throw new RuntimeException('Revision not found.');store_command_version($commandId,(int)$user['id'],'Saved automatically before restoring revision #'.$versionId);db()->prepare('UPDATE commands SET title=?,template=?,description=?,environment=?,safety_level=?,last_verified_at=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$version['title'],$version['template'],$version['description'],$version['environment'],$version['safety_level'],$version['last_verified_at'],$user['id'],$commandId]);if($version['placeholders']===null){$placeholders=array_map(fn($key)=>['placeholder_key'=>$key,'label'=>ucwords(str_replace('_',' ',$key)),'input_type'=>'text','is_required'=>true],placeholder_keys($version['template']));$tagNames=tags_for($commandId);}else{$placeholders=json_decode($version['placeholders'],true,512,JSON_THROW_ON_ERROR);$tagNames=json_decode($version['tag_names']??'[]',true,512,JSON_THROW_ON_ERROR);}replace_command_metadata($commandId,$placeholders,$tagNames);db()->commit();flash('success','Revision restored. The previous current version was saved to history.');redirect('/commands/'.$commandId);}catch(Throwable $e){db()->rollBack();throw $e;} }
if (preg_match('#^/commands/(\d+)/(archive|unarchive)$#',$path,$m) && $method==='POST') { $user=require_admin();verify_csrf();$commandId=(int)$m[1];$archive=$m[2]==='archive';$statement=db()->prepare($archive ? 'UPDATE commands SET archived_at=NOW(),archived_by=?,updated_by=?,updated_at=NOW() WHERE id=? AND archived_at IS NULL' : 'UPDATE commands SET archived_at=NULL,archived_by=NULL,updated_by=?,updated_at=NOW() WHERE id=? AND archived_at IS NOT NULL');$statement->execute($archive ? [$user['id'],$user['id'],$commandId] : [$user['id'],$commandId]);if($statement->rowCount()===0){flash('error','Command was not found or was already in that state.');}else{flash('success',$archive ? 'Command archived. It is hidden from the catalog.' : 'Command restored to the catalog.');}redirect('/commands/'.$commandId); }
if (preg_match('#^/commands/(\d+)$#',$path,$m) && $method==='POST') save_command((int)$m[1]);
if (preg_match('#^/commands/(\d+)$#',$path,$m) && $method==='GET') { $viewer=require_auth();$s=db()->prepare('SELECT c.*,u.name author FROM commands c JOIN users u ON u.id=c.updated_by WHERE c.id=?');$s->execute([$m[1]]);$c=$s->fetch();if(!$c){http_response_code(404);exit('Not found');}$p=db()->prepare('SELECT * FROM command_placeholders WHERE command_id=? ORDER BY position');$p->execute([$m[1]]);$placeholders=$p->fetchAll();page_start($c['title']); ?> <a class="back" href="/">← All commands</a><article class="command-detail"><div class="detail-heading"><div><p class="eyebrow"><?= e($c['environment']) ?></p><h1><?= e($c['title']) ?></h1></div><a class="subtle-link" href="/commands/<?= $c['id'] ?>/edit">Edit</a></div><span class="badge <?= e($c['safety_level']) ?>"><?= e($c['safety_level']) ?></span><?php if($c['safety_level']!=='normal'): ?><p class="warning">Review this command carefully before running it.</p><?php endif; ?><div class="copy-block"><pre id="command-template"><code><?= e($c['template']) ?></code></pre><button class="button copy-raw" data-copy-template>Copy raw command</button></div><?php if($placeholders): ?><section class="fill-panel" data-template="<?= e($c['template']) ?>"><h2>Fill values and copy</h2><p>These values stay in this browser tab. They are never saved or sent to the server.</p><?php foreach($placeholders as $p): ?><label><?= e($p['label']) ?><?= $p['is_required'] ? ' *' : '' ?><?= render_placeholder_input($p) ?><small><?= e($p['help_text'] ?? '') ?></small></label><?php endforeach; ?><button class="button copy-filled">Copy filled command</button><button class="link-button clear-values" type="button">Clear values</button></section><?php endif; ?><?= render_archive_controls((int)$c['id'],$viewer) ?><section class="notes markdown-content"><h2>Notes</h2><?= render_markdown($c['description']) ?></section><footer class="detail-meta">Added by <?= e($c['author']) ?> · Updated <?= e(date('M j, Y',strtotime($c['updated_at']))) ?><?= $c['last_verified_at']?' · Last verified '.e($c['last_verified_at']):'' ?></footer></article><?php page_end();exit; }
if (preg_match('#^/api/commands/(\d+)/revisions$#',$path,$m) && $method==='GET') { $viewer=require_auth();header('Content-Type: application/json');echo json_encode(['html'=>render_revision_history((int)$m[1],$viewer),'copy_html'=>render_copy_status((int)$m[1])]);exit; }
if (preg_match('#^/api/commands/(\d+)/copied$#',$path,$m) && $method==='POST') { $user=require_editor();verify_csrf();$copy=db()->prepare('UPDATE commands SET last_copied_at=NOW(),last_copied_by=? WHERE id=?');$copy->execute([$user['id'],(int)$m[1]]);if($copy->rowCount()===0){http_response_code(404);echo json_encode(['error'=>'Command not found']);exit;}header('Content-Type: application/json');echo json_encode(['copy_html'=>render_copy_status((int)$m[1])]);exit; }
if ($method === 'POST' && $path === '/api/favorites') { $user=require_editor();verify_csrf();$id=(int)($_POST['command_id']??0);$s=db()->prepare('SELECT 1 FROM favorites WHERE user_id=? AND command_id=?');$s->execute([$user['id'],$id]);if($s->fetch())db()->prepare('DELETE FROM favorites WHERE user_id=? AND command_id=?')->execute([$user['id'],$id]);else db()->prepare('INSERT INTO favorites(user_id,command_id) VALUES(?,?) ON CONFLICT DO NOTHING')->execute([$user['id'],$id]);header('Content-Type: application/json');echo json_encode(['ok'=>true]);exit; }
if ($method === 'GET' && $path === '/invitations') { require_admin(); page_start('Invite team member'); ?> <section class="auth"><p class="eyebrow">Team access</p><h1>Invite a teammate</h1><form method="post" action="/invitations"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Email<input name="email" type="email" required></label><label>Role<select name="role"><option value="member">Member</option><option value="viewer">View only</option><option value="admin">Administrator</option></select></label><button class="button">Create invitation</button></form></section><?php page_end();exit; }
if ($method === 'POST' && $path === '/invitations') { $u=require_admin();verify_csrf();$email=strtolower(trim($_POST['email']));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Enter a valid email address.');redirect('/invitations');}$role=in_array($_POST['role']??'',['admin','member','viewer'],true)?$_POST['role']:'member';$token=bin2hex(random_bytes(32));db()->prepare('INSERT INTO invitations(email,role,token_hash,invited_by,expires_at) VALUES(?,?,?,?,NOW()+INTERVAL \'7 days\')')->execute([$email,$role,hash('sha256',$token),$u['id']]);$url=rtrim((string)env('APP_URL','http://localhost:8000'),'/').'/accept-invite?token='.$token;if(send_invitation_email($email,$url)){flash('success','Invitation created and email sent.');}else{error_log("Command Cabinet invitation delivery failed for {$email}: {$url}");flash('error','Invitation was created, but Postfix did not accept the message. Check the PHP and Postfix mail logs.');}redirect('/invitations'); }
if ($method === 'GET' && $path === '/accept-invite') { $token=$_GET['token']??'';$s=db()->prepare('SELECT * FROM invitations WHERE token_hash=? AND accepted_at IS NULL AND expires_at>NOW()');$s->execute([hash('sha256',$token)]);$i=$s->fetch();if(!$i){http_response_code(404);exit('This invitation is invalid or expired.');}page_start('Accept invitation'); ?> <section class="auth"><p class="eyebrow">You’re invited</p><h1>Join Command Cabinet</h1><form method="post" action="/accept-invite"><input type="hidden" name="_token" value="<?=e(csrf_token())?>"><input type="hidden" name="token" value="<?=e($token)?>"><label>Name<input name="name" required></label><label>Email<input value="<?=e($i['email'])?>" disabled></label><label>Password<input name="password" type="password" minlength="12" required></label><button class="button">Create account</button></form></section><?php page_end();exit; }
if ($method === 'POST' && $path === '/accept-invite') {verify_csrf();$token=$_POST['token']??'';db()->beginTransaction();$s=db()->prepare('SELECT * FROM invitations WHERE token_hash=? AND accepted_at IS NULL AND expires_at>NOW() FOR UPDATE');$s->execute([hash('sha256',$token)]);$i=$s->fetch();if(!$i){db()->rollBack();exit('Invitation expired.');}$s=db()->prepare('INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES(?,?,?,?,NOW()) RETURNING id');$s->execute([trim($_POST['name']),$i['email'],password_hash($_POST['password'],PASSWORD_DEFAULT),$i['role']]);$id=$s->fetchColumn();db()->prepare('UPDATE invitations SET accepted_at=NOW() WHERE id=?')->execute([$i['id']]);db()->commit();$_SESSION['user_id']=$id;redirect('/');}
http_response_code(404); echo 'Page not found.';
