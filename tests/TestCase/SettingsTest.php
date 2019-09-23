<?php

namespace Algolia\SearchBundle\TestCase;

use Algolia\SearchBundle\BaseTest;
use Algolia\SearchBundle\TestApp\Entity\Post;
use Algolia\SearchBundle\Settings\AlgoliaSettingsManager;
use Algolia\AlgoliaSearch\SearchClient;

class SettingsTest extends BaseTest
{
    /** @var SearchClient */
    private $client;

    /** @var AlgoliaSettingsManager */
    private $settingsManager;

    private $settingsDir = __DIR__ . '/../cache/settings';

    private $configIndexes;

    public function setUp()
    {
        parent::setUp();

        $this->client = $this->get('search.client');

        $this->settingsManager = $this->get('search.settings_manager');

        $this->configIndexes = $this->get('search.index_manager')->getConfiguration()['indices'];
    }

    public function tearDown()
    {
        $this->get('search.index_manager')->delete(Post::class);
    }

    public function testBackup()
    {
        $this->rrmdir($this->settingsDir);
        $settingsToUpdate = [
            'hitsPerPage'       => 51,
            'maxValuesPerFacet' => 99,
        ];
        $index = $this->client->initIndex($this->getPrefix() . 'posts');
        $task  = $index->setSettings($settingsToUpdate);
        $index->waitTask($task['taskID']);

        $message = $this->settingsManager->backup(['indices' => ['posts']]);

        $this->assertContains('Saved settings for', $message[0]);
        $this->assertTrue(file_exists($this->settingsDir . '/posts-settings.json'));

        $savedSettings = json_decode(file_get_contents(
            $this->settingsDir . '/posts-settings.json'
        ), true);

        $this->assertEquals($settingsToUpdate['hitsPerPage'], $savedSettings['hitsPerPage']);
        $this->assertEquals($settingsToUpdate['maxValuesPerFacet'], $savedSettings['maxValuesPerFacet']);
    }

    public function testBackupWithoutIndices()
    {
        $this->rrmdir($this->settingsDir);
        $settingsToUpdate = [
            'hitsPerPage'       => 51,
            'maxValuesPerFacet' => 99,
        ];

        foreach ($this->configIndexes as $indexName => $configIndex) {
            $index = $this->client->initIndex($this->getPrefix() . $indexName);
            $task  = $index->setSettings($settingsToUpdate);
            $index->waitTask($task['taskID']);
        }

        $message = $this->settingsManager->backup(['indices' => []]);

        $this->assertContains('Saved settings for', $message[0]);

        foreach ($this->configIndexes as $indexName => $configIndex) {
            $this->assertFileExists($this->settingsDir . '/' . $indexName . '-settings.json');

            $savedSettings = json_decode(file_get_contents(
                $this->settingsDir . '/' . $indexName . '-settings.json'
            ), true);

            $this->assertEquals($settingsToUpdate['hitsPerPage'], $savedSettings['hitsPerPage']);
            $this->assertEquals($settingsToUpdate['maxValuesPerFacet'], $savedSettings['maxValuesPerFacet']);
        }
    }

    /**
     * @depends testBackup
     */
    public function testPush()
    {
        $settingsToUpdate = [
            'hitsPerPage'       => 12,
            'maxValuesPerFacet' => 100,
        ];
        $index = $this->client->initIndex($this->getPrefix() . 'posts');
        $task  = $index->setSettings($settingsToUpdate);
        $index->waitTask($task['taskID']);

        $message = $this->settingsManager->push(['indices' => ['posts']]);

        $this->assertContains('Pushed settings for', $message[0]);

        $savedSettings = json_decode(file_get_contents(
            $this->settingsDir . '/posts-settings.json'
        ), true);

        for ($i = 0; $i < 5; $i++) {
            sleep(1);
            $settings = $index->getSettings();
            if (12 != $settings['hitsPerPage']) {
                $this->assertEquals($savedSettings, $settings);
            }
        }
    }

    /**
     * @depends testBackupWithoutIndices
     */
    public function testPushWithoutIndices()
    {
        $settingsToUpdate = [
            'hitsPerPage'       => 12,
            'maxValuesPerFacet' => 100,
        ];

        foreach ($this->configIndexes as $indexName => $configIndex) {
            $index = $this->client->initIndex($this->getPrefix() . $indexName);
            $task  = $index->setSettings($settingsToUpdate);
            $index->waitTask($task['taskID']);
        }

        $message = $this->settingsManager->push(['indices' => []]);

        $this->assertContains('Pushed settings for', $message[0]);

        foreach ($this->configIndexes as $indexName => $configIndex) {
            $savedSettings = json_decode(file_get_contents(
                $this->settingsDir . '/' . $indexName . '-settings.json'
            ), true);

            for ($i = 0; $i < 5; $i++) {
                sleep(1);
                $settings = $index->getSettings();
                if (12 != $settings['hitsPerPage']) {
                    $this->assertEquals($savedSettings, $settings);
                }
            }
        }
    }

    /**
     * @see https://www.php.net/rmdir
     */
    private function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir . '/' . $object)) {
                        rrmdir($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}
