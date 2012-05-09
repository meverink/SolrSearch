<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4; */

/**
 * @package     omeka
 * @subpackage  SolrSearch
 * @author      Scholars' Lab <>
 * @author      Eric Rochester <erochest@virginia.edu>
 * @author      David McClure <david.mcclure@virginia.edu>
 * @copyright   2012 The Board and Visitors of the University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html Apache 2 License
 */


class SolrSearch_Addon_Indexer_Test extends SolrSearch_Test_AppTestCase
{
    
    public function setUp()
    {
        $this->setUpPlugin();
        $this->setUpExhibitBuilder();
        $this->setUpSimplePages();
        $this->loadModels();

        $this->mgr = new SolrSearch_Addon_Manager($this->db);
        $addons = $this->mgr->parseAll();
        $this->exhibits = $addons['exhibits'];

        $this->idxr = new SolrSearch_Addon_Indexer($this->db);
    }

    private function setUpExhibitBuilder()
    {
        $this->_setUpNamedPlugin('ExhibitBuilder');
    }

    private function setUpSimplePages()
    {
        $this->_setUpNamedPlugin('SimplePages');
    }

    private function createExhibit($exhibit)
    {
        $e = new Exhibit();
        $e->title       = property_exists($exhibit, 'title') ? $exhibit->title : null;
        $e->description = property_exists($exhibit, 'description') ? $exhibit->description : null;
        $e->public      = property_exists($exhibit, 'public') ? $exhibit->public : true;
        $e->public      = $e->public ? 1 : 0;
        $e->save();

        if (property_exists($exhibit, 'tags')) {
            $e->addTags($exhibit->tags, $this->user);
            $e->save();
        }

        $i = 1;
        foreach ($exhibit->sections as $section) {
            $s = new ExhibitSection();
            $s->title       = property_exists($section, 'title') ? $section->title : null;
            $s->description = property_exists($section, 'description') ? $section->description : null;
            $s->exhibit_id  = $e->id;
            $s->order       = $i;
            $s->save();

            $j = 0;
            foreach ($section->pages as $page) {
                $p = new ExhibitPage();
                $p->title      = property_exists($page, 'title') ? $page->title : null;
                $p->section_id = $s->id;
                $p->order      = $j;
                $p->save();

                $entries = property_exists($page, 'entries') ? $page->entries : array();
                $k = 0;
                foreach ($entries as $entry) {
                    $item = $this->__item(
                        property_exists($entry, 'title') ? $entry->title : null,
                        property_exists($entry, 'subject') ? $entry->subject : null
                    );

                    $pe = new ExhibitPageEntry();
                    $pe->item_id = $item->id;
                    $pe->page_id = $p->id;
                    $pe->order   = $k;
                    $pe->text    = property_exists($entry, 'text') ? $entry->text : null;
                    $pe->caption = property_exists($entry, 'caption') ? $entry->caption : null;
                    $pe->save();

                    $k++;
                }

                $j++;
            }

            $i++;
        }

        return $e;
    }

    private function loadModels()
    {
        $filename = SOLR_SEARCH_PLUGIN_DIR . '/tests/fixtures/exhibits.json';
        $exhibits = json_decode(file_get_contents($filename));

        foreach ($exhibits as $exhibit) {
            $this->createExhibit($exhibit);
        }
    }

    public function testExhibitBuilderInstalled()
    {
        $table = $this->db->getTable('Exhibit');
        $this->assertNotNull($table);

        $tables = $this->db->fetchAssoc('SHOW TABLES;');
        $this->assertArrayHasKey('omeka_exhibits', $tables);

        $rows = $table->findAll();
        $this->assertNotEmpty($rows);
    }

    public function testModels()
    {
        $table = $this->db->getTable('Exhibit');
        $select = $table->getSelect();
        $table->filterByPublic($select, 1);
        $es = $table->fetchObjects($select);

        $this->assertCount(1, $es);

        $e = $es[0];
        $this->assertEquals('Test Exhibit', $e->title);

        $tags = $e->getTags();
        $this->assertCount(3, $tags);
    }

    public function testMakeSolrName()
    {
        $indexer = $this->idxr;
        $this->assertEquals(
            'exhibits_title_s',
            $indexer->makeSolrName($this->exhibits, 'title')
        );
        $this->assertEquals(
            'exhibits_description_s',
            $indexer->makeSolrName($this->exhibits, 'description')
        );
        $this->assertEquals(
            'section_pages_title_s',
            $indexer->makeSolrName($this->mgr->addons['section_pages'], 'title')
        );
    }

    public function testIndexAddons()
    {
        $idxr = $this->idxr;
        $docs = $idxr->indexAll($this->mgr->addons);

        $this->assertNotEmpty($docs);
        $this->assertInstanceOf('Apache_Solr_Document', $docs[0]);
    }

    public function testIndexFields()
    {
        $idxr = $this->idxr;
        $docs = $idxr->indexAll($this->mgr->addons);

        // All addons have a title field, so check for that on every document.
        foreach ($docs as $doc) {
            $ok = false;
            foreach ($doc->getFieldNames() as $name) {
                $ok = $ok || stripos($name, '_title_') !== FALSE;
            }
            $this->assertTrue($ok, $doc->id);
        }
    }

    public function testIndexExhibit()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('exhibits_title_s')) !== false) {
                $descr = $doc->getField('exhibits_description_s');

                $this->assertEquals('Test Exhibit', $title['value'][0]);
                $this->assertEquals('Like Alice in Wonderland', $descr['value'][0]);
            }
        }
    }

    public function testIndexSection()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('sections_title_s')) !== false) {
                $descr = $doc->getField('sections_descriptions_s');

                $this->assertContains(
                    $title['value'][0],
                    array("White Rabbit's House", "Mad Hatter's Tea Party")
                );
                $this->assertFalse($descr, print_r($descr, true));
            }
        }
    }

    public function testIndexPage()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('section_pages_title_s')) !== false) {
                $this->assertContains(
                    $title['value'][0],
                    array("White Rabbit", "Dormouse")
                );
                $this->assertFalse($descr);
            }
        }
    }

    public function testIndexPageEntry()
    {
        $this->assertTrue(false, 'testIndexPageEntry');
    }

    public function testIndexPrivateExhibit()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('exhibits_title_s')) !== false) {
                $this->assertNotEquals('Private Exhibit', $title['value'][0]);
            }
        }
    }

    public function testIndexPrivateSection()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('sections_title_s')) !== false) {
                $this->assertNotEquals($title['value'][0], "Mud");
            }
        }
    }

    public function testIndexPrivatePage()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($title = $doc->getField('section_pages_title_s')) !== false) {
                $this->assertNotEquals($title['value'][0], "Earthworms");
                $this->assertNotEquals($title['value'][0], "Centipedes");
            }
        }
    }

    public function testIndexTagged()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($doc->getField('exhibits_title_s')) !== false) {
                $tags = $doc->getField('tag');

                $this->assertContains('test',    $tags['value']);
                $this->assertContains('exhibit', $tags['value']);
                $this->assertContains('tagged',  $tags['value']);
            }
        }
    }

    public function testIndexExhibitResultType()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($doc->getField('exhibits_title_s')) !== false) {
                $resultType = $doc->getField('resulttype');

                $this->assertTrue($resultType !== false);
                $this->assertContains('Exhibits', $resultType['value']);
            }
        }
    }

    public function testIndexSectionResultType()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($doc->getField('sections_title_s')) !== false) {
                $resultType = $doc->getField('resulttype');

                $this->assertTrue($resultType !== false);
                $this->assertContains('Sections', $resultType['value']);
            }
        }
    }

    public function testIndexPageResultType()
    {
        $docs = $this->idxr->indexAll($this->mgr->addons);

        foreach ($docs as $doc) {
            if (($doc->getField('section_pages_title_s')) !== false) {
                $resultType = $doc->getField('resulttype');

                $this->assertTrue($resultType !== false);
                $this->assertContains('Exhibit Pages', $resultType['value']);
            }
        }
    }

    public function testAfterSaveRecord()
    {
        $this->assertTrue(false, 'testAfterSaveRecord');
    }

    public function testAfterDeleteRecord()
    {
        $this->assertTrue(false, 'testAfterDeleteRecord');
    }

}

