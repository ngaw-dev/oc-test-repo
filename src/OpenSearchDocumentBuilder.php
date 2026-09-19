<?php

// modules/silverstripe-opensearch/src/OpenSearchDocumentBuilder.php

namespace AmolSW\OpenSearch;

use RuntimeException;
use SilverStripe\Blog\Model\Blog;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * Maps BlogPost records to OpenSearch documents and resolves which
 * posts are publishable (Live stage under a published top-level Blog).
 *
 * The document shape is driven by the `mapping` config on
 * OpenSearchClientFactory, defined at the PROJECT level (e.g.
 * app/_config/opensearch.yml) so each project controls which fields
 * are indexed. A mapping type of `plain_text` strips HTML markup from
 * the field value before indexing; the index mapping sent to OpenSearch
 * receives a normal `text` type instead.
 */
class OpenSearchDocumentBuilder
{
    /**
     * Mapping type that flags a field for HTML-to-plain-text extraction.
     */
    private const TYPE_PLAIN_TEXT = 'plain_text';

    /**
     * Maps a BlogPost to an OpenSearch document, one entry per field
     * configured in the project-level mapping YAML.
     */
    public static function buildDocument(BlogPost $post): array
    {
        $document = ['_id' => $post->ID];

        foreach (self::getMappingFields() as $field => $spec) {
            $type = $spec['type'] ?? 'text';
            $document[$field] = self::getFieldValue($post, $field, $type);
        }

        return $document;
    }

    /**
     * Resolves a configured field name to its BlogPost value.
     *
     * @throws RuntimeException for fields this builder does not know how to extract
     */
    private static function getFieldValue(BlogPost $post, string $field, string $type): mixed
    {
        $value = match ($field) {
            'Title' => (string) $post->Title,
            'Summary' => self::toPlainText($post->dbObject('Summary')->forTemplate()),
            'Content' => self::toPlainText($post->getElementsForSearch()),
            'PageId' => (int) $post->ID,
            'ParentPageId' => (int) $post->ParentID,
            // Link() appends ?stage=Live when Versioned stage is set explicitly; strip it for public URLs
            'PageLink' => preg_replace('/\?stage=\w+$/', '', $post->Link()),
            default => throw new RuntimeException(
                sprintf(
                    'Unknown field "%s" in OpenSearch mapping config. Either remove it from the project-level '
                    . 'mapping YAML or extend %s::getFieldValue() to extract it.',
                    $field,
                    self::class
                )
            ),
        };

        return $value;
    }

    /**
     * Strips HTML tags/entities so OpenSearch indexes searchable text, not markup.
     */
    private static function toPlainText(string $html): string
    {
        return DBField::create_field(DBHTMLText::class, $html)->Plain();
    }

    /**
     * Mapping fields from project-level YAML config on the factory class.
     */
    private static function getMappingFields(): array
    {
        $mapping = Config::inst()->get(OpenSearchClientFactory::class, 'mapping') ?: [];

        if (empty($mapping)) {
            throw new RuntimeException(
                'No OpenSearch mapping configured. Define OpenSearchClientFactory.mapping in project-level '
                . 'YAML (e.g. app/_config/opensearch.yml) — see docs/SETUP.md.'
            );
        }

        return $mapping;
    }

    /**
     * Returns the OpenSearch index mapping (properties) derived from the
     * project-level mapping config: `plain_text` fields are sent to
     * OpenSearch as `text`.
     */
    public static function getIndexMapping(): array
    {
        $properties = [];

        foreach (self::getMappingFields() as $field => $spec) {
            $indexSpec = $spec;
            if (($indexSpec['type'] ?? '') === self::TYPE_PLAIN_TEXT) {
                $indexSpec['type'] = 'text';
            }
            $properties[$field] = $indexSpec;
        }

        return $properties;
    }

    /**
     * Returns Live-stage BlogPosts that belong to a published top-level Blog.
     * Posts under unpublished Blogs are unreachable and must not be indexed;
     * get_by_stage() instead of set_stage()+get(): set_stage() breaks Parent() resolution.
     */
    public static function getPublishablePosts(): DataList
    {
        $blogIds = self::getLiveRootBlogIds();

        if (empty($blogIds)) {
            return BlogPost::get()->filter('ID', 0);
        }

        return Versioned::get_by_stage(BlogPost::class, Versioned::LIVE)->filter('ParentID', $blogIds);
    }

    /**
     * Single source of truth for "may this post appear in public search":
     * the post is on Live stage AND its parent is a published top-level
     * Blog. Silverstripe unpublish is non-recursive, so a Live post under
     * an unpublished Blog is NOT publicly reachable.
     */
    public static function isPostPubliclyVisible(int $pageId): bool
    {
        $post = Versioned::get_by_stage(BlogPost::class, Versioned::LIVE)->byID($pageId);

        if (!$post) {
            return false;
        }

        return in_array($post->ParentID, self::getLiveRootBlogIds(), true);
    }

    /**
     * IDs of top-level Blogs currently on the Live stage.
     */
    private static function getLiveRootBlogIds(): array
    {
        return Versioned::get_by_stage(Blog::class, Versioned::LIVE)
            ->filter('ParentID', 0)
            ->column('ID');
    }
}
