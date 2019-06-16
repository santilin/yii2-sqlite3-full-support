<?php

namespace santilin\sqlite;

use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Bootstrap class of the yii2-sqlite3-full-support extension.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap($app)
    {
		$app->classMap['yii\db\sqlite\QueryBuilder'] = "@vendor/santilin/yii2-sqlite3-full-support/src/sqlite/QueryBuilder.php";
		$app->classMap['santilin\sqlite\SqlExpression'] = "@vendor/santilin/yii2-sqlite3-full-support/src/sqlite/SqlExpression.php";
	}
