<?php
// Sidebar renderer - includes CSS and HTML for the groups sidebar
declare(strict_types=1);

function getGroups(PDO $db): array {
    $rows = $db->query('SELECT code FROM products WHERE archived=0 AND code!=""')->fetchAll(PDO::FETCH_COLUMN);
    $groups = [];
    foreach ($rows as $code) {
        $pos = strpos($code, '-');
        $prefix = $pos !== false && $pos > 0 ? strtoupper(trim(substr($code, 0, $pos))) : 'غير مصنف';
        $groups[$prefix] = ($groups[$prefix] ?? 0) + 1;
    }
    arsort($groups);
    return $groups;
}

function renderSidebar(array $groups, string $activePrefix = ''): string {
    $activePrefix = (string)$activePrefix;
    $html = '<div class="sidebar">';
    $html .= '<div class="sidebar-header">📂 المجموعات (' . count($groups) . ')</div>';
    $html .= '<input type="text" class="sidebar-search" placeholder=" ابحث في المجموعات..." id="sidebarSearch">';
    $html .= '<div class="sidebar-groups">';
    foreach ($groups as $prefix => $count) {
        $isActive = (string)$prefix === $activePrefix;
        $html .= '<a href="?action=group&prefix=' . urlencode((string)$prefix) . '" class="sidebar-group ' . ($isActive ? 'active' : '') . '" data-prefix="' . htmlspecialchars((string)$prefix) . '">';
        $html .= '<span class="group-name">' . h((string)$prefix) . '</span>';
        $html .= '<span class="group-count">' . $count . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
    $html .= '<div class="sidebar-footer"><a href="?action=home" class="sidebar-all">عرض الكل</a></div>';
    $html .= '</div>';
    return $html;
}

// Sidebar CSS
?>
<style>
/* Sidebar Layout */
.main-layout {
    display: flex;
    max-width: 1600px;
    margin: 0 auto;
    gap: 24px;
    padding: 24px;
}
.sidebar {
    width: 280px;
    flex-shrink: 0;
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    height: calc(100vh - 170px);
    position: sticky;
    top: 150px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.sidebar-header {
    padding: 14px 18px;
    font-weight: 700;
    font-size: 0.95rem;
    border-bottom: 3px solid var(--primary);
    background: var(--primary);
    color: white;
}
.sidebar-search {
    width: 100%;
    padding: 10px 14px;
    border: none;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
    outline: none;
    direction: rtl;
    background: #f8f9fa;
}
.sidebar-search:focus { background: var(--primary-light); }
.sidebar-groups {
    flex: 1;
    overflow-y: auto;
    padding: 6px;
}
.sidebar-groups::-webkit-scrollbar { width: 5px; }
.sidebar-groups::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }
.sidebar-group {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 12px;
    margin: 2px 0;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text);
    transition: all 0.15s;
    border-right: 3px solid transparent;
}
.sidebar-group:hover {
    background: var(--primary-light);
    border-right-color: var(--primary);
}
.sidebar-group.active {
    background: var(--primary);
    color: white;
    border-right-color: var(--primary-dark);
    font-weight: 700;
}
.sidebar-group.active .group-count {
    background: rgba(255,255,255,0.25);
    color: white;
}
.group-name {
    font-weight: 600;
    font-size: 0.9rem;
    font-family: 'Consolas', monospace;
}
.group-count {
    background: var(--primary-light);
    color: var(--primary);
    padding: 2px 9px;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 28px;
    text-align: center;
}
.sidebar-footer {
    padding: 10px;
    border-top: 1px solid var(--border);
    text-align: center;
}
.sidebar-all {
    color: var(--primary);
    text-decoration: none;
    font-weight: 700;
    font-size: 0.9rem;
}
.sidebar-all:hover { text-decoration: underline; }
.main-content {
    flex: 1;
    min-width: 0;
}
@media (max-width: 768px) {
    .sidebar { display: none; }
    .main-layout { padding: 16px; }
}
</style>
