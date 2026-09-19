<?php

// modules/silverstripe-opensearch/tests/OpenSearchDocumentBuilderTest.php

namespace AmolSW\OpenSearch\Tests;

use AmolSW\OpenSearch\OpenSearchDocumentBuilder;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Blog\Model\Blog;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Dev\SapphireTest;

class OpenSearchDocumentBuilderTest extends SapphireTest
{
    protected static $fixture_file = 'blog.yml';

    public function testBuildDocumentIncludesElementalContentStrippedOfMarkup()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');

        $post = BlogPost::create();
        $post->Title = 'Elemental Post';
        $post->ParentID = $blog->ID;
        $post->Summary = '<p>A <strong>summary</strong></p>';
        $post->write();

        $area = ElementalArea::create();
        $area->write();

        $element = ElementContent::create();
        $element->Title = 'Body block';
        $element->HTML = '<p>Searchable <em>body text</em> in an element.</p>';
        $element->ParentID = $area->ID;
        $element->write();

        $post->ElementalAreaID = $area->ID;
        $post->write();

        $document = OpenSearchDocumentBuilder::buildDocument($post);

        $this->assertSame($post->ID, $document['_id']);
        $this->assertSame('Elemental Post', $document['Title']);
        $this->assertSame('A summary', $document['Summary']);
        $this->assertSame($post->ID, $document['PageId']);
        $this->assertSame($blog->ID, $document['ParentPageId']);
        // Plain() replaces tags with spaces, so collapse whitespace before comparing
        $content = trim(preg_replace('/\s+/', ' ', $document['Content']));
        $this->assertSame('Searchable body text in an element.', $content);
        $this->assertStringNotContainsString('<p>', $document['Content']);
        $this->assertStringNotContainsString('<em>', $document['Content']);
    }

    public function testBuildDocumentWithoutElementsYieldsEmptyContent()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');

        $post = BlogPost::create();
        $post->Title = 'No Blocks Post';
        $post->ParentID = $blog->ID;
        $post->write();

        $document = OpenSearchDocumentBuilder::buildDocument($post);

        $this->assertSame('', $document['Content']);
    }
}
