<?php

namespace Gaufrette\Adapter;

@trigger_error('The '.__NAMESPACE__.'\SafeLocal is deprecated since version 0.4. Use Gaufrette\Adapter\Local\SafeLocal instead.', E_USER_DEPRECATED);

/**
 * Safe local adapter that encodes key to avoid the use of the directories
 * structure.
 *
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class SafeLocal extends Local
{
    /**
     * {@inheritdoc}
     */
    public function computeKey($path)
    {
        return base64_decode(parent::computeKey($path));
    }

    /**
     * {@inheritdoc}
     */
    protected function computePath($key)
    {
        return parent::computePath(base64_encode($key));
    }
}
