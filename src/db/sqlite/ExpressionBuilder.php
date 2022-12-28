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
 * @author Santilín <software@noviolento.es>
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
		if( $value == 'NOW()' ) {
			return 'CURRENT_TIMESTAMP';
		} else {
			$matches = null;
			if( preg_match_all("/\s*CONCAT\s*\((.*)\)/", $expression, $matches) ) {
				$fields = $matches[1][0];
 				if( preg_match_all("/\s*([^',]+)\s*|\s*'([^']+)'\s*,?/", $fields, $matches) ) {
					$fields = [];
					foreach( $matches[1] as $k => $v ) {
						$v = trim($v);
						if( $v == '' ) {
							if( $matches[2][$k] != '' ) {
								$fields[] = "'".$matches[2][$k]."'";
							}
						} else {
							$fields[] = $v;
						}
					}
					return ( join('||', $fields) );
				}
			}

			return $value;
		}
	}
}
