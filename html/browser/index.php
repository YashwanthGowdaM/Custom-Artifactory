<?php
// =====================================================================
//  Artifact Repository — browser UI
//  The BACKEND section below is unchanged: config, helpers, POST handlers
//  (upload / mkdir), listing and download URLs. Only markup/UI changed.
// =====================================================================

// ---------------------------------------------------------------------
//  BACKEND (unchanged)
// ---------------------------------------------------------------------
// Artifact Repository browser with subfolder support
$baseDir = '/var/www/html/artifacts';   // must match nginx /artifacts/ location
$baseUrl = '/artifacts';                // URL prefix served by nginx WebDAV

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// Returns a cleaned relative path, or null if it tries to escape the base dir
function safeRel($rel) {
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', (string)$rel)) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..' || strpos($p, "\0") !== false) return null;
        $parts[] = $p;
    }
    return implode('/', $parts);
}

function encodePath($rel) {
    return implode('/', array_map('rawurlencode', explode('/', $rel)));
}

$rel = safeRel($_GET['path'] ?? '');
if ($rel === null) { $rel = ''; }
$current = $baseDir . ($rel !== '' ? '/' . $rel : '');
if (!is_dir($current)) { $rel = ''; $current = $baseDir; }

// ---- Handle POST (upload / create folder) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postRel = safeRel($_POST['path'] ?? '');
    $target  = $baseDir . ($postRel ? '/' . $postRel : '');
    $msg = ''; $type = 'success';

    if ($postRel === null || !is_dir($target)) {
        $msg = 'Invalid folder.'; $type = 'danger';
    } elseif (($_POST['action'] ?? '') === 'mkdir') {
        $name = trim($_POST['folder'] ?? '');
        if ($name === '' || preg_match('#[/\\\\]#', $name) || $name === '.' || $name === '..' || $name[0] === '.') {
            $msg = 'Invalid folder name.'; $type = 'danger';
        } elseif (file_exists("$target/$name")) {
            $msg = 'Folder already exists.'; $type = 'warning';
        } elseif (mkdir("$target/$name", 0775)) {
            $msg = "Folder '$name' created.";
        } else {
            $msg = 'Could not create folder (check permissions).'; $type = 'danger';
        }
    } elseif (($_POST['action'] ?? '') === 'upload') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? -1;
            $msg = "Upload failed (error code $err). Check php.ini upload limits."; $type = 'danger';
        } else {
            $name = basename($_FILES['file']['name']);
            if ($name === '' || $name[0] === '.') {
                $msg = 'Invalid file name.'; $type = 'danger';
            } elseif (move_uploaded_file($_FILES['file']['tmp_name'], "$target/$name")) {
                chmod("$target/$name", 0664);
                $msg = "Uploaded '$name'.";
            } else {
                $msg = 'Could not save file (check permissions).'; $type = 'danger';
            }
        }
    }

    $q = http_build_query(['path' => $postRel ?: '', 'msg' => $msg, 'type' => $type]);
    header('Location: /browser/?' . $q);
    exit;
}

// ---- Build listing ----
$dirs = []; $files = [];
foreach (scandir($current) as $item) {
    if ($item[0] === '.') continue;
    $full = "$current/$item";
    $entry = ['name' => $item, 'mtime' => filemtime($full)];
    if (is_dir($full)) { $dirs[] = $entry; }
    else { $entry['size'] = filesize($full); $files[] = $entry; }
}
usort($dirs,  fn($a, $b) => strcasecmp($a['name'], $b['name']));
usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$crumbs = $rel === '' ? [] : explode('/', $rel);
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$flashMsg  = $_GET['msg'] ?? '';
$flashType = in_array($_GET['type'] ?? '', ['success', 'danger', 'warning']) ? $_GET['type'] : 'success';

// ---------------------------------------------------------------------
//  UI HELPERS (read-only: they only read the filesystem for display)
// ---------------------------------------------------------------------

// Icon + colour class per file type (Bootstrap Icons)
function fileIcon($name) {
    static $map = [
        // archives
        'zip' => ['bi-file-earmark-zip', 'ft-archive'], '7z' => ['bi-file-earmark-zip', 'ft-archive'],
        'rar' => ['bi-file-earmark-zip', 'ft-archive'], 'gz' => ['bi-file-earmark-zip', 'ft-archive'],
        'bz2' => ['bi-file-earmark-zip', 'ft-archive'], 'xz' => ['bi-file-earmark-zip', 'ft-archive'],
        'zst' => ['bi-file-earmark-zip', 'ft-archive'], 'tar' => ['bi-file-earmark-zip', 'ft-archive'],
        // config / data
        'yaml' => ['bi-filetype-yml', 'ft-config'], 'yml' => ['bi-filetype-yml', 'ft-config'],
        'json' => ['bi-filetype-json', 'ft-config'], 'xml' => ['bi-filetype-xml', 'ft-config'],
        'toml' => ['bi-file-earmark-code', 'ft-config'], 'ini' => ['bi-file-earmark-code', 'ft-config'],
        'conf' => ['bi-file-earmark-code', 'ft-config'], 'cfg' => ['bi-file-earmark-code', 'ft-config'],
        'env'  => ['bi-file-earmark-code', 'ft-config'], 'properties' => ['bi-file-earmark-code', 'ft-config'],
        // text / docs
        'txt' => ['bi-filetype-txt', 'ft-text'], 'log' => ['bi-filetype-txt', 'ft-text'],
        'md'  => ['bi-filetype-md', 'ft-text'],
        'pdf' => ['bi-filetype-pdf', 'ft-pdf'],
        'doc' => ['bi-filetype-doc', 'ft-word'], 'docx' => ['bi-filetype-docx', 'ft-word'],
        'xls' => ['bi-filetype-xls', 'ft-excel'], 'xlsx' => ['bi-filetype-xlsx', 'ft-excel'],
        'csv' => ['bi-filetype-csv', 'ft-excel'],
        'ppt' => ['bi-filetype-ppt', 'ft-ppt'], 'pptx' => ['bi-filetype-pptx', 'ft-ppt'],
        // images
        'png' => ['bi-filetype-png', 'ft-image'], 'jpg' => ['bi-filetype-jpg', 'ft-image'],
        'jpeg' => ['bi-filetype-jpg', 'ft-image'], 'gif' => ['bi-filetype-gif', 'ft-image'],
        'svg' => ['bi-filetype-svg', 'ft-image'], 'webp' => ['bi-file-earmark-image', 'ft-image'],
        'bmp' => ['bi-file-earmark-image', 'ft-image'], 'ico' => ['bi-file-earmark-image', 'ft-image'],
        // media
        'mp4' => ['bi-filetype-mp4', 'ft-video'], 'mov' => ['bi-filetype-mov', 'ft-video'],
        'mkv' => ['bi-file-earmark-play', 'ft-video'], 'avi' => ['bi-file-earmark-play', 'ft-video'],
        'webm' => ['bi-file-earmark-play', 'ft-video'],
        'mp3' => ['bi-filetype-mp3', 'ft-audio'], 'wav' => ['bi-filetype-wav', 'ft-audio'],
        'flac' => ['bi-file-earmark-music', 'ft-audio'], 'ogg' => ['bi-file-earmark-music', 'ft-audio'],
        'm4a' => ['bi-file-earmark-music', 'ft-audio'],
        // databases / disks
        'sql' => ['bi-filetype-sql', 'ft-db'], 'db' => ['bi-database', 'ft-db'],
        'sqlite' => ['bi-database', 'ft-db'], 'dump' => ['bi-database', 'ft-db'],
        'iso' => ['bi-disc', 'ft-disk'], 'img' => ['bi-disc', 'ft-disk'],
        'vmdk' => ['bi-disc', 'ft-disk'], 'qcow2' => ['bi-disc', 'ft-disk'], 'ova' => ['bi-disc', 'ft-disk'],
        // shell
        'sh' => ['bi-filetype-sh', 'ft-shell'], 'bash' => ['bi-filetype-sh', 'ft-shell'],
        'zsh' => ['bi-filetype-sh', 'ft-shell'], 'ps1' => ['bi-terminal', 'ft-shell'],
        'bat' => ['bi-terminal', 'ft-shell'], 'cmd' => ['bi-terminal', 'ft-shell'],
        // packages
        'deb' => ['bi-box-seam', 'ft-package'], 'rpm' => ['bi-box-seam', 'ft-package'],
        'apk' => ['bi-box-seam', 'ft-package'], 'pkg' => ['bi-box-seam', 'ft-package'],
        'dmg' => ['bi-box-seam', 'ft-package'], 'jar' => ['bi-box-seam', 'ft-package'],
        'war' => ['bi-box-seam', 'ft-package'], 'ear' => ['bi-box-seam', 'ft-package'],
        'whl' => ['bi-box-seam', 'ft-package'], 'gem' => ['bi-box-seam', 'ft-package'],
        'nupkg' => ['bi-box-seam', 'ft-package'],
        // terraform
        'tf' => ['bi-layers', 'ft-terraform'], 'tfvars' => ['bi-layers', 'ft-terraform'],
        'tfstate' => ['bi-layers', 'ft-terraform'], 'hcl' => ['bi-layers', 'ft-terraform'],
        // source code
        'py' => ['bi-filetype-py', 'ft-code'], 'js' => ['bi-filetype-js', 'ft-code'],
        'php' => ['bi-filetype-php', 'ft-code'], 'java' => ['bi-filetype-java', 'ft-code'],
        'rb' => ['bi-filetype-rb', 'ft-code'], 'css' => ['bi-filetype-css', 'ft-code'],
        'html' => ['bi-filetype-html', 'ft-code'], 'scss' => ['bi-filetype-scss', 'ft-code'],
        'go' => ['bi-file-earmark-code', 'ft-code'], 'rs' => ['bi-file-earmark-code', 'ft-code'],
        'c' => ['bi-file-earmark-code', 'ft-code'], 'cpp' => ['bi-file-earmark-code', 'ft-code'],
        'ts' => ['bi-file-earmark-code', 'ft-code'],
        // certificates / keys
        'pem' => ['bi-file-earmark-lock2', 'ft-key'], 'crt' => ['bi-file-earmark-lock2', 'ft-key'],
        'cer' => ['bi-file-earmark-lock2', 'ft-key'], 'pub' => ['bi-file-earmark-lock2', 'ft-key'],
        'p12' => ['bi-file-earmark-lock2', 'ft-key'], 'pfx' => ['bi-file-earmark-lock2', 'ft-key'],
        // binaries
        'exe' => ['bi-filetype-exe', 'ft-binary'], 'msi' => ['bi-file-earmark-binary', 'ft-binary'],
        'bin' => ['bi-file-earmark-binary', 'ft-binary'],
    ];
    $n = strtolower($name);
    if (preg_match('/\.(tar(\.(gz|bz2|xz|zst))?|tgz|tbz2|txz)$/', $n)) return ['bi-file-earmark-zip', 'ft-archive'];
    if ($n === 'dockerfile' || strpos($n, 'dockerfile.') === 0 || substr($n, -11) === '.dockerfile'
        || preg_match('/^docker-compose.*\.ya?ml$/', $n)) return ['bi-boxes', 'ft-docker'];
    $ext = pathinfo($n, PATHINFO_EXTENSION);
    return $map[$ext] ?? ['bi-file-earmark', 'ft-default'];
}

// Whole-repository totals for the dashboard cards
function repoStats($baseDir) {
    $s = ['files' => 0, 'folders' => 0, 'size' => 0, 'last' => 0, 'lastName' => ''];
    if (!is_dir($baseDir)) return $s;
    try {
        $dirIt  = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dirIt, function ($cur) {
            return $cur->getFilename()[0] !== '.';
        });
        $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $info) {
            if ($info->isDir()) { $s['folders']++; continue; }
            $s['files']++;
            $s['size'] += $info->getSize();
            $m = $info->getMTime();
            if ($m > $s['last']) { $s['last'] = $m; $s['lastName'] = $info->getFilename(); }
        }
    } catch (Throwable $e) { /* unreadable entries are skipped */ }
    return $s;
}

// Folder tree for the sidebar (depth/size limited)
function buildTree($baseDir, $rel, $depth, $maxDepth = 5) {
    $path = $baseDir . ($rel !== '' ? '/' . $rel : '');
    $out = [];
    $items = @scandir($path);
    if ($items === false) return $out;
    natcasesort($items);
    $count = 0;
    foreach ($items as $it) {
        if ($it[0] === '.' || !is_dir("$path/$it")) continue;
        if (++$count > 200) break;
        $childRel = ($rel !== '' ? "$rel/" : '') . $it;
        $out[] = [
            'name' => $it,
            'rel' => $childRel,
            'children' => $depth < $maxDepth ? buildTree($baseDir, $childRel, $depth + 1, $maxDepth) : [],
        ];
    }
    return $out;
}

function renderTreeNodes(array $nodes, $currentRel, callable $h) {
    $html = '';
    foreach ($nodes as $n) {
        $isCurrent  = $n['rel'] === $currentRel;
        $isAncestor = $currentRel !== '' && strpos($currentRel . '/', $n['rel'] . '/') === 0;
        $hasKids    = !empty($n['children']);
        $expanded   = $isAncestor && $hasKids;
        $html .= '<li role="treeitem" class="tree-item"'
              . ($hasKids ? ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"' : '')
              . ' aria-selected="' . ($isCurrent ? 'true' : 'false') . '">';
        $html .= '<div class="tree-row' . ($isCurrent ? ' active' : '') . '">';
        $html .= $hasKids
            ? '<button type="button" class="tree-toggle" tabindex="-1" aria-label="Expand or collapse ' . $h($n['name']) . '"><i class="bi bi-chevron-right"></i></button>'
            : '<span class="tree-spacer"></span>';
        $html .= '<a class="tree-link" href="/browser/?path=' . $h(rawurlencode($n['rel'])) . '" tabindex="' . ($isCurrent ? '0' : '-1') . '"'
              . ($isCurrent ? ' aria-current="page"' : '') . ' title="' . $h($n['name']) . '">'
              . '<i class="bi ' . (($isCurrent || $isAncestor) ? 'bi-folder2-open' : 'bi-folder-fill') . '"></i>'
              . '<span>' . $h($n['name']) . '</span></a>';
        $html .= '</div>';
        if ($hasKids) {
            $html .= '<ul role="group" class="tree-children"' . ($expanded ? '' : ' hidden') . '>'
                  . renderTreeNodes($n['children'], $currentRel, $h) . '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}

// Row action buttons. $primaryHref is already HTML-escaped by the caller.
function renderActions($isDir, $name, $primaryHref, callable $h) {
    $n = $h($name);
    $o = '<div class="actions">';
    if ($isDir) {
        $o .= '<a class="icon-btn act-open" href="' . $primaryHref . '" data-tip="Open folder" aria-label="Open folder ' . $n . '"><i class="bi bi-folder2-open"></i></a>';
    } else {
        $o .= '<a class="icon-btn act-download" href="' . $primaryHref . '" download data-tip="Download" aria-label="Download ' . $n . '"><i class="bi bi-download"></i></a>';
    }
    $o .= '<button type="button" class="icon-btn d-none d-md-inline-flex" data-action="copy-url" data-tip="Copy URL" aria-label="Copy URL of ' . $n . '"><i class="bi bi-link-45deg"></i></button>';
    $o .= '<button type="button" class="icon-btn d-none d-md-inline-flex" data-action="rename" data-tip="Rename" aria-label="Rename ' . $n . '"><i class="bi bi-pencil-square"></i></button>';
    $o .= '<button type="button" class="icon-btn danger d-none d-md-inline-flex" data-action="delete" data-tip="Delete" aria-label="Delete ' . $n . '"><i class="bi bi-trash3"></i></button>';
    $o .= '<div class="dropdown">';
    $o .= '<button type="button" class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" data-tip="More" aria-label="More actions for ' . $n . '"><i class="bi bi-three-dots"></i></button>';
    $o .= '<ul class="dropdown-menu dropdown-menu-end">';
    $o .= '<li class="d-md-none"><button type="button" class="dropdown-item" data-action="copy-url"><i class="bi bi-link-45deg"></i>Copy URL</button></li>';
    $o .= '<li class="d-md-none"><button type="button" class="dropdown-item" data-action="rename"><i class="bi bi-pencil-square"></i>Rename</button></li>';
    $o .= '<li class="d-md-none"><button type="button" class="dropdown-item text-danger-ink" data-action="delete"><i class="bi bi-trash3"></i>Delete</button></li>';
    $o .= '<li class="d-md-none"><hr class="dropdown-divider"></li>';
    if ($isDir) {
        $o .= '<li><button type="button" class="dropdown-item" data-action="copy-upload"><i class="bi bi-terminal"></i>Copy curl upload command</button></li>';
    } else {
        $o .= '<li><button type="button" class="dropdown-item" data-action="copy-curl"><i class="bi bi-terminal"></i>Copy curl command</button></li>';
        $o .= '<li><button type="button" class="dropdown-item" data-action="copy-wget"><i class="bi bi-terminal-plus"></i>Copy wget command</button></li>';
    }
    $o .= '<li><button type="button" class="dropdown-item" data-action="props"><i class="bi bi-info-circle"></i>Properties</button></li>';
    $o .= '</ul></div></div>';
    return $o;
}

$stats = repoStats($baseDir);
$tree  = buildTree($baseDir, '', 1, 5);
$user  = $_SERVER['REMOTE_USER'] ?? ($_SERVER['PHP_AUTH_USER'] ?? 'user');
$user  = preg_replace('/[^\w.@-]/', '', (string)$user) ?: 'user';
$appConfig = [
    'baseUrl' => $baseUrl,
    'path'    => $rel,
    'user'    => $user,
];
?>
<!DOCTYPE html>
<html lang="en" class="no-js" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Artifact Repository<?= $rel !== '' ? ' · ' . $h($rel) : '' ?></title>

    <!-- Apply saved theme / sidebar state before first paint (no flicker) -->
    <script>
    (function () {
        var r = document.documentElement;
        r.classList.replace('no-js', 'js');
        try {
            var t = localStorage.getItem('ar-theme');
            if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            r.setAttribute('data-bs-theme', t);
            if (localStorage.getItem('ar-sidebar') === 'collapsed' && matchMedia('(min-width: 992px)').matches) {
                r.classList.add('sb-collapsed');
            }
        } catch (e) {}
    })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/browser/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ======================= SIDEBAR ======================= -->
<aside class="sidebar" id="sidebar" aria-label="Repository navigation">
    <div class="sb-head">
        <a class="brand" href="/browser/" aria-label="Artifact Repository home">
            <span class="brand-mark"><i class="bi bi-box-seam-fill"></i></span>
            <span class="brand-text"><strong>Artifact Repo</strong><small>Enterprise registry</small></span>
        </a>
        <button type="button" class="icon-btn sb-close" id="sidebarClose" aria-label="Collapse sidebar" data-tip="Collapse sidebar">
            <i class="bi bi-layout-sidebar-inset"></i>
        </button>
    </div>

    <div class="sb-section">Folders</div>
    <nav class="sb-scroll" aria-label="Folder tree">
        <ul class="tree" role="tree" aria-label="Repository folders">
            <li role="treeitem" class="tree-item" aria-expanded="true" aria-selected="<?= $rel === '' ? 'true' : 'false' ?>">
                <div class="tree-row<?= $rel === '' ? ' active' : '' ?>">
                    <span class="tree-spacer"></span>
                    <a class="tree-link" href="/browser/" tabindex="<?= $rel === '' ? '0' : '-1' ?>"<?= $rel === '' ? ' aria-current="page"' : '' ?>>
                        <i class="bi bi-hdd-stack-fill"></i><span>All artifacts</span>
                    </a>
                </div>
                <?php if ($tree): ?>
                <ul role="group" class="tree-children tree-root-children">
                    <?= renderTreeNodes($tree, $rel, $h) ?>
                </ul>
                <?php endif; ?>
            </li>
        </ul>
        <?php if (!$tree): ?>
            <p class="sb-empty">No folders yet. Use <strong>New folder</strong> to create one.</p>
        <?php endif; ?>
    </nav>

    <div class="sb-foot">
        <div class="endpoint">
            <span class="endpoint-label">WebDAV endpoint</span>
            <div class="endpoint-row">
                <code id="endpointText"><?= $h($baseUrl) ?>/</code>
                <button type="button" class="icon-btn" data-action="copy-endpoint" data-tip="Copy endpoint" aria-label="Copy WebDAV endpoint URL"><i class="bi bi-copy"></i></button>
            </div>
        </div>
    </div>
</aside>

<div class="main-wrap">
    <!-- ======================= TOP HEADER ======================= -->
    <header class="topbar" role="banner">
        <div class="topbar-left">
            <button type="button" class="icon-btn bordered" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="sidebar" aria-expanded="true" data-tip="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="titles">
                <h1 class="page-title">Artifact Repository</h1>
                <nav aria-label="Breadcrumb">
                    <ol class="crumbs">
                        <li><a href="/browser/"><i class="bi bi-house-door-fill" aria-hidden="true"></i><span>Home</span></a></li>
                        <?php $acc = []; foreach ($crumbs as $i => $c): $acc[] = $c; $isLast = $i === count($crumbs) - 1; ?>
                            <li<?= $isLast ? ' aria-current="page"' : '' ?>>
                                <?php if ($isLast): ?>
                                    <span><?= $h($c) ?></span>
                                <?php else: ?>
                                    <a href="/browser/?path=<?= $h(rawurlencode(implode('/', $acc))) ?>"><?= $h($c) ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="topbar-search">
            <div class="search-box" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="searchInput" type="search" class="form-control" placeholder="Search in this folder…"
                       aria-label="Search files and folders in this folder" autocomplete="off" spellcheck="false">
                <kbd class="search-kbd" aria-hidden="true">/</kbd>
            </div>
        </div>

        <div class="topbar-actions">
            <button type="button" class="btn btn-primary btn-pill" data-bs-toggle="modal" data-bs-target="#uploadModal" data-tip="Upload files (U)">
                <i class="bi bi-cloud-arrow-up-fill"></i><span class="d-none d-lg-inline">Upload</span>
            </button>
            <button type="button" class="btn btn-soft btn-pill" data-bs-toggle="modal" data-bs-target="#folderModal" data-tip="New folder (N)">
                <i class="bi bi-folder-plus"></i><span class="d-none d-lg-inline">New folder</span>
            </button>
            <button type="button" class="icon-btn bordered" id="refreshBtn" aria-label="Refresh" data-tip="Refresh (R)">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <button type="button" class="icon-btn bordered" id="themeToggle" aria-label="Toggle dark mode" aria-pressed="false" data-tip="Dark mode (D)">
                <i class="bi bi-moon-stars-fill theme-moon"></i><i class="bi bi-sun-fill theme-sun"></i>
            </button>
            <div class="dropdown">
                <button type="button" class="user-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User menu">
                    <span class="avatar"><?= $h(strtoupper(substr($user, 0, 1))) ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end user-menu">
                    <div class="user-head">
                        <span class="avatar avatar-lg"><?= $h(strtoupper(substr($user, 0, 1))) ?></span>
                        <div>
                            <div class="fw-semibold"><?= $h($user) ?></div>
                            <div class="small text-muted">Basic authentication</div>
                        </div>
                    </div>
                    <hr class="dropdown-divider">
                    <button type="button" class="dropdown-item" data-action="copy-endpoint"><i class="bi bi-link-45deg"></i>Copy endpoint URL</button>
                    <hr class="dropdown-divider">
                    <div class="dropdown-item-text shortcuts">
                        <div class="small text-muted mb-1">Keyboard shortcuts</div>
                        <div><kbd>/</kbd> Search</div>
                        <div><kbd>U</kbd> Upload</div>
                        <div><kbd>N</kbd> New folder</div>
                        <div><kbd>R</kbd> Refresh</div>
                        <div><kbd>D</kbd> Dark mode</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- ======================= CONTENT ======================= -->
    <main id="main" class="content" tabindex="-1">

        <?php if ($flashMsg !== ''): ?>
            <noscript><div class="alert alert-<?= $flashType ?> py-2"><?= $h($flashMsg) ?></div></noscript>
        <?php endif; ?>

        <!-- Dashboard cards -->
        <section class="stats" aria-label="Repository statistics">
            <div class="stat-card" style="--c:#2563EB">
                <span class="stat-icon"><i class="bi bi-files"></i></span>
                <div class="stat-body">
                    <div class="stat-label">Total files</div>
                    <div class="stat-value"><?= number_format($stats['files']) ?></div>
                </div>
            </div>
            <div class="stat-card" style="--c:#F59E0B">
                <span class="stat-icon"><i class="bi bi-folder2-open"></i></span>
                <div class="stat-body">
                    <div class="stat-label">Total folders</div>
                    <div class="stat-value"><?= number_format($stats['folders']) ?></div>
                </div>
            </div>
            <div class="stat-card" style="--c:#8B5CF6">
                <span class="stat-icon"><i class="bi bi-hdd-stack"></i></span>
                <div class="stat-body">
                    <div class="stat-label">Repository size</div>
                    <div class="stat-value"><?= $h(formatSize($stats['size'])) ?></div>
                </div>
            </div>
            <div class="stat-card" style="--c:#22C55E">
                <span class="stat-icon"><i class="bi bi-clock-history"></i></span>
                <div class="stat-body">
                    <div class="stat-label">Last upload</div>
                    <?php if ($stats['last']): ?>
                        <div class="stat-value stat-value-sm"><?= date('d-M-Y H:i', $stats['last']) ?></div>
                        <div class="stat-sub" title="<?= $h($stats['lastName']) ?>">
                            <span data-ts="<?= (int)$stats['last'] ?>"></span> · <?= $h($stats['lastName']) ?>
                        </div>
                    <?php else: ?>
                        <div class="stat-value stat-value-sm">—</div>
                        <div class="stat-sub">Nothing uploaded yet</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- File table -->
        <section class="card-soft table-card" aria-labelledby="listTitle">
            <div class="table-head">
                <div>
                    <h2 id="listTitle" class="table-title">Contents</h2>
                    <p class="table-sub" id="resultCount" aria-live="polite"><?= count($dirs) ?> folder<?= count($dirs) === 1 ? '' : 's' ?> · <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></p>
                </div>
                <code class="path-chip" title="/<?= $h($rel) ?>"><i class="bi bi-hdd-network" aria-hidden="true"></i><span>/<?= $h($rel) ?></span></code>
            </div>

            <table class="repo-table" id="repoTable">
                <caption class="visually-hidden">Files and folders in /<?= $h($rel) ?></caption>
                <thead>
                    <tr>
                        <th scope="col" class="col-icon"><span class="visually-hidden">Type</span></th>
                        <th scope="col" class="col-name" aria-sort="ascending">
                            <button type="button" class="th-sort active" data-sort="name">File <i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                        </th>
                        <th scope="col" class="col-size" aria-sort="none">
                            <button type="button" class="th-sort" data-sort="size">Size <i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                        </th>
                        <th scope="col" class="col-mod" aria-sort="none">
                            <button type="button" class="th-sort" data-sort="mtime">Modified <i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                        </th>
                        <th scope="col" class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="fileRows">
                <?php if ($rel !== ''): ?>
                    <?php $parent = safeRel(dirname($rel) === '.' ? '' : dirname($rel)); ?>
                    <tr class="up-row">
                        <td class="col-icon"><span class="ficon ft-default"><i class="bi bi-arrow-up-left"></i></span></td>
                        <td class="col-name" colspan="4">
                            <a class="name-link" href="/browser/<?= $parent ? '?path=' . $h(rawurlencode($parent)) : '' ?>">
                                <span class="name-text">.. <span class="text-muted fw-normal">Parent folder</span></span>
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($dirs as $d): $sub = ($rel !== '' ? "$rel/" : '') . $d['name']; ?>
                    <tr class="item-row" data-type="dir"
                        data-name="<?= $h($d['name']) ?>"
                        data-rel="<?= $h($sub) ?>"
                        data-url="<?= $h($baseUrl . '/' . encodePath($sub) . '/') ?>"
                        data-size="0" data-sizeh="—"
                        data-mtime="<?= (int)$d['mtime'] ?>"
                        data-mod="<?= date('d-M-Y H:i:s', $d['mtime']) ?>">
                        <td class="col-icon"><span class="ficon ft-folder"><i class="bi bi-folder-fill"></i></span></td>
                        <td class="col-name">
                            <a class="name-link is-folder" href="/browser/?path=<?= $h(rawurlencode($sub)) ?>">
                                <span class="name-text"><?= $h($d['name']) ?></span>
                            </a>
                        </td>
                        <td class="col-size text-muted">—</td>
                        <td class="col-mod">
                            <span class="mod-abs"><?= date('d-M-Y H:i:s', $d['mtime']) ?></span>
                            <span class="mod-rel" data-ts="<?= (int)$d['mtime'] ?>"></span>
                        </td>
                        <td class="col-actions"><?= renderActions(true, $d['name'], $h('/browser/?path=' . rawurlencode($sub)), $h) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ($files as $f):
                    $fileRel = ($rel !== '' ? "$rel/" : '') . $f['name'];
                    $dlHref  = $baseUrl . '/' . encodePath($fileRel);
                    [$ico, $cls] = fileIcon($f['name']);
                ?>
                    <tr class="item-row" data-type="file"
                        data-name="<?= $h($f['name']) ?>"
                        data-rel="<?= $h($fileRel) ?>"
                        data-url="<?= $h($dlHref) ?>"
                        data-size="<?= (int)$f['size'] ?>" data-sizeh="<?= $h(formatSize($f['size'])) ?>"
                        data-mtime="<?= (int)$f['mtime'] ?>"
                        data-mod="<?= date('d-M-Y H:i:s', $f['mtime']) ?>">
                        <td class="col-icon"><span class="ficon <?= $cls ?>"><i class="bi <?= $ico ?>"></i></span></td>
                        <td class="col-name">
                            <span class="name-link"><span class="name-text" title="<?= $h($f['name']) ?>"><?= $h($f['name']) ?></span></span>
                        </td>
                        <td class="col-size"><?= formatSize($f['size']) ?></td>
                        <td class="col-mod">
                            <span class="mod-abs"><?= date('d-M-Y H:i:s', $f['mtime']) ?></span>
                            <span class="mod-rel" data-ts="<?= (int)$f['mtime'] ?>"></span>
                        </td>
                        <td class="col-actions"><?= renderActions(false, $f['name'], $h($dlHref), $h) ?></td>
                    </tr>
                <?php endforeach; ?>

                    <tr class="state-row" id="noMatchRow" hidden>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="bi bi-search"></i>
                                <h3>No matches</h3>
                                <p>Nothing in this folder matches your search.</p>
                            </div>
                        </td>
                    </tr>
                    <?php if (!$dirs && !$files): ?>
                    <tr class="state-row" id="emptyRow">
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <h3>This folder is empty</h3>
                                <p>Drag files anywhere on this page, or use the button below to add your first artifact.</p>
                                <button type="button" class="btn btn-primary btn-pill" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                    <i class="bi bi-cloud-arrow-up-fill"></i> Upload files
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="load-more" id="loadMore" hidden>
                <button type="button" class="btn btn-soft btn-pill" id="loadMoreBtn"><i class="bi bi-chevron-double-down"></i> Show more</button>
            </div>
        </section>
    </main>
</div>

<!-- ======================= MODALS ======================= -->

<!-- Upload -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="uploadForm" method="post" action="/browser/" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="path" value="<?= $h($rel) ?>">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-icon" style="--c:#2563EB"><i class="bi bi-cloud-arrow-up-fill"></i></span>
                    <div>
                        <h2 class="modal-title" id="uploadTitle">Upload files</h2>
                        <div class="small text-muted">Destination <code>/<?= $h($rel) ?></code></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="dropzone" id="dropzone" for="fileInput" tabindex="0">
                    <span class="dz-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                    <strong class="dz-title">Drag &amp; drop files here</strong>
                    <span class="dz-or">or</span>
                    <span class="btn btn-primary btn-pill dz-btn"><i class="bi bi-folder2-open"></i> Browse files</span>
                    <small class="dz-hint">Multiple files supported · uploads start immediately</small>
                    <input type="file" name="file" id="fileInput" class="visually-hidden" required>
                </label>
                <ul class="upload-queue" id="uploadQueue" aria-live="polite" hidden></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft btn-pill" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary btn-pill" id="uploadSubmit"><i class="bi bi-cloud-arrow-up-fill"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- New folder -->
<div class="modal fade" id="folderModal" tabindex="-1" aria-labelledby="folderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="folderForm" method="post" action="/browser/" novalidate>
            <input type="hidden" name="action" value="mkdir">
            <input type="hidden" name="path" value="<?= $h($rel) ?>">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-icon" style="--c:#F59E0B"><i class="bi bi-folder-plus"></i></span>
                    <div>
                        <h2 class="modal-title" id="folderTitle">Create folder</h2>
                        <div class="small text-muted">Inside <code>/<?= $h($rel) ?></code></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="folderName">Folder name</label>
                <input class="form-control" id="folderName" name="folder" required maxlength="200" autocomplete="off" placeholder="e.g. terraform">
                <div class="invalid-feedback" id="folderErr"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft btn-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-pill"><i class="bi bi-folder-plus"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Rename -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="renameForm" novalidate>
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-icon" style="--c:#2563EB"><i class="bi bi-pencil-square"></i></span>
                    <h2 class="modal-title" id="renameTitle">Rename</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="renameInput">New name</label>
                <input class="form-control" id="renameInput" maxlength="200" autocomplete="off" required>
                <div class="invalid-feedback" id="renameErr"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft btn-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-pill" id="renameConfirm"><i class="bi bi-check2"></i> Rename</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-icon" style="--c:#EF4444"><i class="bi bi-trash3"></i></span>
                    <h2 class="modal-title" id="deleteTitle">Delete permanently?</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="deleteName"></strong> will be removed from the repository.</p>
                <p class="mb-0 text-muted small" id="deleteDirNote" hidden>This deletes the folder and everything inside it.</p>
                <p class="mb-0 text-muted small">This action cannot be undone.</p>
                <div class="inline-error" id="deleteErr" role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft btn-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-pill" id="deleteConfirm"><i class="bi bi-trash3"></i> Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Properties -->
<div class="modal fade" id="propsModal" tabindex="-1" aria-labelledby="propsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-icon" style="--c:#8B5CF6"><i class="bi bi-info-circle"></i></span>
                    <h2 class="modal-title" id="propsTitle">Properties</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="props">
                    <dt>Name</dt><dd id="pName"></dd>
                    <dt>Type</dt><dd id="pType"></dd>
                    <dt>Location</dt><dd id="pPath"></dd>
                    <dt>Size</dt><dd id="pSize"></dd>
                    <dt>Modified</dt><dd id="pMod"></dd>
                    <dt>URL</dt><dd id="pUrl" class="break"></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft btn-pill" id="pCopy"><i class="bi bi-link-45deg"></i> Copy URL</button>
                <button type="button" class="btn btn-primary btn-pill" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- Page-wide drag & drop overlay -->
<div class="drop-overlay" id="dropOverlay" aria-hidden="true">
    <div class="drop-card">
        <i class="bi bi-cloud-arrow-up-fill"></i>
        <h2>Drop files to upload</h2>
        <p>to <code>/<?= $h($rel) ?></code></p>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastHost" aria-live="polite" aria-atomic="false"></div>

<script id="app-config" type="application/json"><?= json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/browser/script.js?v=<?= @filemtime(__DIR__ . '/script.js') ?: 1 ?>"></script>
</body>
</html>
