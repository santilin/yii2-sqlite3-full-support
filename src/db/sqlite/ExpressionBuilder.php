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
		$might_need_changes = true;
		while ($might_need_changes) {
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
			} elseif (preg_match_all("/(.*)\bCONCAT\b\(((?:[^()]|\([^()]*\))*)\)(.*)/", $value, $matches)) {
// simple: /(.*)\bCONCAT\b\((.*?)\)(.*)/
				$concat_params = $matches[2][0];
				if( preg_match_all(<<<regexp
/(((?:[^,()]|\([^()]*\))+)\s*,{0,1})/
regexp
// simple: parameters /\s*([^'`,]+)\s*|\s*['`]([^'`]+)['`]\s*/
					, $concat_params, $concat_params_matches, PREG_SET_ORDER)) {
					$sqlite_concat_params = []; // Adds ` to field names wihtout quotes
					foreach ($concat_params_matches as $concat_param) {
						$v = trim($concat_param[2]);
						if( $v != '' ) {
							// must coallesce fields to '' to avoid getting a whole null string
							if (preg_match('/("[a-zA-Z_]([a-zA-Z0-9_]*)")|([a-zA-Z_]([a-zA-Z0-9_]*))/', $v)) {
								$sqlite_concat_params[] = "COALESCE($v, '')";
							} else {
								$sqlite_concat_params[] = $v;
							}
						}
					}
					$value = $matches[1][0] . join('||', $sqlite_concat_params) . $matches[3][0];
					$might_need_changes = true;
				}
			} else if (preg_match_all("/(.*)\bGROUP_CONCAT\b\((.*?)\bSEPARATOR\b(.*)\)/", $value, $matches) ) {
				$value = "GROUP_CONCAT({$matches[2][0]}, {$matches[3][0]})";
				$might_need_changes = true;
			} else if (preg_match(<<<regexp
/\bIF\s*\(\s*([^,]+)\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/
regexp
				, $value, $matches)) {
	// @todo: /\bIF\b\(\s*([^,()]*(?:'[^']*'|"[^"]*")?[^,()]*)\s*,\s*([^,()]*(?:'[^']*'|"[^"]*")?[^,()]*)\s*,\s*([^,()]*(?:'[^']*'|"[^"]*")?[^,()]*)\s*\)/
				$value = "CASE WHEN {$matches[1]} THEN {$matches[2]} ELSE {$matches[3]} END";
				$might_need_changes = true;
            } elseif (preg_match_all("/SUBSTRING_INDEX\s*\(\s*([\w\.]+)\s*,\s*['\"]\.\s*['\"]\s*,\s*1\s*\)/i", $value, $matches)) {
                // Replace MySQL SUBSTRING_INDEX(column, '.', 1) with SQLite expression
                foreach ($matches[0] as $idx => $fullMatch) {
                    $column = $matches[1][$idx];
                    $replacement = "(CASE WHEN instr($column, '.') > 0 THEN substr($column, 1, instr($column, '.') - 1) ELSE $column END)";
                    // Replace the found substring in $value
                    $value = str_replace($fullMatch, $replacement, $value);
                }
                $might_need_changes = true;
			} else {
				return $value;
			}
		}
	}

}


// /(.*)\bCONCAT\b\(((?:[^()]|\([^()]*\))*)\)(.*)/
