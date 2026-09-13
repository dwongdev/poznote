<?php
/**
 * The icon colour palette: one .folder-color-option per swatch, the first one
 * clearing the colour. Shared by the folder/note icon modal
 * (modals/folder_icon_modal.php, driven by js/folder-icon.js) and the icon rail
 * colour modal (icon_sidebar.php, driven by js/icon-sidebar-colors.js), so both
 * offer exactly the same colours.
 */
$iconColorOptions = [
    '#ef4444' => t('modals.folder_icon.red', [], 'Red'),
    '#f97316' => t('modals.folder_icon.orange', [], 'Orange'),
    '#f59e0b' => t('modals.folder_icon.amber', [], 'Amber'),
    '#eab308' => t('modals.folder_icon.yellow', [], 'Yellow'),
    '#84cc16' => t('modals.folder_icon.lime', [], 'Lime'),
    '#22c55e' => t('modals.folder_icon.green', [], 'Green'),
    '#10b981' => t('modals.folder_icon.emerald', [], 'Emerald'),
    '#14b8a6' => t('modals.folder_icon.teal', [], 'Teal'),
    '#06b6d4' => t('modals.folder_icon.cyan', [], 'Cyan'),
    '#0ea5e9' => t('modals.folder_icon.sky', [], 'Sky'),
    '#3b82f6' => t('modals.folder_icon.blue', [], 'Blue'),
    '#6366f1' => t('modals.folder_icon.indigo', [], 'Indigo'),
    '#8b5cf6' => t('modals.folder_icon.violet', [], 'Violet'),
    '#a855f7' => t('modals.folder_icon.purple', [], 'Purple'),
    '#d946ef' => t('modals.folder_icon.fuchsia', [], 'Fuchsia'),
    '#ec4899' => t('modals.folder_icon.pink', [], 'Pink'),
    '#f43f5e' => t('modals.folder_icon.rose', [], 'Rose'),
    '#64748b' => t('modals.folder_icon.slate', [], 'Slate'),
    '#6b7280' => t('modals.folder_icon.gray', [], 'Gray'),
    '#78716c' => t('modals.folder_icon.stone', [], 'Stone'),
    '#111827' => t('modals.folder_icon.black', [], 'Black'),
];
?>
            <div class="folder-color-picker">
                <div class="folder-color-option" data-color="" title="<?php echo t_h('modals.folder_icon.default_color', [], 'Default'); ?>">
                    <div class="folder-color-swatch folder-color-default"></div>
                </div>
<?php foreach ($iconColorOptions as $iconColorHex => $iconColorName): ?>
                <div class="folder-color-option" data-color="<?php echo $iconColorHex; ?>" title="<?php echo htmlspecialchars($iconColorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <div class="folder-color-swatch" style="background-color: <?php echo $iconColorHex; ?>;"></div>
                </div>
<?php endforeach; ?>
            </div>
