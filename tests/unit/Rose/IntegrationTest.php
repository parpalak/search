<?php /** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/**
 * @copyright 2016-2020 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test;

use Codeception\Test\Unit;
use S2\Rose\Entity\ExternalContent;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\Query;
use S2\Rose\Finder;
use S2\Rose\Indexer;
use S2\Rose\SnippetBuilder;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Stemmer\PorterStemmerRussian;
use S2\Rose\Storage\Database\MysqlRepository;
use S2\Rose\Storage\Database\PdoStorage;
use S2\Rose\Storage\Exception\EmptyIndexException;
use S2\Rose\Storage\File\SingleFileArrayStorage;
use S2\Rose\Storage\StorageReadInterface;
use S2\Rose\Storage\StorageWriteInterface;

/**
 * @group int
 */
class IntegrationTest extends Unit
{
    const TEST_FILE_NUM = 17;

    /**
     * @return string
     */
    private function getTempFilename()
    {
        return __DIR__ . '/../../tmp/index2.php';
    }

    protected function _before()
    {
        @unlink($this->getTempFilename());
    }

    /**
     * @dataProvider indexableProvider
     *
     * @param Indexable[]           $indexables
     * @param StorageReadInterface  $readStorage
     * @param StorageWriteInterface $writeStorage
     *
     * @throws \Exception
     */
    public function testFeatures(
        array $indexables,
        StorageReadInterface $readStorage,
        StorageWriteInterface $writeStorage
    ) {
        $stemmer = new PorterStemmerRussian(new PorterStemmerEnglish());
        $indexer = new Indexer($writeStorage, $stemmer);

        // We're working on an empty storage
        if ($writeStorage instanceof PdoStorage) {
            $writeStorage->erase();
        }

        foreach ($indexables as $indexable) {
            $indexer->index($indexable);
        }

        if ($writeStorage instanceof SingleFileArrayStorage) {
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        // Reinit storage
        if ($readStorage instanceof SingleFileArrayStorage) {
            $readStorage->load();
        }
        $finder         = new Finder($readStorage, $stemmer);
        $snippetBuilder = new SnippetBuilder($stemmer);

        $snippetCallbackProvider = static function (array $ids) use ($indexables) {
            $result = new ExternalContent();
            foreach ($indexables as $indexable) {
                foreach ($ids as $id) {
                    if ($indexable->getExternalId()->equals($id)) {
                        $result->attach($id, $indexable->getContent());
                    }
                }
            }

            return $result;
        };

        // Query 1
        $resultSet1 = $finder->find(new Query('snippets'));
        $this->assertEquals([], $resultSet1->getSortedRelevanceByExternalId(), 'Do not index description');

        // Query 2
        $resultSet2 = $finder->find(new Query('content'));

        $this->assertEquals(['20:id_2' => 31, '20:id_1' => 1.5, '10:id_1' => 1], $resultSet2->getSortedRelevanceByExternalId());

        $items = $resultSet2->getItems();
        $this->assertEquals('id_1', $items[2]->getId());
        $this->assertEquals('10', $items[2]->getInstanceId());
        $this->assertEquals('Description can be used in snippets', $items[2]->getSnippet());

        $snippetBuilder->attachSnippets($resultSet2, $snippetCallbackProvider);

        $items = $resultSet2->getItems();

        $this->assertEquals('Test page title', $items[2]->getTitle());
        $this->assertEquals('url1', $items[2]->getUrl());
        $this->assertEquals('Description can be used in snippets', $items[2]->getDescription());
        $this->assertEquals(new \DateTime('2016-08-24 00:00:00'), $items[2]->getDate());
        $this->assertEquals(1.0, $items[2]->getRelevance());
        $this->assertEquals('I have changed the <i>content</i>.', $items[2]->getSnippet());

        $this->assertEquals(31, $items[0]->getRelevance());
        $this->assertEquals('This is the second page to be indexed. Let\'s compose something new.', $items[0]->getSnippet());

        $resultSet2 = $finder->find(new Query('content'));
        $resultSet2->setRelevanceRatio(new ExternalId('id_1', 10), 3.14);

        $this->assertEquals(['20:id_2' => 31.0, '10:id_1' => 3.14, '20:id_1' => 1.5], $resultSet2->getSortedRelevanceByExternalId());

        $resultSet2 = $finder->find(new Query('content'));
        $resultSet2->setRelevanceRatio(new ExternalId('id_1', 10), 100);
        $resultItems = $resultSet2->getItems();
        $this->assertCount(3, $resultItems);
        $this->assertEquals(100, $resultItems[0]->getRelevance(), 'Setting relevance ratio or sorting by relevance is not working');

        $resultSet2 = $finder->find(new Query('title'));
        $this->assertEquals('id_1', $resultSet2->getItems()[0]->getId());
        $this->assertEquals('Test page <i>title</i>', $resultSet2->getItems()[0]->getHighlightedTitle($stemmer));

        $resultSet2 = $finder->find((new Query('content'))->setInstanceId(10));
        $this->assertCount(1, $resultSet2->getItems());
        $this->assertEquals('id_1', $resultSet2->getItems()[0]->getId());
        $this->assertEquals(10, $resultSet2->getItems()[0]->getInstanceId());

        $resultSet2 = $finder->find((new Query('content'))->setInstanceId(20));
        $this->assertCount(2, $resultSet2->getItems());
        $this->assertEquals('id_2', $resultSet2->getItems()[0]->getId());
        $this->assertEquals(20, $resultSet2->getItems()[0]->getInstanceId());
        $this->assertEquals('id_1', $resultSet2->getItems()[1]->getId());
        $this->assertEquals(20, $resultSet2->getItems()[1]->getInstanceId());

        // Query 3
        $resultSet3 = $finder->find(new Query('сущность Plus'));
        $snippetBuilder->attachSnippets($resultSet3, $snippetCallbackProvider);
        $this->assertEquals('id_3', $resultSet3->getItems()[0]->getId());
        $this->assertEquals(
            'Тут есть тонкость - нужно проверить, как происходит экранировка в <i>сущностях</i> вроде &plus;. Для этого нужно включить в текст само сочетание букв "<i>plus</i>".',
            $resultSet3->getItems()[0]->getSnippet()
        );

        // Query 4
        $resultSet4 = $finder->find(new Query('эпл'));
        $this->assertCount(1, $resultSet4->getItems());

        $snippetBuilder->attachSnippets($resultSet4, $snippetCallbackProvider);
        $this->assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        $this->assertEquals(
            'Например, красно-черный, <i>эпл</i>-вотчем, и другие интересные комбинации.',
            $resultSet4->getItems()[0]->getSnippet()
        );

        $finder->setHighlightTemplate('<b>%s</b>');
        $resultSet4   = $finder->find(new Query('красный заголовку'));
        $resultItems4 = $resultSet4->getItems();
        $this->assertCount(1, $resultItems4);

        $snippetBuilder->attachSnippets($resultSet4, $snippetCallbackProvider);
        $this->assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        $this->assertEquals(
            'Например, <b>красно</b>-черный, эпл-вотчем, и другие интересные комбинации.',
            $resultItems4[0]->getSnippet()
        );
        $this->assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        $this->assertEquals(
            'Русский текст. <b>Красным</b> <b>заголовком</b>',
            $resultItems4[0]->getHighlightedTitle($stemmer)
        );

        // Query 5
        $resultSet5 = $finder->find(new Query('русский'));
        $this->assertCount(1, $resultSet5->getItems());
        $this->assertEquals(20, $resultSet5->getItems()[0]->getRelevance());

        $resultSet5 = $finder->find(new Query('русскому'));
        $this->assertCount(1, $resultSet5->getItems());
        $this->assertEquals(20, $resultSet5->getItems()[0]->getRelevance());

        // Query 6
        $resultSet6 = $finder->find(new Query('учитель не должен'));
        $this->assertCount(1, $resultSet6->getItems());
        $this->assertEquals(63.5, $resultSet6->getItems()[0]->getRelevance());

        // Query 7: Test empty queries
        $resultSet7 = $finder->find(new Query(''));
        $this->assertCount(0, $resultSet7->getItems());

        $resultSet7 = $finder->find(new Query('\'')); // ' must be cleared
        $this->assertCount(0, $resultSet7->getItems());

        // Query 8
        $resultSet8 = $finder->find(new Query('ціна'));
        $snippetBuilder->attachSnippets($resultSet8, $snippetCallbackProvider);
        $this->assertEquals(
            'Например, в украинском есть слово <b>ціна</b>.',
            $resultSet8->getItems()[0]->getSnippet()
        );

        // Query 9
        $resultSet9 = $finder->find(new Query('7.0'));
        $snippetBuilder->attachSnippets($resultSet9, $snippetCallbackProvider);
        $this->assertEquals(
            'Я не помню Windows 3.1, но помню Turbo Pascal <b>7.0</b>.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('7'));
        $snippetBuilder->attachSnippets($resultSet9, $snippetCallbackProvider);
        $this->assertEquals(
            'В 1,<b>7</b> раз больше... Я не помню Windows 3.1, но помню Turbo Pascal <b>7</b>.0. Надо отдельно посмотреть, что ищется по одной цифре <b>7</b>...',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('Windows 3'));
        $snippetBuilder->attachSnippets($resultSet9, $snippetCallbackProvider);
        $this->assertEquals(
            'Я не помню <b>Windows</b> <b>3</b>.1, но помню Turbo Pascal 7.0.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('Windows 3.1'));
        $snippetBuilder->attachSnippets($resultSet9, $snippetCallbackProvider);
        $this->assertEquals(
            'Я не помню <b>Windows</b> <b>3.1</b>, но помню Turbo Pascal 7.0.',
            $resultSet9->getItems()[0]->getSnippet()
        );
    }

    /**
     * @dataProvider indexableProvider
     *
     * @param Indexable[]           $indexables
     * @param StorageReadInterface  $readStorage
     * @param StorageWriteInterface $writeStorage
     *
     * @throws \RuntimeException
     */
    public function testParallelIndexingAndSearching(
        array $indexables,
        StorageReadInterface $readStorage,
        StorageWriteInterface $writeStorage
    ) {
        $stemmer = new PorterStemmerRussian();
        $indexer = new Indexer($writeStorage, $stemmer);

        // We're working on an empty storage
        if ($writeStorage instanceof PdoStorage) {
            $writeStorage->erase();
        }

        $indexer->index($indexables[0]);
        if ($writeStorage instanceof SingleFileArrayStorage) {
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        // Reinit storage
        if ($readStorage instanceof SingleFileArrayStorage) {
            $readStorage->load();
        }

        $finder    = new Finder($readStorage, $stemmer);
        $resultSet = $finder->find(new Query('page'));  // a word in $indexables[0]
        $this->assertCount(1, $resultSet->getItems());

        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->load();
        }
        $indexer->index($indexables[1]);
        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        $resultSet = $finder->find(new Query('page')); // a word in $indexables[1]
        if (!($readStorage instanceof SingleFileArrayStorage)) {
            $this->assertCount(2, $resultSet->getItems());
        }

        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->load();
        }
        $indexer->removeById($indexables[1]->getExternalId()->getId(), $indexables[1]->getExternalId()->getInstanceId());
        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        $resultSet = $finder->find(new Query('page'));
        if (!($readStorage instanceof SingleFileArrayStorage)) {
            $this->assertCount(1, $resultSet->getItems());
        }
    }

    public function testAutoErase()
    {
        global $s2_rose_test_db;
        $pdo = new \PDO($s2_rose_test_db['dsn'], $s2_rose_test_db['username'], $s2_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('DROP TABLE IF EXISTS ' . 'test_' . MysqlRepository::TOC);

        $pdoStorage = new PdoStorage($pdo, 'test_');
        $stemmer    = new PorterStemmerRussian();
        $indexer    = new Indexer($pdoStorage, $stemmer);
        $indexable  = new Indexable('id_1', 'Test page title', 'This is the first page to be indexed. I have to make up a content.', 10);

        $e = null;
        try {
            $indexer->index($indexable);
        } catch (EmptyIndexException $e) {
        }
        $this->assertNotNull($e);

        $indexer->setAutoErase(true);
        $indexer->index($indexable);
    }

    public function indexableProvider()
    {
        $indexables = [
            (new Indexable('id_1', 'Test page title', 'This is the first page to be indexed. I have to make up a content.', 10))
                ->setKeywords('singlekeyword, multiple keywords')
                ->setDescription('Description can be used in snippets')
                ->setDate(new \DateTime('2016-08-24 00:00:00'))
                ->setUrl('url1')
            ,
            (new Indexable('id_2', 'To be continued...', 'This is the second page to be indexed. Let\'s compose something new.', 20))
                ->setKeywords('content, ')
                ->setDescription('')
                ->setDate(new \DateTime('2016-08-20 00:00:00'))
                ->setUrl('any string')
            ,
            (new Indexable('id_3', 'Русский текст. Красным заголовком', '<p>Для проверки работы нужно написать побольше слов. В 1,7 раз больше. Вот еще одно предложение.</p><p>Тут есть тонкость - нужно проверить, как происходит экранировка в сущностях вроде &plus;. Для этого нужно включить в текст само сочетание букв "plus".</p><p>Еще одна особенность - наличие слов с дефисом. Например, красно-черный, эпл-вотчем, и другие интересные комбинации. Встречаются и другие знаки препинания, например, цифры. Я не помню Windows 3.1, но помню Turbo Pascal 7.0. Надо отдельно посмотреть, что ищется по одной цифре 7... Учитель не должен допускать такого...</p><p>А еще текст бывает на других языках. Например, в украинском есть слово ціна.</p>', 20))
                ->setKeywords('ключевые слова')
                ->setDescription('')
                ->setDate(new \DateTime('2016-08-22 00:00:00'))
                ->setUrl('/якобы.урл')
            ,
            (new Indexable('id_1', 'Test page title', 'This is the first page to be indexed. I have changed the content.', 10))
                ->setKeywords('singlekeyword, multiple keywords')
                ->setDescription('Description can be used in snippets')
                ->setDate(new \DateTime('2016-08-24 00:00:00'))
                ->setUrl('url1')
            ,
            (new Indexable('id_1', 'Another instance', 'The same id but another instance. Word "content" is present here. Twice: content.', 20))
            ,
        ];

        global $s2_rose_test_db;
        $pdo = new \PDO($s2_rose_test_db['dsn'], $s2_rose_test_db['username'], $s2_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $filename = $this->getTempFilename();

        return [
            'files' => [$indexables, new SingleFileArrayStorage($filename), new SingleFileArrayStorage($filename)],
            'db'    => [$indexables, new PdoStorage($pdo, 'test_'), new PdoStorage($pdo, 'test_')],
        ];
    }
}
