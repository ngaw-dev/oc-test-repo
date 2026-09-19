<?php

// modules/silverstripe-opensearch/src/Admin/SearchDataGridToolbar.php

namespace AmolSW\OpenSearch\Admin;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;

/**
 * Renders the Search Data filter row and bulk-action buttons inside the
 * GridField header (buttons-before-left / buttons-before-right fragments).
 *
 * A raw GET-driven filter is used instead of GridFieldFilterHeader because
 * the grid lists an ArrayList of ArrayData built from OpenSearch hits —
 * no SearchContext exists for the filter header to hook into.
 */
class SearchDataGridToolbar implements GridField_HTMLProvider
{
    public function __construct(
        private readonly string $filterLink,
        private readonly string $clearLink,
        private readonly string $deleteAllLink,
        private readonly string $reindexLink,
        private readonly string $term = ''
    ) {
    }

    public function getHTMLFragments($grid)
    {
        $escapedTerm = htmlspecialchars($this->term, ENT_QUOTES, 'UTF-8');

        // Nested <form> inside the CMS edit form is invalid HTML and gets
        // dropped by browsers, so the filter uses JS navigation instead
        $filter = sprintf(
            '<div class="d-flex align-items-center gap-2 search-data-filter">'
            . '<input type="text" id="search-data-term" value="%s" placeholder="Search by title or summary..."'
            . ' class="form-control no-change-track" style="min-width: 320px;"'
            . ' onkeydown="if (event.key === \'Enter\') { window.location.href = \'%s?term=\''
            . ' + encodeURIComponent(document.getElementById(\'search-data-term\').value); }" />'
            . ' <button type="button" class="btn btn-primary" onclick="window.location.href='
            . '\'%s?term=\' + encodeURIComponent(document.getElementById(\'search-data-term\').value);">Search</button>'
            . ' <button type="button" class="btn btn-outline-primary"'
            . ' onclick="window.location.href=\'%s\';">Clear</button>'
            . '</div>',
            $escapedTerm,
            $this->filterLink,
            $this->filterLink,
            $this->clearLink
        );

        // Destructive delete-all is demoted to outline-danger; reindex is
        // the common safe action and stays primary
        $actions = sprintf(
            '<div class="d-flex gap-2 search-data-actions">'
            . '<a href="%s" class="btn btn-outline-danger font-icon-trash"'
            . ' onclick="return confirm(\'Delete ALL documents from the search index?'
            . ' This cannot be undone.\');">Delete all</a>'
            . ' <a href="%s" class="btn btn-primary font-icon-sync">Reindex all</a>'
            . '</div>',
            $this->deleteAllLink,
            $this->reindexLink
        );

        return [
            'buttons-before-left' => $filter,
            'buttons-before-right' => $actions,
        ];
    }
}
