<?php

namespace Gaufrette\Adapter;

use Gaufrette\Checksum;
use Gaufrette\Path;
use Gaufrette\File;

use Gaufrette\FileCursor\GridFS as GridFSFileCursor;
use Gaufrette\Filesystem;

/**
 * Adapter for the GridFS filesystem on MongoDB database
 *
 * @author Tomi Saarinen <tomi.saarinen@rohea.com>
 */
class GridFS extends Base
{
    /**
     * GridFS Instance
     * @var \MongoGridFS instance
     */
    protected $gridfsInstance = null;

    /**
     * Constructor
     *
     * @param \MongoGridFS instance
     */
    public function __construct(\MongoGridFS $instance)
    {
        $this->gridfsInstance = $instance;
    }

   /**
    * Gets file object by key
    *
    * @param string $key
    * @return File file object
    */
    public function get($key, $filesystem)
    {
        $gridfsFile = $this->gridfsInstance->findOne(array('key' => $key));
        $file = new File($key, $filesystem);
        $file->setName($gridfsFile->file['filename']);
        $file->setCreated(new \DateTime("@".$gridfsFile->file['uploadDate']->sec));
        $file->setSize($gridfsFile->file['length']);
        if (isset($gridfsFile->file['metadata'])) {
            $file->setMetadata($gridfsFile->file['metadata']);
        }

        return $file;
    }

    /**
     * {@InheritDoc}
     */
    public function read($key)
    {
        //TODO: Normalize key somehow
        //var_dump( Path::normalize($key));
        $gridfsFile = $this->gridfsInstance->findOne(array('key'=>$key));

        return $gridfsFile->getBytes();
    }

    /**
     * {@InheritDoc}
     * @param array metadata any metadata in assoc array format
     * @param string filename human readable (e.g. someImage.jpg) NOT IN USE ATM.
     */
    public function write($key, $content, array $metadata=null)
    {
        //If a file exists with the same key, delete it
        if ($this->exists($key)) {
            $this->delete($key);
        }
        //Break down key, assume '/' is used for delimiter and last part is the filename
        $keyParts = array_filter(explode('/', $key));
        $dataArray = array(
            'key' => $key,
            'filename' => isset($keyParts[count($keyParts)]) ? $keyParts[count($keyParts)] : '',
            'uploadDate' => new \MongoDate(),
            'metadata' => $metadata,
        );
        $mongoId = $this->gridfsInstance->storeBytes($content, $dataArray);
        //TODO: How to do better counting of bytes for gridfs insertion
        $numBytes = strlen($content);

        return $numBytes;
    }

    /**
     * Rename = fetch old + write new + delete old
     *
     * @param key Current key (from)
     * @param new New key (to)
     * @return boolean
     */
    public function rename($key, $new)
    {
        $gridfsFile = $this->gridfsInstance->findOne(array('key' => $key));

        if (is_object($gridfsFile)) {
            $retval = $this->write($new, $gridfsFile->getBytes(), $gridfsFile->file['metadata']);

            if ($retval > 0) {
                return $this->delete($key);
            }
        }

        return false;
    }

    /**
     * {@InheritDoc}
     */
    public function exists($key)
    {
        return is_object($this->gridfsInstance->findOne(array('key'=>$key)));
    }

    /**
     * {@InheritDoc}
     */
    public function keys($prefix = null)
    {
		if (null !== $prefix) {
		    $cursor = $this->gridfsInstance->find(array('key' => sprintf('/^%s/', preg_quote($prefix)) ), array('key'));
		} else {
            /**
             * This seems to work but performance is a big question...
             */
            $cursor = $this->gridfsInstance->find(array(), array('key'));
        }
        $temp = array();
        foreach($cursor as $f) {
            $temp[] = $f->file['key'];
        }

        return $temp;
    }

    /**
     * {@InheritDoc}
     */
    public function mtime($key)
    {
        throw new \BadMethodCallException("Method not implemented yet.");
    }

    /**
     * {@inheritDoc}
     */
    public function checksum($key)
    {
        throw new \BadMethodCallException("Method not implemented yet.");
    }

    /**
     * {@InheritDoc}
     */
    public function delete($key)
    {
        $success = $this->gridfsInstance->remove(array('key'=>$key));

        return $success;
    }

    /**
     * {@InheritDoc}
     */
    public function supportsMetadata()
    {
        return true;
    }
}
