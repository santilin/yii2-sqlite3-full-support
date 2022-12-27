<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 * @author santilin (software@noviolento.es)
 */
namespace santilin\db\sqlite;
use yii\db\ExpressionInterface;
use yii\db\ExpressionBuilderInterface;
use yii\db\ExpressionBuilderTrait;

/**
 * Class ExpressionBuilder builds objects of [[yii\db\Expression]] class.
 *
 * @author Dmitry Naumenko <d.naumenko.a@gmail.com>
 * @since 2.0.14
 */
class ExpressionBuilder implements ExpressionBuilderInterface
{
    use ExpressionBuilderTrait;

    /**
     * {@inheritdoc}
     * @param Expression|ExpressionInterface $expression the expression to be built
     */
	public function build(ExpressionInterface $expression, array &$params = [])
    {
        $params = array_merge($params, $expression->params);
        $value = $expression->__toString();
		if( $value == "NOW()" ) {
			return "CURRENT_TIMESTAMP";
		} else if( $value == "UNIX_TIMESTAMP()" ) {
			return "CAST(strftime('%s', 'now') AS INT)";
		} else {
			return $value;
		}
	}
}
