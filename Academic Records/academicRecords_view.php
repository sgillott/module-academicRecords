<?php
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, "/modules/Academic Records/academicRecords_view.php")) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
    return;
} else {
    // Proceed!
    $page->breadcrumbs
        ->add(__('View Academic Records'));
    
    // Page heading
    echo '<h2>';
    echo __('View Academic Records');
    echo '</h2>';

}
