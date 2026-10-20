-- Additive MySQL migration for the editable three-level product category hierarchy.
-- Run this against the existing catalog database. It does not modify or import product/media rows.
-- The product-category map starts empty; assign products through the catalog UI after applying it.

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(120) NOT NULL,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_key (category_key),
    INDEX idx_categories_parent_sort (parent_id, sort_order, id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_categories (
    product_id INT NOT NULL PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_categories_category (category_id),
    CONSTRAINT fk_product_categories_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial editable category tree. INSERT IGNORE makes re-running the migration safe and preserves renamed nodes.
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('bathroom', NULL, 'Bathroom', 1);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware', parent.id, 'Sanitary Ware', 1
FROM categories AS parent WHERE parent.category_key = 'bathroom';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.toilets', parent.id, 'Toilets', 1
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.wash-basins', parent.id, 'Wash Basins', 2
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.bidets', parent.id, 'Bidets', 3
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.urinals', parent.id, 'Urinals', 4
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.squatting-pans', parent.id, 'Squatting Pans', 5
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.cisterns', parent.id, 'Cisterns', 6
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.sanitary-ware.ceramic-accessories', parent.id, 'Ceramic Accessories', 7
FROM categories AS parent WHERE parent.category_key = 'bathroom.sanitary-ware';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers', parent.id, 'Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'bathroom';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.basin-mixers', parent.id, 'Basin Mixers', 1
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.shower-mixers', parent.id, 'Shower Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.bath-mixers', parent.id, 'Bath Mixers', 3
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.concealed-mixers', parent.id, 'Concealed Mixers', 4
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.thermostatic-mixers', parent.id, 'Thermostatic Mixers', 5
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.kitchen-mixers', parent.id, 'Kitchen Mixers', 6
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.mixers.special-mixers', parent.id, 'Special Mixers', 7
FROM categories AS parent WHERE parent.category_key = 'bathroom.mixers';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories', parent.id, 'Accessories', 3
FROM categories AS parent WHERE parent.category_key = 'bathroom';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.towel-bars', parent.id, 'Towel Bars', 1
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.towel-rings', parent.id, 'Towel Rings', 2
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.robe-hooks', parent.id, 'Robe Hooks', 3
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.toilet-paper-holders', parent.id, 'Toilet Paper Holders', 4
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.soap-dispensers', parent.id, 'Soap Dispensers', 5
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.soap-dishes', parent.id, 'Soap Dishes', 6
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.toilet-brushes', parent.id, 'Toilet Brushes', 7
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.shelves', parent.id, 'Shelves', 8
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.grab-bars', parent.id, 'Grab Bars', 9
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'bathroom.accessories.other-accessories', parent.id, 'Other Accessories', 10
FROM categories AS parent WHERE parent.category_key = 'bathroom.accessories';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('kitchen', NULL, 'Kitchen', 2);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks', parent.id, 'Sinks', 1
FROM categories AS parent WHERE parent.category_key = 'kitchen';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.granite-sinks', parent.id, 'Granite Sinks', 1
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.stainless-steel-sinks', parent.id, 'Stainless Steel Sinks', 2
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.undermount-sinks', parent.id, 'Undermount Sinks', 3
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.topmount-sinks', parent.id, 'Topmount Sinks', 4
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.workstation-sinks', parent.id, 'Workstation Sinks', 5
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.kitchen-accessories', parent.id, 'Kitchen Accessories', 6
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.sinks.other-sinks', parent.id, 'Other Sinks', 7
FROM categories AS parent WHERE parent.category_key = 'kitchen.sinks';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers', parent.id, 'Kitchen Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'kitchen';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.pull-out-mixers', parent.id, 'Pull-out Mixers', 1
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.single-handle-mixers', parent.id, 'Single Handle Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.dual-handle-mixers', parent.id, 'Dual Handle Mixers', 3
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.filtered-water-mixers', parent.id, 'Filtered Water Mixers', 4
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.pot-fillers', parent.id, 'Pot Fillers', 5
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kitchen.kitchen-mixers.other-kitchen-mixers', parent.id, 'Other Kitchen Mixers', 6
FROM categories AS parent WHERE parent.category_key = 'kitchen.kitchen-mixers';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('kids', NULL, 'Kids', 3);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-sanitary-ware', parent.id, 'Kids Sanitary Ware', 1
FROM categories AS parent WHERE parent.category_key = 'kids';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-sanitary-ware.kids-toilets', parent.id, 'Kids Toilets', 1
FROM categories AS parent WHERE parent.category_key = 'kids.kids-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-sanitary-ware.kids-wash-basins', parent.id, 'Kids Wash Basins', 2
FROM categories AS parent WHERE parent.category_key = 'kids.kids-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-sanitary-ware.kids-urinals', parent.id, 'Kids Urinals', 3
FROM categories AS parent WHERE parent.category_key = 'kids.kids-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-sanitary-ware.kids-bathroom-sets', parent.id, 'Kids Bathroom Sets', 4
FROM categories AS parent WHERE parent.category_key = 'kids.kids-sanitary-ware';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-mixers', parent.id, 'Kids Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'kids';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-mixers.kids-basin-mixers', parent.id, 'Kids Basin Mixers', 1
FROM categories AS parent WHERE parent.category_key = 'kids.kids-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-mixers.kids-shower-mixers', parent.id, 'Kids Shower Mixers', 2
FROM categories AS parent WHERE parent.category_key = 'kids.kids-mixers';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'kids.kids-mixers.kids-accessories', parent.id, 'Kids Accessories', 3
FROM categories AS parent WHERE parent.category_key = 'kids.kids-mixers';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('accessibility', NULL, 'Accessibility', 4);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-sanitary-ware', parent.id, 'Accessible Sanitary Ware', 1
FROM categories AS parent WHERE parent.category_key = 'accessibility';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-sanitary-ware.accessible-toilets', parent.id, 'Accessible Toilets', 1
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-sanitary-ware.accessible-wash-basins', parent.id, 'Accessible Wash Basins', 2
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-sanitary-ware.accessible-showers', parent.id, 'Accessible Showers', 3
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-sanitary-ware';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-sanitary-ware.accessible-urinals', parent.id, 'Accessible Urinals', 4
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-sanitary-ware';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.kids-bars-supports', parent.id, 'Kids Bars & Supports', 2
FROM categories AS parent WHERE parent.category_key = 'accessibility';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.kids-bars-supports.grab-bars', parent.id, 'Grab Bars', 1
FROM categories AS parent WHERE parent.category_key = 'accessibility.kids-bars-supports';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.kids-bars-supports.foldable-supports', parent.id, 'Foldable Supports', 2
FROM categories AS parent WHERE parent.category_key = 'accessibility.kids-bars-supports';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.kids-bars-supports.shower-seats', parent.id, 'Shower Seats', 3
FROM categories AS parent WHERE parent.category_key = 'accessibility.kids-bars-supports';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.kids-bars-supports.other-supports', parent.id, 'Other Supports', 4
FROM categories AS parent WHERE parent.category_key = 'accessibility.kids-bars-supports';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-accessories', parent.id, 'Accessible Accessories', 3
FROM categories AS parent WHERE parent.category_key = 'accessibility';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-accessories.accessible-mixers', parent.id, 'Accessible Mixers', 1
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-accessories.accessible-shower-systems', parent.id, 'Accessible Shower Systems', 2
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-accessories.accessible-mirrors', parent.id, 'Accessible Mirrors', 3
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-accessories';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'accessibility.accessible-accessories.other-accessories', parent.id, 'Other Accessories', 4
FROM categories AS parent WHERE parent.category_key = 'accessibility.accessible-accessories';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('shower-enclosures', NULL, 'Shower Enclosures', 5);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.sliding-enclosures', parent.id, 'Sliding Enclosures', 1
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.hinged-enclosures', parent.id, 'Hinged Enclosures', 2
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.walk-in-enclosures', parent.id, 'Walk-in Enclosures', 3
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.corner-enclosures', parent.id, 'Corner Enclosures', 4
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.frameless-enclosures', parent.id, 'Frameless Enclosures', 5
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.semi-framed-enclosures', parent.id, 'Semi-Framed Enclosures', 6
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.profiles-hardware', parent.id, 'Profiles & Hardware', 7
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'shower-enclosures.other-enclosures', parent.id, 'Other Enclosures', 8
FROM categories AS parent WHERE parent.category_key = 'shower-enclosures';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('vanity-units', NULL, 'Vanity Units', 6);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.solid-wood-vanity', parent.id, 'Solid Wood Vanity', 1
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.pvc-vanity', parent.id, 'PVC Vanity', 2
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.mdf-vanity', parent.id, 'MDF Vanity', 3
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.wall-hung-vanity', parent.id, 'Wall Hung Vanity', 4
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.floor-standing-vanity', parent.id, 'Floor Standing Vanity', 5
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.countertop-vanity', parent.id, 'Countertop Vanity', 6
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.vanity-tops', parent.id, 'Vanity Tops', 7
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'vanity-units.other-vanity-units', parent.id, 'Other Vanity Units', 8
FROM categories AS parent WHERE parent.category_key = 'vanity-units';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('mirrors', NULL, 'Mirrors', 7);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.led-mirrors', parent.id, 'LED Mirrors', 1
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.framed-mirrors', parent.id, 'Framed Mirrors', 2
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.frameless-mirrors', parent.id, 'Frameless Mirrors', 3
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.cabinet-mirrors', parent.id, 'Cabinet Mirrors', 4
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.magnifying-mirrors', parent.id, 'Magnifying Mirrors', 5
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.smart-mirrors', parent.id, 'Smart Mirrors', 6
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'mirrors.other-mirrors', parent.id, 'Other Mirrors', 7
FROM categories AS parent WHERE parent.category_key = 'mirrors';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('drains', NULL, 'Drains', 8);
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.shower-drains', parent.id, 'Shower Drains', 1
FROM categories AS parent WHERE parent.category_key = 'drains';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.floor-drains', parent.id, 'Floor Drains', 2
FROM categories AS parent WHERE parent.category_key = 'drains';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-application', parent.id, 'By Application', 3
FROM categories AS parent WHERE parent.category_key = 'drains';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-application.indoor', parent.id, 'Indoor', 1
FROM categories AS parent WHERE parent.category_key = 'drains.by-application';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-application.outdoor', parent.id, 'Outdoor', 2
FROM categories AS parent WHERE parent.category_key = 'drains.by-application';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-mounting-type', parent.id, 'By Mounting Type', 4
FROM categories AS parent WHERE parent.category_key = 'drains';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-mounting-type.wall-hung', parent.id, 'Wall Hung', 1
FROM categories AS parent WHERE parent.category_key = 'drains.by-mounting-type';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-mounting-type.floor-standing', parent.id, 'Floor Standing', 2
FROM categories AS parent WHERE parent.category_key = 'drains.by-mounting-type';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-mounting-type.free-standing', parent.id, 'Free Standing', 3
FROM categories AS parent WHERE parent.category_key = 'drains.by-mounting-type';
INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order)
SELECT 'drains.by-mounting-type.other-drains', parent.id, 'Other Drains', 4
FROM categories AS parent WHERE parent.category_key = 'drains.by-mounting-type';

INSERT IGNORE INTO categories (category_key, parent_id, name, sort_order) VALUES ('drinking-fountains', NULL, 'Drinking Fountains', 9);
