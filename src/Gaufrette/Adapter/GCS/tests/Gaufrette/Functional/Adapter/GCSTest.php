<?php

namespace Gaufrette\Functional\Adapter;

use Gaufrette\Adapter\GCS\GCS;
use Gaufrette\Filesystem;

/**
 * Functional tests for the GoogleCloudStorage adapter.
 *
 * Copy the ../adapters/GoogleCloudStorage.php.dist to GoogleCloudStorage.php and
 * adapt to your needs.
 *
 * @author  Patrik Karisch <patrik@karisch.guru>
 */
class GCSTest extends FunctionalTestCase
{
    public function setUp()
    {
        $client = new \Google_Client();
        $client->setApplicationName(getenv('GCS_APP_NAME'));
        $cred = $client->loadServiceAccountJson(
            getenv('GCS_ACCOUNT_JSON'),
            'https://www.googleapis.com/auth/devstorage.read_write'
        );
        $client->setAssertionCredentials($cred);

        $service = new \Google_Service_Storage($client);
        $this->filesystem = new Filesystem(new GCS($service, getenv('GCS_BUCKET')));
    }

    /**
     * @test
     * @group functional
     *
     * @expectedException \RuntimeException
     */
    public function shouldThrowExceptionIfBucketMissing()
    {
        /** @var \Gaufrette\Adapter\GoogleCloudStorage $adapter */
        $adapter = $this->filesystem->getAdapter();
        $oldBucket = $adapter->getOptions();
        $adapter->setBucket('Gaufrette-' . mt_rand());

        $adapter->read('foo');
        $adapter->setBucket($oldBucket);
    }

    /**
     * @test
     * @group functional
     */
    public function shouldWriteAndReadWithDirectory()
    {
        /** @var \Gaufrette\Adapter\GoogleCloudStorage $adapter */
        $adapter = $this->filesystem->getAdapter();
        $oldOptions = $adapter->getOptions();
        $adapter->setOptions(array('directory' => 'Gaufrette'));

        $this->assertEquals(12, $this->filesystem->write('foo', 'Some content'));
        $this->assertEquals(13, $this->filesystem->write('test/subdir/foo', 'Some content1', true));

        $this->assertEquals('Some content', $this->filesystem->read('foo'));
        $this->assertEquals('Some content1', $this->filesystem->read('test/subdir/foo'));

        $this->filesystem->delete('foo');
        $this->filesystem->delete('test/subdir/foo');
        $adapter->setOptions($oldOptions);
    }

    /**
     * @test
     * @group functional
     */
    public function shouldSetMetadataCorrectly()
    {
        /** @var \Gaufrette\Adapter\GoogleCloudStorage $adapter */
        $adapter = $this->filesystem->getAdapter();

        $adapter->setMetadata('metadata.txt', array(
            'CacheControl' => 'public, maxage=7200',
            'ContentDisposition' => 'attachment; filename="test.txt"',
            'ContentEncoding' => 'identity',
            'ContentLanguage' => 'en',
            'Colour' => 'Yellow',
        ));

        $this->assertEquals(12, $this->filesystem->write('metadata.txt', 'Some content', true));

        $reflectionObject = new \ReflectionObject($adapter);
        $reflectionMethod = $reflectionObject->getMethod('getObjectData');
        $reflectionMethod->setAccessible(true);
        $metadata = $reflectionMethod->invoke($adapter, array('metadata.txt'));

        $this->assertEquals('public, maxage=7200', $metadata->cacheControl);
        $this->assertEquals('attachment; filename="test.txt"', $metadata->contentDisposition);
        $this->assertEquals('identity', $metadata->contentEncoding);
        $this->assertEquals('en', $metadata->contentLanguage);
        $this->assertEquals(array(
            'Colour' => 'Yellow',
        ), $metadata->metadata);

        $this->filesystem->delete('metadata.txt');
    }
}
