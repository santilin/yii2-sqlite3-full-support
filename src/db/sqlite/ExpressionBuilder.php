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
        $value = trim($expression->__toString());
		if ($value == "AUTO_INCREMENT") {
			$value = ""; // not needed
		} elseif (trim($value) == "UNSIGNED") {
			$value = ""; // not supported
		} elseif ($value == "NOW()") {
			return "CURRENT_TIMESTAMP";
		} elseif ($value == "NOW(3)") {
			return "strftime('%Y-%m-%d %H:%M:%f', 'now')";
		} elseif ($value == "UNIX_TIMESTAMP()") {
			return "CAST(strftime('%s', 'now') AS INT)";
		} elseif( preg_match_all("/(.*)\bCONCAT\b\((.*?)\)(.*)/", $expression, $matches) ) {
			$fields = $matches[2][0];
			if( preg_match_all(<<<regexp
/\s*([^'`,]+)\s*|\s*['`]([^'`]+)['`]\s*/
regexp
				, $fields, $fld_matches)) {
				$fields = []; // Adds ` to field names wihtout quotes
				foreach ($fld_matches[0] as $k => $v ) {
					$v = trim($v);
					if( $v != '' ) {
						// must coallesce fields to '' to avoid getting a whole null string
						if (preg_match('/("[a-zA-Z_]([a-zA-Z0-9_]*)")|([a-zA-Z_]([a-zA-Z0-9_]*))/', $v)) {
							$fields[] = "COALESCE($v, '')";
						} else {
							$fields[] = $v;
						}
					}
				}
				$value = $matches[1][0] . join('||', $fields) . $matches[3][0];
			}
		} else if( preg_match_all("/(.*)\bGROUP_CONCAT\b\((.*?)\bSEPARATOR\b(.*)\)/", $expression, $matches) ) {
			return "GROUP_CONCAT({$matches[2][0]}, {$matches[3][0]})";
		}
		return $value;
	}

}
