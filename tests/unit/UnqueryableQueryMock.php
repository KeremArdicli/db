<?php
/**
 * @link http://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db\tests\unit;

use yii\db\Query;
use yii\exceptions\InvalidCallException;

class UnqueryableQueryMock extends Query
{
    /**
     * {@inheritdoc}
     */
    public function one($db = null)
    {
        throw new InvalidCallException();
    }

    /**
     * {@inheritdoc}
     */
    public function all($db = null)
    {
        throw new InvalidCallException();
    }
}
