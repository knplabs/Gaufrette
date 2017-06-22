<?php

namespace Gaufrette\Adapter;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Gaufrette\Adapter;
use Gaufrette\Util;

/**
 * Amazon S3 adapter using the AWS SDK for PHP v2.x.
 *
 * @author  Michael Dowling <mtdowling@gmail.com>
 */
class AwsS3 implements Adapter, MetadataSupporter, ListKeysAware, SizeCalculator, MimeTypeProvider
{
    /*
     * Amazon S3 does not not allow uploads > 5Go
     */
    const MAX_CONTENT_SIZE = 5368709120;
    const DEFAULT_PART_SIZE = 5242880;

    /** @var S3Client */
    protected $service;
    /** @var string */
    protected $bucket;
    /** @var array */
    protected $options;
    /** @var bool */
    protected $bucketExists;
    /** @var array */
    protected $metadata = [];
    /** @var bool */
    protected $detectContentType;
    /** @var int */
    protected $sizeLimit = self::MAX_CONTENT_SIZE;
    /** @var int */
    protected $partSize = self::DEFAULT_PART_SIZE;

    /**
     * @param S3Client $service
     * @param string   $bucket
     * @param array    $options
     * @param bool     $detectContentType
     */
    public function __construct(S3Client $service, $bucket, array $options = [], $detectContentType = false)
    {
        $this->service = $service;
        $this->bucket = $bucket;

        if (isset($options['size_limit']) && $options['size_limit'] <= self::MAX_CONTENT_SIZE) {
            $this->sizeLimit = $options['size_limit'];
        }

        if (isset($options['part_size']) && $options['part_size'] >= self::DEFAULT_PART_SIZE) {
            $this->partSize = $options['part_size'];
        }

        $this->options = array_replace(
            [
                'create' => false,
                'directory' => '',
                'acl' => 'private',
            ],
            $options
        );

        $this->detectContentType = $detectContentType;
    }

    /**
     * Gets the publicly accessible URL of an Amazon S3 object.
     *
     * @param string $key     Object key
     * @param array  $options Associative array of options used to buld the URL
     *                        - expires: The time at which the URL should expire
     *                        represented as a UNIX timestamp
     *                        - Any options available in the Amazon S3 GetObject
     *                        operation may be specified.
     *
     * @return string
     *
     * @deprecated 1.0 Resolving object path into URLs is out of the scope of this repository since v0.4. gaufrette/extras
     *                 provides a Filesystem decorator with a regular resolve() method. You should use it instead.
     * @see https://github.com/Gaufrette/extras
     */
    public function getUrl($key, array $options = [])
    {
        @trigger_error(
            E_USER_DEPRECATED,
            'Using AwsS3::getUrl() method was deprecated since v0.4. Please chek gaufrette/extras package if you want this feature'
        );

        return $this->service->getObjectUrl(
            $this->bucket,
            $this->computePath($key),
            $options['expires'] ?? null,
            $options
        );
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata($key, $metadata)
    {
        // BC with AmazonS3 adapter
        if (isset($metadata['contentType'])) {
            $metadata['ContentType'] = $metadata['contentType'];
            unset($metadata['contentType']);
        }

        $this->metadata[$key] = $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($key)
    {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : [];
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions($key);

        try {
            // Get remote object
            $object = $this->service->getObject($options);
            // If there's no metadata array set up for this object, set it up
            if (!array_key_exists($key, $this->metadata) || !is_array($this->metadata[$key])) {
                $this->metadata[$key] = [];
            }
            // Make remote ContentType metadata available locally
            $this->metadata[$key]['ContentType'] = $object->get('ContentType');

            return (string) $object->get('Body');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions(
            $targetKey,
            ['CopySource' => $this->bucket . '/' . $this->computePath($sourceKey)]
        );

        try {
            $this->service->copyObject(array_merge($options, $this->getMetadata($targetKey)));

            return $this->delete($sourceKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions($key, ['Body' => $content]);

        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as binary/octet-stream.
         */
        if (!isset($options['ContentType']) && $this->detectContentType) {
            $options['ContentType'] = $this->guessContentType($content);
        }

        if ($isResource = is_resource($content)) {
            $size = Util\Size::fromResource($content);
        } else {
            $size = Util\Size::fromContent($content);
        }

        try {
            $success = true;

            if ($size >= $this->sizeLimit && $isResource) {
                $success = $this->multipartUpload($key, $content);
            } elseif ($size <= $this->sizeLimit) {
                $this->service->putObject($options);
            } else {
                /*
                 * content is a string & too big to be uploaded in one shot
                 * We may want to throw an exception here ?
                 */
                return false;
            }

            return $success ? $size : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        return $this->service->doesObjectExist($this->bucket, $this->computePath($key));
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return strtotime($result['LastModified']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return $result['ContentLength'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        return $this->listKeys();
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = '')
    {
        $this->ensureBucketExists();

        $options = ['Bucket' => $this->bucket];
        if ((string) $prefix != '') {
            $options['Prefix'] = $this->computePath($prefix);
        } elseif (!empty($this->options['directory'])) {
            $options['Prefix'] = $this->options['directory'];
        }

        $keys = [];
        $iter = $this->service->getIterator('ListObjects', $options);
        foreach ($iter as $file) {
            $keys[] = $this->computeKey($file['Key']);
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $this->service->deleteObject($this->getOptions($key));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key)
    {
        $result = $this->service->listObjects([
            'Bucket' => $this->bucket,
            'Prefix' => rtrim($this->computePath($key), '/') . '/',
            'MaxKeys' => 1,
        ]);
        if (isset($result['Contents'])) {
            if (is_array($result['Contents']) || $result['Contents'] instanceof \Countable) {
                return count($result['Contents']) > 0;
            }
        }

        return false;
    }

    /**
     * Ensures the specified bucket exists. If the bucket does not exists
     * and the create option is set to true, it will try to create the
     * bucket. The bucket is created using the same region as the supplied
     * client object.
     *
     * @throws \RuntimeException if the bucket does not exists or could not be
     *                           created
     */
    protected function ensureBucketExists()
    {
        if ($this->bucketExists) {
            return true;
        }

        if ($this->bucketExists = $this->service->doesBucketExist($this->bucket)) {
            return true;
        }

        if (!$this->options['create']) {
            throw new \RuntimeException(sprintf('The configured bucket "%s" does not exist.', $this->bucket));
        }

        $this->service->createBucket([
            'Bucket' => $this->bucket,
            'LocationConstraint' => $this->service->getRegion(),
        ]);
        $this->bucketExists = true;

        return true;
    }

    protected function getOptions($key, array $options = [])
    {
        $options['ACL'] = $this->options['acl'];
        $options['Bucket'] = $this->bucket;
        $options['Key'] = $this->computePath($key);

        /*
         * Merge global options for adapter, which are set in the constructor, with metadata.
         * Metadata will override global options.
         */
        $options = array_merge($this->options, $options, $this->getMetadata($key));

        return $options;
    }

    protected function computePath($key)
    {
        if (empty($this->options['directory'])) {
            return $key;
        }

        return sprintf('%s/%s', $this->options['directory'], $key);
    }

    /**
     * MultiPart upload for big files (exceeding size_limit)
     *
     * @param string   $key
     * @param resource $content
     *
     * @return bool
     */
    protected function multipartUpload($key, $content)
    {
        $uploadId = $this->initiateMultipartUpload($key);

        $parts = [];
        $partNumber = 1;

        rewind($content);

        try {
            while (!feof($content)) {
                $result = $this->uploadNextPart($key, $content, $uploadId, $partNumber);
                $parts[] = [
                    'PartNumber' => $partNumber++,
                    'ETag' => $result['ETag'],
                ];
            }
        } catch (S3Exception $e) {
            $this->abortMultipartUpload($key, $uploadId);

            return false;
        }

        $this->completeMultipartUpload($key, $uploadId, $parts);

        return true;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function initiateMultipartUpload($key)
    {
        $options = $this->getOptions($key);
        $result = $this->service->createMultipartUpload($options);

        return $result['UploadId'];
    }

    /**
     * @param string $key
     * @param string $content
     * @param string $uploadId
     * @param int    $partNumber
     *
     * @return \Aws\Result
     */
    protected function uploadNextPart($key, $content, $uploadId, $partNumber)
    {
        $options = $this->getOptions(
            $key,
            [
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => fread($content, $this->partSize),
            ]
        );

        $options = $this->getOptions($key, $options);

        return $this->service->uploadPart($options);
    }

    /**
     * @param string $key
     * @param string $uploadId
     * @param array  $parts
     */
    protected function completeMultipartUpload($key, $uploadId, $parts)
    {
        $options = $this->getOptions(
           $key,
           [
               'UploadId' => $uploadId,
               'MultipartUpload' => ['Parts' => $parts],
           ]
        );
        $this->service->completeMultipartUpload($options);
    }

    /**
     * @param string $key
     * @param string $uploadId
     */
    protected function abortMultipartUpload($key, $uploadId)
    {
        $options = $this->getOptions($key, ['UploadId' => $uploadId]);
        $this->service->abortMultipartUpload($options);
    }

    /**
     * Computes the key from the specified path.
     *
     * @param string $path
     *
     * @return string
     */
    protected function computeKey($path)
    {
        return ltrim(substr($path, strlen($this->options['directory'])), '/');
    }

    /**
     * @param string $content
     *
     * @return string
     */
    private function guessContentType($content)
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        if (is_resource($content)) {
            return $fileInfo->file(stream_get_meta_data($content)['uri']);
        }

        return $fileInfo->buffer($content);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function mimeType($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return $result['ContentType'];
        } catch (\Exception $e) {
            return false;
        }
    }
}
