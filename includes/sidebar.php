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

function getPrimaryCategoryGroups(PDO $db): array {
    if (!categorySchemaReady($db)) return [];
    $rows = categoryRows($db);
    $byId = [];
    $roots = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $parentId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        $byId[$id] = ['parent_id' => $parentId];
        if ($parentId === 0)
            $roots[$id] = ['id' => $id, 'name' => (string)$row['name'], 'count' => 0];
    }
    if (!$roots) return [];
    foreach (run($db, 'SELECT pc.category_id FROM product_categories pc JOIN products p ON p.id=pc.product_id WHERE p.archived=0')->fetchAll(PDO::FETCH_COLUMN) as $assignedId) {
        $categoryId = (int)$assignedId;
        $visited = [];
        while (isset($byId[$categoryId]) && $byId[$categoryId]['parent_id'] > 0 && !isset($visited[$categoryId])) {
            $visited[$categoryId] = true;
            $categoryId = $byId[$categoryId]['parent_id'];
        }
        if (isset($roots[$categoryId])) $roots[$categoryId]['count']++;
    }
    return array_values($roots);
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

function renderSidebar(array $groups, string $activePrefix = '', int $activeCategoryId = 0): string {
    $activePrefix = (string)$activePrefix;
    $categories = getGroupCategories($groups);
    $totalProducts = array_sum($groups);
    $d = db();
    $primaryCategories = getPrimaryCategoryGroups($d);
    $categoryRows = categoryRows($d);
    $categoryById = [];
    foreach ($categoryRows as $row) $categoryById[(int)$row['id']] = $row;
    $activeRootId = $activeCategoryId;
    $visited = [];
    while ($activeRootId > 0 && isset($categoryById[$activeRootId]) && $categoryById[$activeRootId]['parent_id'] !== null && !isset($visited[$activeRootId])) {
        $visited[$activeRootId] = true;
        $activeRootId = (int)$categoryById[$activeRootId]['parent_id'];
    }
    $activeMode = $activeRootId > 0 ? 'category' : 'code';

    // Get first image for each code group.
    $firstImages = [];
    $stmt = $d->query('SELECT p.id, p.code, m.path FROM products p JOIN media m ON m.product_id=p.id WHERE p.archived=0 AND m.previewable=1 ORDER BY p.id,m.sort_order,m.id');
    $seen = [];
    while ($row = $stmt->fetch()) {
        $prefix = groupCode($row['code']);
        if (!isset($seen[$prefix]) && mediaPathExists((string)$row['path'])) {
            $firstImages[$prefix] = $row['path'];
            $seen[$prefix] = true;
        }
    }

    $html = '<div class="sidebar" data-active-mode="'.h($activeMode).'">';
    $html .= '<div class="sidebar-header">';
    $html .= '<div class="sidebar-title"><i class="fas fa-folder-open"></i> استكشف المنتجات</div>';
    $html .= '<div class="sidebar-total">'.$totalProducts.' منتج · '.count($groups).' مجموعة كود · '.count($primaryCategories).' تصنيف رئيسي</div>';
    $html .= '</div>';
    $html .= '<div class="sidebar-mode-switch" role="group" aria-label="طريقة تصنيف المنتجات">'
        .'<button type="button" class="sidebar-mode-btn '.($activeMode === 'code' ? 'active' : '').'" data-sidebar-mode="code" aria-pressed="'.($activeMode === 'code' ? 'true' : 'false').'"><i class="fas fa-hashtag"></i> حسب الكود</button>'
        .'<button type="button" class="sidebar-mode-btn '.($activeMode === 'category' ? 'active' : '').'" data-sidebar-mode="category" aria-pressed="'.($activeMode === 'category' ? 'true' : 'false').'"><i class="fas fa-layer-group"></i> حسب التصنيف</button>'
        .'</div>';
    $html .= '<div class="sidebar-search-wrap"><input type="text" class="sidebar-search" placeholder="ابحث في الكود أو التصنيف..." id="sidebarSearch"></div>';
    $html .= '<div class="sidebar-views">';
    $html .= '<div class="sidebar-view" id="sidebarCodeView" style="'.($activeMode === 'code' ? '' : 'display:none').'">';
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
            if ($thumb) $html .= '<img src="' . h($thumb) . '" class="sidebar-thumb" alt="">';
            else $html .= '<i class="fas ' . $icon . ' group-icon"></i>';
            $html .= '<span class="group-name">' . h((string)$prefix) . '</span><span class="group-count">' . $count . '</span></a>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="sidebar-view" id="sidebarPrimaryView" style="'.($activeMode === 'category' ? '' : 'display:none').'">';
    if ($primaryCategories) {
        $html .= '<div class="sidebar-primary-list">';
        foreach ($primaryCategories as $category) {
            $id = (int)$category['id'];
            $name = (string)$category['name'];
            $isActive = $id === $activeRootId;
            $html .= '<a href="?action=category&amp;category_id='.$id.'" class="sidebar-primary-group '.($isActive ? 'active' : '').'" data-category-name="'.h($name).'" '.($isActive ? 'aria-current="page"' : '').'>'
                .'<span class="sidebar-primary-icon"><i class="fas fa-layer-group"></i></span>'
                .'<span class="sidebar-primary-name">'.h($name).'</span>'
                .'<span class="group-count">'.(int)$category['count'].'</span></a>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="sidebar-category-empty"><i class="fas fa-circle-info"></i><p>شغّل ترحيل التصنيفات لعرض المجموعات الرئيسية.</p><a href="?action=categories">إعداد التصنيفات</a></div>';
    }
    $html .= '</div></div>';
    $html .= '<div class="sidebar-footer"><a href="?action=home" class="sidebar-all-btn"><i class="fas fa-home"></i> عرض كل المنتجات</a></div>';
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
.sidebar-mode-switch {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 10px 12px 0;
}
.sidebar-mode-btn {
    padding: 9px 7px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #f5f7fa;
    color: var(--text-muted);
    font: inherit;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .18s ease;
}
.sidebar-mode-btn:hover { border-color: var(--primary); color: var(--primary); }
.sidebar-mode-btn.active { background: var(--primary); border-color: var(--primary); color: white; box-shadow: 0 4px 12px rgba(31,158,158,.22); }
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

.sidebar-views { flex: 1; min-height: 0; overflow: hidden; }
.sidebar-view { height: 100%; overflow-y: auto; }
.sidebar-view::-webkit-scrollbar { width: 5px; }
.sidebar-view::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }
.sidebar-categories { padding: 8px 0; }

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
.sidebar-primary-list { display: grid; gap: 8px; padding: 10px; }
.sidebar-primary-group {
    --root-color: #15999d;
    --root-tint: rgba(21,153,157,.10);
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 48px;
    padding: 7px 10px;
    border: 1px solid rgba(255,255,255,.8);
    border-right: 4px solid var(--root-color);
    border-radius: 11px;
    background: linear-gradient(115deg, rgba(255,255,255,.78), var(--root-tint));
    color: var(--text);
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(38,55,82,.07);
    transition: all .17s ease;
}
.sidebar-primary-group:nth-child(2) { --root-color:#3f9b68; --root-tint:rgba(63,155,104,.11); }
.sidebar-primary-group:nth-child(3) { --root-color:#8b62cf; --root-tint:rgba(139,98,207,.11); }
.sidebar-primary-group:nth-child(4) { --root-color:#d17a49; --root-tint:rgba(209,122,73,.12); }
.sidebar-primary-group:nth-child(5) { --root-color:#397bbd; --root-tint:rgba(57,123,189,.11); }
.sidebar-primary-group:nth-child(6) { --root-color:#bb5f91; --root-tint:rgba(187,95,145,.11); }
.sidebar-primary-group:nth-child(7) { --root-color:#5f69bd; --root-tint:rgba(95,105,189,.11); }
.sidebar-primary-group:nth-child(8) { --root-color:#268bab; --root-tint:rgba(38,139,171,.11); }
.sidebar-primary-group:nth-child(9) { --root-color:#397f78; --root-tint:rgba(57,127,120,.11); }
.sidebar-primary-group:hover { transform: translateX(-2px); box-shadow: 0 7px 16px rgba(38,55,82,.12); }
.sidebar-primary-group.active { background: linear-gradient(130deg, var(--root-color), var(--primary-dark)); color: white; }
.sidebar-primary-icon { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; background: var(--root-tint); color: var(--root-color); flex: 0 0 auto; }
.sidebar-primary-group.active .sidebar-primary-icon { background: rgba(255,255,255,.2); color: white; }
.sidebar-primary-name { flex: 1; min-width: 0; font-size: .84rem; font-weight: 700; }
.sidebar-primary-group .group-count { background: rgba(255,255,255,.7); color: var(--root-color); }
.sidebar-primary-group.active .group-count { background: rgba(255,255,255,.28); color: white; }
.sidebar-category-empty { padding: 24px 18px; color: var(--text-muted); text-align: center; font-size: .83rem; }
.sidebar-category-empty i { color: var(--primary); font-size: 1.4rem; }
.sidebar-category-empty a { display: inline-block; margin-top: 6px; color: var(--primary-dark); font-weight: 700; }
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

// Switch between the existing code groups and the nine primary category groups.
const sidebarRoot = document.querySelector('.sidebar');
if (sidebarRoot) {
    const codeView = document.getElementById('sidebarCodeView');
    const categoryView = document.getElementById('sidebarPrimaryView');
    const modeButtons = sidebarRoot.querySelectorAll('[data-sidebar-mode]');
    const setSidebarMode = mode => {
        const showCategories = mode === 'category';
        codeView.style.display = showCategories ? 'none' : 'block';
        categoryView.style.display = showCategories ? 'block' : 'none';
        modeButtons.forEach(button => {
            const active = button.dataset.sidebarMode === mode;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    };
    setSidebarMode(sidebarRoot.dataset.activeMode || 'code');
    modeButtons.forEach(button => button.addEventListener('click', () => setSidebarMode(button.dataset.sidebarMode)));
}

// Sidebar search filters whichever grouping is visible.
const sidebarSearch = document.getElementById('sidebarSearch');
if (sidebarSearch) {
    sidebarSearch.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase();
        document.querySelectorAll('.sidebar-group').forEach(el => {
            const prefix = el.getAttribute('data-prefix').toLowerCase();
            el.style.display = prefix.includes(query) ? 'flex' : 'none';
        });
        document.querySelectorAll('.sidebar-primary-group').forEach(el => {
            const name = (el.getAttribute('data-category-name') || el.textContent).toLowerCase();
            el.style.display = name.includes(query) ? 'flex' : 'none';
        });
    });
}

</script>
<?php
}
