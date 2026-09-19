<?php

// modules/silverstripe-opensearch/src/Admin/SearchDataManagerAdmin.php

namespace AmolSW\OpenSearch\Admin;

use AmolSW\OpenSearch\OpenSearchService;
use OpenSearch\Common\Exceptions\TransportException;
use RuntimeException;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

class SearchDataManagerAdmin extends LeftAndMain
{
    private static string $url_segment = 'search-data';

    private static string $menu_title = 'Search Data';

    private static string $menu_icon_class = 'font-icon-search';

    private static array $allowed_actions = [
        'deleteDocument',
        'deleteAllDocuments',
        'reindexAll',
    ];

    private static array $required_permission_codes = ['CMS_ACCESS_SearchDataManagerAdmin'];

    private const SESSION_NOTICE_KEY = 'SearchDataManagerAdmin.Notice';

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $term = trim((string) ($this->getRequest()->getVar('term') ?? ''));
        $result = $this->fetchDocuments($term);

        $notice = $this->getRequest()->getSession()->get(self::SESSION_NOTICE_KEY);
        $this->getRequest()->getSession()->clear(self::SESSION_NOTICE_KEY);

        $escapedTerm = htmlspecialchars($term, ENT_QUOTES, 'UTF-8');
        $escapedIndex = htmlspecialchars(OpenSearchService::getIndexName(), ENT_QUOTES, 'UTF-8');

        $fields = FieldList::create(
            HeaderField::create('SearchDataHeader', 'Indexed Search Documents'),
            LiteralField::create(
                'SearchDataNotice',
                $notice ? sprintf('<div class="alert alert-info">%s</div>', $notice) : ''
            ),
            LiteralField::create(
                'SearchDataStats',
                sprintf(
                    '<p class="search-data-stats">Showing %d document(s) in index "<strong>%s</strong>"'
                    . ' — %d total%s.</p>',
                    count($result['documents']),
                    $escapedIndex,
                    $result['total'],
                    $term !== '' ? sprintf(' for "<strong>%s</strong>"', $escapedTerm) : ''
                )
            ),
            $this->buildDocumentGrid($result['documents'], $term)
        );

        $form->setFields($fields);

        return $form;
    }

    private function fetchDocuments(string $term): array
    {
        try {
            return OpenSearchService::searchDocuments($term);
        } catch (RuntimeException | TransportException $e) {
            // RuntimeException: missing env config; TransportException family:
            // cluster unreachable (NoNodesAvailableException) and 5xx responses
            $this->getRequest()->getSession()->set(
                self::SESSION_NOTICE_KEY,
                'Could not reach the search index: ' . $e->getMessage()
            );
            return ['total' => 0, 'documents' => []];
        }
    }

    /**
     * Builds the document table from OpenSearch hits as ArrayData rows.
     * Filter + bulk actions render via SearchDataGridToolbar inside the
     * GridField header fragments.
     */
    private function buildDocumentGrid(array $documents, string $term): GridField
    {
        $list = ArrayList::create();

        foreach ($documents as $document) {
            $id = (string) ($document['ID'] ?? '');
            $list->push(ArrayData::create([
                'ID' => $id,
                'Title' => $document['Title'] ?? '',
                'PageId' => $document['PageId'] ?? '',
                'PageLink' => $document['PageLink'] ?? '',
                'Summary' => mb_substr((string) ($document['Summary'] ?? ''), 0, 140),
            ]));
        }

        $token = urlencode((string) SecurityToken::inst()->getValue());

        // Minimal config: GridFieldConfig_Base's filter header requires a
        // SearchContext on the listed class, which ArrayData cannot provide
        // GridFieldButtonRow defines the buttons-before-left/right fragment
        // slots that SearchDataGridToolbar fills
        $config = GridFieldConfig::create()
            ->addComponent(new GridFieldButtonRow('before'))
            ->addComponent(new SearchDataGridToolbar(
                $this->Link(),
                $this->Link(),
                $this->Link('deleteAllDocuments') . '?SecurityID=' . $token,
                $this->Link('reindexAll') . '?SecurityID=' . $token,
                $term
            ))
            ->addComponent(new GridFieldSortableHeader())
            ->addComponent(new GridFieldDataColumns())
            ->addComponent(new GridFieldPaginator());
        $grid = GridField::create('Documents', 'Documents', $list, $config);

        $dataColumns = $config->getComponentByType(GridFieldDataColumns::class);
        if ($dataColumns) {
            $dataColumns->setDisplayFields([
                'Title' => 'Title',
                'PageId' => 'Page ID',
                'PageLink' => 'Link',
                'Summary' => 'Summary',
                'Delete' => 'Delete',
            ]);
            $dataColumns->setFieldFormatting([
                'PageLink' => function ($value) {
                    return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', $value, $value);
                },
                'Delete' => function ($value, ArrayData $item) {
                    return sprintf(
                        '<a href="%s" class="btn btn-danger font-icon-trash btn-small"'
                        . ' onclick="return confirm(\'Delete this document from the search index?\');">Delete</a>',
                        $this->Link('deleteDocument')
                            . '?id=' . urlencode((string) $item->ID)
                            . '&SecurityID=' . urlencode((string) SecurityToken::inst()->getValue())
                    );
                },
            ]);
        }

        return $grid;
    }

    /**
     * Deletes a single document by id (doc _id = PageId), then redirects back to the list.
     */
    public function deleteDocument(): HTTPResponse
    {
        $this->checkPermission();
        $this->checkCsrfToken();

        $id = (string) ($this->getRequest()->getVar('id') ?? '');
        if ($id === '' || !ctype_digit($id)) {
            $this->getRequest()->getSession()->set(self::SESSION_NOTICE_KEY, 'Invalid document id.');
            return $this->redirectBack();
        }

        try {
            // deletePost is the facade's 404-tolerant single-doc delete
            OpenSearchService::deletePost((int) $id);
            $this->getRequest()->getSession()->set(
                self::SESSION_NOTICE_KEY,
                sprintf('Document %s deleted from the search index.', $id)
            );
        } catch (RuntimeException | TransportException $e) {
            $this->getRequest()->getSession()->set(self::SESSION_NOTICE_KEY, 'Delete failed: ' . $e->getMessage());
        }

        return $this->redirectBack();
    }

    /**
     * Deletes all documents (admin only).
     */
    public function deleteAllDocuments(): HTTPResponse
    {
        $this->checkPermission(true);
        $this->checkCsrfToken();

        try {
            $deleted = OpenSearchService::deleteAllDocuments();
            $this->getRequest()->getSession()->set(
                self::SESSION_NOTICE_KEY,
                sprintf('Deleted %d document(s) from the search index.', $deleted)
            );
        } catch (RuntimeException | TransportException $e) {
            $this->getRequest()->getSession()->set(self::SESSION_NOTICE_KEY, 'Delete-all failed: ' . $e->getMessage());
        }

        return $this->redirectBack();
    }

    /**
     * Re-indexes all published posts into the search index.
     */
    public function reindexAll(): HTTPResponse
    {
        $this->checkPermission();
        $this->checkCsrfToken();

        try {
            $result = OpenSearchService::reindexAllPosts();
            $message = sprintf('Re-indexed %d document(s).', $result['indexed']);
            if (!empty($result['errors'])) {
                $message .= sprintf(' %d document(s) failed.', count($result['errors']));
            }
            $this->getRequest()->getSession()->set(self::SESSION_NOTICE_KEY, $message);
        } catch (RuntimeException | TransportException $e) {
            $this->getRequest()->getSession()->set(self::SESSION_NOTICE_KEY, 'Re-index failed: ' . $e->getMessage());
        }

        return $this->redirectBack();
    }

    private function checkPermission(bool $requireAdmin = false): void
    {
        $member = Security::getCurrentUser();

        if (!$member || !$this->canView($member)) {
            $this->httpError(403, 'You do not have permission to manage search data.');
        }

        // Destructive delete-all is reserved for admins
        if ($requireAdmin && !Permission::check('ADMIN')) {
            $this->httpError(403, 'Only administrators may delete all search documents.');
        }
    }

    /**
     * The write actions are plain links inside the CMS edit form (nested
     * forms are invalid HTML), so the token travels as a query param and
     * is validated server-side — without it any page could trigger
     * delete/reindex as a logged-in CMS user via a crafted GET request.
     */
    private function checkCsrfToken(): void
    {
        if (!SecurityToken::inst()->checkRequest($this->getRequest())) {
            $this->httpError(400, 'Invalid security token.');
        }
    }
}
