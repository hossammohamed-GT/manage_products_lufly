<?php
declare(strict_types=1);

function getGroupIcon(string $prefix): string {
    $icons = [
        '1300' => 'fa-faucet', '1320' => 'fa-faucet', 'SFD' => 'fa-faucet',
        '1520' => 'fa-toilet', '1620' => 'fa-toilet', '1640' => 'fa-toilet', '1685' => 'fa-toilet',
        '1682' => 'fa-bath', '1650' => 'fa-bath',
        '1610' => 'fa-sink', '1694' => 'fa-sink',
        '1653' => 'fa-shower', '1654' => 'fa-shower', '1722' => 'fa-shower',
        '1270' => 'fa-restroom', '1635' => 'fa-restroom',
        '1690' => 'fa-chair', '1695' => 'fa-chair', '1681' => 'fa-chair',
        '1755' => 'fa-pump-soap', '1644' => 'fa-pump-soap', '1670' => 'fa-pump-soap',
        'HL' => 'fa-wrench', 'LXD' => 'fa-wrench', '5000' => 'fa-wrench',
    ];
    return $icons[$prefix] ?? 'fa-box';
}

function getGroups(PDO $db): array {
    $rows = $db->query('SELECT code FROM products WHERE archived=0')->fetchAll(PDO::FETCH_COLUMN);
    $groups = [];
    foreach ($rows as $code) {
        $prefix = groupCode($code);
        $groups[$prefix] = ($groups[$prefix] ?? 0) + 1;
    }
    arsort($groups);
    return $groups;
}

function getGroupCategories(array $groups): array {
    $categories = [
        'WC & Toilets' => [],
        'Washbasins' => [],
        'Bidets' => [],
        'Urinals & Squat' => [],
        'Shower & Bath' => [],
        'Seats & Covers' => [],
        'Accessories' => [],
        'Faucets' => [],
        'Others' => [],
    ];
    foreach ($groups as $prefix => $count) {
        $p = (string)$prefix;
        if (preg_match('/^1[56][24]0$/', $p) || $p === '1685') $categories['WC & Toilets'][$prefix] = $count;
        elseif (preg_match('/^161[0-9]$/', $p) || $p === '1694') $categories['Washbasins'][$prefix] = $count;
        elseif (in_array($p, ['1682', '1650'])) $categories['Bidets'][$prefix] = $count;
        elseif (in_array($p, ['1270', '1635'])) $categories['Urinals & Squat'][$prefix] = $count;
        elseif (in_array($p, ['1653', '1654', '1722'])) $categories['Shower & Bath'][$prefix] = $count;
        elseif (in_array($p, ['1690', '1695', '1681'])) $categories['Seats & Covers'][$prefix] = $count;
        elseif (in_array($p, ['1755', '1644', '1670'])) $categories['Accessories'][$prefix] = $count;
        elseif (in_array($p, ['1300', '1320', 'SFD'])) $categories['Faucets'][$prefix] = $count;
        else $categories['Others'][$prefix] = $count;
    }
    return array_filter($categories, fn($g) => !empty($g));
}

function renderSidebar(array $groups, string $activePrefix = ''): string {
    $activePrefix = (string)$activePrefix;
    $categories = getGroupCategories($groups);
    $totalProducts = array_sum($groups);
    
    // Get first image for each group
    $d = db();
    $firstImages = [];
    $stmt = $d->query('SELECT p.id, p.code, (SELECT m.path FROM media m WHERE m.product_id=p.id AND m.previewable=1 ORDER BY m.sort_order,m.id LIMIT 1) AS path FROM products p WHERE p.archived=0 ORDER BY p.id');
    $seen = [];
    while ($row = $stmt->fetch()) {
        $prefix = groupCode($row['code']);
        if ($row['path'] && !isset($seen[$prefix])) {
            $firstImages[$prefix] = $row['path'];
            $seen[$prefix] = true;
        }
    }
    
    $html = '<div class="sidebar">';
    $html .= '<div class="sidebar-header">';
    $html .= '<div class="sidebar-title"><i class="fas fa-folder-open"></i> استكشف المنتجات</div>';
    $html .= '<div class="sidebar-total">' . $totalProducts . ' منتج في ' . count($groups) . ' مجموعة</div>';
    $html .= '</div>';
    
    $html .= '<div class="sidebar-search-wrap"><input type="text" class="sidebar-search" placeholder="ابحث في المجموعات..." id="sidebarSearch"></div>';
    
    $html .= '<div class="sidebar-categories">';
    foreach ($categories as $catName => $catGroups) {
        $catId = 'cat-' . preg_replace('/[^a-z]/', '', strtolower($catName));
        $isOpen = empty($activePrefix) || isset($catGroups[$activePrefix]);
        $html .= '<div class="sidebar-category">';
        $html .= '<button class="sidebar-cat-btn" data-target="' . $catId . '" aria-expanded="' . ($isOpen ? 'true' : 'false') . '">';
        $html .= '<span class="cat-name">' . h($catName) . '</span>';
        $html .= '<span class="cat-arrow"><i class="fas fa-chevron-down"></i></span>';
        $html .= '</button>';
        $html .= '<div class="sidebar-cat-items" id="' . $catId . '" style="' . ($isOpen ? '' : 'display:none') . '">';
        foreach ($catGroups as $prefix => $count) {
            $isActive = (string)$prefix === $activePrefix;
            $icon = getGroupIcon((string)$prefix);
            $thumb = $firstImages[(string)$prefix] ?? '';
            $html .= '<a href="?action=group&amp;prefix=' . rawurlencode((string)$prefix) . '" class="sidebar-group ' . ($isActive ? 'active' : '') . '" data-prefix="' . h((string)$prefix) . '">';
            if ($thumb) {
                $html .= '<img src="' . h($thumb) . '" class="sidebar-thumb" alt="">';
            } else {
                $html .= '<i class="fas ' . $icon . ' group-icon"></i>';
            }
            $html .= '<span class="group-name">' . h((string)$prefix) . '</span>';
            $html .= '<span class="group-count">' . $count . '</span>';
            $html .= '</a>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    
    $html .= '<div class="sidebar-footer">';
    $html .= '<a href="?action=home" class="sidebar-all-btn"><i class="fas fa-home"></i> عرض كل المنتجات</a>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function sidebarAssets(): void {
?>
<style>
/* ══ Sidebar ══ */
.sidebar {
    width: 320px;
    flex-shrink: 0;
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    height: calc(100vh - 200px);
    position: sticky;
    top: 170px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.sidebar-header {
    padding: 18px 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
}
.sidebar-title {
    font-weight: 800;
    font-size: 1.05rem;
    margin-bottom: 4px;
}
.sidebar-total {
    font-size: 0.8rem;
    opacity: 0.9;
}
.sidebar-search-wrap {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    background: #f8fafb;
}
.sidebar-search {
    width: 100%;
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 0.85rem;
    outline: none;
    direction: rtl;
    background: white;
    transition: border-color 0.2s;
}
.sidebar-search:focus { border-color: var(--primary); }

.sidebar-categories {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
}
.sidebar-categories::-webkit-scrollbar { width: 5px; }
.sidebar-categories::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }

.sidebar-category {
    border-bottom: 1px solid #f0f0f0;
}
.sidebar-cat-btn {
    width: 100%;
    padding: 12px 18px;
    background: none;
    border: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text);
    transition: background 0.15s;
}
.sidebar-cat-btn:hover { background: var(--primary-light); }
.cat-arrow {
    transition: transform 0.2s;
    color: var(--text-muted);
    font-size: 0.8rem;
}
.sidebar-cat-btn[aria-expanded="true"] .cat-arrow { transform: rotate(-90deg); }

.sidebar-cat-items {
    background: #fafbfc;
}
.sidebar-group {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 18px 9px 28px;
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
}
.sidebar-group.active .group-count { background: rgba(255,255,255,0.3); color: white; }

.group-icon { font-size: 1rem; width: 20px; text-align: center; color: var(--text-muted); }
.sidebar-group.active .group-icon { color: white; }

/* Thumbnail image in sidebar */
.sidebar-thumb {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: #f0f2f5;
}
.group-name {
    font-weight: 600;
    font-size: 0.88rem;
    font-family: 'Consolas', monospace;
    flex: 1;
}
.group-count {
    background: var(--primary-light);
    color: var(--primary);
    padding: 2px 9px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 32px;
    text-align: center;
}

.sidebar-footer {
    padding: 12px;
    border-top: 1px solid var(--border);
    background: #f8fafb;
}
.sidebar-all-btn {
    display: block;
    text-align: center;
    padding: 10px;
    background: var(--primary);
    color: white;
    text-decoration: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.9rem;
    transition: background 0.2s;
}
.sidebar-all-btn:hover { background: var(--primary-dark); }

@media (max-width: 900px) { .sidebar { display: none; } }
</style>

<script>
// Sidebar category toggle
document.querySelectorAll('.sidebar-cat-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const target = this.getAttribute('data-target');
        const items = document.getElementById(target);
        const isExpanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', (!isExpanded).toString());
        items.style.display = isExpanded ? 'none' : 'block';
    });
});

// Sidebar search filter
const sidebarSearch = document.getElementById('sidebarSearch');
if (sidebarSearch) {
    sidebarSearch.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase();
        document.querySelectorAll('.sidebar-group').forEach(el => {
            const prefix = el.getAttribute('data-prefix').toLowerCase();
            el.style.display = prefix.includes(query) ? 'flex' : 'none';
        });
    });
}

</script>
<?php
}
