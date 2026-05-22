<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

$statusMessage = '';
$statusType = 'success';
if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'upload_success': $statusMessage = 'Document uploaded successfully.'; break;
        case 'upload_error': $statusMessage = 'Upload failed.'; $statusType = 'danger'; break;
        case 'export_error': $statusMessage = 'No documents available to export.'; $statusType = 'danger'; break;
        case 'document_not_found': $statusMessage = 'Document not found.'; $statusType = 'danger'; break;
    }
}

// Get current user for incoming notification
$me = $_SESSION['username'];
$incomingState = 'Incoming';

// Get total incoming count for sidebar notification
$incomingCountStmt = $conn->prepare("SELECT COUNT(*) as total_count FROM documents WHERE route_state = ? AND receiver_name = ?");
$incomingCountStmt->bind_param('ss', $incomingState, $me);
$incomingCountStmt->execute();
$incomingCountResult = $incomingCountStmt->get_result();
$totalIncomingCount = $incomingCountResult->fetch_assoc()['total_count'];
$incomingCountStmt->close();
// Transparency view: all received documents
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = $conn->prepare("SELECT * FROM documents WHERE route_state = 'Received' AND receiver_name = ? AND (title COLLATE utf8mb4_general_ci LIKE ? OR status COLLATE utf8mb4_general_ci LIKE ? OR sender_name COLLATE utf8mb4_general_ci LIKE ? OR receiver_name COLLATE utf8mb4_general_ci LIKE ? OR remarks COLLATE utf8mb4_general_ci LIKE ?) ORDER BY COALESCE(date_time_receiving, received_at, time_stamp_received) DESC, created_at DESC");
    $stmt->bind_param('ssssss', $me, $like, $like, $like, $like, $like);
} else {
    $stmt = $conn->prepare("SELECT * FROM documents WHERE route_state = 'Received' AND receiver_name = ? ORDER BY COALESCE(date_time_receiving, received_at, time_stamp_received) DESC, created_at DESC LIMIT 10");
    $stmt->bind_param('s', $me);
}
$stmt->execute();
$receivedDocs = $stmt->get_result();
$stmt->close();
if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $allStmt = $conn->prepare(
        "SELECT d.id, d.title, d.status, d.route_state, d.sender_name, d.receiver_name, d.created_at, COALESCE(d.date_time_receiving, d.received_at, d.time_stamp_received) AS received_time, d.date_time_receiving, d.received_at, d.time_stamp_received, d.remarks
         FROM documents d
         WHERE LOWER(TRIM(d.route_state)) = 'received'
           AND (
                d.title COLLATE utf8mb4_general_ci LIKE ?
             OR d.status COLLATE utf8mb4_general_ci LIKE ?
             OR d.sender_name COLLATE utf8mb4_general_ci LIKE ?
             OR d.receiver_name COLLATE utf8mb4_general_ci LIKE ?
             OR d.route_state COLLATE utf8mb4_general_ci LIKE ?
             OR d.remarks COLLATE utf8mb4_general_ci LIKE ?
           )
         ORDER BY COALESCE(d.date_time_receiving, d.status, d.received_at, d.time_stamp_received) DESC, d.created_at DESC"
    );
    $allStmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
    $allStmt->execute();
    $allDocs = $allStmt->get_result();
    $allStmt->close();
} else {
    $allStmt = $conn->prepare(
        "SELECT d.id, d.title, d.status, d.route_state, d.sender_name, d.receiver_name, d.created_at, COALESCE(d.date_time_receiving, d.received_at, d.time_stamp_received) AS received_time, d.date_time_receiving, d.received_at, d.time_stamp_received, d.remarks
         FROM documents d
         WHERE LOWER(TRIM(d.route_state)) = 'received'
         ORDER BY COALESCE(d.date_time_receiving, d.status, d.received_at, d.time_stamp_received) DESC, d.created_at DESC"
    );
    $allStmt->execute();
    $allDocs = $allStmt->get_result();
    $allStmt->close();
}

// Fetch list of other users for the Forward dropdown
$forwardUsers = [];
if ($usersRes = $conn->query("SELECT username FROM users WHERE username <> '" . $conn->real_escape_string($me) . "' ORDER BY username ASC")) {
    while ($u = $usersRes->fetch_assoc()) { $forwardUsers[] = $u['username']; }
    $usersRes->close();
}

function status_badge_class($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'for consideration')          return 'status-consideration';
    if ($s === 'for appropriate action')     return 'status-action';
    if ($s === 'for comments')               return 'status-comments';
    if ($s === 'for initial/signature')      return 'status-signature';
    if ($s === 'for information/file')       return 'status-info';
    if ($s === 'drafting of reply')          return 'status-drafting';
    if ($s === 'specify' || $s === '')       return 'status-default';
    return 'status-specify';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PSA Routing — Dashboard</title>
    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color:#0012b0; --bg-light:#f9fafb; --text-main:#111827; --text-muted:#6b7280; --border-color:#e5e7eb; --success-color:#10b981; --danger-color:#ef4444; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Inter',sans-serif; }
        body { background:var(--bg-light); color:var(--text-main); }
        .dashboard-container { display:flex; min-height:100vh; }
        .sidebar { width:260px; background:white; border-right:1px solid var(--border-color); padding:24px; position:fixed; height:100vh; display:flex; flex-direction:column; }
        .logo-section { display:flex; align-items:center; gap:12px; margin-bottom:32px; font-weight:700; color:var(--primary-color); font-size:1.1rem; }
        .sidebar-menu { flex:1; display:flex; flex-direction:column; gap:8px; }
        .menu-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; color:var(--text-muted); text-decoration:none; font-weight:500; }
        .menu-item:hover, .menu-item.active { background:rgba(0,18,176,.08); color:var(--primary-color); }
        .logout-btn { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; background:#fee2e2; color:var(--danger-color); text-decoration:none; font-weight:500; }
        .main-content { margin-left:260px; flex:1; padding:32px; }
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; background:white; padding:20px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
        .header h1 { font-size:1.5rem; }
        .page-alert { margin-bottom:20px; padding:12px 16px; border-radius:10px; font-weight:600; }
        .page-alert.success { background:#ecfdf5; color:#065f46; border:1px solid #d1fae5; }
        .page-alert.danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .documents-section { background:white; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); overflow:hidden; }
        .documents-header { padding:18px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; }
        .documents-header h2 { font-size:1.15rem; }
        .scrollable-table { max-height:540px; overflow-y:auto; }
        .badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.75rem; font-weight:600; background:#dcfce7; color:#166534; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:14px 16px; text-align:left; border-bottom:1px solid var(--border-color); font-size:.92rem; }
        th { background:var(--bg-light); text-transform:uppercase; font-size:.78rem; letter-spacing:.05em; color:var(--text-muted); }
        tbody tr:hover { background:#fafafa; }
        .action-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:8px; background:#f3f4f6; color:#374151; text-decoration:none; font-size:.8rem; font-weight:600; border:none; cursor:pointer; margin:0 2px; }
        .action-btn.edit { background:#8b5cf6; color:white; }
        .action-btn.forward { background:#0ea5e9; color:white; }
        .empty-state { padding:48px; text-align:center; color:var(--text-muted); }
        .empty-state i { font-size:2.5rem; opacity:.5; margin-bottom:12px; display:block; }
        .search-form { display:flex; gap:8px; align-items:center; }
        .search-form input { border:1px solid var(--border-color); border-radius:8px; padding:9px 12px; font-size:.9rem; min-width:240px; outline:none; }
        .search-form input:focus { border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(0,18,176,.1); }
        .search-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 14px; border-radius:8px; background:var(--primary-color); color:white; border:none; cursor:pointer; font-weight:600; font-size:.85rem; text-decoration:none; }
        .search-btn.clear { background:#f3f4f6; color:#374151; }
        .badge.route { background:#e0e7ff; color:#3730a3; transition: background .3s, color .3s; }
        .badge.status-consideration { background:#e0f2fe; color:#075985; }
        .badge.status-action        { background:#fee2e2; color:#991b1b; }
        .badge.status-comments      { background:#fef3c7; color:#92400e; }
        .badge.status-signature     { background:#ede9fe; color:#5b21b6; }
        .badge.status-info          { background:#dbeafe; color:#1e40af; }
        .badge.status-drafting      { background:#fce7f3; color:#9d174d; }
        .badge.status-specify       { background:#dcfce7; color:#166534; }
        .badge.status-default       { background:#f3f4f6; color:#374151; }
        .badge.route.flash { animation: routeFlash 1.8s ease-out; }
        @keyframes routeFlash {
            0%   { background:#fde68a; color:#92400e; box-shadow:0 0 0 4px rgba(253,230,138,.6); }
            60%  { background:#fde68a; color:#92400e; box-shadow:0 0 0 4px rgba(253,230,138,0); }
            100% { background:#e0e7ff; color:#3730a3; box-shadow:none; }
        }
        .section-spacer { height:24px; }
        .toast { position:fixed; top:16px; right:16px; padding:12px 16px; border-radius:10px; color:white; font-weight:600; z-index:200; }
        .toast.success { background: var(--success-color); }
        .toast.error { background:#ef4444; }
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100; align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:white; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { font-size:1.25rem; font-weight:700; margin-bottom:16px; color: var(--primary-color); display:flex; justify-content:space-between; align-items:center; }
        .modal-close { font-size:1.5rem; cursor:pointer; color:#999; }
        .form-group { margin-bottom:16px; display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-weight:500; font-size:0.9rem; }
        .form-group textarea, .form-group select { padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; font-size:.9rem; }
        .modal-buttons { display:flex; gap:12px; justify-content:flex-end; margin-top:20px; }
        .modal-btn { padding:10px 16px; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .modal-btn-primary { background: var(--primary-color); color:white; }
        .modal-btn-secondary { background:#e5e7eb; color: var(--text-main); }
    </style>
</head>
<body>
<?php if ($statusMessage): ?>
    <div class="page-alert <?php echo $statusType; ?>" style="margin:16px 32px 0;"><?php echo htmlspecialchars($statusMessage); ?></div>
<?php endif; ?>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="logo-section"><i class="fas fa-file-alt"></i><span>PSA ROUTING</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item active"><i class="fas fa-home"></i><span>Dashboard</span></a>
            <a href="incoming.php" class="menu-item">
                <i class="fas fa-arrow-down"></i>
                <span>Incoming</span>
                <?php if ($totalIncomingCount > 0): ?>
                    <span style="background:#3b82f6;color:white;padding:2px 6px;border-radius:10px;font-size:.7rem;margin-left:auto;"><?php echo $totalIncomingCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="outgoing.php" class="menu-item"><i class="fas fa-arrow-up"></i><span>Outgoing</span></a>
            <a href="pending.php" class="menu-item"><i class="fas fa-clock"></i><span>Pending</span></a>
        </nav>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </aside>
    <main class="main-content">
        <div class="header">
            <h1>Welcome back! <?php echo htmlspecialchars($me); ?> 👤</h1>
            <form class="search-form" method="get" action="dashboard.php">
                <input type="text" name="q" placeholder="Search title, status, sender, receiver…" value="<?php echo htmlspecialchars($searchTerm); ?>">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
                <?php if ($searchTerm !== ''): ?>
                    <a href="dashboard.php" class="search-btn clear"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Transparency: All Documents visible to every user -->
        <div class="documents-section" id="transparencySection" data-search="<?php echo htmlspecialchars($searchTerm); ?>">
            <div class="documents-header">
                <h2>
                    All Documents
                    <span class="badge route" style="margin-left:8px;">Transparency View</span>
                    <span class="badge" id="liveBadge" style="margin-left:6px; background:#dcfce7; color:#166534;">
                        <i class="fas fa-circle" style="font-size:.55rem; margin-right:4px;"></i>Live
                    </span>
                </h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <span style="color:var(--text-muted); font-size:.85rem;">
                        <span id="docCount"><?php echo $allDocs ? intval($allDocs->num_rows) : 0; ?></span> document(s)
                        <?php if ($searchTerm !== ''): ?> · matching "<?php echo htmlspecialchars($searchTerm); ?>"<?php endif; ?>
                        · updated <span id="lastUpdated">just now</span>
                    </span>
                </div>
            </div>
            <div id="transparencyBody">
            <?php if ($allDocs && $allDocs->num_rows > 0): ?>
                <div class="scrollable-table"><div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th>Title</th><th>Status</th><th>Route State</th><th>Sender</th><th>Receiver</th><th>Created</th><th>Received At</th><th>Remarks</th></tr></thead>
                        <tbody id="transparencyTbody">
                        <?php while ($d = $allDocs->fetch_assoc()): ?>
                            <tr data-id="<?php echo intval($d['id'] ?? 0); ?>">
                                <td><?php echo htmlspecialchars($d['title'] ?? 'No transaction yet'); ?></td>
                                <td><span class="badge <?php echo status_badge_class($d['status'] ?? ''); ?>"><?php echo htmlspecialchars($d['status'] ?? '—'); ?></span></td>
                                <td><span class="badge route"><?php echo htmlspecialchars($d['route_state'] ?? '—'); ?></span></td>
                                <td><?php echo htmlspecialchars($d['sender_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($d['receiver_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($d['created_at'] ?? '—'); ?></td>
                                <td><?php echo !empty($d['received_time']) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($d['received_time']))) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($d['remarks'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div></div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p><?php echo $searchTerm !== '' ? 'No documents match your search.' : 'No documents in the system yet.'; ?></p>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <script>
        (function() {
            const section = document.getElementById('transparencySection');
            const searchTerm = section.dataset.search || '';
            const bodyWrap = document.getElementById('transparencyBody');
            const countEl = document.getElementById('docCount');
            const updatedEl = document.getElementById('lastUpdated');
            const liveBadge = document.getElementById('liveBadge');

            function esc(s) {
                return (s === null || s === undefined ? '' : String(s))
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function statusClass(s) {
                const v = (s || '').toString().trim().toLowerCase();
                if (v === 'for consideration')      return 'status-consideration';
                if (v === 'for appropriate action') return 'status-action';
                if (v === 'for comments')           return 'status-comments';
                if (v === 'for initial/signature')  return 'status-signature';
                if (v === 'for information/file')   return 'status-info';
                if (v === 'drafting of reply')      return 'status-drafting';
                if (v === 'specify' || v === '')    return 'status-default';
                return 'status-specify';
            }

            function renderTable(docs) {
                if (!docs.length) {
                    bodyWrap.innerHTML = '<div class="empty-state"><i class="fas fa-folder-open"></i><p>' +
                        (searchTerm ? 'No documents match your search.' : 'No documents in the system yet.') + '</p></div>';
                    return;
                }
                let html = '<div class="scrollable-table"><div style="overflow-x:auto;"><table>' +
                    '<thead><tr><th>Title</th><th>Status</th><th>Route State</th><th>Sender</th><th>Receiver</th><th>Created</th><th>Received At</th><th>Remarks</th></tr></thead>' +
                    '<tbody id="transparencyTbody">';
                docs.forEach(d => {
                    html += '<tr data-id="' + esc(d.id || 0) + '">' +
                        '<td>' + esc(d.title || 'No transaction yet') + '</td>' +
                        '<td><span class="badge ' + statusClass(d.status) + '">' + esc(d.status || '—') + '</span></td>' +
                        '<td><span class="badge route">' + esc(d.route_state || '—') + '</span></td>' +
                        '<td>' + esc(d.sender_name || '—') + '</td>' +
                        '<td>' + esc(d.receiver_name || '—') + '</td>' +
                        '<td>' + esc(d.created_at || '—') + '</td>' +
                        '<td>' + esc(d.received_time || d.date_time_receiving || d.received_at || d.time_stamp_received || '—') + '</td>' +
                        '<td>' + esc(d.remarks || '') + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div></div>';
                bodyWrap.innerHTML = html;
            }

            const prevRouteState = new Map();
            document.querySelectorAll('#transparencyTbody tr').forEach(tr => {
                const id = tr.dataset.id;
                const badge = tr.querySelector('td:nth-child(3) .badge.route');
                if (id && badge) prevRouteState.set(id, badge.textContent.trim());
            });

            function flashChangedRouteStates(docs) {
                docs.forEach(d => {
                    const id = String(d.id);
                    const current = (d.route_state || '—').toString();
                    const previous = prevRouteState.get(id);
                    if (previous !== undefined && previous !== current) {
                        const row = document.querySelector('#transparencyTbody tr[data-id="' + id + '"]');
                        if (row) {
                            const badge = row.querySelector('td:nth-child(3) .badge.route');
                            if (badge) {
                                badge.classList.remove('flash');
                                void badge.offsetWidth;
                                badge.classList.add('flash');
                            }
                        }
                    }
                    prevRouteState.set(id, current);
                });
            }

            let lastSnapshot = '';
            async function refresh() {
                try {
                    const url = 'transparency_feed.php' + (searchTerm ? ('?q=' + encodeURIComponent(searchTerm)) : '');
                    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    const snapshot = JSON.stringify(data.documents);
                    if (snapshot !== lastSnapshot) {
                        lastSnapshot = snapshot;
                        renderTable(data.documents);
                        countEl.textContent = data.count;
                        flashChangedRouteStates(data.documents);
                    }
                    updatedEl.textContent = new Date().toLocaleTimeString();
                    liveBadge.style.background = '#dcfce7';
                    liveBadge.style.color = '#166534';
                } catch (e) {
                    liveBadge.style.background = '#fee2e2';
                    liveBadge.style.color = '#991b1b';
                }
            }

            refresh();
            setInterval(refresh, 5000);
        })();
        </script>
        <div class="section-spacer"></div>
        <div class="documents-section">
            <div class="documents-header">
                <h2>Received Documents</h2>
                <span style="color:var(--text-muted); font-size:.85rem;">Documents you confirmed receipt of</span>
            </div>
            <?php if ($receivedDocs && $receivedDocs->num_rows > 0): ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th>Title</th><th>Status</th><th>Route State</th><th>Sender</th><th>Sent At</th><th>Received At</th><th>Remarks</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php while ($r = $receivedDocs->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['title']); ?></td>
                                <td><span class="badge <?php echo status_badge_class($r['status'] ?? ''); ?>"><?php echo htmlspecialchars($r['status'] ?? '—'); ?></span></td>
                                <td><span class="badge route"><?php echo htmlspecialchars($r['route_state'] ?? '—'); ?></span></td>
                                <td><?php echo htmlspecialchars($r['sender_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                                <td><?php $receivedTime = $r['date_time_receiving'] ?: ($r['received_at'] ?: ($r['time_stamp_received'] ?? null)); echo !empty($receivedTime) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($receivedTime))) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($r['remarks']); ?></td>
                                <td>
                                    <a class="action-btn" title="History" aria-label="History" href="history.php?id=<?php echo intval($r['id']); ?>&return_to=dashboard.php"><i class="fas fa-clock-rotate-left"></i> </a>
                                    <button type="button" class="action-btn edit" title="Edit Remarks" aria-label="Edit Remarks" onclick="openEditRemarks(<?php echo intval($r['id']); ?>, <?php echo htmlspecialchars(json_encode($r['remarks'] ?? ''), ENT_QUOTES); ?>)"><i class="fas fa-edit"></i> </button>
                                    <button type="button" class="action-btn forward" title="Forward" aria-label="Forward" onclick="openForward(<?php echo intval($r['id']); ?>, <?php echo htmlspecialchars(json_encode($r['title'] ?? ''), ENT_QUOTES); ?>)"><i class="fas fa-share"></i> </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No received documents yet.</p>
                    <p style="font-size:.85rem; margin-top:6px;">Open <a href="incoming.php" style="color:var(--primary-color); font-weight:600;">Incoming</a> and click "Mark as Received" to move docs here.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Edit Remarks Modal -->
    <div id="editRemarksModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Edit Remarks</span>
                <span class="modal-close" onclick="closeEditRemarks()">&times;</span>
            </div>
            <form id="editRemarksForm">
                <input type="hidden" id="editDocId" name="doc_id">
                <div class="form-group">
                    <label for="editRemarksText">Remarks</label>
                    <textarea id="editRemarksText" name="remarks" rows="4" placeholder="Enter your remarks..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeEditRemarks()">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Forward Modal -->
    <div id="forwardModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Forward Document</span>
                <span class="modal-close" onclick="closeForward()">&times;</span>
            </div>
            <form id="forwardForm">
                <input type="hidden" id="forwardDocId" name="doc_id">
                <div class="form-group">
                    <label>Document</label>
                    <div id="forwardDocTitle" style="font-weight:600; color:var(--text-main);"></div>
                </div>
                <div class="form-group">
                    <label for="forwardReceiver">Forward to user</label>
                    <select id="forwardReceiver" name="receiver" required>
                        <option value="">-- Select user --</option>
                        <?php foreach ($forwardUsers as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="forwardRemarks">Remarks (optional)</label>
                    <textarea id="forwardRemarks" name="remarks" rows="3" placeholder="Add a note for the recipient..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeForward()">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-primary">Forward</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showToast(msg, kind) {
    const t = document.createElement('div');
    t.className = 'toast ' + (kind || 'success');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}

function openEditRemarks(id, currentRemarks) {
    document.getElementById('editDocId').value = id;
    document.getElementById('editRemarksText').value = currentRemarks || '';
    document.getElementById('editRemarksModal').classList.add('active');
}
function closeEditRemarks() {
    document.getElementById('editRemarksModal').classList.remove('active');
}

document.getElementById('editRemarksForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData();
    fd.append('doc_id', document.getElementById('editDocId').value);
    fd.append('remarks', document.getElementById('editRemarksText').value);
    try {
        const r = await fetch('edit_remarks.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Remarks updated successfully');
            closeEditRemarks();
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(j.message || 'Failed to update remarks', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
});

function openForward(id, title) {
    document.getElementById('forwardDocId').value = id;
    document.getElementById('forwardDocTitle').textContent = title || '(untitled)';
    document.getElementById('forwardReceiver').value = '';
    document.getElementById('forwardRemarks').value = '';
    document.getElementById('forwardModal').classList.add('active');
}
function closeForward() {
    document.getElementById('forwardModal').classList.remove('active');
}

document.getElementById('forwardForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const receiver = document.getElementById('forwardReceiver').value;
    if (!receiver) { showToast('Please select a user', 'error'); return; }
    const fd = new FormData();
    fd.append('doc_id', document.getElementById('forwardDocId').value);
    fd.append('receiver', receiver);
    fd.append('remarks', document.getElementById('forwardRemarks').value);
    try {
        const r = await fetch('forward_document.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Document forwarded to ' + receiver);
            closeForward();
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(j.message || 'Failed to forward', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
});

// Close modals on backdrop click
['editRemarksModal','forwardModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', (e) => {
        if (e.target.id === id) e.currentTarget.classList.remove('active');
    });
});
</script>
</body>
</html>
