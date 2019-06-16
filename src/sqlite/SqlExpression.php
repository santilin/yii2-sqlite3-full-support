<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace santilin\sqlite;

/**
 * Condition that connects two or more SQL expressions with the `AND` operator.
 *
 * @author Dmytro Naumenko <d.naumenko.a@gmail.com>
 * @since 2.0.14
 */
class SqlExpression extends Expression
{
    /**
     * Returns the operator that is represented by this condition class, e.g. `AND`, `OR`.
     *
     * @return string
     */
    /**
     * String magic method.
     * @return string the DB expression.
     */
    public function __toString()
    {
        return "'now'";
    }
}

